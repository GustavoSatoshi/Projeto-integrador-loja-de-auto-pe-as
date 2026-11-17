<?php
declare(strict_types=1);

// =====================================================================
// Pedidos do cliente: finalizar compra (checkout), listar, ver e cancelar.
// =====================================================================

const STATUS_ROTULOS = [
    'aguardando_pagamento' => 'Aguardando pagamento',
    'pago'                 => 'Pagamento confirmado',
    'em_separacao'         => 'Em separação',
    'enviado'              => 'Enviado',
    'pronto_retirada'      => 'Pronto para retirada',
    'entregue'             => 'Entregue',
    'cancelado'            => 'Cancelado',
];

/** Para onde cada status pode ir (usado pelo painel admin). */
const TRANSICOES_PEDIDO = [
    'aguardando_pagamento' => ['pago', 'cancelado'],
    'pago'                 => ['em_separacao', 'cancelado'],
    'em_separacao'         => ['enviado', 'pronto_retirada', 'cancelado'],
    'enviado'              => ['entregue'],
    'pronto_retirada'      => ['entregue', 'cancelado'],
    'entregue'             => [],
    'cancelado'            => [],
];

/** Status que contam como venda concluída/paga (faturamento). */
const STATUS_FATURADOS = ['pago', 'em_separacao', 'enviado', 'pronto_retirada', 'entregue'];

function pedido_json(array $pe, bool $completo = true): array
{
    $json = [
        'id'              => (int) $pe['id'],
        'codigo'          => $pe['codigo'],
        'status'          => $pe['status'],
        'status_rotulo'   => STATUS_ROTULOS[$pe['status']] ?? $pe['status'],
        'tipo_entrega'    => $pe['tipo_entrega'],
        'forma_pagamento' => $pe['forma_pagamento'],
        'subtotal'        => (float) $pe['subtotal'],
        'desconto'        => (float) $pe['desconto'],
        'frete'           => (float) $pe['frete'],
        'total'           => (float) $pe['total'],
        'prazo_dias'      => $pe['prazo_dias'] !== null ? (int) $pe['prazo_dias'] : null,
        'codigo_rastreio' => $pe['codigo_rastreio'],
        'observacao'      => $pe['observacao'],
        'pago_em'         => $pe['pago_em'],
        'criado_em'       => $pe['criado_em'],
    ];
    if (isset($pe['qtd_itens'])) {
        $json['quantidade_itens'] = (int) $pe['qtd_itens'];
    }

    if ($pe['tipo_entrega'] === 'entrega') {
        $json['endereco_entrega'] = [
            'destinatario' => $pe['entrega_destinatario'],
            'cep'          => $pe['entrega_cep'],
            'logradouro'   => $pe['entrega_logradouro'],
            'numero'       => $pe['entrega_numero'],
            'complemento'  => $pe['entrega_complemento'],
            'bairro'       => $pe['entrega_bairro'],
            'cidade'       => $pe['entrega_cidade'],
            'uf'           => $pe['entrega_uf'],
        ];
    } else {
        $json['retirada_na_loja'] = regras_frete()['loja_endereco'];
    }

    if ($completo) {
        $json['itens'] = array_map(fn(array $i) => [
            'produto_id'     => (int) $i['produto_id'],
            'sku'            => $i['sku'],
            'nome'           => $i['nome'],
            'preco_unitario' => (float) $i['preco_unitario'],
            'quantidade'     => (int) $i['quantidade'],
            'subtotal'       => (float) $i['subtotal'],
        ], db_todos('SELECT produto_id, sku, nome, preco_unitario, quantidade, subtotal FROM pedido_itens WHERE pedido_id = ? ORDER BY id', [$pe['id']]));

        $json['historico'] = array_map(fn(array $h) => [
            'status'        => $h['status'],
            'status_rotulo' => STATUS_ROTULOS[$h['status']] ?? $h['status'],
            'observacao'    => $h['observacao'],
            'criado_em'     => $h['criado_em'],
        ], db_todos('SELECT status, observacao, criado_em FROM pedido_historico WHERE pedido_id = ? ORDER BY id', [$pe['id']]));
    }
    return $json;
}

function gerar_codigo_pedido(): string
{
    return 'SZ-' . strtoupper(bin2hex(random_bytes(4)));   // ex.: SZ-9F3A21BC
}

function pedido_historico_registrar(int $pedidoId, string $status, ?string $obs, ?int $usuarioId): void
{
    db_executar(
        'INSERT INTO pedido_historico (pedido_id, status, observacao, usuario_id) VALUES (?, ?, ?, ?)',
        [$pedidoId, $status, $obs, $usuarioId]
    );
}

// ---------------------------------------------------------------------
// Checkout
// ---------------------------------------------------------------------

function ctl_pedidos_criar(array $p): void
{
    $u = exigir_login();
    $v = validar(entrada(), [
        'tipo_entrega'    => 'obrigatorio|enum:entrega,retirada',
        'forma_pagamento' => 'obrigatorio|enum:pix,boleto,cartao',
        'endereco_id'     => 'inteiro:1',
        'observacao'      => 'texto:1:500',
    ]);

    $endereco = null;
    if ($v['tipo_entrega'] === 'entrega') {
        if (!isset($v['endereco_id'])) {
            abortar(422, 'Dados inválidos.', ['endereco_id' => 'Escolha o endereço de entrega.']);
        }
        $endereco = endereco_do_usuario((int) $v['endereco_id'], (int) $u['id']);
    }

    $carrinho = carrinho_ids();
    if (!$carrinho) {
        abortar(422, 'Seu carrinho está vazio.');
    }

    // Tudo abaixo acontece numa TRANSAÇÃO: ou o pedido inteiro é gravado
    // (pedido + itens + baixa no estoque), ou nada é gravado.
    $pedidoId = db_transacao(function () use ($u, $v, $endereco, $carrinho): int {
        $ids = array_map('intval', array_keys($carrinho));
        sort($ids);

        // FOR UPDATE trava as linhas: dois clientes comprando a última peça
        // ao mesmo tempo não conseguem os dois.
        $linhas = [];
        foreach (db_todos(
            'SELECT id, sku, nome, preco, preco_promocional, estoque, ativo,
                    peso_kg, comprimento_cm, largura_cm, altura_cm,
                    (SELECT c.ativo FROM categorias c WHERE c.id = produtos.categoria_id) AS categoria_ativa
               FROM produtos WHERE id IN (' . db_marcadores($ids) . ') ORDER BY id FOR UPDATE',
            $ids
        ) as $l) {
            $linhas[(int) $l['id']] = $l;
        }

        $problemas = [];
        $itens     = [];
        $subtotal  = 0;
        foreach ($carrinho as $pid => $qtd) {
            $pid = (int) $pid;
            $qtd = (int) $qtd;
            $l = $linhas[$pid] ?? null;
            if ($l === null || (int) $l['ativo'] !== 1 || (int) $l['categoria_ativa'] !== 1) {
                $problemas[] = ($l['nome'] ?? "Produto #$pid") . ': produto indisponível.';
                continue;
            }
            if ((int) $l['estoque'] < $qtd) {
                $problemas[] = $l['nome'] . ': estoque insuficiente (restam ' . (int) $l['estoque'] . ').';
                continue;
            }
            $unit = preco_final_centavos($l);   // preço vem do BANCO, nunca do cliente
            $itens[] = $l + ['quantidade' => $qtd, 'unit' => $unit];
            $subtotal += $unit * $qtd;
        }
        if ($problemas) {
            abortar(422, 'Alguns itens do carrinho não estão mais disponíveis.', ['itens' => $problemas]);
        }

        $pct       = desconto_oficina_percentual($u);
        $desconto  = (int) round($subtotal * $pct / 100);
        $baseFrete = $subtotal - $desconto;

        $frete = 0;
        $prazo = null;
        if ($endereco !== null) {
            $f     = frete_calcular($itens, $endereco['cep'], $baseFrete);
            $frete = $f['valor_centavos'];
            $prazo = $f['prazo_dias'];
        }
        $total = $baseFrete + $frete;

        // Cria o pedido (tenta outro código se por azar repetir)
        $pedidoId = null;
        $codigo   = '';
        for ($tentativa = 0; $tentativa < 5 && $pedidoId === null; $tentativa++) {
            $codigo = gerar_codigo_pedido();
            try {
                $pedidoId = db_inserir(
                    'INSERT INTO pedidos
                       (codigo, usuario_id, tipo_entrega, forma_pagamento, subtotal, desconto, frete, total,
                        prazo_dias, observacao, entrega_destinatario, entrega_cep, entrega_logradouro,
                        entrega_numero, entrega_complemento, entrega_bairro, entrega_cidade, entrega_uf)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $codigo, $u['id'], $v['tipo_entrega'], $v['forma_pagamento'],
                        para_decimal_sql($subtotal), para_decimal_sql($desconto), para_decimal_sql($frete), para_decimal_sql($total),
                        $prazo, $v['observacao'] ?? null,
                        $endereco['destinatario'] ?? null, $endereco['cep'] ?? null, $endereco['logradouro'] ?? null,
                        $endereco['numero'] ?? null, $endereco['complemento'] ?? null, $endereco['bairro'] ?? null,
                        $endereco['cidade'] ?? null, $endereco['uf'] ?? null,
                    ]
                );
            } catch (Throwable $e) {
                if (!db_erro_duplicado($e)) {
                    throw $e;
                }
            }
        }
        if ($pedidoId === null) {
            abortar(500, 'Não foi possível gerar o pedido. Tente novamente.');
        }

        foreach ($itens as $i) {
            db_executar(
                'INSERT INTO pedido_itens (pedido_id, produto_id, sku, nome, preco_unitario, quantidade, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$pedidoId, $i['id'], $i['sku'], $i['nome'], para_decimal_sql($i['unit']), $i['quantidade'], para_decimal_sql($i['unit'] * $i['quantidade'])]
            );
            $afetadas = db_executar(
                'UPDATE produtos SET estoque = estoque - ? WHERE id = ? AND estoque >= ?',
                [$i['quantidade'], $i['id'], $i['quantidade']]
            );
            if ($afetadas !== 1) {
                abortar(409, 'O estoque mudou durante a compra. Tente novamente.');
            }
            db_executar(
                "INSERT INTO estoque_movimentos (produto_id, tipo, quantidade, estoque_apos, motivo, pedido_id, usuario_id)
                 VALUES (?, 'pedido', ?, ?, ?, ?, ?)",
                [$i['id'], -$i['quantidade'], (int) $i['estoque'] - $i['quantidade'], "Pedido $codigo", $pedidoId, $u['id']]
            );
        }

        pedido_historico_registrar($pedidoId, 'aguardando_pagamento', 'Pedido criado.', (int) $u['id']);
        return $pedidoId;
    });

    $_SESSION['carrinho'] = [];   // compra feita: esvazia o carrinho
    responder(pedido_json(db_um('SELECT * FROM pedidos WHERE id = ?', [$pedidoId])), 201);
}

// ---------------------------------------------------------------------
// Consulta e cancelamento (cliente)
// ---------------------------------------------------------------------

function ctl_pedidos_listar(array $p): void
{
    $u = exigir_login();
    [$pagina, $porPagina, $offset] = paginacao(10, 50);

    $where  = 'WHERE pe.usuario_id = ?';
    $params = [$u['id']];
    if (($s = consulta('status')) !== null && isset(STATUS_ROTULOS[$s])) {
        $where .= ' AND pe.status = ?';
        $params[] = $s;
    }

    $total  = (int) db_valor("SELECT COUNT(*) FROM pedidos pe $where", $params);
    $linhas = db_todos(
        "SELECT pe.*, (SELECT COALESCE(SUM(quantidade), 0) FROM pedido_itens WHERE pedido_id = pe.id) AS qtd_itens
           FROM pedidos pe $where ORDER BY pe.id DESC LIMIT $porPagina OFFSET $offset",
        $params
    );
    responder_lista(array_map(fn($l) => pedido_json($l, false), $linhas), $total, $pagina, $porPagina);
}

/** Busca pelo código (SZ-XXXXXXXX) garantindo que o pedido é do usuário logado. */
function pedido_do_usuario(string $codigo, int $usuarioId): array
{
    $pe = db_um('SELECT * FROM pedidos WHERE codigo = ? AND usuario_id = ?', [strtoupper($codigo), $usuarioId]);
    if ($pe === null) {
        abortar(404, 'Pedido não encontrado.');
    }
    return $pe;
}

function ctl_pedidos_detalhe(array $p): void
{
    $u = exigir_login();
    responder(pedido_json(pedido_do_usuario($p['codigo'], (int) $u['id'])));
}

function ctl_pedidos_cancelar(array $p): void
{
    $u  = exigir_login();
    $pe = pedido_do_usuario($p['codigo'], (int) $u['id']);
    pedido_cancelar((int) $pe['id'], (int) $u['id'], 'Cancelado pelo cliente.', ['aguardando_pagamento']);
    responder(pedido_json(db_um('SELECT * FROM pedidos WHERE id = ?', [$pe['id']])));
}

/**
 * Cancela um pedido e DEVOLVE os produtos ao estoque.
 * $statusPermitidos limita de quais status o cancelamento é aceito
 * (o cliente só cancela enquanto aguarda pagamento).
 */
function pedido_cancelar(int $pedidoId, ?int $usuarioId, string $motivo, ?array $statusPermitidos = null): void
{
    db_transacao(function () use ($pedidoId, $usuarioId, $motivo, $statusPermitidos): void {
        $pe = db_um('SELECT id, codigo, status FROM pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
        if ($pe === null) {
            abortar(404, 'Pedido não encontrado.');
        }
        $permitidos = $statusPermitidos ?? array_keys(array_filter(
            TRANSICOES_PEDIDO,
            fn(array $destinos) => in_array('cancelado', $destinos, true)
        ));
        if (!in_array($pe['status'], $permitidos, true)) {
            abortar(409, 'Este pedido não pode ser cancelado (status atual: ' . (STATUS_ROTULOS[$pe['status']] ?? $pe['status']) . ').');
        }

        $itens = db_todos('SELECT produto_id, quantidade FROM pedido_itens WHERE pedido_id = ? ORDER BY produto_id', [$pedidoId]);
        foreach ($itens as $i) {
            $atual = (int) db_valor('SELECT estoque FROM produtos WHERE id = ? FOR UPDATE', [$i['produto_id']]);
            $novo  = $atual + (int) $i['quantidade'];
            db_executar('UPDATE produtos SET estoque = ? WHERE id = ?', [$novo, $i['produto_id']]);
            db_executar(
                "INSERT INTO estoque_movimentos (produto_id, tipo, quantidade, estoque_apos, motivo, pedido_id, usuario_id)
                 VALUES (?, 'cancelamento', ?, ?, ?, ?, ?)",
                [$i['produto_id'], (int) $i['quantidade'], $novo, 'Cancelamento do pedido ' . $pe['codigo'], $pedidoId, $usuarioId]
            );
        }

        db_executar("UPDATE pedidos SET status = 'cancelado' WHERE id = ?", [$pedidoId]);
        pedido_historico_registrar($pedidoId, 'cancelado', $motivo, $usuarioId);
    });
}

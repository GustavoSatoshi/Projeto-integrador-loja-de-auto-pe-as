<?php
declare(strict_types=1);

// =====================================================================
// PAINEL ADMIN - Pedidos, clientes e dashboard.
// =====================================================================

// ---------------------------------------------------------------------
// Pedidos
// ---------------------------------------------------------------------

function ctl_admin_pedidos_listar(array $p): void
{
    [$pagina, $porPagina, $offset] = paginacao(20, 100);

    $where  = [];
    $params = [];
    if (($s = consulta('status')) !== null) {
        if (!isset(STATUS_ROTULOS[$s])) {
            abortar(422, 'Status inválido.', ['status' => 'Use: ' . implode(', ', array_keys(STATUS_ROTULOS)) . '.']);
        }
        $where[] = 'pe.status = ?';
        $params[] = $s;
    }
    if (($busca = consulta('busca')) !== null) {
        $like = like_param($busca);
        $where[] = '(' . sql_like('pe.codigo') . ' OR ' . sql_like('u.nome') . ' OR ' . sql_like('u.email') . ')';
        array_push($params, $like, $like, $like);
    }
    foreach (['data_de' => '>=', 'data_ate' => '<'] as $chave => $op) {
        $d = consulta($chave);
        if ($d !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
                abortar(422, 'Data inválida.', [$chave => 'Use o formato AAAA-MM-DD.']);
            }
            // data_ate é "inclusiva": soma 1 dia e usa "<"
            $where[] = "pe.criado_em $op ?";
            $params[] = $chave === 'data_ate' ? date('Y-m-d', strtotime($d . ' +1 day')) : $d;
        }
    }
    $clausula = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total  = (int) db_valor("SELECT COUNT(*) FROM pedidos pe JOIN usuarios u ON u.id = pe.usuario_id $clausula", $params);
    $linhas = db_todos(
        "SELECT pe.*, u.nome AS cliente_nome, u.email AS cliente_email,
                (SELECT COALESCE(SUM(quantidade), 0) FROM pedido_itens WHERE pedido_id = pe.id) AS qtd_itens
           FROM pedidos pe JOIN usuarios u ON u.id = pe.usuario_id
           $clausula ORDER BY pe.id DESC LIMIT $porPagina OFFSET $offset",
        $params
    );
    responder_lista(array_map(function (array $l): array {
        $j = pedido_json($l, false);
        $j['cliente'] = ['nome' => $l['cliente_nome'], 'email' => $l['cliente_email']];
        return $j;
    }, $linhas), $total, $pagina, $porPagina);
}

function pedido_admin_json(int $id): array
{
    $pe = db_um('SELECT * FROM pedidos WHERE id = ?', [$id]);
    if ($pe === null) {
        abortar(404, 'Pedido não encontrado.');
    }
    $json = pedido_json($pe);
    $json['cliente'] = usuario_publico(db_um('SELECT * FROM usuarios WHERE id = ?', [$pe['usuario_id']]));
    $json['proximos_status'] = TRANSICOES_PEDIDO[$pe['status']] ?? [];
    // Só oferece o destino que faz sentido para o tipo de entrega
    $json['proximos_status'] = array_values(array_filter(
        $json['proximos_status'],
        fn(string $s) => !($s === 'enviado' && $pe['tipo_entrega'] !== 'entrega')
                      && !($s === 'pronto_retirada' && $pe['tipo_entrega'] !== 'retirada')
    ));
    return $json;
}

function ctl_admin_pedidos_ver(array $p): void
{
    responder(pedido_admin_json((int) $p['id']));
}

function ctl_admin_pedidos_status(array $p): void
{
    $admin = exigir_admin();
    $id = (int) $p['id'];
    $v = validar(entrada(), [
        'status'          => 'obrigatorio|enum:' . implode(',', array_keys(STATUS_ROTULOS)),
        'codigo_rastreio' => 'texto:3:40',
        'observacao'      => 'texto:1:255',
    ]);

    if ($v['status'] === 'cancelado') {
        // Cancelar devolve os itens ao estoque (lógica compartilhada com o cliente)
        pedido_cancelar($id, (int) $admin['id'], $v['observacao'] ?? 'Cancelado pela loja.');
        responder(pedido_admin_json($id));
    }

    db_transacao(function () use ($id, $v, $admin): void {
        $pe = db_um('SELECT id, status, tipo_entrega FROM pedidos WHERE id = ? FOR UPDATE', [$id]);
        if ($pe === null) {
            abortar(404, 'Pedido não encontrado.');
        }
        $permitidos = TRANSICOES_PEDIDO[$pe['status']] ?? [];
        if (!in_array($v['status'], $permitidos, true)) {
            abortar(409, 'Não é possível ir de "' . $pe['status'] . '" para "' . $v['status'] . '".', [
                'status_atual'      => $pe['status'],
                'proximos_possiveis' => $permitidos,
            ]);
        }
        if ($v['status'] === 'enviado' && $pe['tipo_entrega'] !== 'entrega') {
            abortar(422, 'Pedidos para retirada não são "enviados". Use "pronto_retirada".');
        }
        if ($v['status'] === 'pronto_retirada' && $pe['tipo_entrega'] !== 'retirada') {
            abortar(422, 'Este pedido é para entrega. Use "enviado".');
        }

        $campos = ['status' => $v['status']];
        if ($v['status'] === 'pago') {
            $campos['pago_em'] = date('Y-m-d H:i:s');
        }
        if ($v['status'] === 'enviado' && isset($v['codigo_rastreio'])) {
            $campos['codigo_rastreio'] = $v['codigo_rastreio'];
        }
        db_atualizar('pedidos', $campos, 'id = ?', [$id]);
        pedido_historico_registrar($id, $v['status'], $v['observacao'] ?? null, (int) $admin['id']);
    });

    responder(pedido_admin_json($id));
}

// ---------------------------------------------------------------------
// Clientes
// ---------------------------------------------------------------------

function ctl_admin_usuarios_listar(array $p): void
{
    [$pagina, $porPagina, $offset] = paginacao(20, 100);
    $where  = [];
    $params = [];

    if (($busca = consulta('busca')) !== null) {
        $like = like_param($busca);
        $where[] = '(' . sql_like('nome') . ' OR ' . sql_like('email') . ' OR ' . sql_like('empresa') . ' OR ' . sql_like('documento') . ')';
        array_push($params, $like, $like, $like, $like);
    }
    if (($perfil = consulta('perfil')) !== null && in_array($perfil, ['cliente', 'admin'], true)) {
        $where[] = 'perfil = ?';
        $params[] = $perfil;
    }
    if (($tipo = consulta('tipo_cliente')) !== null && in_array($tipo, ['pf', 'oficina'], true)) {
        $where[] = 'tipo_cliente = ?';
        $params[] = $tipo;
    }
    if (($ativo = consulta('ativo')) !== null) {
        $where[] = 'ativo = ?';
        $params[] = filter_var($ativo, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (flag_consulta('oficina_pendente')) {   // oficinas aguardando aprovação
        $where[] = "tipo_cliente = 'oficina' AND oficina_aprovada = 0";
    }
    $clausula = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total  = (int) db_valor("SELECT COUNT(*) FROM usuarios $clausula", $params);
    $linhas = db_todos(
        "SELECT *, (SELECT COUNT(*) FROM pedidos WHERE usuario_id = usuarios.id) AS total_pedidos
           FROM usuarios $clausula ORDER BY id DESC LIMIT $porPagina OFFSET $offset",
        $params
    );
    responder_lista(array_map(function (array $u): array {
        return usuario_publico($u) + ['ativo' => (bool) $u['ativo'], 'total_pedidos' => (int) $u['total_pedidos']];
    }, $linhas), $total, $pagina, $porPagina);
}

function ctl_admin_usuarios_ver(array $p): void
{
    $u = db_um('SELECT * FROM usuarios WHERE id = ?', [(int) $p['id']]);
    if ($u === null) {
        abortar(404, 'Usuário não encontrado.');
    }
    $resumo = db_um(
        'SELECT COUNT(*) AS pedidos, COALESCE(SUM(CASE WHEN status IN (' . db_marcadores(STATUS_FATURADOS) . ') THEN total END), 0) AS gasto
           FROM pedidos WHERE usuario_id = ?',
        [...STATUS_FATURADOS, $u['id']]
    );
    responder(usuario_publico($u) + [
        'ativo'        => (bool) $u['ativo'],
        'ultimo_login' => $u['ultimo_login'],
        'total_pedidos'=> (int) $resumo['pedidos'],
        'total_gasto'  => (float) $resumo['gasto'],
    ]);
}

function ctl_admin_usuarios_atualizar(array $p): void
{
    $admin = exigir_admin();
    $id = (int) $p['id'];
    $u = db_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    if ($u === null) {
        abortar(404, 'Usuário não encontrado.');
    }
    $v = validar(entrada(), [
        'ativo'            => 'bool',
        'perfil'           => 'enum:cliente,admin',
        'oficina_aprovada' => 'bool',
    ]);

    // Evita o admin se trancar para fora do próprio sistema
    if ($id === (int) $admin['id'] && (array_key_exists('ativo', $v) || array_key_exists('perfil', $v))) {
        if (($v['ativo'] ?? true) === false || ($v['perfil'] ?? 'admin') !== 'admin') {
            abortar(422, 'Você não pode desativar nem rebaixar a sua própria conta.');
        }
    }
    if (!empty($v['oficina_aprovada']) && $u['tipo_cliente'] !== 'oficina') {
        abortar(422, 'Dados inválidos.', ['oficina_aprovada' => 'Só contas do tipo "oficina" podem ser aprovadas.']);
    }

    db_atualizar('usuarios', $v, 'id = ?', [$id]);
    $novo = db_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    responder(usuario_publico($novo) + ['ativo' => (bool) $novo['ativo']]);
}

// ---------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------

function ctl_admin_dashboard(array $p): void
{
    $faturados = db_marcadores(STATUS_FATURADOS);

    $mes = db_um(
        "SELECT COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS faturamento
           FROM pedidos
          WHERE status IN ($faturados) AND pago_em >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
        STATUS_FATURADOS
    );
    $faturamentoMes = (float) $mes['faturamento'];
    $pedidosMes     = (int) $mes['pedidos'];

    $porStatus = array_fill_keys(array_keys(STATUS_ROTULOS), 0);
    foreach (db_todos('SELECT status, COUNT(*) AS qtd FROM pedidos GROUP BY status') as $l) {
        $porStatus[$l['status']] = (int) $l['qtd'];
    }

    // Vendas dos últimos 7 dias (preenche os dias sem venda com zero para o gráfico)
    $vendas = [];
    foreach (db_todos(
        "SELECT DATE(pago_em) AS dia, COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS valor
           FROM pedidos
          WHERE status IN ($faturados) AND pago_em >= (CURDATE() - INTERVAL 6 DAY)
          GROUP BY DATE(pago_em)",
        STATUS_FATURADOS
    ) as $l) {
        $vendas[$l['dia']] = $l;
    }
    $serie = [];
    for ($i = 6; $i >= 0; $i--) {
        $dia = date('Y-m-d', strtotime("-$i day"));
        $serie[] = [
            'dia'     => $dia,
            'pedidos' => (int) ($vendas[$dia]['pedidos'] ?? 0),
            'valor'   => (float) ($vendas[$dia]['valor'] ?? 0),
        ];
    }

    $estoqueBaixo = db_todos(
        'SELECT id, sku, nome, estoque, estoque_minimo FROM produtos
          WHERE ativo = 1 AND estoque <= estoque_minimo ORDER BY estoque ASC, nome LIMIT 10'
    );
    $maisVendidos = db_todos(
        "SELECT pi.produto_id, pi.sku, pi.nome, SUM(pi.quantidade) AS quantidade, SUM(pi.subtotal) AS receita
           FROM pedido_itens pi JOIN pedidos pe ON pe.id = pi.pedido_id
          WHERE pe.status <> 'cancelado' AND pe.criado_em >= (NOW() - INTERVAL 30 DAY)
          GROUP BY pi.produto_id, pi.sku, pi.nome
          ORDER BY quantidade DESC LIMIT 5"
    );

    responder([
        'faturamento_mes'      => $faturamentoMes,
        'pedidos_pagos_mes'    => $pedidosMes,
        'ticket_medio_mes'     => $pedidosMes > 0 ? round($faturamentoMes / $pedidosMes, 2) : 0.0,
        'pedidos_hoje'         => (int) db_valor("SELECT COUNT(*) FROM pedidos WHERE criado_em >= CURDATE()"),
        'pedidos_por_status'   => $porStatus,
        'produtos_ativos'      => (int) db_valor('SELECT COUNT(*) FROM produtos WHERE ativo = 1'),
        'produtos_sem_estoque' => (int) db_valor('SELECT COUNT(*) FROM produtos WHERE ativo = 1 AND estoque = 0'),
        'clientes_total'       => (int) db_valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'cliente'"),
        'clientes_novos_30d'   => (int) db_valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'cliente' AND criado_em >= (NOW() - INTERVAL 30 DAY)"),
        'oficinas_pendentes'   => (int) db_valor("SELECT COUNT(*) FROM usuarios WHERE tipo_cliente = 'oficina' AND oficina_aprovada = 0"),
        'vendas_ultimos_7_dias'=> $serie,
        'estoque_baixo'        => array_map(fn($l) => [
            'id' => (int) $l['id'], 'sku' => $l['sku'], 'nome' => $l['nome'],
            'estoque' => (int) $l['estoque'], 'estoque_minimo' => (int) $l['estoque_minimo'],
        ], $estoqueBaixo),
        'mais_vendidos_30d'    => array_map(fn($l) => [
            'produto_id' => (int) $l['produto_id'], 'sku' => $l['sku'], 'nome' => $l['nome'],
            'quantidade' => (int) $l['quantidade'], 'receita' => (float) $l['receita'],
        ], $maisVendidos),
    ]);
}

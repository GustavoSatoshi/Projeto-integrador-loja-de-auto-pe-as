<?php
declare(strict_types=1);

// =====================================================================
// Carrinho e frete.
// O carrinho fica na SESSÃO do PHP ($_SESSION['carrinho'] = [id => qtd]),
// então funciona mesmo para quem ainda não fez login.
// Preços e estoque são SEMPRE lidos do banco: o cliente só informa
// "produto" e "quantidade", nunca o preço.
// =====================================================================

const CARRINHO_MAX_POR_ITEM = 99;

/** Percentual de desconto do cliente (só oficinas aprovadas pelo admin). */
function desconto_oficina_percentual(?array $usuario): int
{
    if ($usuario && $usuario['tipo_cliente'] === 'oficina' && (int) $usuario['oficina_aprovada'] === 1) {
        return max(0, min(50, (int) env('OFICINA_DESCONTO_PERCENTUAL', '5')));
    }
    return 0;
}

function carrinho_ids(): array
{
    iniciar_sessao();
    return $_SESSION['carrinho'] ?? [];
}

/**
 * Lê os produtos do carrinho no banco e calcula tudo em centavos.
 * Itens com problema (inativo, sem estoque suficiente) aparecem com
 * "problema" preenchido e NÃO entram nos totais.
 */
function carrinho_calcular(): array
{
    $carrinho = carrinho_ids();
    $itens    = [];
    $linhasOk = [];
    $subtotal = 0;
    $qtdTotal = 0;
    $temProblema = false;

    if ($carrinho) {
        $ids = array_map('intval', array_keys($carrinho));
        $linhas = [];
        foreach (db_todos(
            'SELECT p.id, p.sku, p.nome, p.slug, p.preco, p.preco_promocional, p.estoque, p.ativo,
                    p.peso_kg, p.comprimento_cm, p.largura_cm, p.altura_cm, c.ativo AS categoria_ativa
               FROM produtos p JOIN categorias c ON c.id = p.categoria_id
              WHERE p.id IN (' . db_marcadores($ids) . ')',
            $ids
        ) as $l) {
            $linhas[(int) $l['id']] = $l;
        }

        foreach ($carrinho as $produtoId => $qtd) {
            $produtoId = (int) $produtoId;
            $qtd = (int) $qtd;
            $l = $linhas[$produtoId] ?? null;
            $problema = null;

            if ($l === null || (int) $l['ativo'] !== 1 || (int) $l['categoria_ativa'] !== 1) {
                $problema = 'Produto indisponível.';
            } elseif ((int) $l['estoque'] < $qtd) {
                $problema = (int) $l['estoque'] > 0
                    ? 'Estoque insuficiente (restam ' . (int) $l['estoque'] . ').'
                    : 'Produto sem estoque.';
            }

            if ($l === null) {
                $itens[] = ['produto_id' => $produtoId, 'nome' => 'Produto removido', 'quantidade' => $qtd, 'problema' => $problema];
                $temProblema = true;
                continue;
            }

            $unit = preco_final_centavos($l);
            $item = [
                'produto_id'     => $produtoId,
                'sku'            => $l['sku'],
                'nome'           => $l['nome'],
                'slug'           => $l['slug'],
                'preco_unitario' => de_centavos($unit),
                'quantidade'     => $qtd,
                'subtotal'       => de_centavos($unit * $qtd),
                'estoque'        => (int) $l['estoque'],
                'problema'       => $problema,
            ];
            $itens[] = $item;

            if ($problema === null) {
                $subtotal += $unit * $qtd;
                $qtdTotal += $qtd;
                $linhasOk[] = $l + ['quantidade' => $qtd, 'preco_unit_centavos' => $unit];
            } else {
                $temProblema = true;
            }
        }
    }

    $pct      = desconto_oficina_percentual(usuario_logado());
    $desconto = (int) round($subtotal * $pct / 100);
    $r        = regras_frete();

    return [
        'itens'                   => $itens,
        'linhas_ok'               => $linhasOk,           // uso interno (checkout / frete)
        'quantidade_total'        => $qtdTotal,
        'subtotal_centavos'       => $subtotal,
        'desconto_centavos'       => $desconto,
        'desconto_percentual'     => $pct,
        'total_produtos_centavos' => $subtotal - $desconto,
        'tem_problema'            => $temProblema,
        'frete_gratis_acima'      => $r['gratis_acima'],
    ];
}

function carrinho_json(): array
{
    $c = carrinho_calcular();
    $totalProdutos = $c['total_produtos_centavos'];
    return [
        'itens'                   => $c['itens'],
        'quantidade_total'        => $c['quantidade_total'],
        'subtotal'                => de_centavos($c['subtotal_centavos']),
        'desconto_oficina'        => de_centavos($c['desconto_centavos']),
        'desconto_oficina_percentual' => $c['desconto_percentual'],
        'total_produtos'          => de_centavos($totalProdutos),
        'frete_gratis_acima'      => de_centavos($c['frete_gratis_acima']),
        'falta_para_frete_gratis' => de_centavos(max(0, $c['frete_gratis_acima'] - $totalProdutos)),
        'pode_finalizar'          => $c['quantidade_total'] > 0 && !$c['tem_problema'],
    ];
}

function ctl_carrinho_ver(array $p): void
{
    responder(carrinho_json());
}

function ctl_carrinho_adicionar(array $p): void
{
    $v = validar(entrada(), [
        'produto_id' => 'obrigatorio|inteiro:1',
        'quantidade' => 'inteiro:1:' . CARRINHO_MAX_POR_ITEM,
    ]);
    $id  = (int) $v['produto_id'];
    $qtd = (int) ($v['quantidade'] ?? 1);

    $produto = db_um(
        'SELECT p.id, p.estoque FROM produtos p JOIN categorias c ON c.id = p.categoria_id
          WHERE p.id = ? AND p.ativo = 1 AND c.ativo = 1',
        [$id]
    );
    if ($produto === null) {
        abortar(404, 'Produto não encontrado ou indisponível.');
    }

    iniciar_sessao();
    $nova = ((int) ($_SESSION['carrinho'][$id] ?? 0)) + $qtd;
    if ($nova > CARRINHO_MAX_POR_ITEM) {
        abortar(422, 'Quantidade máxima por item: ' . CARRINHO_MAX_POR_ITEM . '.', ['quantidade' => 'Limite excedido.']);
    }
    if ($nova > (int) $produto['estoque']) {
        abortar(422, 'Estoque insuficiente. Disponível: ' . (int) $produto['estoque'] . '.', ['quantidade' => 'Estoque insuficiente.']);
    }
    $_SESSION['carrinho'][$id] = $nova;

    responder(carrinho_json(), 201);
}

function ctl_carrinho_atualizar(array $p): void
{
    $id = (int) $p['produto_id'];
    $v  = validar(entrada(), ['quantidade' => 'obrigatorio|inteiro:0:' . CARRINHO_MAX_POR_ITEM]);

    iniciar_sessao();
    if (!isset($_SESSION['carrinho'][$id])) {
        abortar(404, 'Este produto não está no carrinho.');
    }
    $qtd = (int) $v['quantidade'];
    if ($qtd === 0) {
        unset($_SESSION['carrinho'][$id]);
        responder(carrinho_json());
    }

    $estoque = db_valor('SELECT estoque FROM produtos WHERE id = ? AND ativo = 1', [$id]);
    if ($estoque === null) {
        unset($_SESSION['carrinho'][$id]);
        abortar(404, 'Produto indisponível. Ele foi removido do carrinho.');
    }
    if ($qtd > (int) $estoque) {
        abortar(422, 'Estoque insuficiente. Disponível: ' . (int) $estoque . '.', ['quantidade' => 'Estoque insuficiente.']);
    }
    $_SESSION['carrinho'][$id] = $qtd;
    responder(carrinho_json());
}

function ctl_carrinho_remover(array $p): void
{
    iniciar_sessao();
    unset($_SESSION['carrinho'][(int) $p['produto_id']]);
    responder(carrinho_json());
}

function ctl_carrinho_limpar(array $p): void
{
    iniciar_sessao();
    $_SESSION['carrinho'] = [];
    responder(carrinho_json());
}

// ---------------------------------------------------------------------
// Frete (simulação por região). Para usar os Correios ou o Melhor Envio,
// basta trocar o miolo de frete_calcular().
// ---------------------------------------------------------------------

/** Peso que vale para cobrança: o maior entre o peso real e o peso "cubado" (volume / 6000). */
function peso_tarifavel_kg(array $linhas): float
{
    $total = 0.0;
    foreach ($linhas as $l) {
        $real = (float) $l['peso_kg'];
        $c = (float) $l['comprimento_cm'];
        $la = (float) $l['largura_cm'];
        $a = (float) $l['altura_cm'];
        $cubado = ($c > 0 && $la > 0 && $a > 0) ? ($c * $la * $a) / 6000 : 0.0;
        $total += max($real, $cubado) * (int) $l['quantidade'];
    }
    return max(0.3, $total);
}

/**
 * Calcula o frete de entrega para um CEP.
 * $subtotalCentavos = valor dos produtos já com desconto (para o frete grátis).
 */
function frete_calcular(array $linhas, string $cep, int $subtotalCentavos): array
{
    $uf = uf_por_cep($cep);
    if ($uf === null) {
        abortar(422, 'CEP inválido ou inexistente.', ['cep' => 'CEP inválido.']);
    }
    $r    = regras_frete();
    $zona = $uf === $r['origem_uf'] ? 'mesma_uf' : (in_array($uf, $r['sul_sudeste'], true) ? 'sul_sudeste' : 'demais');
    $z    = $r['zonas'][$zona];
    $peso = peso_tarifavel_kg($linhas);

    $valor  = $z['base'] + $z['por_kg'] * (int) ceil($peso);
    $gratis = $subtotalCentavos >= $r['gratis_acima'];

    return [
        'uf'                => $uf,
        'zona'              => $zona,
        'peso_tarifavel_kg' => round($peso, 3),
        'valor_centavos'    => $gratis ? 0 : $valor,
        'valor_cheio_centavos' => $valor,
        'gratis'            => $gratis,
        'prazo_dias'        => $z['prazo'],
    ];
}

/** POST /api/frete/calcular  {cep, produto_id?, quantidade?} */
function ctl_frete_calcular(array $p): void
{
    $v = validar(entrada(), [
        'cep'        => 'obrigatorio|cep',
        'produto_id' => 'inteiro:1',
        'quantidade' => 'inteiro:1:' . CARRINHO_MAX_POR_ITEM,
    ]);

    if (isset($v['produto_id'])) {
        // Simulação na página do produto (sem usar o carrinho)
        $l = db_um(
            'SELECT p.id, p.preco, p.preco_promocional, p.peso_kg, p.comprimento_cm, p.largura_cm, p.altura_cm
               FROM produtos p JOIN categorias c ON c.id = p.categoria_id
              WHERE p.id = ? AND p.ativo = 1 AND c.ativo = 1',
            [(int) $v['produto_id']]
        );
        if ($l === null) {
            abortar(404, 'Produto não encontrado.');
        }
        $qtd = (int) ($v['quantidade'] ?? 1);
        $linhas = [$l + ['quantidade' => $qtd]];
        $subtotal = preco_final_centavos($l) * $qtd;
    } else {
        $c = carrinho_calcular();
        if ($c['quantidade_total'] === 0) {
            abortar(422, 'Seu carrinho está vazio.');
        }
        $linhas = $c['linhas_ok'];
        $subtotal = $c['total_produtos_centavos'];
    }

    $f = frete_calcular($linhas, $v['cep'], $subtotal);
    responder([
        'cep' => $v['cep'],
        'uf'  => $f['uf'],
        'opcoes' => [
            [
                'tipo'       => 'entrega',
                'nome'       => 'Entrega SpeedZone',
                'valor'      => de_centavos($f['valor_centavos']),
                'valor_cheio'=> de_centavos($f['valor_cheio_centavos']),
                'gratis'     => $f['gratis'],
                'prazo_dias' => $f['prazo_dias'],
            ],
            [
                'tipo'       => 'retirada',
                'nome'       => 'Retirada na loja',
                'valor'      => 0.0,
                'valor_cheio'=> 0.0,
                'gratis'     => true,
                'prazo_dias' => 0,
                'endereco'   => regras_frete()['loja_endereco'],
            ],
        ],
    ]);
}

<?php
declare(strict_types=1);

// =====================================================================
// Catálogo público: produtos, categorias, marcas e veículos.
// Evolução do listar_livro.php (SELECT + while), agora com filtros,
// busca, ordenação e paginação.
// =====================================================================

/** Preço que vale hoje (promocional se existir e for menor que o normal). */
const SQL_PRECO_FINAL = 'CASE WHEN p.preco_promocional IS NOT NULL AND p.preco_promocional > 0 AND p.preco_promocional < p.preco THEN p.preco_promocional ELSE p.preco END';

/** SELECT base de produtos (usado também pelo painel admin). */
function produtos_sql_base(): string
{
    return "SELECT p.id, p.sku, p.nome, p.slug, p.codigo_fabricante, p.preco, p.preco_promocional,
                   p.estoque, p.estoque_minimo, p.destaque, p.universal, p.ativo, p.criado_em,
                   c.id AS categoria_id, c.nome AS categoria_nome, c.slug AS categoria_slug,
                   m.id AS marca_id, m.nome AS marca_nome, m.slug AS marca_slug,
                   (SELECT i.arquivo FROM produto_imagens i
                     WHERE i.produto_id = p.id
                     ORDER BY i.principal DESC, i.ordem, i.id LIMIT 1) AS imagem
              FROM produtos p
              JOIN categorias c ON c.id = p.categoria_id
              LEFT JOIN marcas m ON m.id = p.marca_id";
}

const SQL_PRODUTOS_JOINS = 'FROM produtos p JOIN categorias c ON c.id = p.categoria_id LEFT JOIN marcas m ON m.id = p.marca_id';

/** Transforma uma linha do banco no JSON que o front-end recebe. */
function produto_resumo(array $p): array
{
    $normal = para_centavos($p['preco']);
    $final  = preco_final_centavos($p);
    $promo  = $final < $normal ? $final : null;

    return [
        'id'                 => (int) $p['id'],
        'sku'                => $p['sku'],
        'nome'               => $p['nome'],
        'slug'               => $p['slug'],
        'codigo_fabricante'  => $p['codigo_fabricante'],
        'preco'              => de_centavos($normal),
        'preco_promocional'  => $promo !== null ? de_centavos($promo) : null,
        'preco_final'        => de_centavos($final),
        'em_promocao'        => $promo !== null,
        'desconto_percentual'=> $promo !== null ? (int) round((1 - $promo / $normal) * 100) : 0,
        'estoque'            => (int) $p['estoque'],
        'em_estoque'         => (int) $p['estoque'] > 0,
        'ultimas_unidades'   => (int) $p['estoque'] > 0 && (int) $p['estoque'] <= 3,
        'destaque'           => (bool) $p['destaque'],
        'universal'          => (bool) $p['universal'],
        'ativo'              => (bool) $p['ativo'],
        'categoria'          => ['id' => (int) $p['categoria_id'], 'nome' => $p['categoria_nome'], 'slug' => $p['categoria_slug']],
        'marca'              => $p['marca_id'] !== null
                                ? ['id' => (int) $p['marca_id'], 'nome' => $p['marca_nome'], 'slug' => $p['marca_slug']]
                                : null,
        'imagem'             => url_imagem($p['imagem']),
    ];
}

function flag_consulta(string $chave): bool
{
    $v = consulta($chave);
    return $v !== null && filter_var($v, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Monta o WHERE a partir dos filtros da URL.
 * Devolve [texto_do_where, parametros].
 */
function produtos_filtros(bool $admin): array
{
    $where  = [];
    $params = [];

    if (!$admin) {
        $where[] = 'p.ativo = 1';
        $where[] = 'c.ativo = 1';
    } elseif (($ativo = consulta('ativo')) !== null) {
        $where[] = 'p.ativo = ?';
        $params[] = filter_var($ativo, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    // Busca por palavras: cada palavra precisa aparecer no nome, SKU ou código do fabricante
    if (($busca = consulta('busca')) !== null) {
        foreach (array_slice(preg_split('/\s+/', $busca) ?: [], 0, 6) as $palavra) {
            $like = like_param($palavra);
            $where[] = '(' . sql_like('p.nome') . ' OR ' . sql_like('p.sku') . ' OR ' . sql_like('p.codigo_fabricante') . ')';
            array_push($params, $like, $like, $like);
        }
    }

    // Categoria (por id ou slug). Categoria principal inclui as subcategorias.
    if (($cat = consulta('categoria')) !== null) {
        $catId = ctype_digit($cat) ? (int) $cat : db_valor('SELECT id FROM categorias WHERE slug = ?', [$cat]);
        if ($catId === null) {
            $where[] = '1 = 0';
        } else {
            $where[] = '(c.id = ? OR c.parent_id = ?)';
            array_push($params, (int) $catId, (int) $catId);
        }
    }

    // Marca (por id ou slug)
    if (($marca = consulta('marca')) !== null) {
        $marcaId = ctype_digit($marca) ? (int) $marca : db_valor('SELECT id FROM marcas WHERE slug = ?', [$marca]);
        if ($marcaId === null) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'p.marca_id = ?';
            $params[] = (int) $marcaId;
        }
    }

    // Faixa de preço (sobre o preço final, já considerando promoção)
    foreach (['preco_min' => '>=', 'preco_max' => '<='] as $chave => $op) {
        $v = consulta($chave);
        if ($v !== null && is_numeric(str_replace(',', '.', $v))) {
            $where[] = SQL_PRECO_FINAL . " $op ?";
            $params[] = (float) str_replace(',', '.', $v);
        }
    }

    // "Serve no meu carro?": peças do veículo + peças universais
    if (($vid = consulta('veiculo_id')) !== null && ctype_digit($vid)) {
        $where[] = '(p.universal = 1 OR EXISTS (SELECT 1 FROM produto_veiculo pv WHERE pv.produto_id = p.id AND pv.veiculo_id = ?))';
        $params[] = (int) $vid;
    }

    if (flag_consulta('em_estoque')) {
        $where[] = 'p.estoque > 0';
    }
    if (flag_consulta('destaque')) {
        $where[] = 'p.destaque = 1';
    }
    if ($admin && flag_consulta('estoque_baixo')) {
        $where[] = 'p.estoque <= p.estoque_minimo';
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function produtos_ordenacao(string $padrao): string
{
    $ordens = [
        'relevancia'  => 'p.destaque DESC, p.nome ASC',
        'menor_preco' => SQL_PRECO_FINAL . ' ASC, p.nome ASC',
        'maior_preco' => SQL_PRECO_FINAL . ' DESC, p.nome ASC',
        'nome'        => 'p.nome ASC',
        'recentes'    => 'p.id DESC',
        'estoque'     => 'p.estoque ASC, p.nome ASC',
    ];
    return $ordens[consulta('ordem', $padrao)] ?? $ordens[$padrao];
}

function ctl_produtos_listar(array $p): void
{
    [$pagina, $porPagina, $offset] = paginacao(12, 48);
    [$where, $params] = produtos_filtros(false);
    $ordem = produtos_ordenacao('relevancia');

    $total  = (int) db_valor("SELECT COUNT(*) " . SQL_PRODUTOS_JOINS . " $where", $params);
    $linhas = db_todos(produtos_sql_base() . " $where ORDER BY $ordem LIMIT $porPagina OFFSET $offset", $params);

    responder_lista(array_map('produto_resumo', $linhas), $total, $pagina, $porPagina);
}

/** Dados completos de um produto (para a página do produto e para o admin). */
function produto_detalhe(array $linha): array
{
    $id = (int) $linha['id'];
    $detalhe = produto_resumo($linha);

    $extra = db_um(
        'SELECT descricao, peso_kg, comprimento_cm, largura_cm, altura_cm, estoque_minimo, especificacoes
           FROM produtos WHERE id = ?',
        [$id]
    );
    $specs = $extra['especificacoes'] !== null ? json_decode($extra['especificacoes'], true) : null;

    $detalhe['descricao']        = $extra['descricao'];
    $detalhe['especificacoes']   = is_array($specs) ? $specs : (object) [];
    $detalhe['peso_kg']          = (float) $extra['peso_kg'];
    $detalhe['dimensoes_cm']     = [
        'comprimento' => (float) $extra['comprimento_cm'],
        'largura'     => (float) $extra['largura_cm'],
        'altura'      => (float) $extra['altura_cm'],
    ];
    $detalhe['estoque_minimo']   = (int) $extra['estoque_minimo'];

    $detalhe['imagens'] = array_map(
        fn(array $i) => ['id' => (int) $i['id'], 'url' => url_imagem($i['arquivo']), 'principal' => (bool) $i['principal']],
        db_todos('SELECT id, arquivo, principal FROM produto_imagens WHERE produto_id = ? ORDER BY principal DESC, ordem, id', [$id])
    );

    $detalhe['veiculos_compativeis'] = array_map('veiculo_json', db_todos(
        'SELECT v.* FROM veiculos v JOIN produto_veiculo pv ON pv.veiculo_id = v.id
          WHERE pv.produto_id = ? ORDER BY v.montadora, v.modelo, v.ano_inicio',
        [$id]
    ));

    return $detalhe;
}

function ctl_produtos_detalhe(array $p): void
{
    $ref = $p['ref'];
    $coluna = ctype_digit($ref) ? 'p.id' : 'p.slug';
    $linha = db_um(produtos_sql_base() . " WHERE $coluna = ? AND p.ativo = 1 AND c.ativo = 1", [ctype_digit($ref) ? (int) $ref : $ref]);
    if ($linha === null) {
        abortar(404, 'Produto não encontrado.');
    }

    $detalhe = produto_detalhe($linha);

    // Produtos relacionados: mesma categoria, em estoque
    $relacionados = db_todos(
        produtos_sql_base() . ' WHERE p.ativo = 1 AND c.ativo = 1 AND p.categoria_id = ? AND p.id <> ? AND p.estoque > 0
          ORDER BY p.destaque DESC, p.id DESC LIMIT 4',
        [(int) $linha['categoria_id'], (int) $linha['id']]
    );
    $detalhe['relacionados'] = array_map('produto_resumo', $relacionados);

    responder($detalhe);
}

// ---------------------------------------------------------------------
// Categorias e marcas
// ---------------------------------------------------------------------

function ctl_categorias_listar(array $p): void
{
    $linhas = db_todos('SELECT id, parent_id, nome, slug, descricao FROM categorias WHERE ativo = 1 ORDER BY ordem, nome');
    $arvore = [];
    foreach ($linhas as $c) {
        if ($c['parent_id'] === null) {
            $arvore[(int) $c['id']] = ['id' => (int) $c['id'], 'nome' => $c['nome'], 'slug' => $c['slug'], 'descricao' => $c['descricao'], 'filhas' => []];
        }
    }
    foreach ($linhas as $c) {
        if ($c['parent_id'] !== null && isset($arvore[(int) $c['parent_id']])) {
            $arvore[(int) $c['parent_id']]['filhas'][] = ['id' => (int) $c['id'], 'nome' => $c['nome'], 'slug' => $c['slug'], 'descricao' => $c['descricao']];
        }
    }
    responder(array_values($arvore));
}

function ctl_marcas_listar(array $p): void
{
    responder(array_map(
        fn(array $m) => ['id' => (int) $m['id'], 'nome' => $m['nome'], 'slug' => $m['slug']],
        db_todos('SELECT id, nome, slug FROM marcas WHERE ativo = 1 ORDER BY nome')
    ));
}

// ---------------------------------------------------------------------
// Veículos ("Qual é o seu carro?")
// ---------------------------------------------------------------------

function veiculo_json(array $v): array
{
    return [
        'id'         => (int) $v['id'],
        'montadora'  => $v['montadora'],
        'modelo'     => $v['modelo'],
        'ano_inicio' => (int) $v['ano_inicio'],
        'ano_fim'    => $v['ano_fim'] !== null ? (int) $v['ano_fim'] : null,
        'motor'      => $v['motor'],
    ];
}

function ctl_veiculos_montadoras(array $p): void
{
    responder(array_column(db_todos('SELECT DISTINCT montadora FROM veiculos ORDER BY montadora'), 'montadora'));
}

function ctl_veiculos_modelos(array $p): void
{
    $montadora = consulta('montadora');
    if ($montadora === null) {
        abortar(422, 'Informe a montadora.', ['montadora' => 'Campo obrigatório.']);
    }
    responder(array_column(
        db_todos('SELECT DISTINCT modelo FROM veiculos WHERE montadora = ? ORDER BY modelo', [$montadora]),
        'modelo'
    ));
}

function ctl_veiculos_listar(array $p): void
{
    $where = [];
    $params = [];
    if (($m = consulta('montadora')) !== null) {
        $where[] = 'montadora = ?';
        $params[] = $m;
    }
    if (($m = consulta('modelo')) !== null) {
        $where[] = 'modelo = ?';
        $params[] = $m;
    }
    if (($ano = consulta('ano')) !== null && ctype_digit($ano)) {
        $where[] = 'ano_inicio <= ? AND COALESCE(ano_fim, 9999) >= ?';
        array_push($params, (int) $ano, (int) $ano);
    }
    $sql = 'SELECT * FROM veiculos' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY montadora, modelo, ano_inicio LIMIT 200';
    responder(array_map('veiculo_json', db_todos($sql, $params)));
}

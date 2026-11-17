<?php
declare(strict_types=1);

// =====================================================================
// PAINEL ADMIN - Categorias, marcas e veículos.
// =====================================================================

// ---------------------------------------------------------------------
// Categorias (2 níveis: principal > subcategoria)
// ---------------------------------------------------------------------

function categoria_admin_json(array $c): array
{
    return [
        'id'             => (int) $c['id'],
        'parent_id'      => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
        'nome'           => $c['nome'],
        'slug'           => $c['slug'],
        'descricao'      => $c['descricao'],
        'ordem'          => (int) $c['ordem'],
        'ativo'          => (bool) $c['ativo'],
        'total_produtos' => isset($c['total_produtos']) ? (int) $c['total_produtos'] : null,
    ];
}

function categoria_buscar(int $id): array
{
    $c = db_um(
        'SELECT c.*, (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id = c.id) AS total_produtos
           FROM categorias c WHERE c.id = ?',
        [$id]
    );
    if ($c === null) {
        abortar(404, 'Categoria não encontrada.');
    }
    return $c;
}

function ctl_admin_categorias_listar(array $p): void
{
    responder(array_map('categoria_admin_json', db_todos(
        'SELECT c.*, (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id = c.id) AS total_produtos
           FROM categorias c ORDER BY COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.ordem, c.nome'
    )));
}

function categoria_conferir_pai(?int $parentId, ?int $idAtual): void
{
    if ($parentId === null) {
        return;
    }
    $pai = db_um('SELECT id, parent_id FROM categorias WHERE id = ?', [$parentId]);
    if ($pai === null) {
        abortar(422, 'Dados inválidos.', ['parent_id' => 'Categoria pai não encontrada.']);
    }
    if ($pai['parent_id'] !== null) {
        abortar(422, 'Dados inválidos.', ['parent_id' => 'Só há 2 níveis: a categoria pai precisa ser uma categoria principal.']);
    }
    if ($idAtual !== null) {
        if ($parentId === $idAtual) {
            abortar(422, 'Dados inválidos.', ['parent_id' => 'Uma categoria não pode ser pai dela mesma.']);
        }
        if (db_valor('SELECT id FROM categorias WHERE parent_id = ? LIMIT 1', [$idAtual]) !== null) {
            abortar(422, 'Dados inválidos.', ['parent_id' => 'Esta categoria tem subcategorias, então não pode virar subcategoria.']);
        }
    }
}

function ctl_admin_categorias_criar(array $p): void
{
    $v = validar(entrada(), [
        'nome'      => 'obrigatorio|texto:2:80',
        'slug'      => 'texto:2:100',
        'parent_id' => 'anulavel|inteiro:1',
        'descricao' => 'anulavel|texto:1:255',
        'ordem'     => 'inteiro:0:32000',
        'ativo'     => 'bool',
    ]);
    categoria_conferir_pai($v['parent_id'] ?? null, null);

    if (isset($v['slug'])) {
        $v['slug'] = slugificar($v['slug']);
        if (db_valor('SELECT id FROM categorias WHERE slug = ?', [$v['slug']]) !== null) {
            abortar(409, 'Este slug já está em uso.', ['slug' => 'Slug já cadastrado.']);
        }
    } else {
        $v['slug'] = slug_unico('categorias', $v['nome']);
    }
    $id = db_inserir_campos('categorias', $v);
    responder(categoria_admin_json(categoria_buscar($id)), 201);
}

function ctl_admin_categorias_atualizar(array $p): void
{
    $id = (int) $p['id'];
    categoria_buscar($id);
    $v = validar(entrada(), [
        'nome'      => 'texto:2:80',
        'slug'      => 'texto:2:100',
        'parent_id' => 'anulavel|inteiro:1',
        'descricao' => 'anulavel|texto:1:255',
        'ordem'     => 'inteiro:0:32000',
        'ativo'     => 'bool',
    ]);
    if (array_key_exists('parent_id', $v)) {
        categoria_conferir_pai($v['parent_id'], $id);
    }
    if (isset($v['slug'])) {
        $v['slug'] = slugificar($v['slug']);
        if (db_valor('SELECT id FROM categorias WHERE slug = ? AND id <> ?', [$v['slug'], $id]) !== null) {
            abortar(409, 'Este slug já está em uso.', ['slug' => 'Slug já cadastrado.']);
        }
    }
    db_atualizar('categorias', $v, 'id = ?', [$id]);
    responder(categoria_admin_json(categoria_buscar($id)));
}

function ctl_admin_categorias_excluir(array $p): void
{
    $id = (int) $p['id'];
    $c  = categoria_buscar($id);
    if ((int) $c['total_produtos'] > 0) {
        abortar(409, 'Há produtos nesta categoria. Mova-os ou desative a categoria.');
    }
    if (db_valor('SELECT id FROM categorias WHERE parent_id = ? LIMIT 1', [$id]) !== null) {
        abortar(409, 'Esta categoria possui subcategorias. Remova-as primeiro.');
    }
    db_executar('DELETE FROM categorias WHERE id = ?', [$id]);
    responder(['mensagem' => 'Categoria excluída.']);
}

// ---------------------------------------------------------------------
// Marcas
// ---------------------------------------------------------------------

function marca_admin_json(array $m): array
{
    return [
        'id'             => (int) $m['id'],
        'nome'           => $m['nome'],
        'slug'           => $m['slug'],
        'ativo'          => (bool) $m['ativo'],
        'total_produtos' => isset($m['total_produtos']) ? (int) $m['total_produtos'] : null,
    ];
}

function marca_buscar(int $id): array
{
    $m = db_um('SELECT m.*, (SELECT COUNT(*) FROM produtos p WHERE p.marca_id = m.id) AS total_produtos FROM marcas m WHERE m.id = ?', [$id]);
    if ($m === null) {
        abortar(404, 'Marca não encontrada.');
    }
    return $m;
}

function ctl_admin_marcas_listar(array $p): void
{
    responder(array_map('marca_admin_json', db_todos(
        'SELECT m.*, (SELECT COUNT(*) FROM produtos p WHERE p.marca_id = m.id) AS total_produtos FROM marcas m ORDER BY m.nome'
    )));
}

function ctl_admin_marcas_criar(array $p): void
{
    $v = validar(entrada(), ['nome' => 'obrigatorio|texto:2:80', 'ativo' => 'bool']);
    if (db_valor('SELECT id FROM marcas WHERE nome = ?', [$v['nome']]) !== null) {
        abortar(409, 'Já existe uma marca com este nome.', ['nome' => 'Já cadastrada.']);
    }
    $v['slug'] = slug_unico('marcas', $v['nome']);
    $id = db_inserir_campos('marcas', $v);
    responder(marca_admin_json(marca_buscar($id)), 201);
}

function ctl_admin_marcas_atualizar(array $p): void
{
    $id = (int) $p['id'];
    marca_buscar($id);
    $v = validar(entrada(), ['nome' => 'texto:2:80', 'ativo' => 'bool']);
    if (isset($v['nome']) && db_valor('SELECT id FROM marcas WHERE nome = ? AND id <> ?', [$v['nome'], $id]) !== null) {
        abortar(409, 'Já existe uma marca com este nome.', ['nome' => 'Já cadastrada.']);
    }
    db_atualizar('marcas', $v, 'id = ?', [$id]);
    responder(marca_admin_json(marca_buscar($id)));
}

function ctl_admin_marcas_excluir(array $p): void
{
    $id = (int) $p['id'];
    $m  = marca_buscar($id);
    if ((int) $m['total_produtos'] > 0) {
        abortar(409, 'Há produtos desta marca. Mova-os ou desative a marca.');
    }
    db_executar('DELETE FROM marcas WHERE id = ?', [$id]);
    responder(['mensagem' => 'Marca excluída.']);
}

// ---------------------------------------------------------------------
// Veículos (para a compatibilidade das peças)
// ---------------------------------------------------------------------

function regras_veiculo(bool $criando): array
{
    $o = $criando ? 'obrigatorio|' : '';
    return [
        'montadora'  => $o . 'texto:2:40',
        'modelo'     => $o . 'texto:1:60',
        'ano_inicio' => $o . 'inteiro:1950:2100',
        'ano_fim'    => 'anulavel|inteiro:1950:2100',
        'motor'      => 'anulavel|texto:1:40',
    ];
}

function veiculo_buscar(int $id): array
{
    $v = db_um('SELECT * FROM veiculos WHERE id = ?', [$id]);
    if ($v === null) {
        abortar(404, 'Veículo não encontrado.');
    }
    return $v;
}

function ctl_admin_veiculos_criar(array $p): void
{
    $v = validar(entrada(), regras_veiculo(true));
    if (isset($v['ano_fim']) && $v['ano_fim'] < $v['ano_inicio']) {
        abortar(422, 'Dados inválidos.', ['ano_fim' => 'Não pode ser menor que o ano inicial.']);
    }
    $id = db_inserir_campos('veiculos', $v);
    responder(veiculo_json(veiculo_buscar($id)), 201);
}

function ctl_admin_veiculos_atualizar(array $p): void
{
    $id    = (int) $p['id'];
    $atual = veiculo_buscar($id);
    $v = validar(entrada(), regras_veiculo(false));

    $ini = $v['ano_inicio'] ?? (int) $atual['ano_inicio'];
    $fim = array_key_exists('ano_fim', $v) ? $v['ano_fim'] : ($atual['ano_fim'] !== null ? (int) $atual['ano_fim'] : null);
    if ($fim !== null && $fim < $ini) {
        abortar(422, 'Dados inválidos.', ['ano_fim' => 'Não pode ser menor que o ano inicial.']);
    }
    db_atualizar('veiculos', $v, 'id = ?', [$id]);
    responder(veiculo_json(veiculo_buscar($id)));
}

function ctl_admin_veiculos_excluir(array $p): void
{
    $id = (int) $p['id'];
    veiculo_buscar($id);
    db_executar('DELETE FROM veiculos WHERE id = ?', [$id]);   // remove também os vínculos com produtos
    responder(['mensagem' => 'Veículo excluído.']);
}

<?php
declare(strict_types=1);

// =====================================================================
// PAINEL ADMIN - Produtos (CRUD completo), estoque, imagens e
// compatibilidade com veículos.
// Evolução de cadastrar_livro / salvar_livro / editar_livro /
// atualizar_livro / excluir_livro da biblioteca. Todas as rotas daqui
// exigem perfil "admin" (definido em routes.php).
// =====================================================================

function regras_produto(bool $criando): array
{
    $o = $criando ? 'obrigatorio|' : '';
    return [
        'sku'               => $o . 'texto:2:40',
        'nome'              => $o . 'texto:3:180',
        'slug'              => 'texto:2:200',
        'descricao'         => 'anulavel|texto:1:5000',
        'categoria_id'      => $o . 'inteiro:1',
        'marca_id'          => 'anulavel|inteiro:1',
        'codigo_fabricante' => 'anulavel|texto:1:60',
        'preco'             => $o . 'decimal:0.01:99999999',
        'preco_promocional' => 'anulavel|decimal:0.01:99999999',
        'estoque_minimo'    => 'inteiro:0:100000',
        'peso_kg'           => 'decimal:0.001:999',
        'comprimento_cm'    => 'decimal:0:999',
        'largura_cm'        => 'decimal:0:999',
        'altura_cm'         => 'decimal:0:999',
        'universal'         => 'bool',
        'destaque'          => 'bool',
        'ativo'             => 'bool',
    ];
}

/** Valida "especificacoes" (objeto chave => valor). Devolve JSON ou null. */
function validar_especificacoes(mixed $valor): ?string
{
    if ($valor === null) {
        return null;
    }
    if (!is_array($valor) || count($valor) > 30 || ($valor && array_is_list($valor))) {
        abortar(422, 'Dados inválidos.', ['especificacoes' => 'Envie um objeto {"Nome":"Valor"} com até 30 itens.']);
    }
    $limpo = [];
    foreach ($valor as $k => $v) {
        $k = trim((string) $k);
        if ($k === '' || mb_strlen($k) > 60 || !(is_string($v) || is_int($v) || is_float($v)) || mb_strlen((string) $v) > 200) {
            abortar(422, 'Dados inválidos.', ['especificacoes' => 'Nomes até 60 e valores até 200 caracteres (texto ou número).']);
        }
        $limpo[$k] = is_string($v) ? trim($v) : $v;
    }
    return $limpo ? json_encode($limpo, JSON_UNESCAPED_UNICODE) : null;
}

function conferir_veiculos_existem(array $ids): void
{
    if (!$ids) {
        return;
    }
    $qtd = (int) db_valor('SELECT COUNT(*) FROM veiculos WHERE id IN (' . db_marcadores($ids) . ')', $ids);
    if ($qtd !== count($ids)) {
        abortar(422, 'Dados inválidos.', ['veiculo_ids' => 'Um ou mais veículos não existem.']);
    }
}

function produto_sincronizar_veiculos(int $produtoId, array $ids): void
{
    db_executar('DELETE FROM produto_veiculo WHERE produto_id = ?', [$produtoId]);
    foreach ($ids as $vid) {
        db_executar('INSERT INTO produto_veiculo (produto_id, veiculo_id) VALUES (?, ?)', [$produtoId, $vid]);
    }
}

function produto_admin_buscar(int $id): array
{
    $linha = db_um(produtos_sql_base() . ' WHERE p.id = ?', [$id]);
    if ($linha === null) {
        abortar(404, 'Produto não encontrado.');
    }
    return $linha;
}

function produto_admin_json(int $id): array
{
    $d = produto_detalhe(produto_admin_buscar($id));
    $d['veiculo_ids'] = array_column($d['veiculos_compativeis'], 'id');
    return $d;
}

/** Confere categoria/marca informadas e preço promocional. */
function produto_conferir_relacoes(array $v, ?array $atual = null): void
{
    if (isset($v['categoria_id']) && db_valor('SELECT id FROM categorias WHERE id = ?', [$v['categoria_id']]) === null) {
        abortar(422, 'Dados inválidos.', ['categoria_id' => 'Categoria não encontrada.']);
    }
    if (!empty($v['marca_id']) && db_valor('SELECT id FROM marcas WHERE id = ?', [$v['marca_id']]) === null) {
        abortar(422, 'Dados inválidos.', ['marca_id' => 'Marca não encontrada.']);
    }
    $preco = isset($v['preco']) ? round((float) $v['preco'], 2) : ($atual !== null ? (float) $atual['preco'] : null);
    $promo = array_key_exists('preco_promocional', $v)
        ? ($v['preco_promocional'] !== null ? round((float) $v['preco_promocional'], 2) : null)
        : ($atual !== null && $atual['preco_promocional'] !== null ? (float) $atual['preco_promocional'] : null);
    if ($preco !== null && $promo !== null && $promo >= $preco) {
        abortar(422, 'Dados inválidos.', ['preco_promocional' => 'O preço promocional deve ser menor que o preço normal.']);
    }
}

// ---------------------------------------------------------------------
// Listar / ver
// ---------------------------------------------------------------------

function ctl_admin_produtos_listar(array $p): void
{
    [$pagina, $porPagina, $offset] = paginacao(20, 100);
    [$where, $params] = produtos_filtros(true);
    $ordem = produtos_ordenacao('recentes');

    $total  = (int) db_valor('SELECT COUNT(*) ' . SQL_PRODUTOS_JOINS . " $where", $params);
    $linhas = db_todos(produtos_sql_base() . " $where ORDER BY $ordem LIMIT $porPagina OFFSET $offset", $params);
    responder_lista(array_map('produto_resumo', $linhas), $total, $pagina, $porPagina);
}

function ctl_admin_produtos_ver(array $p): void
{
    responder(produto_admin_json((int) $p['id']));
}

// ---------------------------------------------------------------------
// Criar
// ---------------------------------------------------------------------

function ctl_admin_produtos_criar(array $p): void
{
    $admin = exigir_admin();
    $dados = entrada();
    $v = validar($dados, regras_produto(true) + ['estoque' => 'inteiro:0:1000000']);

    $especificacoes = array_key_exists('especificacoes', $dados) ? validar_especificacoes($dados['especificacoes']) : null;
    $veiculoIds     = array_key_exists('veiculo_ids', $dados) ? validar_lista_ids($dados['veiculo_ids'], 'veiculo_ids') : [];
    conferir_veiculos_existem($veiculoIds);
    produto_conferir_relacoes($v);

    if (db_valor('SELECT id FROM produtos WHERE sku = ?', [$v['sku']]) !== null) {
        abortar(409, 'Já existe um produto com este SKU.', ['sku' => 'SKU já cadastrado.']);
    }
    if (isset($v['slug'])) {
        $v['slug'] = slugificar($v['slug']);
        if (db_valor('SELECT id FROM produtos WHERE slug = ?', [$v['slug']]) !== null) {
            abortar(409, 'Este slug já está em uso.', ['slug' => 'Slug já cadastrado.']);
        }
    } else {
        $v['slug'] = slug_unico('produtos', $v['nome']);
    }

    $estoque = (int) ($v['estoque'] ?? 0);
    unset($v['estoque']);
    $v['preco'] = round((float) $v['preco'], 2);
    if (isset($v['preco_promocional'])) {
        $v['preco_promocional'] = round((float) $v['preco_promocional'], 2);
    }
    $v['estoque'] = $estoque;
    $v['especificacoes'] = $especificacoes;

    $id = db_transacao(function () use ($v, $veiculoIds, $estoque, $admin): int {
        $id = db_inserir_campos('produtos', $v);
        produto_sincronizar_veiculos($id, $veiculoIds);
        if ($estoque > 0) {
            db_executar(
                "INSERT INTO estoque_movimentos (produto_id, tipo, quantidade, estoque_apos, motivo, usuario_id)
                 VALUES (?, 'entrada', ?, ?, 'Estoque inicial', ?)",
                [$id, $estoque, $estoque, $admin['id']]
            );
        }
        return $id;
    });

    responder(produto_admin_json($id), 201);
}

// ---------------------------------------------------------------------
// Atualizar (parcial: só os campos enviados mudam)
// O estoque NÃO é alterado aqui; use /estoque para ficar no histórico.
// ---------------------------------------------------------------------

function ctl_admin_produtos_atualizar(array $p): void
{
    $id    = (int) $p['id'];
    $atual = db_um('SELECT * FROM produtos WHERE id = ?', [$id]);
    if ($atual === null) {
        abortar(404, 'Produto não encontrado.');
    }

    $dados = entrada();
    $v = validar($dados, regras_produto(false));
    produto_conferir_relacoes($v, $atual);

    if (isset($v['sku']) && db_valor('SELECT id FROM produtos WHERE sku = ? AND id <> ?', [$v['sku'], $id]) !== null) {
        abortar(409, 'Já existe um produto com este SKU.', ['sku' => 'SKU já cadastrado.']);
    }
    if (isset($v['slug'])) {
        $v['slug'] = slugificar($v['slug']);
        if (db_valor('SELECT id FROM produtos WHERE slug = ? AND id <> ?', [$v['slug'], $id]) !== null) {
            abortar(409, 'Este slug já está em uso.', ['slug' => 'Slug já cadastrado.']);
        }
    }
    if (isset($v['preco'])) {
        $v['preco'] = round((float) $v['preco'], 2);
    }
    if (isset($v['preco_promocional'])) {
        $v['preco_promocional'] = round((float) $v['preco_promocional'], 2);
    }
    if (array_key_exists('especificacoes', $dados)) {
        $v['especificacoes'] = validar_especificacoes($dados['especificacoes']);
    }
    $veiculoIds = array_key_exists('veiculo_ids', $dados) ? validar_lista_ids($dados['veiculo_ids'], 'veiculo_ids') : null;
    if ($veiculoIds !== null) {
        conferir_veiculos_existem($veiculoIds);
    }

    db_transacao(function () use ($id, $v, $veiculoIds): void {
        db_atualizar('produtos', $v, 'id = ?', [$id]);
        if ($veiculoIds !== null) {
            produto_sincronizar_veiculos($id, $veiculoIds);
        }
    });

    responder(produto_admin_json($id));
}

// ---------------------------------------------------------------------
// Excluir: se o produto já foi vendido, só desativa (o histórico dos
// pedidos precisa continuar existindo).
// ---------------------------------------------------------------------

function ctl_admin_produtos_excluir(array $p): void
{
    $id = (int) $p['id'];
    produto_admin_buscar($id);

    $jaVendido = db_valor('SELECT id FROM pedido_itens WHERE produto_id = ? LIMIT 1', [$id]) !== null;
    if ($jaVendido) {
        db_executar('UPDATE produtos SET ativo = 0 WHERE id = ?', [$id]);
        responder(['excluido' => false, 'desativado' => true, 'mensagem' => 'Produto já vendido: foi desativado em vez de excluído.']);
    }

    $arquivos = array_column(db_todos('SELECT arquivo FROM produto_imagens WHERE produto_id = ?', [$id]), 'arquivo');
    db_executar('DELETE FROM produtos WHERE id = ?', [$id]);
    foreach ($arquivos as $a) {
        @unlink(UPLOAD_DIR . '/' . basename($a));
    }
    responder(['excluido' => true, 'desativado' => false, 'mensagem' => 'Produto excluído.']);
}

// ---------------------------------------------------------------------
// Compatibilidade com veículos
// ---------------------------------------------------------------------

function ctl_admin_produtos_veiculos(array $p): void
{
    $id = (int) $p['id'];
    produto_admin_buscar($id);
    $ids = validar_lista_ids(entrada()['veiculo_ids'] ?? null, 'veiculo_ids');
    conferir_veiculos_existem($ids);
    db_transacao(fn() => produto_sincronizar_veiculos($id, $ids));
    responder(produto_admin_json($id));
}

// ---------------------------------------------------------------------
// Estoque (entrada, saída e ajuste, sempre com histórico)
// ---------------------------------------------------------------------

function ctl_admin_estoque_movimentar(array $p): void
{
    $admin = exigir_admin();
    $id = (int) $p['id'];
    produto_admin_buscar($id);

    $v = validar(entrada(), [
        'tipo'       => 'obrigatorio|enum:entrada,saida,ajuste',
        'quantidade' => 'obrigatorio|inteiro:0:1000000',
        'motivo'     => 'texto:1:160',
    ]);
    if ($v['tipo'] !== 'ajuste' && $v['quantidade'] < 1) {
        abortar(422, 'Dados inválidos.', ['quantidade' => 'Informe uma quantidade maior que zero.']);
    }

    db_transacao(function () use ($id, $v, $admin): void {
        $atual = (int) db_valor('SELECT estoque FROM produtos WHERE id = ? FOR UPDATE', [$id]);
        $novo = match ($v['tipo']) {
            'entrada' => $atual + $v['quantidade'],
            'saida'   => $atual - $v['quantidade'],
            'ajuste'  => $v['quantidade'],      // no ajuste, a quantidade é o NOVO total
        };
        if ($novo < 0) {
            abortar(422, 'Estoque insuficiente para esta saída (atual: ' . $atual . ').', ['quantidade' => 'Maior que o estoque atual.']);
        }
        if ($novo === $atual) {
            abortar(422, 'Nada mudou: o estoque já é ' . $atual . '.');
        }
        db_executar('UPDATE produtos SET estoque = ? WHERE id = ?', [$novo, $id]);
        db_executar(
            'INSERT INTO estoque_movimentos (produto_id, tipo, quantidade, estoque_apos, motivo, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $v['tipo'], $novo - $atual, $novo, $v['motivo'] ?? null, $admin['id']]
        );
    });

    responder(produto_admin_json($id));
}

function ctl_admin_estoque_historico(array $p): void
{
    $id = (int) $p['id'];
    produto_admin_buscar($id);
    [$pagina, $porPagina, $offset] = paginacao(20, 100);
    $total = (int) db_valor('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id = ?', [$id]);
    $linhas = db_todos(
        "SELECT m.id, m.tipo, m.quantidade, m.estoque_apos, m.motivo, m.criado_em, pe.codigo AS pedido, u.nome AS usuario
           FROM estoque_movimentos m
           LEFT JOIN pedidos pe ON pe.id = m.pedido_id
           LEFT JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.produto_id = ? ORDER BY m.id DESC LIMIT $porPagina OFFSET $offset",
        [$id]
    );
    responder_lista($linhas, $total, $pagina, $porPagina);
}

// ---------------------------------------------------------------------
// Imagens
// ---------------------------------------------------------------------

/** Valida o arquivo enviado e o grava com nome aleatório. Devolve o nome do arquivo. */
function salvar_upload_imagem(?array $arquivo): string
{
    if ($arquivo === null || !isset($arquivo['error'])) {
        abortar(422, 'Dados inválidos.', ['imagem' => 'Envie um arquivo no campo "imagem" (multipart/form-data).']);
    }
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE || $arquivo['size'] > UPLOAD_MAX_BYTES) {
        abortar(422, 'Dados inválidos.', ['imagem' => 'A imagem deve ter no máximo ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB.']);
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        abortar(422, 'Dados inválidos.', ['imagem' => 'Falha no envio do arquivo.']);
    }

    // Confere o CONTEÚDO real do arquivo (a extensão enviada pelo usuário não é confiável)
    $info = @getimagesize($arquivo['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($tipos[$info[2]])) {
        abortar(422, 'Dados inválidos.', ['imagem' => 'Formato não aceito. Use JPG, PNG ou WEBP.']);
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $nome = bin2hex(random_bytes(16)) . '.' . $tipos[$info[2]];
    if (!move_uploaded_file($arquivo['tmp_name'], UPLOAD_DIR . '/' . $nome)) {
        abortar(500, 'Não foi possível salvar a imagem.');
    }
    return $nome;
}

function ctl_admin_imagens_enviar(array $p): void
{
    $id = (int) $p['id'];
    produto_admin_buscar($id);

    $qtd = (int) db_valor('SELECT COUNT(*) FROM produto_imagens WHERE produto_id = ?', [$id]);
    if ($qtd >= MAX_IMAGENS_PRODUTO) {
        abortar(422, 'Limite de ' . MAX_IMAGENS_PRODUTO . ' imagens por produto.');
    }

    $nome = salvar_upload_imagem($_FILES['imagem'] ?? null);
    $principal = $qtd === 0 || filter_var($_POST['principal'] ?? false, FILTER_VALIDATE_BOOLEAN);

    try {
        db_transacao(function () use ($id, $nome, $principal, $qtd): void {
            if ($principal) {
                db_executar('UPDATE produto_imagens SET principal = 0 WHERE produto_id = ?', [$id]);
            }
            db_executar(
                'INSERT INTO produto_imagens (produto_id, arquivo, principal, ordem) VALUES (?, ?, ?, ?)',
                [$id, $nome, $principal, $qtd]
            );
        });
    } catch (Throwable $e) {
        @unlink(UPLOAD_DIR . '/' . $nome);   // não deixa arquivo órfão se o banco falhar
        throw $e;
    }
    responder(produto_admin_json($id), 201);
}

function imagem_do_produto(int $produtoId, int $imagemId): array
{
    $img = db_um('SELECT * FROM produto_imagens WHERE id = ? AND produto_id = ?', [$imagemId, $produtoId]);
    if ($img === null) {
        abortar(404, 'Imagem não encontrada.');
    }
    return $img;
}

function ctl_admin_imagens_principal(array $p): void
{
    $id = (int) $p['id'];
    imagem_do_produto($id, (int) $p['imagem_id']);
    db_transacao(function () use ($id, $p): void {
        db_executar('UPDATE produto_imagens SET principal = 0 WHERE produto_id = ?', [$id]);
        db_executar('UPDATE produto_imagens SET principal = 1 WHERE id = ?', [(int) $p['imagem_id']]);
    });
    responder(produto_admin_json($id));
}

function ctl_admin_imagens_excluir(array $p): void
{
    $id  = (int) $p['id'];
    $img = imagem_do_produto($id, (int) $p['imagem_id']);

    db_transacao(function () use ($id, $img): void {
        db_executar('DELETE FROM produto_imagens WHERE id = ?', [$img['id']]);
        if ((int) $img['principal'] === 1) {   // promove outra imagem a principal
            db_executar('UPDATE produto_imagens SET principal = 1 WHERE produto_id = ? ORDER BY ordem, id LIMIT 1', [$id]);
        }
    });
    @unlink(UPLOAD_DIR . '/' . basename($img['arquivo']));
    responder(produto_admin_json($id));
}

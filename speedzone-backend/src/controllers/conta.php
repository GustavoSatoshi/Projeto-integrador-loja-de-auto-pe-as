<?php
declare(strict_types=1);

// =====================================================================
// Conta do cliente: perfil, troca de senha e endereços de entrega.
// Todas as rotas daqui exigem login.
// =====================================================================

const MAX_ENDERECOS = 10;

function ctl_conta_ver(array $p): void
{
    responder(usuario_publico(exigir_login()));
}

function ctl_conta_atualizar(array $p): void
{
    $u = exigir_login();
    $v = validar(entrada(), [
        'nome'     => 'texto:2:120',
        'telefone' => 'anulavel|telefone',
        'empresa'  => 'anulavel|texto:2:160',
    ]);
    // Oficina precisa manter o nome da empresa preenchido
    if ($u['tipo_cliente'] === 'oficina' && array_key_exists('empresa', $v) && $v['empresa'] === null) {
        abortar(422, 'Dados inválidos.', ['empresa' => 'Oficinas precisam informar a empresa.']);
    }
    db_atualizar('usuarios', $v, 'id = ?', [$u['id']]);
    responder(usuario_publico(db_um('SELECT * FROM usuarios WHERE id = ?', [$u['id']])));
}

function ctl_conta_trocar_senha(array $p): void
{
    $u = exigir_login();
    $v = validar(entrada(), [
        'senha_atual' => 'obrigatorio|texto:1:72',
        'nova_senha'  => 'obrigatorio|texto:8:72',
    ]);
    $hash = db_valor('SELECT senha_hash FROM usuarios WHERE id = ?', [$u['id']]);
    if (!password_verify($v['senha_atual'], (string) $hash)) {
        abortar(422, 'Dados inválidos.', ['senha_atual' => 'Senha atual incorreta.']);
    }
    db_executar('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($v['nova_senha'], PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    responder(['mensagem' => 'Senha alterada com sucesso.']);
}

// ---------------------------------------------------------------------
// Endereços
// ---------------------------------------------------------------------

function endereco_json(array $e): array
{
    return [
        'id'           => (int) $e['id'],
        'apelido'      => $e['apelido'],
        'destinatario' => $e['destinatario'],
        'cep'          => $e['cep'],
        'logradouro'   => $e['logradouro'],
        'numero'       => $e['numero'],
        'complemento'  => $e['complemento'],
        'bairro'       => $e['bairro'],
        'cidade'       => $e['cidade'],
        'uf'           => $e['uf'],
        'padrao'       => (bool) $e['padrao'],
    ];
}

/** Busca um endereço garantindo que ele pertence ao usuário logado. */
function endereco_do_usuario(int $id, int $usuarioId): array
{
    $e = db_um('SELECT * FROM enderecos WHERE id = ? AND usuario_id = ?', [$id, $usuarioId]);
    if ($e === null) {
        abortar(404, 'Endereço não encontrado.');
    }
    return $e;
}

function regras_endereco(bool $criando): array
{
    $o = $criando ? 'obrigatorio|' : '';
    return [
        'apelido'      => 'texto:1:40',
        'destinatario' => $o . 'texto:2:120',
        'cep'          => $o . 'cep',
        'logradouro'   => $o . 'texto:2:160',
        'numero'       => $o . 'texto:1:10',
        'complemento'  => 'anulavel|texto:1:80',
        'bairro'       => $o . 'texto:2:80',
        'cidade'       => $o . 'texto:2:80',
        'uf'           => $o . 'uf',
        'padrao'       => 'bool',
    ];
}

function ctl_enderecos_listar(array $p): void
{
    $u = exigir_login();
    responder(array_map('endereco_json', db_todos(
        'SELECT * FROM enderecos WHERE usuario_id = ? ORDER BY padrao DESC, id DESC',
        [$u['id']]
    )));
}

function ctl_enderecos_criar(array $p): void
{
    $u = exigir_login();
    $v = validar(entrada(), regras_endereco(true));

    if (uf_por_cep($v['cep']) === null) {
        abortar(422, 'Dados inválidos.', ['cep' => 'CEP inexistente.']);
    }

    $id = db_transacao(function () use ($u, $v) {
        $qtd = (int) db_valor('SELECT COUNT(*) FROM enderecos WHERE usuario_id = ? FOR UPDATE', [$u['id']]);
        if ($qtd >= MAX_ENDERECOS) {
            abortar(422, 'Limite de ' . MAX_ENDERECOS . ' endereços atingido.');
        }
        $padrao = $qtd === 0 || !empty($v['padrao']);   // o primeiro endereço já nasce como padrão
        if ($padrao) {
            db_executar('UPDATE enderecos SET padrao = 0 WHERE usuario_id = ?', [$u['id']]);
        }
        $v['padrao'] = $padrao;
        $v['usuario_id'] = $u['id'];
        $colunas = array_keys($v);
        return db_inserir(
            'INSERT INTO enderecos (' . implode(',', $colunas) . ') VALUES (' . db_marcadores($colunas) . ')',
            array_values($v)
        );
    });

    responder(endereco_json(endereco_do_usuario($id, (int) $u['id'])), 201);
}

function ctl_enderecos_atualizar(array $p): void
{
    $u  = exigir_login();
    $id = (int) $p['id'];
    endereco_do_usuario($id, (int) $u['id']);
    $v = validar(entrada(), regras_endereco(false));

    if (isset($v['cep']) && uf_por_cep($v['cep']) === null) {
        abortar(422, 'Dados inválidos.', ['cep' => 'CEP inexistente.']);
    }

    db_transacao(function () use ($u, $id, $v) {
        if (!empty($v['padrao'])) {
            db_executar('UPDATE enderecos SET padrao = 0 WHERE usuario_id = ?', [$u['id']]);
        }
        db_atualizar('enderecos', $v, 'id = ? AND usuario_id = ?', [$id, $u['id']]);
    });

    responder(endereco_json(endereco_do_usuario($id, (int) $u['id'])));
}

function ctl_enderecos_excluir(array $p): void
{
    $u  = exigir_login();
    $id = (int) $p['id'];
    $e  = endereco_do_usuario($id, (int) $u['id']);

    db_transacao(function () use ($u, $id, $e) {
        db_executar('DELETE FROM enderecos WHERE id = ? AND usuario_id = ?', [$id, $u['id']]);
        if ((int) $e['padrao'] === 1) {   // promove o endereço mais recente a padrão
            db_executar('UPDATE enderecos SET padrao = 1 WHERE usuario_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]);
        }
    });
    responder(['mensagem' => 'Endereço removido.']);
}

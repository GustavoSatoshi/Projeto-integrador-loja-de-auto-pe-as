<?php
declare(strict_types=1);

// =====================================================================
// Autenticação: cadastro, login, logout e "quem sou eu".
// Evolução de cadastro.php + salvar_usuario.php + autenticar.php
// (que estava vazio) + logout.php da biblioteca.
// =====================================================================

/** Dados do usuário que podem ir para o front-end (nunca a senha). */
function usuario_publico(array $u): array
{
    return [
        'id'               => (int) $u['id'],
        'nome'             => $u['nome'],
        'email'            => $u['email'],
        'perfil'           => $u['perfil'],
        'tipo_cliente'     => $u['tipo_cliente'],
        'documento'        => $u['documento'] ?? null,
        'empresa'          => $u['empresa'] ?? null,
        'telefone'         => $u['telefone'] ?? null,
        'oficina_aprovada' => (bool) $u['oficina_aprovada'],
        'criado_em'        => $u['criado_em'] ?? null,
    ];
}

function ctl_csrf(array $p): void
{
    responder(['csrf_token' => csrf_token()]);
}

function ctl_auth_cadastro(array $p): void
{
    $v = validar(entrada(), [
        'nome'         => 'obrigatorio|texto:2:120',
        'email'        => 'obrigatorio|email',
        'senha'        => 'obrigatorio|texto:8:72',   // bcrypt só considera 72 bytes
        'tipo_cliente' => 'enum:pf,oficina',
        'documento'    => 'obrigatorio|texto:11:18',
        'empresa'      => 'texto:2:160',
        'telefone'     => 'telefone',
    ]);

    $tipo = $v['tipo_cliente'] ?? 'pf';
    $doc  = somente_digitos($v['documento']);

    if ($tipo === 'pf' && !validar_cpf($doc)) {
        abortar(422, 'Dados inválidos.', ['documento' => 'CPF inválido.']);
    }
    if ($tipo === 'oficina') {
        if (!validar_cnpj($doc)) {
            abortar(422, 'Dados inválidos.', ['documento' => 'CNPJ inválido.']);
        }
        if (empty($v['empresa'])) {
            abortar(422, 'Dados inválidos.', ['empresa' => 'Informe a razão social ou nome da oficina.']);
        }
    }

    // Mesma checagem de e-mail duplicado do salvar_usuario.php, agora com prepared statement
    if (db_valor('SELECT id FROM usuarios WHERE email = ?', [$v['email']]) !== null) {
        abortar(409, 'Este e-mail já está cadastrado.', ['email' => 'Já cadastrado.']);
    }
    if (db_valor('SELECT id FROM usuarios WHERE documento = ?', [$doc]) !== null) {
        abortar(409, 'Este documento já está cadastrado.', ['documento' => 'Já cadastrado.']);
    }

    try {
        $id = db_inserir(
            'INSERT INTO usuarios (nome, email, senha_hash, tipo_cliente, documento, empresa, telefone)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $v['nome'], $v['email'], password_hash($v['senha'], PASSWORD_DEFAULT),
                $tipo, $doc, $v['empresa'] ?? null, $v['telefone'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        if (db_erro_duplicado($e)) {   // duas pessoas cadastrando ao mesmo tempo
            abortar(409, 'E-mail ou documento já cadastrado.');
        }
        throw $e;
    }

    fazer_login($id);
    $u = db_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    responder(
        ['usuario' => usuario_publico($u), 'csrf_token' => csrf_token()],
        201
    );
}

function ctl_auth_login(array $p): void
{
    $v = validar(entrada(), [
        'email' => 'obrigatorio|email',
        'senha' => 'obrigatorio|texto:1:72',
    ]);

    if (login_bloqueado($v['email'])) {
        abortar(429, 'Muitas tentativas. Aguarde ' . LOGIN_JANELA_MIN . ' minutos e tente de novo.', [], 'login_bloqueado');
    }

    $u = db_um('SELECT * FROM usuarios WHERE email = ?', [$v['email']]);

    // Sempre roda o password_verify, mesmo sem usuário, para não revelar se o e-mail existe pelo tempo de resposta
    $hash  = $u['senha_hash'] ?? hash_falso();
    $senhaOk = password_verify($v['senha'], $hash);

    if ($u === null || !$senhaOk) {
        login_registrar_falha($v['email']);
        abortar(401, 'E-mail ou senha inválidos.', [], 'credenciais_invalidas');
    }
    if ((int) $u['ativo'] !== 1) {
        abortar(403, 'Esta conta está desativada. Fale com o suporte.', [], 'conta_desativada');
    }

    // Se o PHP passou a usar um algoritmo mais forte, atualiza o hash silenciosamente
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        db_executar('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($v['senha'], PASSWORD_DEFAULT), $u['id']]);
    }

    login_limpar_falhas($v['email']);
    db_executar('UPDATE usuarios SET ultimo_login = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    fazer_login((int) $u['id']);

    responder(['usuario' => usuario_publico($u), 'csrf_token' => csrf_token()]);
}

function ctl_auth_logout(array $p): void
{
    encerrar_sessao();
    responder(['mensagem' => 'Sessão encerrada.']);
}

function ctl_auth_me(array $p): void
{
    $u = usuario_logado();
    if ($u === null) {
        responder(['autenticado' => false]);
    }
    responder(['autenticado' => true, 'usuario' => usuario_publico($u)]);
}

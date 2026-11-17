<?php
declare(strict_types=1);

// =====================================================================
// auth.php - sessão, login, permissões e proteção CSRF.
//
// É a evolução de verificar_sessao.php / logout.php da biblioteca:
//   verificar_sessao.php  ->  exigir_login() e exigir_admin()
//   logout.php            ->  fazer_logout()
// =====================================================================

const LOGIN_MAX_FALHAS   = 5;    // tentativas erradas permitidas...
const LOGIN_JANELA_MIN   = 15;   // ...dentro de 15 minutos (por e-mail + IP)

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('speedzone_sid');
    session_set_cookie_params([
        'lifetime' => 0,          // some ao fechar o navegador
        'path'     => '/',
        'secure'   => $https,     // só HTTPS quando o site estiver em HTTPS
        'httponly' => true,       // JavaScript não consegue ler o cookie
        'samesite' => 'Lax',      // não é enviado em requisições de outros sites
    ]);
    session_start();
}

// ---------------------------------------------------------------------
// Usuário logado
// ---------------------------------------------------------------------

/** Dados do usuário logado (consulta o banco a cada requisição) ou null. */
function usuario_logado(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    iniciar_sessao();
    $id = $_SESSION['usuario_id'] ?? null;
    if (!$id) {
        return $cache = null;
    }
    $u = db_um(
        'SELECT id, nome, email, perfil, tipo_cliente, documento, empresa, telefone,
                oficina_aprovada, ativo, criado_em
           FROM usuarios WHERE id = ?',
        [(int) $id]
    );
    // Usuário apagado ou desativado depois de logar: derruba a sessão
    if ($u === null || (int) $u['ativo'] !== 1) {
        encerrar_sessao();
        return $cache = null;
    }
    return $cache = $u;
}

/** Equivalente ao verificar_sessao.php: bloqueia quem não está logado. */
function exigir_login(): array
{
    $u = usuario_logado();
    if ($u === null) {
        abortar(401, 'Faça login para continuar.', [], 'nao_autenticado');
    }
    return $u;
}

/** Só administradores passam daqui. */
function exigir_admin(): array
{
    $u = exigir_login();
    if ($u['perfil'] !== 'admin') {
        abortar(403, 'Acesso restrito a administradores.', [], 'sem_permissao');
    }
    return $u;
}

/** Marca o usuário como logado (e troca o id da sessão contra "session fixation"). */
function fazer_login(int $usuarioId): void
{
    iniciar_sessao();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['csrf'] = bin2hex(random_bytes(32)); // novo token após login
}

/** Limpa sessão e cookie (mesma lógica do logout.php, mantendo o carrinho de fora). */
function encerrar_sessao(): void
{
    iniciar_sessao();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function hash_falso(): string
{
    // Usado quando o e-mail não existe, para o tempo de resposta ser parecido
    return '$2y$10$CxYpQWdYYy1.rBW8ZESbqOLjR3vlVssFpzm0FvbO62a5DNoDdAy6e';
}

// ---------------------------------------------------------------------
// Limite de tentativas de login (força bruta)
// ---------------------------------------------------------------------

function ip_cliente(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function login_bloqueado(string $email): bool
{
    $falhas = (int) db_valor(
        'SELECT COUNT(*) FROM login_tentativas
          WHERE email = ? AND ip = ? AND criado_em > ?',
        [$email, ip_cliente(), date('Y-m-d H:i:s', time() - LOGIN_JANELA_MIN * 60)]
    );
    return $falhas >= LOGIN_MAX_FALHAS;
}

function login_registrar_falha(string $email): void
{
    db_executar('INSERT INTO login_tentativas (email, ip) VALUES (?, ?)', [$email, ip_cliente()]);
    // Limpeza oportunista de registros antigos
    db_executar('DELETE FROM login_tentativas WHERE criado_em < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

function login_limpar_falhas(string $email): void
{
    db_executar('DELETE FROM login_tentativas WHERE email = ? AND ip = ?', [$email, ip_cliente()]);
}

// ---------------------------------------------------------------------
// CSRF: impede que outro site faça o navegador do cliente executar
// ações (comprar, excluir produto...) usando o cookie de sessão dele.
// O front-end busca o token em GET /api/csrf e envia em todo
// POST/PUT/PATCH/DELETE no cabeçalho "X-CSRF-Token".
// ---------------------------------------------------------------------

function csrf_token(): string
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verificar_csrf(): void
{
    iniciar_sessao();
    $esperado = $_SESSION['csrf'] ?? '';
    $recebido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($esperado === '' || !is_string($recebido) || !hash_equals($esperado, $recebido)) {
        abortar(403, 'Token CSRF ausente ou inválido. Busque um novo em GET /api/csrf.', [], 'csrf_invalido');
    }
}

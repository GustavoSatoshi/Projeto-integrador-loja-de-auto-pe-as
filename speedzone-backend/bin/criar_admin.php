<?php
declare(strict_types=1);

// =====================================================================
// Cria (ou promove) um usuário administrador pelo terminal.
//
//   php bin/criar_admin.php "Seu Nome" seu@email.com
//
// A senha é pedida no terminal (não fica salva em lugar nenhum).
// Se o e-mail já existir, o usuário vira admin e a senha é atualizada.
// =====================================================================

if (PHP_SAPI !== 'cli') {
    exit("Este script só roda no terminal.\n");
}

require __DIR__ . '/../src/app.php';

$nome  = $argv[1] ?? null;
$email = isset($argv[2]) ? mb_strtolower(trim($argv[2])) : null;

if (!$nome || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Uso: php bin/criar_admin.php \"Nome\" email@exemplo.com\n");
}

fwrite(STDOUT, 'Senha (mínimo 8 caracteres; ela aparece enquanto você digita): ');
$senha = trim((string) fgets(STDIN));
if (mb_strlen($senha) < 8 || mb_strlen($senha) > 72) {
    exit("Senha inválida: use de 8 a 72 caracteres.\n");
}

$hash = password_hash($senha, PASSWORD_DEFAULT);
$existente = db_valor('SELECT id FROM usuarios WHERE email = ?', [$email]);

if ($existente !== null) {
    db_executar("UPDATE usuarios SET perfil = 'admin', senha_hash = ?, ativo = 1, nome = ? WHERE id = ?", [$hash, $nome, $existente]);
    echo "Usuário existente promovido a administrador: $email\n";
} else {
    db_executar("INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'admin')", [$nome, $email, $hash]);
    echo "Administrador criado: $email\n";
}

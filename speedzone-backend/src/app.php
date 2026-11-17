<?php
declare(strict_types=1);

// =====================================================================
// app.php - carrega todas as peças do sistema (sem executar nada).
// É usado pelo public/index.php (web) e pelos scripts da pasta bin/ (terminal).
// =====================================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');   // erros vão para o log, nunca para a tela do cliente

// Transforma avisos/erros do PHP em exceções (assim viram resposta JSON 500)
set_error_handler(function (int $nivel, string $msg, string $arquivo, int $linha): bool {
    if (!(error_reporting() & $nivel) || in_array($nivel, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }
    throw new ErrorException($msg, 0, $nivel, $arquivo, $linha);
});

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/validacao.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/router.php';

foreach (glob(__DIR__ . '/controllers/*.php') ?: [] as $arquivo) {
    require $arquivo;
}

<?php
declare(strict_types=1);

// =====================================================================
// public/index.php - ÚNICA porta de entrada da API.
// Todo pedido (/api/...) passa por aqui e é encaminhado pelo roteador.
// =====================================================================

// Servidor embutido do PHP (php -S): deixa as imagens enviadas passarem direto
if (PHP_SAPI === 'cli-server') {
    $arquivo = __DIR__ . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    if (is_file($arquivo) && basename($arquivo) !== 'index.php') {
        return false;
    }
}

require __DIR__ . '/../src/app.php';
require __DIR__ . '/../src/routes.php';

executar();

<?php
declare(strict_types=1);

// =====================================================================
// Cancela pedidos que ficaram "aguardando pagamento" por tempo demais
// e devolve os produtos ao estoque (senão peças ficam presas).
//
//   php bin/cancelar_expirados.php
//
// Agende para rodar de hora em hora:
//   - Linux:   crontab -e  ->  0 * * * * php /caminho/bin/cancelar_expirados.php
//   - Windows: Agendador de Tarefas chamando php.exe com este arquivo
// O prazo vem do .env (PEDIDO_EXPIRA_HORAS, padrão 48).
// =====================================================================

if (PHP_SAPI !== 'cli') {
    exit("Este script só roda no terminal.\n");
}

require __DIR__ . '/../src/app.php';

$horas = max(1, (int) env('PEDIDO_EXPIRA_HORAS', '48'));
$pedidos = db_todos(
    "SELECT id, codigo FROM pedidos
      WHERE status = 'aguardando_pagamento' AND criado_em < ?",
    [date('Y-m-d H:i:s', time() - $horas * 3600)]
);

foreach ($pedidos as $pe) {
    try {
        pedido_cancelar((int) $pe['id'], null, "Cancelado automaticamente: sem pagamento em {$horas}h.", ['aguardando_pagamento']);
        echo "Cancelado: {$pe['codigo']}\n";
    } catch (Throwable $e) {
        echo "Falha em {$pe['codigo']}: {$e->getMessage()}\n";
    }
}
echo count($pedidos) . " pedido(s) processado(s).\n";

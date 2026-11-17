<?php
declare(strict_types=1);

// =====================================================================
// router.php - recebe a URL e chama a função (controller) certa.
// As rotas ficam todas registradas em src/routes.php.
// =====================================================================

/** @var array<int, array{metodo:string, regex:string, handler:string, acesso:string}> */
$GLOBALS['ROTAS'] = [];

/**
 * Registra uma rota.
 *   $metodo  GET, POST, PUT, PATCH ou DELETE
 *   $padrao  ex.: /api/produtos/{id}   ({id:num} aceita só números)
 *   $handler nome da função que atende a rota
 *   $acesso  'publico' | 'login' | 'admin'
 */
function rota(string $metodo, string $padrao, string $handler, string $acesso = 'publico'): void
{
    $regex = '#^' . preg_replace_callback(
        '/\{(\w+)(?::(num))?\}/',
        fn(array $m) => '(?P<' . $m[1] . '>' . (($m[2] ?? '') === 'num' ? '\d+' : '[^/]+') . ')',
        $padrao
    ) . '$#';
    $GLOBALS['ROTAS'][] = compact('metodo', 'regex', 'handler', 'acesso');
}

function despachar(string $metodo, string $caminho): void
{
    $caminho = '/' . trim($caminho, '/');
    $outrosMetodos = [];

    foreach ($GLOBALS['ROTAS'] as $r) {
        if (!preg_match($r['regex'], $caminho, $m)) {
            continue;
        }
        if ($r['metodo'] !== $metodo) {
            $outrosMetodos[] = $r['metodo'];
            continue;
        }

        $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

        // 1) CSRF em tudo que altera dados
        if ($metodo !== 'GET') {
            verificar_csrf();
        }
        // 2) Permissão da rota
        if ($r['acesso'] === 'login') {
            exigir_login();
        } elseif ($r['acesso'] === 'admin') {
            exigir_admin();
        }
        // 3) Executa o controller
        ($r['handler'])($params);
        return;
    }

    if ($outrosMetodos) {
        header('Allow: ' . implode(', ', array_unique($outrosMetodos)));
        abortar(405, 'Método não permitido para esta rota.');
    }
    abortar(404, 'Rota não encontrada.');
}

// ---------------------------------------------------------------------
// Cabeçalhos comuns e CORS
// ---------------------------------------------------------------------

function aplicar_cabecalhos_api(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');   // mesma ideia do cache-control do verificar_sessao.php

    $origem     = $_SERVER['HTTP_ORIGIN'] ?? '';
    $permitidas = array_filter(array_map('trim', explode(',', env('CORS_ORIGINS', '') ?? '')));
    if ($origem !== '' && in_array($origem, $permitidas, true)) {
        header('Access-Control-Allow-Origin: ' . $origem);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Vary: Origin');
    }
}

/** Ponto de entrada: trata qualquer erro e devolve sempre JSON. */
function executar(): void
{
    try {
        aplicar_cabecalhos_api();
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($metodo === 'OPTIONS') {          // "preflight" do navegador (CORS)
            responder_vazio(204);
        }
        despachar($metodo, caminho_requisicao());
    } catch (ApiErro $e) {
        responder_erro($e->status, $e->getMessage(), $e->detalhes, $e->codigo);
    } catch (Throwable $e) {
        error_log('[SpeedZone] ' . $e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        $detalhes = APP_DEBUG ? ['excecao' => $e->getMessage(), 'arquivo' => basename($e->getFile()) . ':' . $e->getLine()] : [];
        responder_erro(500, 'Erro interno do servidor.', $detalhes);
    }
}

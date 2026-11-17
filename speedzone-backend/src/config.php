<?php
declare(strict_types=1);

// =====================================================================
// config.php - lê o arquivo .env e expõe as configurações do sistema.
// Parecido com o conexao.php da biblioteca, mas sem deixar senha
// escrita dentro do código.
// =====================================================================

date_default_timezone_set('America/Sao_Paulo');

/** Lê um arquivo .env simples (CHAVE=valor, # comentário, aspas opcionais). */
function carregar_env(string $arquivo): array
{
    $vars = [];
    if (!is_file($arquivo)) {
        return $vars;
    }
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = explode('=', $linha, 2);
        $valor = trim($valor);
        // Remove comentário no fim da linha quando o valor não está entre aspas
        if ($valor !== '' && $valor[0] !== '"' && $valor[0] !== "'") {
            $valor = trim(preg_replace('/\s+#.*$/', '', $valor));
        }
        $valor = trim($valor, "\"'");
        $vars[trim($chave)] = $valor;
    }
    return $vars;
}

/** Devolve uma configuração: primeiro do .env, depois do ambiente do servidor, depois o padrão. */
function env(string $chave, ?string $padrao = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = carregar_env(__DIR__ . '/../.env');
    }
    if (isset($vars[$chave]) && $vars[$chave] !== '') {
        return $vars[$chave];
    }
    $doAmbiente = getenv($chave);
    return ($doAmbiente !== false && $doAmbiente !== '') ? $doAmbiente : $padrao;
}

define('APP_DEBUG', env('APP_ENV', 'producao') === 'desenvolvimento');
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/produtos');
define('UPLOAD_MAX_BYTES', 3 * 1024 * 1024);   // 3 MB por imagem
define('MAX_IMAGENS_PRODUTO', 8);

/** Regras de frete (valores em centavos). Troque por uma API real (Correios, Melhor Envio) quando quiser. */
function regras_frete(): array
{
    return [
        'origem_uf'         => strtoupper(env('FRETE_ORIGEM_UF', 'PR')),
        'gratis_acima'      => (int) round(((float) env('FRETE_GRATIS_ACIMA', '499')) * 100),
        'sul_sudeste'       => ['SP', 'RJ', 'MG', 'ES', 'PR', 'SC', 'RS'],
        'zonas' => [
            'mesma_uf'    => ['base' => 1490, 'por_kg' => 350, 'prazo' => 2],
            'sul_sudeste' => ['base' => 2190, 'por_kg' => 550, 'prazo' => 4],
            'demais'      => ['base' => 3290, 'por_kg' => 850, 'prazo' => 8],
        ],
        'loja_endereco'     => env('LOJA_ENDERECO', 'Retirada na loja'),
    ];
}

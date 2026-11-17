<?php
declare(strict_types=1);

// =====================================================================
// helpers.php - funções de apoio usadas por todo o sistema:
// respostas JSON, erros, leitura da requisição, dinheiro, slug,
// paginação, CPF/CNPJ, CEP e URLs.
// =====================================================================

/** Erro "esperado" da API (validação, não encontrado, sem permissão...). */
class ApiErro extends RuntimeException
{
    public function __construct(
        public int $status,
        string $mensagem,
        public array $detalhes = [],
        public string $codigo = ''
    ) {
        parent::__construct($mensagem);
    }
}

/** Interrompe a requisição devolvendo um erro em JSON. */
function abortar(int $status, string $mensagem, array $detalhes = [], string $codigo = ''): never
{
    throw new ApiErro($status, $mensagem, $detalhes, $codigo);
}

// ---------------------------------------------------------------------
// Respostas
// ---------------------------------------------------------------------

function json_flags(): int
{
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
         | JSON_INVALID_UTF8_SUBSTITUTE;
}

/** Envia {"sucesso": true, "dados": ...} e encerra. */
function responder(mixed $dados = null, int $status = 200, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['sucesso' => true, 'dados' => $dados] + $extra, json_flags());
    exit;
}

/** Envia uma lista paginada: {"dados": [...], "paginacao": {...}}. */
function responder_lista(array $itens, int $total, int $pagina, int $porPagina): never
{
    responder($itens, 200, ['paginacao' => [
        'pagina'        => $pagina,
        'por_pagina'    => $porPagina,
        'total'         => $total,
        'total_paginas' => max(1, (int) ceil($total / max(1, $porPagina))),
    ]]);
}

function responder_vazio(int $status = 204): never
{
    http_response_code($status);
    exit;
}

function responder_erro(int $status, string $mensagem, array $detalhes = [], string $codigo = ''): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $corpo = ['sucesso' => false, 'erro' => $mensagem];
    if ($codigo !== '') {
        $corpo['codigo'] = $codigo;
    }
    if ($detalhes) {
        $corpo['detalhes'] = $detalhes;
    }
    echo json_encode($corpo, json_flags());
    exit;
}

// ---------------------------------------------------------------------
// Requisição
// ---------------------------------------------------------------------

/** Corpo da requisição como array (aceita JSON ou formulário). */
function entrada(): array
{
    static $dados = null;
    if ($dados !== null) {
        return $dados;
    }
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($tipo, 'application/json') !== false) {
        $bruto = file_get_contents('php://input');
        if ($bruto === '' || $bruto === false) {
            return $dados = [];
        }
        $json = json_decode($bruto, true);
        if (!is_array($json)) {
            abortar(400, 'JSON inválido no corpo da requisição.');
        }
        return $dados = $json;
    }
    return $dados = $_POST;
}

/** Parâmetro da URL (?busca=...) já com trim. */
function consulta(string $chave, ?string $padrao = null): ?string
{
    $v = $_GET[$chave] ?? null;
    if (!is_string($v)) {
        return $padrao;
    }
    $v = trim($v);
    return $v === '' ? $padrao : $v;
}

/** Lê ?pagina= e ?por_pagina= com limites seguros. */
function paginacao(int $padrao = 12, int $maximo = 50): array
{
    $pagina    = max(1, (int) (consulta('pagina', '1')));
    $porPagina = (int) consulta('por_pagina', (string) $padrao);
    $porPagina = min($maximo, max(1, $porPagina));
    return [$pagina, $porPagina, ($pagina - 1) * $porPagina];
}

/** Caminho da URL sem a pasta do projeto (ex.: /speedzone-backend/public). */
function caminho_requisicao(): string
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $candidatos = [$base, rtrim(dirname($base), '/')];
    foreach ($candidatos as $prefixo) {
        if ($prefixo !== '' && $prefixo !== '.' && str_starts_with($uri, $prefixo . '/')) {
            return substr($uri, strlen($prefixo));
        }
    }
    return $uri;
}

/** URL pública da pasta /public (para montar o link das imagens). */
function url_base(): string
{
    $configurada = env('APP_URL');
    if ($configurada) {
        return rtrim($configurada, '/');
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base;
}

function url_imagem(?string $arquivo): ?string
{
    return $arquivo ? url_base() . '/uploads/produtos/' . rawurlencode($arquivo) : null;
}

// ---------------------------------------------------------------------
// Dinheiro: todos os cálculos são feitos em CENTAVOS (inteiros).
// Evita erros de arredondamento do tipo 0.1 + 0.2 = 0.30000000000000004
// ---------------------------------------------------------------------

function para_centavos(mixed $valor): int
{
    return (int) round(((float) $valor) * 100);
}

function de_centavos(int $centavos): float
{
    return round($centavos / 100, 2);
}

/** Preço que o cliente paga: promocional (se existir e for menor) ou normal. */
function preco_final_centavos(array $produto): int
{
    $normal = para_centavos($produto['preco']);
    if ($produto['preco_promocional'] !== null) {
        $promo = para_centavos($produto['preco_promocional']);
        if ($promo > 0 && $promo < $normal) {
            return $promo;
        }
    }
    return $normal;
}

// ---------------------------------------------------------------------
// Texto, documentos e CEP
// ---------------------------------------------------------------------

function somente_digitos(string $texto): string
{
    return preg_replace('/\D+/', '', $texto) ?? '';
}

/** "Pastilha de Freio Cerâmica" -> "pastilha-de-freio-ceramica" */
function slugificar(string $texto): string
{
    $texto = sem_acentos($texto);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto) ?? '';
    $texto = trim($texto, '-');
    return $texto !== '' ? mb_substr($texto, 0, 90) : 'item';
}

/** Gera um slug que ainda não existe na tabela (acrescenta -2, -3...). */
function slug_unico(string $tabela, string $texto, ?int $ignorarId = null): string
{
    $tabelasPermitidas = ['produtos', 'categorias', 'marcas'];
    if (!in_array($tabela, $tabelasPermitidas, true)) {
        throw new InvalidArgumentException('Tabela inválida para slug.');
    }
    $base = slugificar($texto);
    $slug = $base;
    $n = 2;
    while (db_valor("SELECT id FROM $tabela WHERE slug = ? AND id <> ?", [$slug, $ignorarId ?? 0]) !== null) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

function validar_cpf(string $cpf): bool
{
    $cpf = somente_digitos($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cpf[$i] * (($t + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$t] !== $digito) {
            return false;
        }
    }
    return true;
}

function validar_cnpj(string $cnpj): bool
{
    $cnpj = somente_digitos($cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }
    foreach ([12, 13] as $t) {
        $pesos = $t === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cnpj[$i] * $pesos[$i];
        }
        $resto = $soma % 11;
        $digito = $resto < 2 ? 0 : 11 - $resto;
        if ((int) $cnpj[$t] !== $digito) {
            return false;
        }
    }
    return true;
}

const UFS_BRASIL = [
    'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA',
    'PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO',
];

/** Descobre o estado pelo CEP (faixas oficiais dos Correios). null = CEP inexistente. */
function uf_por_cep(string $cep): ?string
{
    $cep = somente_digitos($cep);
    if (strlen($cep) !== 8) {
        return null;
    }
    $n = (int) substr($cep, 0, 5);
    $faixas = [
        [1000, 19999, 'SP'], [20000, 28999, 'RJ'], [29000, 29999, 'ES'],
        [30000, 39999, 'MG'], [40000, 48999, 'BA'], [49000, 49999, 'SE'],
        [50000, 56999, 'PE'], [57000, 57999, 'AL'], [58000, 58999, 'PB'],
        [59000, 59999, 'RN'], [60000, 63999, 'CE'], [64000, 64999, 'PI'],
        [65000, 65999, 'MA'], [66000, 68899, 'PA'], [68900, 68999, 'AP'],
        [69000, 69299, 'AM'], [69300, 69399, 'RR'], [69400, 69899, 'AM'],
        [69900, 69999, 'AC'], [70000, 72799, 'DF'], [72800, 72999, 'GO'],
        [73000, 73699, 'DF'], [73700, 76799, 'GO'], [76800, 76999, 'RO'],
        [77000, 77995, 'TO'], [78000, 78899, 'MT'], [78900, 78999, 'RO'],
        [79000, 79999, 'MS'], [80000, 87999, 'PR'], [88000, 89999, 'SC'],
        [90000, 99999, 'RS'],
    ];
    foreach ($faixas as [$ini, $fim, $uf]) {
        if ($n >= $ini && $n <= $fim) {
            return $uf;
        }
    }
    return null;
}

/** Escapa % e _ para o usuário não "quebrar" o LIKE. */
function escapar_like(string $texto): string
{
    return addcslashes($texto, '%_\\');
}

/** Centavos -> texto "129.90" para gravar em colunas DECIMAL sem erro de arredondamento. */
function para_decimal_sql(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

/** Minúsculas e sem acento: "Suspensão" -> "suspensao". */
function sem_acentos(string $texto): string
{
    return strtr(mb_strtolower($texto, 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
}

/** Valor para usar em "coluna LIKE ?" (com % nas pontas). */
function like_param(string $texto): string
{
    return '%' . escapar_like($texto) . '%';
}

/** Trecho SQL de busca: sql_like('p.nome') -> "p.nome LIKE ?" (o MySQL já ignora acento e maiúsculas). */
function sql_like(string $coluna): string
{
    return "$coluna LIKE ?";
}

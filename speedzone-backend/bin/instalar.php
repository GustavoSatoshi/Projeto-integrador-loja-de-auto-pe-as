<?php
declare(strict_types=1);

// =====================================================================
// Instalador: cria o .env, o banco no MySQL, as tabelas, os dados de
// exemplo e o administrador. Pode rodar quantas vezes quiser: só faz o
// que falta.     php bin/instalar.php
// Pré-requisito: o servidor MySQL instalado e ligado (o mesmo que você
// enxerga no MySQL Workbench).
// =====================================================================

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal.\n");
}
$raiz = dirname(__DIR__);
if (!is_file("$raiz/.env")) {
    $modelo = "$raiz/.env.example";
    $padrao = "APP_ENV=desenvolvimento\nAPP_URL=\n\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME=speedzone\nDB_USER=root\nDB_PASS=\"\"\n\nCORS_ORIGINS=\n\n"
            . "OFICINA_DESCONTO_PERCENTUAL=5\nFRETE_ORIGEM_UF=PR\nFRETE_GRATIS_ACIMA=499.00\nPEDIDO_EXPIRA_HORAS=48\nLOJA_ENDERECO=\"Av. Exemplo, 1000 - Curitiba/PR (retirada na loja)\"\n";
    // usa o .env.example se existir; senão cria um .env com valores padrão
    if (file_put_contents("$raiz/.env", is_file($modelo) ? file_get_contents($modelo) : $padrao) === false) {
        echo "\n[ERRO] Não consegui criar o arquivo .env em $raiz (verifique as permissões da pasta).\n";
        exit(1);
    }
    echo "[ok] Arquivo .env criado.\n";
}
require "$raiz/src/config.php";
mysqli_report(MYSQLI_REPORT_OFF);

function perguntar(string $texto, string $padrao = ''): string
{
    echo $texto . ($padrao !== '' ? " [$padrao]" : '') . ': ';
    $r = fgets(STDIN);
    $r = $r === false ? '' : trim($r);
    return $r !== '' ? $r : $padrao;
}
function falhar(string $msg): never
{
    echo "\n[ERRO] $msg\n";
    exit(1);
}
function gravar_env(string $raiz, string $chave, string $valor): void
{
    $arq = "$raiz/.env";
    $txt = file_get_contents($arq);
    $linha = $chave . '="' . $valor . '"';
    $novo = preg_replace_callback('/^' . $chave . '=.*$/m', fn() => $linha, $txt, 1, $n);
    file_put_contents($arq, $n ? $novo : $txt . "\n$linha\n");
}

foreach (['mysqli', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        falhar("A extensão $ext do PHP não está ativa.\n  -> Abra o php.ini do seu PHP e tire o ponto e vírgula da linha ';extension=$ext'.");
    }
}

// 1) Conecta ao MySQL (se a porta estiver errada, tenta 3306 e 3307 e corrige o .env)
function conectar(string $host, string $usuario, string $senha, array $portas): array
{
    $primeiroErro = ['', 0];
    foreach ($portas as $porta) {
        $c = @new mysqli($host, $usuario, $senha, null, $porta);
        if (!$c->connect_errno) {
            return [$c, $porta, $primeiroErro];
        }
        // guarda o erro mais útil: "senha errada" vale mais que "porta sem servidor"
        $semServidor = in_array($primeiroErro[1], [0, 2002, 2003], true);
        if ($semServidor && ($primeiroErro[1] === 0 || !in_array($c->connect_errno, [2002, 2003], true))) {
            $primeiroErro = [$c->connect_error, $c->connect_errno];
        }
    }
    return [null, 0, $primeiroErro];
}

$host    = env('DB_HOST', '127.0.0.1');
$usuario = env('DB_USER', 'root');
$portaEnv = (int) env('DB_PORT', '3306');
$portas  = array_values(array_unique([$portaEnv, 3306, 3307]));
[$db, $porta, $erro] = conectar($host, $usuario, env('DB_PASS', ''), $portas);

if ($db === null && $erro[1] === 1045) {          // senha incorreta: pergunta e guarda no .env
    echo "A senha do usuário '$usuario' do MySQL no arquivo .env não funcionou.\n";
    $senha = perguntar("Digite a senha do MySQL (a mesma que você usa no Workbench)");
    [$db, $porta, $erro] = conectar($host, $usuario, $senha, $portas);
    if ($db !== null) {
        gravar_env($raiz, 'DB_PASS', $senha);
        echo "[ok] Senha salva no .env.\n";
    }
}
if ($db === null) {
    falhar("Não consegui conectar ao MySQL ({$erro[0]}).\n  -> Confirme que o servidor MySQL está ligado (no Windows: serviço \"MySQL80\", ou o MySQL Workbench mostra se a instância está rodando).\n  -> Confira DB_HOST, DB_PORT, DB_USER e DB_PASS no arquivo .env.");
}
if ($porta !== $portaEnv) {
    gravar_env($raiz, 'DB_PORT', (string) $porta);
    echo "[ok] MySQL encontrado na porta $porta (o .env foi corrigido).\n";
}
$db->set_charset('utf8mb4');
echo "[ok] Conectado ao MySQL.\n";

// 2) Cria o banco e as tabelas, se ainda não existirem
$nome = preg_replace('/[^A-Za-z0-9_]/', '', env('DB_NAME', 'speedzone'));
$tem = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$nome' AND table_name = 'usuarios'")->fetch_row()[0];

function rodar_sql(mysqli $db, string $arquivo, string $nome): void
{
    $sql = file_get_contents($arquivo);
    $sql = preg_replace('/^(CREATE DATABASE IF NOT EXISTS|USE)\s+speedzone/mi', '$1 ' . $nome, $sql);
    if (!$db->multi_query($sql)) {
        falhar('Erro no ' . basename($arquivo) . ': ' . $db->error);
    }
    do {
        if ($r = $db->store_result()) {
            $r->free();
        }
    } while ($db->more_results() && $db->next_result());
    if ($db->errno) {
        falhar('Erro no ' . basename($arquivo) . ': ' . $db->error);
    }
}

if ((int) $tem === 0) {
    rodar_sql($db, "$raiz/database/schema.sql", $nome);
    echo "[ok] Banco '$nome' e tabelas criados.\n";
    if (strtolower(perguntar('Carregar produtos de exemplo para testar a loja? (s/n)', 's'))[0] !== 'n') {
        rodar_sql($db, "$raiz/database/seed.sql", $nome);
        echo "[ok] Produtos de exemplo carregados.\n";
    }
} else {
    echo "[ok] Banco '$nome' já existe.\n";
}
$db->select_db($nome);

// 3) Administrador
if ((int) $db->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetch_row()[0] === 0) {
    echo "\nVamos criar o administrador do painel.\n";
    $nomeAdm = perguntar('Nome', 'Administrador');
    $email = mb_strtolower(perguntar('E-mail de acesso', 'admin@speedzone.local'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        falhar('E-mail inválido.');
    }
    $senha = perguntar('Senha do painel (mín. 8 caracteres; Enter = gerar uma automática)');
    $gerada = $senha === '';
    if ($gerada) {
        $senha = bin2hex(random_bytes(5));
    }
    if (mb_strlen($senha) < 8 || mb_strlen($senha) > 72) {
        falhar('Senha inválida: use de 8 a 72 caracteres.');
    }
    $st = $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'admin') ON DUPLICATE KEY UPDATE perfil = 'admin', senha_hash = VALUES(senha_hash), nome = VALUES(nome), ativo = 1");
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $st->bind_param('sss', $nomeAdm, $email, $hash);
    $st->execute();
    echo "[ok] Administrador criado.\n\n  E-mail: $email\n  Senha:  $senha" . ($gerada ? "   <- anote, ela não será mostrada de novo" : '') . "\n";
} else {
    echo "[ok] Já existe um administrador.\n";
}

// 4) Imagens dos produtos de exemplo (vincula uma vez só; depois troque pelas fotos reais no painel)
require "$raiz/bin/imagens_exemplo.php";
// (é seguro rodar sempre: só liga o que falta ou corrige nome de arquivo que não existe)
$qtd = vincular_imagens_exemplo($db, $raiz);
if ($qtd > 0) {
    echo "[ok] Imagens vinculadas/corrigidas em $qtd produto(s).\n";
}

echo "\nTudo pronto!  Loja: http://localhost:8000/   Painel: http://localhost:8000/admin/index.html\n";

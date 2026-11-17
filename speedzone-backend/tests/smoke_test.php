<?php
declare(strict_types=1);

// =====================================================================
// Teste de fumaça da API SpeedZone: percorre o fluxo real de uso.
//
//   php tests/smoke_test.php http://localhost:8000 admin@email.com SenhaDoAdmin
//
// Roda contra um banco de DESENVOLVIMENTO (cria clientes, pedidos e
// um produto de teste). Precisa da extensão curl do PHP (já vem no XAMPP).
// =====================================================================

$base      = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$adminMail = $argv[2] ?? null;
$adminPass = $argv[3] ?? null;

$ok = 0;
$falhas = [];

function check(string $descricao, bool $condicao, string $detalhe = ''): void
{
    global $ok, $falhas;
    if ($condicao) {
        $ok++;
        echo "  [ok]  $descricao\n";
    } else {
        $falhas[] = $descricao;
        echo "  [FALHA] $descricao" . ($detalhe !== '' ? "  -> $detalhe" : '') . "\n";
    }
}

function titulo(string $t): void
{
    echo "\n== $t\n";
}

/** Cliente HTTP com cookie de sessão próprio (simula um navegador). */
class Cliente
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'szjar');
    }

    public function req(string $metodo, string $caminho, ?array $corpo = null, array $extra = [], bool $comCsrf = true): array
    {
        if ($comCsrf && $metodo !== 'GET' && $this->csrf === null) {
            $r = $this->req('GET', '/api/csrf');
            $this->csrf = $r['json']['dados']['csrf_token'] ?? null;
        }
        $ch = curl_init($this->base . $caminho);
        $headers = ['Accept: application/json'];
        if ($metodo !== 'GET' && $comCsrf && $this->csrf) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($corpo !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        foreach ($extra as $k => $v) {
            $opts[$k] = $v;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode((string) $resp, true), 'raw' => $resp];
    }

    /** Atualiza o token CSRF (necessário depois de login/cadastro, que renovam a sessão). */
    public function usarCsrfDe(array $r): void
    {
        $this->csrf = $r['json']['dados']['csrf_token'] ?? $this->csrf;
    }

    public function upload(string $caminho, string $arquivo, string $mime): array
    {
        return $this->req('POST', $caminho, null, [
            CURLOPT_POSTFIELDS => ['imagem' => new CURLFile($arquivo, $mime, basename($arquivo)), 'principal' => '1'],
        ]);
    }
}

// ---- Geradores de CPF/CNPJ válidos (para os testes) -----------------

function gerar_cpf(): string
{
    $n = [];
    for ($i = 0; $i < 9; $i++) {
        $n[] = random_int(0, 9);
    }
    for ($t = 9; $t < 11; $t++) {
        $s = 0;
        for ($i = 0; $i < $t; $i++) {
            $s += $n[$i] * (($t + 1) - $i);
        }
        $n[] = ((10 * $s) % 11) % 10;
    }
    return implode('', $n);
}

function gerar_cnpj(): string
{
    $n = [];
    for ($i = 0; $i < 8; $i++) {
        $n[] = random_int(0, 9);
    }
    array_push($n, 0, 0, 0, 1);
    foreach ([12, 13] as $t) {
        $p = $t === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
        $s = 0;
        for ($i = 0; $i < $t; $i++) {
            $s += $n[$i] * $p[$i];
        }
        $r = $s % 11;
        $n[] = $r < 2 ? 0 : 11 - $r;
    }
    return implode('', $n);
}

$sufixo = bin2hex(random_bytes(3));

// =====================================================================
titulo('1. Saúde, CSRF e catálogo público');
$anon = new Cliente($base);

$r = $anon->req('GET', '/api/health');
check('GET /api/health responde 200 e banco conectado', $r['status'] === 200 && ($r['json']['dados']['banco'] ?? '') === 'conectado', (string) $r['raw']);

$r = $anon->req('GET', '/api/produtos');
$total = $r['json']['paginacao']['total'] ?? 0;
check('Lista de produtos responde 200 com paginação', $r['status'] === 200 && $total > 0);
$ids = array_column($r['json']['dados'] ?? [], 'id');

$r = $anon->req('GET', '/api/produtos?busca=pastilha');
check('Busca por "pastilha" encontra a pastilha', ($r['json']['paginacao']['total'] ?? 0) >= 1);

$r = $anon->req('GET', '/api/produtos?categoria=freios');
check('Categoria principal "freios" inclui as subcategorias', ($r['json']['paginacao']['total'] ?? 0) >= 2);

$r = $anon->req('GET', '/api/produtos?ordem=menor_preco&por_pagina=50');
$precos = array_column($r['json']['dados'] ?? [], 'preco_final');
$ordenado = $precos;
sort($ordenado);
check('Ordenação por menor preço está crescente', $precos === $ordenado && count($precos) > 1);

$r = $anon->req('GET', '/api/produtos?veiculo_id=4&por_pagina=50');   // Fiat Uno
$slugs = array_column($r['json']['dados'] ?? [], 'slug');
check('Filtro "serve no meu carro" traz vela e filtro de óleo', in_array('vela-de-ignicao-iridium', $slugs, true) && in_array('filtro-de-oleo-do-motor', $slugs, true));
check('Filtro por veículo traz peça universal (óleo 5W30)', in_array('oleo-de-motor-5w30-sintetico-1l', $slugs, true));
check('Filtro por veículo NÃO traz peça de outro carro (disco do Gol/Onix)', !in_array('disco-de-freio-ventilado-dianteiro-par', $slugs, true));

$r = $anon->req('GET', "/api/produtos?busca=" . rawurlencode("' OR 1=1 -- "));
check('Tentativa de SQL Injection na busca é inofensiva (200, 0 resultados)', $r['status'] === 200 && ($r['json']['paginacao']['total'] ?? -1) === 0);

$r = $anon->req('GET', '/api/produtos/pastilha-de-freio-dianteira-ceramica');
check('Detalhe por slug traz preço promocional, imagens, compatibilidade e relacionados',
    $r['status'] === 200 && ($r['json']['dados']['em_promocao'] ?? false) === true
    && isset($r['json']['dados']['veiculos_compativeis'][0]) && is_array($r['json']['dados']['relacionados'] ?? null));

$r = $anon->req('GET', '/api/produtos/produto-descontinuado-exemplo-inativo');
check('Produto inativo não aparece na loja (404)', $r['status'] === 404);

$r = $anon->req('GET', '/api/categorias');
check('Árvore de categorias tem subcategorias', ($r['json']['dados'][0]['filhas'][0]['nome'] ?? null) !== null);

$r = $anon->req('GET', '/api/veiculos/modelos?montadora=Volkswagen');
check('Modelos da Volkswagen listados', in_array('Gol', $r['json']['dados'] ?? [], true));

// =====================================================================
titulo('2. Carrinho (sem login) e proteção CSRF');
$c1 = new Cliente($base);
$r = $c1->req('POST', '/api/carrinho/itens', ['produto_id' => 1, 'quantidade' => 1], [], false);
check('POST sem token CSRF é bloqueado (403)', $r['status'] === 403 && ($r['json']['codigo'] ?? '') === 'csrf_invalido');

$r = $c1->req('POST', '/api/carrinho/itens', ['produto_id' => 1, 'quantidade' => 2]);
check('Adicionar 2 pastilhas ao carrinho (201)', $r['status'] === 201 && ($r['json']['dados']['quantidade_total'] ?? 0) === 2, (string) $r['raw']);
check('Carrinho usa o preço promocional vindo do banco (109,90 x 2 = 219,80)', abs(($r['json']['dados']['subtotal'] ?? 0) - 219.80) < 0.001);

$r = $c1->req('POST', '/api/carrinho/itens', ['produto_id' => 10, 'quantidade' => 5]);
check('Quantidade acima do estoque é recusada (422)', $r['status'] === 422);

$r = $c1->req('POST', '/api/carrinho/itens', ['produto_id' => 11, 'quantidade' => 1]);
check('Produto sem estoque não entra no carrinho (422)', $r['status'] === 422);

$r = $c1->req('POST', '/api/carrinho/itens', ['produto_id' => 13, 'quantidade' => 1]);
check('Produto inativo não entra no carrinho (404)', $r['status'] === 404);

$r = $c1->req('PUT', '/api/carrinho/itens/1', ['quantidade' => 1]);
check('Atualizar quantidade para 1', ($r['json']['dados']['quantidade_total'] ?? 0) === 1);

$r = $c1->req('POST', '/api/frete/calcular', ['cep' => '80010-000']);
$entrega = $r['json']['dados']['opcoes'][0] ?? [];
check('Frete para Curitiba (mesma UF da loja) = R$ 18,40, 2 dias', ($entrega['valor'] ?? 0) == 18.40 && ($entrega['prazo_dias'] ?? 0) === 2, (string) $r['raw']);
$r = $c1->req('POST', '/api/frete/calcular', ['cep' => '69900-000']);
check('Frete para o Acre é mais caro e demorado', ($r['json']['dados']['opcoes'][0]['valor'] ?? 0) > 18.40 && ($r['json']['dados']['opcoes'][0]['prazo_dias'] ?? 0) === 8);
$r = $c1->req('POST', '/api/frete/calcular', ['cep' => '00000-000']);
check('CEP inexistente é recusado (422)', $r['status'] === 422);

// =====================================================================
titulo('3. Cadastro, login e conta');
$cpf = gerar_cpf();
$email = "cliente_$sufixo@teste.com";

$r = $c1->req('POST', '/api/auth/cadastro', ['nome' => 'Cliente Teste', 'email' => $email, 'senha' => 'SenhaForte123', 'documento' => '111.111.111-11']);
check('CPF inválido é recusado (422)', $r['status'] === 422 && isset($r['json']['detalhes']['documento']));

$r = $c1->req('POST', '/api/auth/cadastro', ['nome' => 'Cliente Teste', 'email' => $email, 'senha' => '123', 'documento' => $cpf]);
check('Senha curta é recusada (422)', $r['status'] === 422 && isset($r['json']['detalhes']['senha']));

$r = $c1->req('POST', '/api/auth/cadastro', ['nome' => 'Cliente Teste', 'email' => $email, 'senha' => 'SenhaForte123', 'documento' => $cpf, 'telefone' => '(41) 99999-1234']);
check('Cadastro válido (201) já deixa o cliente logado', $r['status'] === 201 && ($r['json']['dados']['usuario']['email'] ?? '') === $email, (string) $r['raw']);
check('Resposta do cadastro NÃO expõe a senha/hash', !str_contains((string) $r['raw'], 'senha'));
$c1->usarCsrfDe($r);

$c2 = new Cliente($base);
$r = $c2->req('POST', '/api/auth/cadastro', ['nome' => 'Outro', 'email' => $email, 'senha' => 'SenhaForte123', 'documento' => gerar_cpf()]);
check('E-mail duplicado é recusado (409)', $r['status'] === 409);

$r = $c1->req('GET', '/api/auth/me');
check('GET /api/auth/me devolve o usuário logado', ($r['json']['dados']['autenticado'] ?? false) === true);

$r = $c1->req('POST', '/api/conta/enderecos', [
    'destinatario' => 'Cliente Teste', 'cep' => '80010-000', 'logradouro' => 'Rua XV de Novembro', 'numero' => '100',
    'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'pr',
]);
$enderecoId = $r['json']['dados']['id'] ?? 0;
check('Endereço criado (201), UF normalizada e primeiro vira padrão', $r['status'] === 201 && ($r['json']['dados']['uf'] ?? '') === 'PR' && ($r['json']['dados']['padrao'] ?? false) === true, (string) $r['raw']);

// =====================================================================
titulo('4. Checkout, estoque e cancelamento');
$antes = $anon->req('GET', '/api/produtos/1')['json']['dados']['estoque'] ?? null;

$r = $c1->req('POST', '/api/pedidos', ['tipo_entrega' => 'entrega', 'forma_pagamento' => 'pix']);
check('Entrega sem endereço é recusada (422)', $r['status'] === 422);

$r = $c1->req('POST', '/api/pedidos', ['tipo_entrega' => 'entrega', 'forma_pagamento' => 'pix', 'endereco_id' => $enderecoId, 'observacao' => 'Ligar antes']);
$pedido = $r['json']['dados'] ?? [];
check('Pedido criado (201) com código SZ-XXXXXXXX', $r['status'] === 201 && preg_match('/^SZ-[0-9A-F]{8}$/', $pedido['codigo'] ?? '') === 1, (string) $r['raw']);
check('Total = produtos 109,90 + frete 18,40 = 128,30', abs(($pedido['total'] ?? 0) - 128.30) < 0.001, 'total=' . ($pedido['total'] ?? '?'));
check('Status inicial é aguardando_pagamento', ($pedido['status'] ?? '') === 'aguardando_pagamento');

$depois = $anon->req('GET', '/api/produtos/1')['json']['dados']['estoque'] ?? null;
check('Estoque foi reduzido em 1 unidade', $antes !== null && $depois === $antes - 1, "antes=$antes depois=$depois");

$r = $c1->req('GET', '/api/carrinho');
check('Carrinho ficou vazio depois da compra', ($r['json']['dados']['quantidade_total'] ?? -1) === 0);

$r = $c1->req('POST', '/api/pedidos', ['tipo_entrega' => 'retirada', 'forma_pagamento' => 'pix']);
check('Finalizar com carrinho vazio é recusado (422)', $r['status'] === 422);

$codigo = $pedido['codigo'] ?? 'SZ-00000000';
$r = $c1->req('GET', "/api/pedidos/$codigo");
check('Cliente vê o próprio pedido com itens e histórico', $r['status'] === 200 && count($r['json']['dados']['itens'] ?? []) === 1 && count($r['json']['dados']['historico'] ?? []) === 1);

$r = $c1->req('POST', "/api/pedidos/$codigo/cancelar");
check('Cliente cancela pedido não pago (200)', $r['status'] === 200 && ($r['json']['dados']['status'] ?? '') === 'cancelado');
$apos = $anon->req('GET', '/api/produtos/1')['json']['dados']['estoque'] ?? null;
check('Cancelamento devolveu o produto ao estoque', $apos === $antes, "antes=$antes apos=$apos");
$r = $c1->req('POST', "/api/pedidos/$codigo/cancelar");
check('Cancelar de novo é recusado (409)', $r['status'] === 409);

// Pedido para retirada na loja (fica para o teste de admin)
$c1->req('POST', '/api/carrinho/itens', ['produto_id' => 4, 'quantidade' => 2]);
$r = $c1->req('POST', '/api/pedidos', ['tipo_entrega' => 'retirada', 'forma_pagamento' => 'cartao']);
$pedidoRetirada = $r['json']['dados'] ?? [];
check('Pedido para retirada na loja: frete zero', $r['status'] === 201 && ($pedidoRetirada['frete'] ?? -1) == 0 && isset($pedidoRetirada['retirada_na_loja']), (string) $r['raw']);

// Pedido para entrega que será enviado
$c1->req('POST', '/api/carrinho/itens', ['produto_id' => 7, 'quantidade' => 1]);
$r = $c1->req('POST', '/api/pedidos', ['tipo_entrega' => 'entrega', 'forma_pagamento' => 'boleto', 'endereco_id' => $enderecoId]);
$pedidoEntrega = $r['json']['dados'] ?? [];
check('Compra acima de R$ 499 tem frete grátis', $r['status'] === 201 && ($pedidoEntrega['frete'] ?? -1) == 0 && ($pedidoEntrega['total'] ?? 0) == 499.00, (string) $r['raw']);

// =====================================================================
titulo('5. Segurança e permissões');
$r = $anon->req('GET', '/api/pedidos');
check('Listar pedidos sem login (401)', $r['status'] === 401);
$r = $anon->req('GET', '/api/admin/dashboard');
check('Painel admin sem login (401)', $r['status'] === 401);
$r = $c1->req('GET', '/api/admin/dashboard');
check('Painel admin com conta de cliente (403)', $r['status'] === 403);
$r = $c1->req('POST', '/api/admin/produtos', ['sku' => 'X', 'nome' => 'Hack', 'categoria_id' => 1, 'preco' => 1]);
check('Cliente não consegue criar produto (403)', $r['status'] === 403);

$c3 = new Cliente($base);
$r = $c3->req('POST', '/api/auth/cadastro', ['nome' => 'Intruso', 'email' => "intruso_$sufixo@teste.com", 'senha' => 'SenhaForte123', 'documento' => gerar_cpf()]);
$c3->usarCsrfDe($r);
$r = $c3->req('GET', '/api/pedidos/' . ($pedidoEntrega['codigo'] ?? 'SZ-00000000'));
check('Cliente NÃO enxerga pedido de outra pessoa (404)', $r['status'] === 404);

$cx = new Cliente($base);
$ultimo = null;
for ($i = 0; $i < 6; $i++) {
    $ultimo = $cx->req('POST', '/api/auth/login', ['email' => $email, 'senha' => 'errada' . $i]);
}
check('Após 5 senhas erradas o login é bloqueado (429)', $ultimo['status'] === 429);
$r = $cx->req('POST', '/api/auth/login', ['email' => $email, 'senha' => 'SenhaForte123']);
check('Bloqueio vale até para a senha correta (429)', $r['status'] === 429);

$r = $c1->req('POST', '/api/auth/logout');
$r = $c1->req('GET', '/api/auth/me');
check('Logout encerra a sessão', ($r['json']['dados']['autenticado'] ?? true) === false);

// =====================================================================
titulo('6. Oficina: aprovação e desconto');
$co = new Cliente($base);
$r = $co->req('POST', '/api/auth/cadastro', ['nome' => 'Dono da Oficina', 'email' => "oficina_$sufixo@teste.com", 'senha' => 'SenhaForte123',
    'tipo_cliente' => 'oficina', 'documento' => gerar_cnpj(), 'empresa' => 'Auto Center Teste']);
check('Cadastro de oficina com CNPJ válido (201)', $r['status'] === 201, (string) $r['raw']);
$oficinaId = $r['json']['dados']['usuario']['id'] ?? 0;
$co->usarCsrfDe($r);
$co->req('POST', '/api/carrinho/itens', ['produto_id' => 3, 'quantidade' => 1]);
$r = $co->req('GET', '/api/carrinho');
check('Oficina ainda NÃO aprovada: sem desconto', ($r['json']['dados']['desconto_oficina'] ?? -1) == 0);

// =====================================================================
titulo('6b. Conta: perfil, senha e endereços');
$c4 = new Cliente($base);
$mail4 = "conta_$sufixo@teste.com";
$r = $c4->req('POST', '/api/auth/cadastro', ['nome' => 'Conta Teste', 'email' => $mail4, 'senha' => 'SenhaInicial1', 'documento' => gerar_cpf()]);
$c4->usarCsrfDe($r);

$r = $c4->req('PUT', '/api/conta', ['nome' => 'Nome Novo', 'telefone' => '(41) 98888-7777']);
check('Atualizar perfil (nome e telefone)', ($r['json']['dados']['nome'] ?? '') === 'Nome Novo' && ($r['json']['dados']['telefone'] ?? '') === '41988887777', (string) $r['raw']);
$r = $c4->req('PUT', '/api/conta', ['perfil' => 'admin', 'oficina_aprovada' => true, 'email' => 'x@x.com']);
$r = $c4->req('GET', '/api/conta');
check('Cliente NÃO consegue se promover a admin nem mudar e-mail (campos ignorados)', ($r['json']['dados']['perfil'] ?? '') === 'cliente' && ($r['json']['dados']['email'] ?? '') === $mail4 && ($r['json']['dados']['oficina_aprovada'] ?? true) === false);

$r = $c4->req('PUT', '/api/conta/senha', ['senha_atual' => 'senhaerrada', 'nova_senha' => 'NovaSenha123']);
check('Trocar senha com a senha atual errada (422)', $r['status'] === 422);
$r = $c4->req('PUT', '/api/conta/senha', ['senha_atual' => 'SenhaInicial1', 'nova_senha' => 'NovaSenha123']);
check('Trocar senha com a senha atual correta (200)', $r['status'] === 200, (string) $r['raw']);
$c4->req('POST', '/api/auth/logout');
$c4b = new Cliente($base);
$r = $c4b->req('POST', '/api/auth/login', ['email' => $mail4, 'senha' => 'SenhaInicial1']);
check('Senha antiga não funciona mais (401)', $r['status'] === 401);
$r = $c4b->req('POST', '/api/auth/login', ['email' => $mail4, 'senha' => 'NovaSenha123']);
check('Login com a senha nova (200)', $r['status'] === 200);
$c4b->usarCsrfDe($r);

$end = ['destinatario' => 'Conta Teste', 'cep' => '01310-100', 'logradouro' => 'Av. Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista', 'cidade' => 'São Paulo', 'uf' => 'SP'];
$e1 = $c4b->req('POST', '/api/conta/enderecos', $end)['json']['dados']['id'] ?? 0;
$e2r = $c4b->req('POST', '/api/conta/enderecos', $end + ['apelido' => 'Trabalho', 'padrao' => true]);
$e2 = $e2r['json']['dados']['id'] ?? 0;
$lista = $c4b->req('GET', '/api/conta/enderecos')['json']['dados'] ?? [];
$padroes = array_values(array_filter($lista, fn($e) => $e['padrao']));
check('Só um endereço é padrão por vez (o novo)', count($padroes) === 1 && $padroes[0]['id'] === $e2);
$r = $c4b->req('PUT', "/api/conta/enderecos/$e1", ['numero' => '2000']);
check('Editar endereço', ($r['json']['dados']['numero'] ?? '') === '2000');
$r = $c4b->req('POST', '/api/conta/enderecos', array_merge($end, ['cep' => '00000-000']));
check('Endereço com CEP inexistente é recusado (422)', $r['status'] === 422);
$r = $c3->req('PUT', "/api/conta/enderecos/$e1", ['numero' => '1']);
check('Cliente NÃO edita endereço de outra pessoa (404)', $r['status'] === 404);
$r = $c4b->req('DELETE', "/api/conta/enderecos/$e2");
$lista = $c4b->req('GET', '/api/conta/enderecos')['json']['dados'] ?? [];
check('Ao apagar o padrão, outro endereço assume', count($lista) === 1 && $lista[0]['padrao'] === true);

$c4b->req('POST', '/api/carrinho/itens', ['produto_id' => 5, 'quantidade' => 2]);
$r = $c4b->req('POST', '/api/pedidos', ['tipo_entrega' => 'entrega', 'forma_pagamento' => 'pix', 'endereco_id' => $e1]);
check('Entrega para São Paulo usa a faixa Sul/Sudeste (prazo 4 dias)', $r['status'] === 201 && ($r['json']['dados']['prazo_dias'] ?? 0) === 4, (string) $r['raw']);
$r = $c4b->req('GET', '/api/pedidos');
check('Cliente lista os próprios pedidos (paginado, com qtd de itens)', ($r['json']['paginacao']['total'] ?? 0) === 1 && ($r['json']['dados'][0]['quantidade_itens'] ?? 0) === 2, (string) $r['raw']);
$r = $c4b->req('GET', '/api/pedidos?status=cancelado');
check('Filtro de status na listagem do cliente', ($r['json']['paginacao']['total'] ?? -1) === 0);

// =====================================================================
if ($adminMail && $adminPass) {
    titulo('7. Painel administrativo');
    $adm = new Cliente($base);
    $r = $adm->req('POST', '/api/auth/login', ['email' => $adminMail, 'senha' => $adminPass]);
    check('Login do administrador', $r['status'] === 200 && ($r['json']['dados']['usuario']['perfil'] ?? '') === 'admin', (string) $r['raw']);
    $adm->usarCsrfDe($r);

    $r = $adm->req('GET', '/api/admin/dashboard');
    check('Dashboard responde com indicadores', $r['status'] === 200 && isset($r['json']['dados']['vendas_ultimos_7_dias'][6]), (string) $r['raw']);
    check('Dashboard lista a oficina pendente', ($r['json']['dados']['oficinas_pendentes'] ?? 0) >= 1);
    check('Dashboard lista produto com estoque baixo (aditivo)', in_array('SZ-LUB-0002', array_column($r['json']['dados']['estoque_baixo'] ?? [], 'sku'), true));

    // -- Oficina
    $r = $adm->req('PATCH', "/api/admin/usuarios/$oficinaId", ['oficina_aprovada' => true]);
    check('Admin aprova a oficina', $r['status'] === 200 && ($r['json']['dados']['oficina_aprovada'] ?? false) === true, (string) $r['raw']);
    $r = $co->req('GET', '/api/carrinho');
    check('Oficina aprovada: 5% de desconto (259,00 -> 12,95)', abs(($r['json']['dados']['desconto_oficina'] ?? 0) - 12.95) < 0.001, (string) $r['raw']);

    // -- Produto: criar, atualizar, imagem, estoque
    $skuTeste = "SZ-TST-$sufixo";
    $r = $adm->req('POST', '/api/admin/produtos', [
        'sku' => $skuTeste, 'nome' => 'Pivô de Suspensão Teste', 'categoria_id' => 22, 'marca_id' => 4,
        'preco' => '149,90', 'estoque' => 5, 'peso_kg' => 0.7, 'descricao' => 'Produto criado pelo teste.',
        'especificacoes' => ['Posição' => 'Dianteira', 'Material' => 'Aço'], 'veiculo_ids' => [1, 2, 3],
    ]);
    $prod = $r['json']['dados'] ?? [];
    $pid = $prod['id'] ?? 0;
    check('Produto criado (201) com slug automático, specs e compatibilidade',
        $r['status'] === 201 && str_starts_with($prod['slug'] ?? '', 'pivo-de-suspensao-teste'),
        (string) $r['raw']);
    check('Preço "149,90" (vírgula) foi aceito', ($prod['preco'] ?? 0) == 149.90);
    check('Compatibilidade com 3 veículos gravada', count($prod['veiculo_ids'] ?? []) === 3);

    $r = $adm->req('POST', '/api/admin/produtos', ['sku' => $skuTeste, 'nome' => 'Duplicado', 'categoria_id' => 22, 'preco' => 10]);
    check('SKU duplicado é recusado (409)', $r['status'] === 409);
    $r = $adm->req('POST', '/api/admin/produtos', ['sku' => "$skuTeste-B", 'nome' => 'Promo errada', 'categoria_id' => 22, 'preco' => 10, 'preco_promocional' => 20]);
    check('Preço promocional maior que o normal é recusado (422)', $r['status'] === 422);
    $r = $adm->req('POST', '/api/admin/produtos', ['sku' => "$skuTeste-C", 'nome' => 'Sem categoria', 'categoria_id' => 9999, 'preco' => 10]);
    check('Categoria inexistente é recusada (422)', $r['status'] === 422);

    $r = $adm->req('PUT', "/api/admin/produtos/$pid", ['preco' => 139.90, 'preco_promocional' => 129.90, 'destaque' => true]);
    check('Atualização parcial (preço, promoção, destaque)', $r['status'] === 200 && ($r['json']['dados']['preco_final'] ?? 0) == 129.90 && ($r['json']['dados']['destaque'] ?? false) === true, (string) $r['raw']);
    check('Atualização parcial não mexeu no nome nem no estoque', ($r['json']['dados']['nome'] ?? '') === 'Pivô de Suspensão Teste' && ($r['json']['dados']['estoque'] ?? 0) === 5);
    $r = $adm->req('PUT', "/api/admin/produtos/$pid", ['preco_promocional' => null]);
    check('Enviar null remove a promoção', ($r['json']['dados']['em_promocao'] ?? true) === false);

    $r = $adm->req('POST', "/api/admin/produtos/$pid/estoque", ['tipo' => 'entrada', 'quantidade' => 10, 'motivo' => 'Compra de fornecedor']);
    check('Entrada de estoque: 5 + 10 = 15', ($r['json']['dados']['estoque'] ?? 0) === 15, (string) $r['raw']);
    $r = $adm->req('POST', "/api/admin/produtos/$pid/estoque", ['tipo' => 'saida', 'quantidade' => 100]);
    check('Saída maior que o estoque é recusada (422)', $r['status'] === 422);
    $r = $adm->req('POST', "/api/admin/produtos/$pid/estoque", ['tipo' => 'ajuste', 'quantidade' => 12, 'motivo' => 'Inventário']);
    check('Ajuste define o novo total (12)', ($r['json']['dados']['estoque'] ?? 0) === 12);
    $r = $adm->req('GET', "/api/admin/produtos/$pid/estoque");
    check('Histórico de estoque registra as 3 movimentações', ($r['json']['paginacao']['total'] ?? 0) === 3, (string) $r['raw']);

    // -- Upload de imagem
    $png = sys_get_temp_dir() . '/sz_teste.png';
    $im = imagecreatetruecolor(40, 40);
    imagepng($im, $png);
    $r = $adm->upload("/api/admin/produtos/$pid/imagens", $png, 'image/png');
    check('Upload de imagem PNG válida (201)', $r['status'] === 201 && count($r['json']['dados']['imagens'] ?? []) === 1, (string) $r['raw']);
    $urlImg = $r['json']['dados']['imagens'][0]['url'] ?? '';
    $imgId = $r['json']['dados']['imagens'][0]['id'] ?? 0;
    $head = @get_headers($urlImg);
    check('A imagem enviada é acessível pela URL pública', $head && str_contains($head[0], '200'), $urlImg);

    $falso = sys_get_temp_dir() . '/sz_falso.php';
    file_put_contents($falso, '<?php echo "invasor"; ?>');
    $r = $adm->upload("/api/admin/produtos/$pid/imagens", $falso, 'image/png');
    check('Arquivo PHP disfarçado de imagem é recusado (422)', $r['status'] === 422, (string) $r['raw']);

    // -- Pedidos
    $r = $adm->req('GET', '/api/admin/pedidos?status=aguardando_pagamento');
    check('Admin lista pedidos aguardando pagamento', ($r['json']['paginacao']['total'] ?? 0) >= 2);
    $idEntrega = $pedidoEntrega['id'] ?? 0;
    $idRetirada = $pedidoRetirada['id'] ?? 0;

    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'enviado']);
    check('Pular etapas é bloqueado (aguardando -> enviado = 409)', $r['status'] === 409);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'pago']);
    check('Admin confirma o pagamento', $r['status'] === 200 && ($r['json']['dados']['status'] ?? '') === 'pago' && !empty($r['json']['dados']['pago_em']), (string) $r['raw']);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'em_separacao']);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'pronto_retirada']);
    check('Pedido de entrega não vira "pronto para retirada" (422)', $r['status'] === 422 || $r['status'] === 409);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'enviado', 'codigo_rastreio' => 'BR123456789SZ']);
    check('Pedido enviado com código de rastreio', ($r['json']['dados']['status'] ?? '') === 'enviado' && ($r['json']['dados']['codigo_rastreio'] ?? '') === 'BR123456789SZ', (string) $r['raw']);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'entregue']);
    check('Pedido entregue; histórico com 5 etapas', ($r['json']['dados']['status'] ?? '') === 'entregue' && count($r['json']['dados']['historico'] ?? []) === 5, (string) $r['raw']);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idEntrega/status", ['status' => 'cancelado']);
    check('Pedido entregue não pode ser cancelado (409)', $r['status'] === 409);

    $adm->req('PATCH', "/api/admin/pedidos/$idRetirada/status", ['status' => 'pago']);
    $adm->req('PATCH', "/api/admin/pedidos/$idRetirada/status", ['status' => 'em_separacao']);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idRetirada/status", ['status' => 'enviado']);
    check('Pedido de retirada não pode ser "enviado" (422)', $r['status'] === 422);
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idRetirada/status", ['status' => 'pronto_retirada']);
    check('Pedido de retirada vira "pronto para retirada"', ($r['json']['dados']['status'] ?? '') === 'pronto_retirada');

    $estVela = $anon->req('GET', '/api/produtos/4')['json']['dados']['estoque'] ?? null;
    $r = $adm->req('PATCH', "/api/admin/pedidos/$idRetirada/status", ['status' => 'cancelado', 'observacao' => 'Cliente desistiu']);
    $estVela2 = $anon->req('GET', '/api/produtos/4')['json']['dados']['estoque'] ?? null;
    check('Admin cancela pedido e o estoque volta (+2 velas)', ($r['json']['dados']['status'] ?? '') === 'cancelado' && $estVela2 === $estVela + 2, "antes=$estVela depois=$estVela2");

    // -- Categorias / marcas / veículos
    $r = $adm->req('DELETE', '/api/admin/categorias/1');
    check('Categoria com produtos/subcategorias não pode ser excluída (409)', $r['status'] === 409);
    $r = $adm->req('POST', '/api/admin/categorias', ['nome' => "Categoria Teste $sufixo", 'parent_id' => 7]);
    $catId = $r['json']['dados']['id'] ?? 0;
    check('Subcategoria criada com slug automático', $r['status'] === 201 && str_starts_with($r['json']['dados']['slug'] ?? '', 'categoria-teste'), (string) $r['raw']);
    $r = $adm->req('POST', '/api/admin/categorias', ['nome' => 'Terceiro nível', 'parent_id' => $catId]);
    check('Terceiro nível de categoria é recusado (422)', $r['status'] === 422);
    $r = $adm->req('DELETE', "/api/admin/categorias/$catId");
    check('Categoria vazia pode ser excluída', $r['status'] === 200);

    $r = $adm->req('POST', '/api/admin/marcas', ['nome' => "Marca Teste $sufixo"]);
    $marcaId = $r['json']['dados']['id'] ?? 0;
    check('Marca criada (201)', $r['status'] === 201);
    $r = $adm->req('POST', '/api/admin/marcas', ['nome' => "Marca Teste $sufixo"]);
    check('Marca duplicada (409)', $r['status'] === 409);
    $adm->req('DELETE', "/api/admin/marcas/$marcaId");

    $r = $adm->req('POST', '/api/admin/veiculos', ['montadora' => 'Renault', 'modelo' => 'Kwid', 'ano_inicio' => 2018, 'ano_fim' => 2015]);
    check('Veículo com ano final menor que o inicial é recusado (422)', $r['status'] === 422);
    $r = $adm->req('POST', '/api/admin/veiculos', ['montadora' => 'Renault', 'modelo' => 'Kwid', 'ano_inicio' => 2018, 'motor' => '1.0']);
    $veicId = $r['json']['dados']['id'] ?? 0;
    check('Veículo criado (201)', $r['status'] === 201);
    $adm->req('DELETE', "/api/admin/veiculos/$veicId");

    // -- Usuários
    $r = $adm->req('GET', '/api/admin/usuarios?busca=' . rawurlencode($email));
    check('Admin encontra cliente pela busca', ($r['json']['paginacao']['total'] ?? 0) === 1 && ($r['json']['dados'][0]['total_pedidos'] ?? 0) >= 3);
    $meuId = $adm->req('GET', '/api/auth/me')['json']['dados']['usuario']['id'] ?? 0;
    $r = $adm->req('PATCH', "/api/admin/usuarios/$meuId", ['ativo' => false]);
    check('Admin não consegue desativar a própria conta (422)', $r['status'] === 422);

    // -- Extras: compatibilidade, imagens, listagens
    $r = $adm->req('PUT', "/api/admin/produtos/$pid/veiculos", ['veiculo_ids' => [4, 5]]);
    check('Trocar a lista de veículos compatíveis', $r['status'] === 200 && (function (array $ids) { sort($ids); return $ids; })($r['json']['dados']['veiculo_ids'] ?? []) === [4, 5], (string) $r['raw']);
    $r = $adm->req('PUT', "/api/admin/produtos/$pid/veiculos", ['veiculo_ids' => [4, 9999]]);
    check('Veículo inexistente na lista é recusado (422)', $r['status'] === 422);

    $r = $adm->upload("/api/admin/produtos/$pid/imagens", $png, 'image/png');
    $imgs = $r['json']['dados']['imagens'] ?? [];
    check('Segunda imagem enviada e a principal continua sendo a nova (principal=1)', count($imgs) === 2 && $imgs[0]['principal'] === true);
    $r = $adm->req('PATCH', "/api/admin/produtos/$pid/imagens/$imgId/principal");
    check('Definir a primeira imagem como principal', ($r['json']['dados']['imagens'][0]['id'] ?? 0) === $imgId);
    $r = $adm->req('DELETE', "/api/admin/produtos/$pid/imagens/$imgId");
    check('Excluir a imagem principal promove outra', count($r['json']['dados']['imagens'] ?? []) === 1 && ($r['json']['dados']['imagens'][0]['principal'] ?? false) === true);
    $head = @get_headers($urlImg);
    check('Arquivo da imagem excluída sumiu do servidor (404)', $head && str_contains($head[0], '404'));

    $r = $adm->req('GET', '/api/admin/produtos?estoque_baixo=1&por_pagina=100');
    check('Filtro de estoque baixo no painel', in_array('SZ-LUB-0002', array_column($r['json']['dados'] ?? [], 'sku'), true));
    $r = $adm->req('GET', '/api/admin/produtos?ativo=0');
    check('Painel enxerga produtos inativos (a loja não)', ($r['json']['paginacao']['total'] ?? 0) >= 1);
    $r = $adm->req('GET', '/api/admin/categorias');
    check('Admin lista todas as categorias com contagem de produtos', ($r['json']['dados'][0]['total_produtos'] ?? null) !== null);
    $r = $adm->req('PUT', '/api/admin/categorias/62', ['ordem' => 5]);
    check('Editar categoria', ($r['json']['dados']['ordem'] ?? 0) === 5);
    $r = $adm->req('PUT', '/api/admin/categorias/1', ['parent_id' => 7]);
    check('Categoria que tem subcategorias não vira subcategoria (422)', $r['status'] === 422);
    $r = $adm->req('GET', "/api/admin/usuarios/$oficinaId");
    check('Admin vê detalhes do cliente', ($r['json']['dados']['tipo_cliente'] ?? '') === 'oficina' && isset($r['json']['dados']['total_gasto']));
    $r = $adm->req('GET', '/api/admin/usuarios?oficina_pendente=1');
    check('Filtro de oficinas pendentes funciona', $r['status'] === 200);
    $r = $adm->req('GET', "/api/admin/pedidos?busca=" . rawurlencode($pedidoEntrega['codigo'] ?? ''));
    check('Admin busca pedido pelo código', ($r['json']['paginacao']['total'] ?? 0) === 1);

    // -- Exclusão de produto
    $r = $adm->req('DELETE', "/api/admin/produtos/$pid");
    check('Produto sem vendas é excluído de verdade', ($r['json']['dados']['excluido'] ?? false) === true, (string) $r['raw']);
    $r = $adm->req('DELETE', '/api/admin/produtos/4');
    check('Produto que já foi vendido só é desativado', ($r['json']['dados']['desativado'] ?? false) === true && ($r['json']['dados']['excluido'] ?? true) === false, (string) $r['raw']);
    $adm->req('PUT', '/api/admin/produtos/4', ['ativo' => true]);   // restaura para não atrapalhar outros testes

    // -- Desativa o cliente de teste: sessão dele cai na hora
    $r = $adm->req('PATCH', '/api/admin/usuarios/' . ($oficinaId) , ['ativo' => false]);
    $r = $co->req('GET', '/api/carrinho');
    $r = $co->req('GET', '/api/conta');
    check('Conta desativada perde o acesso imediatamente (401)', $r['status'] === 401);
} else {
    echo "\n(pulando testes do painel admin: informe e-mail e senha de um admin como 2º e 3º argumentos)\n";
}

echo "\n==============================\n";
echo "Passaram: $ok   Falharam: " . count($falhas) . "\n";
foreach ($falhas as $f) {
    echo " - $f\n";
}
exit($falhas ? 1 : 0);

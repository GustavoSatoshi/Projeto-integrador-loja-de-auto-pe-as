<?php
declare(strict_types=1);

// =====================================================================
// routes.php - MAPA DE TODAS AS ROTAS DA API.
//   rota(MÉTODO, caminho, função_que_atende, quem_pode_acessar)
// Quem pode: 'publico' (qualquer um) | 'login' (cliente logado) | 'admin'
// =====================================================================

// ---- Utilidades ------------------------------------------------------
rota('GET', '/', 'ctl_inicio');
rota('GET', '/api', 'ctl_raiz');
rota('GET', '/api/health', 'ctl_saude');
rota('GET', '/api/csrf', 'ctl_csrf');

// ---- Autenticação ----------------------------------------------------
rota('POST', '/api/auth/cadastro', 'ctl_auth_cadastro');
rota('POST', '/api/auth/login',    'ctl_auth_login');
rota('POST', '/api/auth/logout',   'ctl_auth_logout');
rota('GET',  '/api/auth/me',       'ctl_auth_me');

// ---- Catálogo (loja) -------------------------------------------------
rota('GET', '/api/produtos',        'ctl_produtos_listar');
rota('GET', '/api/produtos/{ref}',  'ctl_produtos_detalhe');     // id ou slug
rota('GET', '/api/categorias',      'ctl_categorias_listar');
rota('GET', '/api/marcas',          'ctl_marcas_listar');
rota('GET', '/api/veiculos',             'ctl_veiculos_listar');
rota('GET', '/api/veiculos/montadoras',  'ctl_veiculos_montadoras');
rota('GET', '/api/veiculos/modelos',     'ctl_veiculos_modelos');

// ---- Carrinho e frete ------------------------------------------------
rota('GET',    '/api/carrinho',                         'ctl_carrinho_ver');
rota('POST',   '/api/carrinho/itens',                   'ctl_carrinho_adicionar');
rota('PUT',    '/api/carrinho/itens/{produto_id:num}',  'ctl_carrinho_atualizar');
rota('DELETE', '/api/carrinho/itens/{produto_id:num}',  'ctl_carrinho_remover');
rota('DELETE', '/api/carrinho',                         'ctl_carrinho_limpar');
rota('POST',   '/api/frete/calcular',                   'ctl_frete_calcular');

// ---- Conta do cliente ------------------------------------------------
rota('GET',    '/api/conta',                      'ctl_conta_ver',          'login');
rota('PUT',    '/api/conta',                      'ctl_conta_atualizar',    'login');
rota('PUT',    '/api/conta/senha',                'ctl_conta_trocar_senha', 'login');
rota('GET',    '/api/conta/enderecos',            'ctl_enderecos_listar',   'login');
rota('POST',   '/api/conta/enderecos',            'ctl_enderecos_criar',    'login');
rota('PUT',    '/api/conta/enderecos/{id:num}',   'ctl_enderecos_atualizar','login');
rota('DELETE', '/api/conta/enderecos/{id:num}',   'ctl_enderecos_excluir',  'login');

// ---- Pedidos do cliente ----------------------------------------------
rota('POST', '/api/pedidos',                      'ctl_pedidos_criar',    'login');
rota('GET',  '/api/pedidos',                      'ctl_pedidos_listar',   'login');
rota('GET',  '/api/pedidos/{codigo}',             'ctl_pedidos_detalhe',  'login');
rota('POST', '/api/pedidos/{codigo}/cancelar',    'ctl_pedidos_cancelar', 'login');

// ---- ADMIN: dashboard ------------------------------------------------
rota('GET', '/api/admin/dashboard', 'ctl_admin_dashboard', 'admin');

// ---- ADMIN: produtos -------------------------------------------------
rota('GET',    '/api/admin/produtos',                                   'ctl_admin_produtos_listar',   'admin');
rota('POST',   '/api/admin/produtos',                                   'ctl_admin_produtos_criar',    'admin');
rota('GET',    '/api/admin/produtos/{id:num}',                          'ctl_admin_produtos_ver',      'admin');
rota('PUT',    '/api/admin/produtos/{id:num}',                          'ctl_admin_produtos_atualizar','admin');
rota('DELETE', '/api/admin/produtos/{id:num}',                          'ctl_admin_produtos_excluir',  'admin');
rota('PUT',    '/api/admin/produtos/{id:num}/veiculos',                 'ctl_admin_produtos_veiculos', 'admin');
rota('GET',    '/api/admin/produtos/{id:num}/estoque',                  'ctl_admin_estoque_historico', 'admin');
rota('POST',   '/api/admin/produtos/{id:num}/estoque',                  'ctl_admin_estoque_movimentar','admin');
rota('POST',   '/api/admin/produtos/{id:num}/imagens',                  'ctl_admin_imagens_enviar',    'admin');
rota('PATCH',  '/api/admin/produtos/{id:num}/imagens/{imagem_id:num}/principal', 'ctl_admin_imagens_principal', 'admin');
rota('DELETE', '/api/admin/produtos/{id:num}/imagens/{imagem_id:num}',  'ctl_admin_imagens_excluir',   'admin');

// ---- ADMIN: categorias, marcas e veículos ----------------------------
rota('GET',    '/api/admin/categorias',            'ctl_admin_categorias_listar',    'admin');
rota('POST',   '/api/admin/categorias',            'ctl_admin_categorias_criar',     'admin');
rota('PUT',    '/api/admin/categorias/{id:num}',   'ctl_admin_categorias_atualizar', 'admin');
rota('DELETE', '/api/admin/categorias/{id:num}',   'ctl_admin_categorias_excluir',   'admin');

rota('GET',    '/api/admin/marcas',                'ctl_admin_marcas_listar',        'admin');
rota('POST',   '/api/admin/marcas',                'ctl_admin_marcas_criar',         'admin');
rota('PUT',    '/api/admin/marcas/{id:num}',       'ctl_admin_marcas_atualizar',     'admin');
rota('DELETE', '/api/admin/marcas/{id:num}',       'ctl_admin_marcas_excluir',       'admin');

rota('POST',   '/api/admin/veiculos',              'ctl_admin_veiculos_criar',       'admin');
rota('PUT',    '/api/admin/veiculos/{id:num}',     'ctl_admin_veiculos_atualizar',   'admin');
rota('DELETE', '/api/admin/veiculos/{id:num}',     'ctl_admin_veiculos_excluir',     'admin');

// ---- ADMIN: pedidos e clientes ---------------------------------------
rota('GET',   '/api/admin/pedidos',                     'ctl_admin_pedidos_listar', 'admin');
rota('GET',   '/api/admin/pedidos/{id:num}',            'ctl_admin_pedidos_ver',    'admin');
rota('PATCH', '/api/admin/pedidos/{id:num}/status',     'ctl_admin_pedidos_status', 'admin');

rota('GET',   '/api/admin/usuarios',                    'ctl_admin_usuarios_listar',   'admin');
rota('GET',   '/api/admin/usuarios/{id:num}',           'ctl_admin_usuarios_ver',      'admin');
rota('PATCH', '/api/admin/usuarios/{id:num}',           'ctl_admin_usuarios_atualizar','admin');

// ---- Funções simples de utilidade ------------------------------------
/** A raiz do site leva o visitante para a loja. */
function ctl_inicio(array $p): void
{
    header('Location: ' . url_base() . '/loja/index.html', true, 302);
    exit;
}

function ctl_raiz(array $p): void
{
    responder([
        'nome'    => 'SpeedZone API',
        'versao'  => '1.0',
        'mensagem'=> 'Mais que autopeças, é performance em cada detalhe.',
        'docs'    => 'Veja o README.md para a lista de endpoints.',
    ]);
}

function ctl_saude(array $p): void
{
    db_valor('SELECT 1');   // se o banco estiver fora do ar, vira erro 500
    responder(['status' => 'ok', 'banco' => 'conectado', 'hora' => date('c')]);
}

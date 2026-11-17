# SpeedZone — Back end do e-commerce de autopeças

API em **PHP + MySQL** (mesma stack do projeto *Biblioteca_PHP*: `mysqli`, sessões, `password_hash`),
com loja pública, carrinho, checkout e **painel administrativo**.

> Mais que autopeças, é performance em cada detalhe.

---

## 1. Como rodar (PHP + MySQL Workbench, sem XAMPP)

O site roda só com o servidor embutido do PHP (`php -S`). O banco é o **MySQL** que você já usa no **MySQL Workbench**.

**Requisitos:** PHP 8.1+ (extensões `mysqli` e `mbstring`) e o **servidor MySQL** instalado e ligado. No Windows, o MySQL Installer já deixa o servidor rodando como serviço (MySQL80); no Workbench, a conexão "Local instance" aparece com o servidor ativo.

### Jeito fácil
- **Windows:** dê dois cliques em **`iniciar.bat`**.
- **Linux/Mac:** `./iniciar.sh`

Ele cria o `.env`, conecta no MySQL (se a senha do `.env` não bater, ele pergunta a que você usa no Workbench e salva), cria o banco `speedzone`, as tabelas e os produtos de exemplo, cria o administrador e abre a loja no navegador. Pode rodar de novo quando quiser: só faz o que falta. Para desligar, feche a janela preta (ou Ctrl+C).

### Jeito manual
```
php bin/instalar.php                                # 1ª vez: cria banco, tabelas e administrador
php -S localhost:8000 -t public public/index.php    # sobe o site
```
Se o PHP reclamar de extensão faltando (Windows): na pasta do PHP, copie `php.ini-development` para `php.ini`, abra-o e tire o `;` do começo das linhas `;extension_dir = "ext"`, `;extension=mysqli` e `;extension=mbstring`.

### Usando o MySQL Workbench
Depois de rodar o instalador, o schema **`speedzone`** aparece no Workbench (clique em *Refresh* no painel SCHEMAS) com todas as tabelas, e você pode consultar e editar os dados por lá.
Se preferir criar o banco pelo Workbench em vez do instalador: abra `database/schema.sql` (File > Open SQL Script), execute (raio) e depois `database/seed.sql`; em seguida rode `php bin/criar_admin.php "Seu Nome" seu@email.com`.

### Front-end (já incluso)
- **Loja:** `http://localhost:8000/` (abre `public/loja/index.html`). O menu tem **Produtos** (lista completa com busca, categorias e "Qual é o seu carro?") e uma busca sempre visível. Login (`#/entrar`) e cadastro (`#/cadastro`) são páginas separadas. Também: página do produto, carrinho, frete, checkout e meus pedidos.
- **Painel:** `http://localhost:8000/admin/index.html` (entre com o admin criado no passo 4): dashboard, produtos (com foto e estoque), pedidos (mudança de status), clientes (aprovar oficinas).
- É HTML/CSS/JS puro (sem build) em `public/loja`, `public/admin` e `public/assets`; fala com a API pelo mesmo endereço.

### Testar tudo de uma vez
```
php tests/smoke_test.php http://localhost:8000 seu@email.com SuaSenhaDeAdmin
```
Percorre ~125 verificações (catálogo, carrinho, checkout, estoque, permissões, painel admin).
**Use só em banco de desenvolvimento**: ele cria clientes, pedidos e um produto de teste.

---

## 2. Estrutura

```
speedzone-backend/
├── database/
│   ├── schema.sql            tabelas, chaves e índices
│   └── seed.sql              dados de exemplo
├── public/                   ÚNICA pasta exposta ao navegador
│   ├── index.php             porta de entrada da API
│   └── uploads/produtos/     imagens enviadas pelo admin
├── src/
│   ├── config.php            lê o .env, regras de frete
│   ├── db.php                mysqli + prepared statements + transações
│   ├── helpers.php           respostas JSON, dinheiro, slug, CPF/CNPJ, CEP
│   ├── validacao.php         validação de entrada
│   ├── auth.php              sessão, permissões, CSRF, limite de login
│   ├── router.php / routes.php   mapa de todas as rotas
│   └── controllers/          a lógica de cada área
├── bin/
│   ├── criar_admin.php       cria o administrador
│   └── cancelar_expirados.php  cancela pedidos sem pagamento (agendar de hora em hora)
└── tests/smoke_test.php
```

**Correspondência com a biblioteca:** `verificar_sessao.php` virou `exigir_login()` / `exigir_admin()` (em `auth.php`);
`logout.php` virou `encerrar_sessao()`; `conexao.php` virou `db.php` + `.env`; cada par
`cadastrar/salvar/editar/atualizar/excluir_livro.php` virou as funções de `admin_produtos.php`.

---

## 3. Como a API funciona

**Formato.** Sucesso: `{"sucesso": true, "dados": ...}` (listas trazem também `"paginacao"`).
Erro: `{"sucesso": false, "erro": "mensagem", "detalhes": {"campo": "problema"}}`.
Códigos: `200/201` ok · `401` sem login · `403` sem permissão · `404` não achou · `409` conflito (duplicado, status inválido)
· `422` dados inválidos · `429` muitas tentativas de login.

**Sessão + CSRF.** O login usa sessão do PHP (cookie `HttpOnly`), como na biblioteca. Para proteger contra CSRF,
toda requisição que **altera dados** (POST/PUT/PATCH/DELETE) precisa do cabeçalho `X-CSRF-Token`:

```js
// 1) uma vez ao abrir o site (e de novo depois de login/cadastro, que devolvem um token novo)
let csrf = (await (await fetch('/api/csrf', { credentials: 'include' })).json()).dados.csrf_token;

// 2) em toda requisição que altera dados
await fetch('/api/carrinho/itens', {
  method: 'POST',
  credentials: 'include',                    // envia o cookie da sessão
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
  body: JSON.stringify({ produto_id: 1, quantidade: 2 }),
});
```
Se o front-end ficar em outro endereço (ex.: Live Server em `localhost:5500`), liste-o em `CORS_ORIGINS` no `.env`.

---

## 4. Endpoints

### Loja (públicos)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/produtos` | Lista com filtros: `busca`, `categoria` (id ou slug), `marca`, `preco_min`, `preco_max`, `veiculo_id`, `em_estoque`, `destaque`, `ordem` (`relevancia`, `menor_preco`, `maior_preco`, `nome`, `recentes`), `pagina`, `por_pagina` |
| GET | `/api/produtos/{id ou slug}` | Detalhe: fotos, especificações, veículos compatíveis, relacionados |
| GET | `/api/categorias` · `/api/marcas` | Menu de categorias (2 níveis) e marcas |
| GET | `/api/veiculos/montadoras` · `/modelos?montadora=` · `/api/veiculos?montadora=&modelo=&ano=` | Seletor "Qual é o seu carro?" |
| GET | `/api/carrinho` | Carrinho com totais |
| POST | `/api/carrinho/itens` | `{produto_id, quantidade}` |
| PUT / DELETE | `/api/carrinho/itens/{produto_id}` | Alterar quantidade (0 remove) / remover |
| DELETE | `/api/carrinho` | Esvaziar |
| POST | `/api/frete/calcular` | `{cep}` (usa o carrinho) ou `{cep, produto_id, quantidade}` (página do produto) |

### Conta e pedidos (exigem login)
| Método | Rota | Descrição |
|---|---|---|
| POST | `/api/auth/cadastro` | `{nome, email, senha, documento, tipo_cliente?, empresa?, telefone?}` |
| POST | `/api/auth/login` · `/api/auth/logout` | |
| GET | `/api/auth/me` | `{autenticado: true/false, usuario}` |
| GET / PUT | `/api/conta` | Ver e editar perfil |
| PUT | `/api/conta/senha` | `{senha_atual, nova_senha}` |
| GET / POST | `/api/conta/enderecos` | Listar / criar (máx. 10) |
| PUT / DELETE | `/api/conta/enderecos/{id}` | Editar / remover |
| POST | `/api/pedidos` | **Checkout:** `{tipo_entrega: "entrega"\|"retirada", forma_pagamento: "pix"\|"boleto"\|"cartao", endereco_id?, observacao?}` |
| GET | `/api/pedidos` · `/api/pedidos/{codigo}` | Meus pedidos (código no formato `SZ-9F3A21BC`) |
| POST | `/api/pedidos/{codigo}/cancelar` | Só enquanto aguarda pagamento |

### Painel administrativo (perfil `admin`)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/admin/dashboard` | Faturamento do mês, ticket médio, pedidos por status, vendas dos últimos 7 dias, estoque baixo, mais vendidos, oficinas pendentes |
| GET / POST | `/api/admin/produtos` | Listar (filtros extras: `ativo`, `estoque_baixo=1`) / criar |
| GET / PUT / DELETE | `/api/admin/produtos/{id}` | Ver / editar (parcial) / excluir |
| PUT | `/api/admin/produtos/{id}/veiculos` | `{veiculo_ids: [..]}` define a compatibilidade |
| POST / GET | `/api/admin/produtos/{id}/estoque` | Movimentar `{tipo: "entrada"\|"saida"\|"ajuste", quantidade, motivo}` / ver histórico |
| POST | `/api/admin/produtos/{id}/imagens` | Upload (`multipart/form-data`, campo `imagem`, JPG/PNG/WEBP até 3 MB) |
| PATCH / DELETE | `/api/admin/produtos/{id}/imagens/{imagem_id}[/principal]` | Definir principal / excluir |
| GET·POST·PUT·DELETE | `/api/admin/categorias`, `/marcas`, `/veiculos` | Cadastros de apoio |
| GET | `/api/admin/pedidos` | Filtros: `status`, `busca` (código, nome, e-mail), `data_de`, `data_ate` |
| GET | `/api/admin/pedidos/{id}` | Detalhe com cliente e `proximos_status` possíveis |
| PATCH | `/api/admin/pedidos/{id}/status` | `{status, codigo_rastreio?, observacao?}` |
| GET / PATCH | `/api/admin/usuarios[/{id}]` | Clientes; PATCH `{ativo, perfil, oficina_aprovada}` |

Exemplo de produto (corpo do `POST /api/admin/produtos`):
```json
{
  "sku": "SZ-FRE-0100", "nome": "Pastilha de Freio Traseira", "categoria_id": 11, "marca_id": 3,
  "codigo_fabricante": "PAST-2002", "preco": "129,90", "preco_promocional": 109.9,
  "estoque": 20, "estoque_minimo": 5, "peso_kg": 0.8, "comprimento_cm": 14, "largura_cm": 10, "altura_cm": 6,
  "destaque": true, "universal": false,
  "especificacoes": {"Posição": "Traseira", "Material": "Cerâmica"},
  "veiculo_ids": [1, 2, 5]
}
```

---

## 5. Regras de negócio

- **Preço sempre vem do banco.** O cliente só diz "produto + quantidade"; promoção, desconto e frete são calculados no servidor, em centavos (sem erros de arredondamento).
- **Checkout em transação.** Pedido + itens + baixa de estoque são gravados juntos (ou nada é gravado). As linhas dos produtos são travadas (`FOR UPDATE`): testado com 5 compras simultâneas da última unidade → 1 venda, 4 recusas, estoque nunca negativo.
- **Pedido guarda uma cópia** do endereço e dos itens/preços. Mudar o preço do produto depois não altera pedidos antigos.
- **Status:** `aguardando_pagamento → pago → em_separacao → enviado → entregue` (entrega) ou `… → pronto_retirada → entregue` (retirada na loja).
  Cancelamento é permitido até antes de "enviado" e **devolve as peças ao estoque**. Todo passo fica no histórico do pedido.
- **Pedidos não pagos expiram** (`PEDIDO_EXPIRA_HORAS`, padrão 48 h) quando você agenda `bin/cancelar_expirados.php`.
- **"Serve no meu carro?"** Produtos têm lista de veículos compatíveis; `?veiculo_id=` retorna as peças compatíveis **mais** as marcadas como `universal`.
- **Oficinas:** cadastro com CNPJ válido; o desconto (`OFICINA_DESCONTO_PERCENTUAL`) só vale depois que o admin aprova (`oficina_aprovada`), evitando que qualquer um se cadastre como oficina para ganhar desconto.
- **Frete (simulado):** por região (mesma UF da loja / Sul-Sudeste / demais) + peso cubado, grátis acima de `FRETE_GRATIS_ACIMA`. Há também retirada na loja (R$ 0).
- **Excluir produto já vendido** apenas o desativa, para não quebrar o histórico.

## 6. Segurança (o que mudou em relação à biblioteca)

| Biblioteca | SpeedZone |
|---|---|
| SQL montado com `"... WHERE id = $id"` (SQL Injection) | *Prepared statements* em 100% das consultas |
| `echo $livro['titulo']` (XSS) | A API devolve JSON; o front-end deve inserir texto com `textContent`, não `innerHTML` |
| Excluir por link `GET` | Ações destrutivas só por `DELETE`, com token CSRF |
| `autenticar.php` vazio | Login completo, com limite de 5 tentativas em 15 min (por e-mail + IP) |
| Qualquer usuário logado administra tudo | Perfis `cliente` / `admin` verificados em cada rota |
| Sem validação | Validação de tipo, tamanho, e-mail, CPF/CNPJ, CEP, enums |

Além disso: a sessão troca de ID no login (`session_regenerate_id`), o cookie é `HttpOnly` + `SameSite=Lax`, e o upload de imagens confere o conteúdo real do arquivo, gera nome aleatório e bloqueia execução de PHP na pasta de uploads.

Antes de publicar de verdade: use HTTPS, `APP_ENV=producao`, troque `root` por um usuário próprio do MySQL, faça backup do banco (no Workbench: Server > Data Export) e nunca envie o `.env` ao GitHub (já está no `.gitignore`).

## 7. O que ainda não está incluído (próximos passos)

- **Pagamento real:** hoje o pedido nasce "aguardando pagamento" e o admin confirma manualmente. O próximo passo é integrar Pix/cartão (Mercado Pago, Stripe, Pagar.me…) e um *webhook* que muda o status para `pago`.
- **Frete real:** trocar `frete_calcular()` pela API dos Correios ou do Melhor Envio.
- **E-mails** (confirmação de pedido, recuperação de senha) e **cupons de desconto**.
- **Front-end:** a versão inclusa é enxuta; faltam, por exemplo, gestão de categorias/marcas/veículos no painel e páginas institucionais.

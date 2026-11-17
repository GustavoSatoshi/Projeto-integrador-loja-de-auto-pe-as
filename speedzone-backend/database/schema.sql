-- =====================================================================
-- SpeedZone - Estrutura do banco de dados (MySQL 5.7+/8 ou MariaDB 10.4+)
-- Importe pelo phpMyAdmin (aba "Importar") ou pelo terminal:
--   mysql -u root -p -P 3307 < database/schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS speedzone
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE speedzone;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- USUÁRIOS (clientes e administradores)
-- Evolução da tabela "usuarios" da biblioteca: agora com perfil,
-- tipo de cliente (pessoa física ou oficina) e documento.
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome              VARCHAR(120) NOT NULL,
  email             VARCHAR(160) NOT NULL,
  senha_hash        VARCHAR(255) NOT NULL,            -- password_hash(), nunca a senha pura
  perfil            ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
  tipo_cliente      ENUM('pf','oficina') NOT NULL DEFAULT 'pf',
  documento         VARCHAR(14) NULL,                 -- CPF (11) ou CNPJ (14), só dígitos
  empresa           VARCHAR(160) NULL,                -- razão social / nome da oficina
  telefone          VARCHAR(11) NULL,
  oficina_aprovada  TINYINT(1) NOT NULL DEFAULT 0,    -- admin aprova -> libera desconto de oficina
  ativo             TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login      DATETIME NULL,
  criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  UNIQUE KEY uq_usuarios_documento (documento)
) ENGINE=InnoDB;

CREATE TABLE enderecos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED NOT NULL,
  apelido       VARCHAR(40) NOT NULL DEFAULT 'Casa',
  destinatario  VARCHAR(120) NOT NULL,
  cep           CHAR(8) NOT NULL,
  logradouro    VARCHAR(160) NOT NULL,
  numero        VARCHAR(10) NOT NULL,
  complemento   VARCHAR(80) NULL,
  bairro        VARCHAR(80) NOT NULL,
  cidade        VARCHAR(80) NOT NULL,
  uf            CHAR(2) NOT NULL,
  padrao        TINYINT(1) NOT NULL DEFAULT 0,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_enderecos_usuario (usuario_id),
  CONSTRAINT fk_enderecos_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATÁLOGO
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id  INT UNSIGNED NULL,                       -- NULL = categoria principal (2 níveis)
  nome       VARCHAR(80) NOT NULL,
  slug       VARCHAR(100) NOT NULL,
  descricao  VARCHAR(255) NULL,
  ordem      SMALLINT NOT NULL DEFAULT 0,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categorias_slug (slug),
  KEY idx_categorias_parent (parent_id),
  CONSTRAINT fk_categorias_parent FOREIGN KEY (parent_id)
    REFERENCES categorias (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE marcas (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome   VARCHAR(80) NOT NULL,
  slug   VARCHAR(100) NOT NULL,
  ativo  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marcas_nome (nome),
  UNIQUE KEY uq_marcas_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE produtos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku                VARCHAR(40) NOT NULL,
  nome               VARCHAR(180) NOT NULL,
  slug               VARCHAR(200) NOT NULL,
  descricao          TEXT NULL,
  categoria_id       INT UNSIGNED NOT NULL,
  marca_id           INT UNSIGNED NULL,
  codigo_fabricante  VARCHAR(60) NULL,                -- part number / referência OEM
  preco              DECIMAL(10,2) NOT NULL,
  preco_promocional  DECIMAL(10,2) NULL,
  estoque            INT NOT NULL DEFAULT 0,
  estoque_minimo     INT NOT NULL DEFAULT 2,          -- abaixo disso aparece como "estoque baixo"
  peso_kg            DECIMAL(7,3) NOT NULL DEFAULT 0.500,
  comprimento_cm     DECIMAL(6,1) NOT NULL DEFAULT 0,
  largura_cm         DECIMAL(6,1) NOT NULL DEFAULT 0,
  altura_cm          DECIMAL(6,1) NOT NULL DEFAULT 0,
  universal          TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = serve em qualquer veículo (ex.: aditivos)
  destaque           TINYINT(1) NOT NULL DEFAULT 0,
  ativo              TINYINT(1) NOT NULL DEFAULT 1,
  especificacoes     JSON NULL,                       -- {"Posição":"Dianteira","Material":"Cerâmica"}
  criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_produtos_sku (sku),
  UNIQUE KEY uq_produtos_slug (slug),
  KEY idx_produtos_categoria (categoria_id),
  KEY idx_produtos_marca (marca_id),
  KEY idx_produtos_listagem (ativo, destaque),
  KEY idx_produtos_codigo_fab (codigo_fabricante),
  CONSTRAINT fk_produtos_categoria FOREIGN KEY (categoria_id)
    REFERENCES categorias (id) ON DELETE RESTRICT,
  CONSTRAINT fk_produtos_marca FOREIGN KEY (marca_id)
    REFERENCES marcas (id) ON DELETE RESTRICT,
  CONSTRAINT ck_produtos_preco CHECK (preco > 0),
  CONSTRAINT ck_produtos_estoque CHECK (estoque >= 0)
) ENGINE=InnoDB;

CREATE TABLE produto_imagens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  produto_id  INT UNSIGNED NOT NULL,
  arquivo     VARCHAR(80) NOT NULL,                   -- nome do arquivo em public/uploads/produtos
  principal   TINYINT(1) NOT NULL DEFAULT 0,
  ordem       SMALLINT NOT NULL DEFAULT 0,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imagens_produto (produto_id),
  CONSTRAINT fk_imagens_produto FOREIGN KEY (produto_id)
    REFERENCES produtos (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- COMPATIBILIDADE COM VEÍCULOS ("serve no meu carro?")
-- ---------------------------------------------------------------------
CREATE TABLE veiculos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  montadora   VARCHAR(40) NOT NULL,
  modelo      VARCHAR(60) NOT NULL,
  ano_inicio  SMALLINT NOT NULL,
  ano_fim     SMALLINT NULL,                          -- NULL = ainda em produção
  motor       VARCHAR(40) NULL,
  PRIMARY KEY (id),
  KEY idx_veiculos_busca (montadora, modelo)
) ENGINE=InnoDB;

CREATE TABLE produto_veiculo (
  produto_id  INT UNSIGNED NOT NULL,
  veiculo_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (produto_id, veiculo_id),
  KEY idx_pv_veiculo (veiculo_id),
  CONSTRAINT fk_pv_produto FOREIGN KEY (produto_id)
    REFERENCES produtos (id) ON DELETE CASCADE,
  CONSTRAINT fk_pv_veiculo FOREIGN KEY (veiculo_id)
    REFERENCES veiculos (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PEDIDOS
-- Os dados do endereço e dos itens são COPIADOS para o pedido
-- (snapshot): se o produto mudar de preço ou o cliente apagar o
-- endereço, o histórico do pedido continua correto.
-- ---------------------------------------------------------------------
CREATE TABLE pedidos (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                CHAR(11) NOT NULL,            -- ex.: SZ-9F3A21BC (o que o cliente vê)
  usuario_id            INT UNSIGNED NOT NULL,
  status                ENUM('aguardando_pagamento','pago','em_separacao','enviado',
                             'pronto_retirada','entregue','cancelado')
                        NOT NULL DEFAULT 'aguardando_pagamento',
  tipo_entrega          ENUM('entrega','retirada') NOT NULL,
  forma_pagamento       ENUM('pix','boleto','cartao') NOT NULL,
  subtotal              DECIMAL(10,2) NOT NULL,
  desconto              DECIMAL(10,2) NOT NULL DEFAULT 0,
  frete                 DECIMAL(10,2) NOT NULL DEFAULT 0,
  total                 DECIMAL(10,2) NOT NULL,
  prazo_dias            SMALLINT NULL,
  codigo_rastreio       VARCHAR(40) NULL,
  observacao            VARCHAR(500) NULL,
  entrega_destinatario  VARCHAR(120) NULL,
  entrega_cep           CHAR(8) NULL,
  entrega_logradouro    VARCHAR(160) NULL,
  entrega_numero        VARCHAR(10) NULL,
  entrega_complemento   VARCHAR(80) NULL,
  entrega_bairro        VARCHAR(80) NULL,
  entrega_cidade        VARCHAR(80) NULL,
  entrega_uf            CHAR(2) NULL,
  pago_em               DATETIME NULL,
  criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedidos_codigo (codigo),
  KEY idx_pedidos_usuario (usuario_id, criado_em),
  KEY idx_pedidos_status (status, criado_em),
  KEY idx_pedidos_pago_em (pago_em),
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE pedido_itens (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id       INT UNSIGNED NOT NULL,
  produto_id      INT UNSIGNED NOT NULL,
  sku             VARCHAR(40) NOT NULL,
  nome            VARCHAR(180) NOT NULL,
  preco_unitario  DECIMAL(10,2) NOT NULL,
  quantidade      INT UNSIGNED NOT NULL,
  subtotal        DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_itens_pedido (pedido_id),
  KEY idx_itens_produto (produto_id),
  CONSTRAINT fk_itens_pedido FOREIGN KEY (pedido_id)
    REFERENCES pedidos (id) ON DELETE CASCADE,
  CONSTRAINT fk_itens_produto FOREIGN KEY (produto_id)
    REFERENCES produtos (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE pedido_historico (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id   INT UNSIGNED NOT NULL,
  status      VARCHAR(30) NOT NULL,
  observacao  VARCHAR(255) NULL,
  usuario_id  INT UNSIGNED NULL,                      -- quem fez a mudança (NULL = sistema)
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hist_pedido (pedido_id),
  CONSTRAINT fk_hist_pedido FOREIGN KEY (pedido_id)
    REFERENCES pedidos (id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ESTOQUE: toda entrada/saída fica registrada (auditoria)
-- ---------------------------------------------------------------------
CREATE TABLE estoque_movimentos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  produto_id    INT UNSIGNED NOT NULL,
  tipo          ENUM('entrada','saida','ajuste','pedido','cancelamento') NOT NULL,
  quantidade    INT NOT NULL,                         -- variação (positiva ou negativa)
  estoque_apos  INT NOT NULL,
  motivo        VARCHAR(160) NULL,
  pedido_id     INT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mov_produto (produto_id, criado_em),
  CONSTRAINT fk_mov_produto FOREIGN KEY (produto_id)
    REFERENCES produtos (id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_pedido FOREIGN KEY (pedido_id)
    REFERENCES pedidos (id) ON DELETE SET NULL,
  CONSTRAINT fk_mov_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SEGURANÇA: tentativas de login que falharam (limite contra força bruta)
-- ---------------------------------------------------------------------
CREATE TABLE login_tentativas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(160) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tentativas (email, ip, criado_em)
) ENGINE=InnoDB;

-- =====================================================================
-- SpeedZone - Dados de EXEMPLO para desenvolvimento
-- Rode DEPOIS do schema.sql. Os produtos, preços e compatibilidades
-- abaixo são fictícios: troque pelos dados reais da loja pelo painel.
-- O usuário administrador NÃO é criado aqui (veja bin/criar_admin.php).
-- =====================================================================

USE speedzone;
SET NAMES utf8mb4;

-- Categorias principais ------------------------------------------------
INSERT INTO categorias (id, parent_id, nome, slug, ordem) VALUES
  (1, NULL, 'Freios',                  'freios',                 1),
  (2, NULL, 'Suspensão e Direção',     'suspensao-e-direcao',    2),
  (3, NULL, 'Motor',                   'motor',                  3),
  (4, NULL, 'Filtros',                 'filtros',                4),
  (5, NULL, 'Elétrica e Iluminação',   'eletrica-e-iluminacao',  5),
  (6, NULL, 'Lubrificantes e Fluidos', 'lubrificantes-e-fluidos',6),
  (7, NULL, 'Acessórios',              'acessorios',             7);

-- Subcategorias --------------------------------------------------------
INSERT INTO categorias (id, parent_id, nome, slug, ordem) VALUES
  (11, 1, 'Pastilhas de Freio',   'pastilhas-de-freio',   1),
  (12, 1, 'Discos de Freio',      'discos-de-freio',      2),
  (21, 2, 'Amortecedores',        'amortecedores',        1),
  (22, 2, 'Pivôs e Terminais',    'pivos-e-terminais',    2),
  (31, 3, 'Velas de Ignição',     'velas-de-ignicao',     1),
  (32, 3, 'Correias e Tensores',  'correias-e-tensores',  2),
  (41, 4, 'Filtros de Óleo',      'filtros-de-oleo',      1),
  (42, 4, 'Filtros de Ar',        'filtros-de-ar',        2),
  (51, 5, 'Baterias',             'baterias',             1),
  (52, 5, 'Lâmpadas',             'lampadas',             2),
  (61, 6, 'Óleos de Motor',       'oleos-de-motor',       1),
  (62, 6, 'Aditivos e Fluidos',   'aditivos-e-fluidos',   2);

-- Marcas ---------------------------------------------------------------
INSERT INTO marcas (id, nome, slug) VALUES
  (1,  'Bosch',  'bosch'),
  (2,  'NGK',    'ngk'),
  (3,  'Cobreq', 'cobreq'),
  (4,  'Nakata', 'nakata'),
  (5,  'Mahle',  'mahle'),
  (6,  'Tecfil', 'tecfil'),
  (7,  'Cofap',  'cofap'),
  (8,  'Moura',  'moura'),
  (9,  'Mobil',  'mobil'),
  (10, 'Osram',  'osram');

-- Veículos -------------------------------------------------------------
INSERT INTO veiculos (id, montadora, modelo, ano_inicio, ano_fim, motor) VALUES
  (1,  'Volkswagen', 'Gol',     2008, 2016, '1.0'),
  (2,  'Volkswagen', 'Gol',     2008, 2016, '1.6'),
  (3,  'Fiat',       'Palio',   2012, 2017, '1.0'),
  (4,  'Fiat',       'Uno',     2010, 2021, '1.0'),
  (5,  'Chevrolet',  'Onix',    2013, 2019, '1.0'),
  (6,  'Chevrolet',  'Onix',    2013, 2019, '1.4'),
  (7,  'Hyundai',    'HB20',    2013, 2019, '1.0'),
  (8,  'Ford',       'Ka',      2015, 2018, '1.0'),
  (9,  'Honda',      'Civic',   2012, 2016, '1.8'),
  (10, 'Toyota',     'Corolla', 2015, 2019, '2.0');

-- Produtos -------------------------------------------------------------
INSERT INTO produtos
  (id, sku, nome, slug, descricao, categoria_id, marca_id, codigo_fabricante,
   preco, preco_promocional, estoque, estoque_minimo,
   peso_kg, comprimento_cm, largura_cm, altura_cm, universal, destaque, ativo, especificacoes)
VALUES
  (1, 'SZ-FRE-0001', 'Pastilha de Freio Dianteira Cerâmica', 'pastilha-de-freio-dianteira-ceramica',
   'Jogo de pastilhas cerâmicas para eixo dianteiro. Frenagem silenciosa e baixa geração de pó.',
   11, 3, 'PAST-1001', 129.90, 109.90, 25, 5, 0.900, 14, 10, 6, 0, 1, 1,
   '{"Posição":"Dianteira","Material":"Cerâmica","Contém":"1 jogo (4 pastilhas)"}'),

  (2, 'SZ-FRE-0002', 'Disco de Freio Ventilado Dianteiro (par)', 'disco-de-freio-ventilado-dianteiro-par',
   'Par de discos ventilados para eixo dianteiro, com tratamento anticorrosivo.',
   12, 3, 'DISC-2001', 289.90, NULL, 12, 3, 6.500, 30, 30, 12, 0, 0, 1,
   '{"Posição":"Dianteira","Tipo":"Ventilado","Contém":"2 discos"}'),

  (3, 'SZ-SUS-0001', 'Amortecedor Dianteiro Pressurizado', 'amortecedor-dianteiro-pressurizado',
   'Amortecedor a gás para o eixo dianteiro. Vendido por unidade.',
   21, 7, 'AMOR-3001', 259.00, NULL, 8, 2, 3.200, 55, 12, 12, 0, 0, 1,
   '{"Posição":"Dianteira","Tipo":"Pressurizado a gás"}'),

  (4, 'SZ-MOT-0001', 'Vela de Ignição Iridium', 'vela-de-ignicao-iridium',
   'Vela de ignição de ponta de irídio: partida mais rápida e maior durabilidade.',
   31, 2, 'VELA-4001', 54.90, NULL, 60, 10, 0.080, 10, 4, 4, 0, 1, 1,
   '{"Eletrodo":"Irídio","Vendido por":"Unidade"}'),

  (5, 'SZ-FIL-0001', 'Filtro de Óleo do Motor', 'filtro-de-oleo-do-motor',
   'Filtro de óleo com elemento filtrante de alta retenção.',
   41, 6, 'FOLE-5001', 24.90, NULL, 100, 20, 0.350, 10, 10, 10, 0, 0, 1, NULL),

  (6, 'SZ-FIL-0002', 'Filtro de Ar do Motor', 'filtro-de-ar-do-motor',
   'Filtro de ar do motor em papel pregueado.',
   42, 5, 'FAR-6001', 49.90, NULL, 40, 8, 0.300, 25, 20, 5, 0, 0, 1, NULL),

  (7, 'SZ-ELE-0001', 'Bateria Automotiva 60Ah', 'bateria-automotiva-60ah',
   'Bateria selada livre de manutenção, 60Ah. Garantia conforme fabricante.',
   51, 8, 'BAT-7001', 549.00, 499.00, 6, 3, 15.500, 24, 17, 19, 0, 1, 1,
   '{"Capacidade":"60Ah","Tensão":"12V","Manutenção":"Livre"}'),

  (8, 'SZ-ELE-0002', 'Lâmpada de Farol H4 Halógena (par)', 'lampada-de-farol-h4-halogena-par',
   'Par de lâmpadas H4 halógenas 12V 60/55W.',
   52, 10, 'LAMP-8001', 69.90, NULL, 30, 6, 0.100, 12, 8, 6, 0, 0, 1,
   '{"Soquete":"H4","Potência":"60/55W","Tensão":"12V"}'),

  (9, 'SZ-LUB-0001', 'Óleo de Motor 5W30 Sintético 1L', 'oleo-de-motor-5w30-sintetico-1l',
   'Óleo lubrificante 100% sintético, viscosidade 5W30.',
   61, 9, 'OLEO-9001', 54.90, NULL, 80, 15, 1.000, 10, 10, 25, 1, 1, 1,
   '{"Viscosidade":"5W30","Tipo":"Sintético","Volume":"1 litro"}'),

  (10, 'SZ-LUB-0002', 'Aditivo para Radiador Concentrado 1L', 'aditivo-para-radiador-concentrado-1l',
   'Aditivo concentrado com proteção anticorrosiva e anticongelante.',
   62, NULL, 'ADIT-1001', 19.90, NULL, 1, 2, 1.050, 10, 10, 25, 1, 0, 1, NULL),

  (11, 'SZ-ACE-0001', 'Tapete Automotivo Universal Preto (jogo)', 'tapete-automotivo-universal-preto-jogo',
   'Jogo com 4 tapetes de PVC, corte universal.',
   7, NULL, NULL, 89.90, NULL, 0, 2, 1.800, 50, 40, 5, 1, 0, 1, NULL),

  (12, 'SZ-MOT-0002', 'Kit Correia Dentada com Tensor', 'kit-correia-dentada-com-tensor',
   'Kit de distribuição: correia dentada e tensor.',
   32, 5, 'KIT-1201', 219.90, NULL, 10, 3, 1.100, 20, 20, 8, 0, 0, 1, NULL),

  (13, 'SZ-OLD-0001', 'Produto Descontinuado (exemplo inativo)', 'produto-descontinuado-exemplo-inativo',
   'Exemplo de produto inativo: não aparece na loja, só no painel.',
   7, NULL, NULL, 10.00, NULL, 0, 0, 0.100, 0, 0, 0, 0, 0, 0, NULL);

-- Compatibilidades -----------------------------------------------------
INSERT INTO produto_veiculo (produto_id, veiculo_id) VALUES
  (1,1),(1,2),(1,3),(1,5),(1,6),(1,7),
  (2,1),(2,2),(2,5),(2,6),
  (3,1),(3,3),(3,5),(3,7),
  (4,1),(4,2),(4,3),(4,4),(4,5),(4,6),(4,7),(4,8),
  (5,1),(5,2),(5,3),(5,4),(5,5),(5,6),(5,7),(5,8),(5,9),(5,10),
  (6,1),(6,2),(6,3),(6,5),(6,7),
  (7,1),(7,2),(7,3),(7,4),(7,5),(7,6),(7,7),(7,8),
  (8,1),(8,2),(8,3),(8,4),(8,8),
  (12,1),(12,2),(12,3),(12,4),(12,5);

-- Movimentação inicial de estoque (para o histórico começar coerente) ---
INSERT INTO estoque_movimentos (produto_id, tipo, quantidade, estoque_apos, motivo)
SELECT id, 'entrada', estoque, estoque, 'Estoque inicial (seed)' FROM produtos WHERE estoque > 0;

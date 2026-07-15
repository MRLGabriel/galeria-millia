-- ============================================================
-- Galeria Millia — schema MySQL/MariaDB
-- Compatível com o MySQL do cPanel da HostGator (Plano M).
-- Importe via phpMyAdmin, DEPOIS de criar o banco pelo painel
-- "MySQL® Databases" do cPanel (o nome real do banco e do
-- usuário virão prefixados, ex: seuusuario_galeriamillia).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Usuários ----------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role          ENUM('admin','artist','visitor') NOT NULL,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  headline      VARCHAR(200),
  bio           TEXT,
  avatar_color  VARCHAR(7),
  avatar_url    VARCHAR(255) NULL,           -- foto de perfil enviada pelo usuário
  cover_url     VARCHAR(255) NULL,           -- capa da página pública do artista
  blocked       TINYINT(1) NOT NULL DEFAULT 0,
  email_verified          TINYINT(1) NOT NULL DEFAULT 0,
  email_verify_token      VARCHAR(64) NULL,
  email_verify_expires    DATETIME NULL,
  email_verify_last_sent  DATETIME NULL,
  two_factor_enabled      TINYINT(1) NOT NULL DEFAULT 0,
  two_factor_code_hash    VARCHAR(64) NULL,
  two_factor_expires      DATETIME NULL,
  password_reset_token    VARCHAR(64) NULL,
  password_reset_expires  DATETIME NULL,
  deactivated             TINYINT(1) NOT NULL DEFAULT 0,
  deactivated_at          DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Cartões salvos (carteira) ----------
-- Nunca guarda o número completo do cartão nem o CVV — só o que é
-- necessário pra exibir de forma mascarada (últimos 4 dígitos, bandeira,
-- nome impresso e validade). Editar um cartão só muda esses metadados;
-- pra trocar o número, a pessoa remove e cadastra outro.
CREATE TABLE payment_cards (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  brand         VARCHAR(30) NOT NULL,
  holder_name   VARCHAR(150) NOT NULL,
  last4         CHAR(4) NOT NULL,
  exp_month     TINYINT UNSIGNED NOT NULL,
  exp_year      SMALLINT UNSIGNED NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_cards_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Categorias da galeria ----------
CREATE TABLE categories (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Séries / coleções de cada artista ----------
CREATE TABLE collections (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id INT UNSIGNED NOT NULL,
  name      VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_collections_artist_name (artist_id, name),
  CONSTRAINT fk_collections_artist FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Obras ----------
CREATE TABLE artworks (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id     INT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED,
  collection_id INT UNSIGNED,
  title         VARCHAR(200) NOT NULL,
  technique     VARCHAR(120),                -- ex.: "Óleo sobre tela" (separado da descrição)
  description   TEXT,
  price_cents   INT UNSIGNED NOT NULL,       -- UNSIGNED já impede preço negativo
  width_cm      DECIMAL(6,2),
  height_cm     DECIMAL(6,2),
  edition_size  INT UNSIGNED NULL,              -- NULL = obra única; N = edição limitada de N cópias
  edition_sold  INT UNSIGNED NOT NULL DEFAULT 0, -- quantas cópias da edição já foram vendidas
  package_weight_kg  DECIMAL(6,2),   -- peso da embalagem pronta pra envio
  package_length_cm  DECIMAL(6,2),
  package_width_cm   DECIMAL(6,2),
  package_height_cm  DECIMAL(6,2),
  approved      TINYINT(1) NOT NULL DEFAULT 0,
  sold          TINYINT(1) NOT NULL DEFAULT 0,
  views         INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_artworks_artist FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_artworks_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_artworks_collection FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL,
  KEY idx_artworks_artist (artist_id),
  KEY idx_artworks_category (category_id),
  KEY idx_artworks_listing (approved, sold)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Fotos de cada obra (galeria de imagens) ----------
CREATE TABLE artwork_images (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artwork_id INT UNSIGNED NOT NULL,
  url        VARCHAR(500) NOT NULL,
  position   INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_artwork_images_artwork FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  KEY idx_artwork_images_artwork (artwork_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Favoritos (usuário <-> obra) ----------
CREATE TABLE favorites (
  user_id    INT UNSIGNED NOT NULL,
  artwork_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, artwork_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_artwork FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  KEY idx_favorites_artwork (artwork_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Seguidores (visitante/artista <-> artista) ----------
CREATE TABLE follows (
  follower_id INT UNSIGNED NOT NULL,
  artist_id   INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, artist_id),
  CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_artist FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_follows_artist (artist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Comentários nas obras ----------
CREATE TABLE comments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artwork_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  body       TEXT NOT NULL,
  hidden     TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comments_artwork FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_comments_artwork (artwork_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Blocos da página pública do artista (page builder) ----------
CREATE TABLE artist_page_blocks (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id INT UNSIGNED NOT NULL,
  type      ENUM('hero','heading','text','quote','image','gallery','button','divider') NOT NULL,
  position  INT UNSIGNED NOT NULL DEFAULT 0,
  props     JSON NOT NULL,
  CONSTRAINT fk_page_blocks_artist FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_page_blocks_artist (artist_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Endereços (entrega do comprador OU origem de envio do artista) ----------
CREATE TABLE addresses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  kind           ENUM('delivery','origin') NOT NULL DEFAULT 'delivery',
  recipient_name VARCHAR(150) NOT NULL,
  cep            VARCHAR(9) NOT NULL,
  street         VARCHAR(200) NOT NULL,
  number         VARCHAR(20) NOT NULL,
  complement     VARCHAR(100),
  neighborhood   VARCHAR(120) NOT NULL,
  city           VARCHAR(120) NOT NULL,
  state          CHAR(2) NOT NULL,
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_addresses_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Pedidos (carrinho = pedido em status 'cart') ----------
CREATE TABLE orders (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  buyer_id       INT UNSIGNED NOT NULL,
  address_id     INT UNSIGNED,
  status         ENUM('cart','pending_payment','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'cart',
  payment_method ENUM('credit_card','pix','boleto'),
  total_cents    INT UNSIGNED NOT NULL DEFAULT 0,
  shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at        DATETIME NULL,
  CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users(id),
  CONSTRAINT fk_orders_address FOREIGN KEY (address_id) REFERENCES addresses(id),
  KEY idx_orders_buyer (buyer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Itens do pedido ----------
CREATE TABLE order_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       INT UNSIGNED NOT NULL,
  artwork_id     INT UNSIGNED NOT NULL,
  price_cents    INT UNSIGNED NOT NULL,       -- preço no momento da compra
  -- Modelo de negócio: a venda repassa 100% pro artista (comissão zero).
  -- A receita da galeria é a mensalidade dos artistas, cobrada fora do sistema.
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  -- Repasse ao artista: líquido = price_cents - gateway_fee_cents.
  -- gateway_fee_cents fica 0 até o gateway real (Mercado Pago) entrar.
  -- O frete não entra no repasse: fica com a galeria pra custear a etiqueta.
  gateway_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
  payout_done    TINYINT(1) NOT NULL DEFAULT 0,
  payout_at      DATETIME NULL,
  UNIQUE KEY uq_order_items_order_artwork (order_id, artwork_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_artwork FOREIGN KEY (artwork_id) REFERENCES artworks(id),
  KEY idx_order_items_artwork (artwork_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Configurações gerais (painel admin) ----------
CREATE TABLE settings (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Triggers
-- (usamos triggers em vez de CHECK para as regras de negócio,
-- porque CHECK só é aplicado de fato a partir do MySQL 8.0.16 /
-- MariaDB 10.2.1 — em versões mais antigas o cPanel aceita o
-- CHECK sem erro mas simplesmente ignora a regra)
-- ============================================================

DELIMITER $$

-- Mantém orders.total_cents em sincronia com os itens do pedido.
CREATE TRIGGER trg_order_items_ai AFTER INSERT ON order_items
FOR EACH ROW
BEGIN
  UPDATE orders SET total_cents = (
    SELECT COALESCE(SUM(price_cents), 0) FROM order_items WHERE order_id = NEW.order_id
  ) WHERE id = NEW.order_id;
END$$

CREATE TRIGGER trg_order_items_ad AFTER DELETE ON order_items
FOR EACH ROW
BEGIN
  UPDATE orders SET total_cents = (
    SELECT COALESCE(SUM(price_cents), 0) FROM order_items WHERE order_id = OLD.order_id
  ) WHERE id = OLD.order_id;
END$$

-- Quando um pedido passa a 'paid', registra o instante do pagamento.
CREATE TRIGGER trg_order_paid_at BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
  IF NEW.status = 'paid' AND OLD.status <> 'paid' THEN
    SET NEW.paid_at = NOW();
  END IF;
END$$

-- ...e marca as obras daquele pedido como vendidas.
CREATE TRIGGER trg_order_paid_mark_sold AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
  IF NEW.status = 'paid' AND OLD.status <> 'paid' THEN
    UPDATE artworks
      SET edition_sold = edition_sold + 1,
          sold = (edition_sold + 1) >= COALESCE(edition_size, 1)
      WHERE id IN (SELECT artwork_id FROM order_items WHERE order_id = NEW.id);
  END IF;
END$$

-- Impede adicionar ao pedido uma obra já esgotada (obra única vendida, ou
-- edição limitada com todas as cópias vendidas). O backend mantém
-- artworks.sold atualizado a cada venda (ver checkout em api.php).
CREATE TRIGGER trg_order_items_prevent_double_sale BEFORE INSERT ON order_items
FOR EACH ROW
BEGIN
  IF (SELECT sold FROM artworks WHERE id = NEW.artwork_id) = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Obra já vendida';
  END IF;
END$$

-- Impede seguir a si mesmo.
CREATE TRIGGER trg_follows_no_self BEFORE INSERT ON follows
FOR EACH ROW
BEGIN
  IF NEW.follower_id = NEW.artist_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não é possível seguir a si mesmo';
  END IF;
END$$

DELIMITER ;

-- ============================================================
-- Seed (dados de demonstração)
-- password_hash aqui está em texto puro só para teste local.
-- Em produção, gere os hashes com password_hash() do PHP (bcrypt)
-- antes de inserir de verdade.
-- ============================================================

INSERT INTO users (role, name, email, password_hash, headline, bio, avatar_color, blocked) VALUES
  ('admin',   'Helena Prado',    'admin@galeriamillia.com',  '$2y$10$15E.xWFlAPZTf1Zb5uc0uuRZBYj1J5tBVNZ8azK0j1pIs6MXvG4im', NULL, NULL, '#8A6240', 0),
  ('artist',  'Marina Duarte',   'marina@galeriamillia.com', '$2y$10$ZVW.sAf7lHm90CNlgoiAUeBcHuHukavXtPoabqQC5UKhswY/JWwL2', 'Pintura contemporânea & cor', 'Pesquiso o encontro entre geometria e afeto. Minhas séries partem de paisagens do interior de Goiás reduzidas a campos de cor.', '#A34438', 0),
  ('artist',  'Rafael Nogueira', 'rafael@galeriamillia.com', '$2y$10$ZVW.sAf7lHm90CNlgoiAUeBcHuHukavXtPoabqQC5UKhswY/JWwL2', 'Fotografia do cerrado', 'Fotografo o cerrado e suas bordas: o que resiste entre o asfalto e o mato.', '#3B5B45', 0),
  ('artist',  'Aiko Tanaka',     'aiko@galeriamillia.com',   '$2y$10$ZVW.sAf7lHm90CNlgoiAUeBcHuHukavXtPoabqQC5UKhswY/JWwL2', 'Arte digital generativa', 'Sistemas, ruído e acaso controlado. Cada obra é um algoritmo que só roda uma vez.', '#3B4C6B', 0),
  ('visitor', 'Caio Mendes',     'caio@email.com',           '$2y$10$CT1/tQ/.sQXs7jgHWCFr/.D0dRB5W2pUr/E0VdED8MVKJq/D6jToy', NULL, NULL, '#59544A', 0);

INSERT INTO categories (name) VALUES
  ('Pintura'), ('Fotografia'), ('Escultura'), ('Arte Digital'), ('Colagem');

SET @marina = (SELECT id FROM users WHERE email = 'marina@galeriamillia.com');
SET @rafael = (SELECT id FROM users WHERE email = 'rafael@galeriamillia.com');
SET @aiko   = (SELECT id FROM users WHERE email = 'aiko@galeriamillia.com');
SET @caio   = (SELECT id FROM users WHERE email = 'caio@email.com');

INSERT INTO collections (artist_id, name) VALUES
  (@marina, 'Série Campos'),
  (@marina, 'Estudos'),
  (@rafael, 'Cerrado'),
  (@aiko,   'Sistemas');

SET @catPintura = (SELECT id FROM categories WHERE name = 'Pintura');
SET @catFoto    = (SELECT id FROM categories WHERE name = 'Fotografia');
SET @catDigital = (SELECT id FROM categories WHERE name = 'Arte Digital');
SET @catColagem = (SELECT id FROM categories WHERE name = 'Colagem');

SET @colCampos   = (SELECT id FROM collections WHERE artist_id = @marina AND name = 'Série Campos');
SET @colEstudos  = (SELECT id FROM collections WHERE artist_id = @marina AND name = 'Estudos');
SET @colCerrado  = (SELECT id FROM collections WHERE artist_id = @rafael AND name = 'Cerrado');
SET @colSistemas = (SELECT id FROM collections WHERE artist_id = @aiko   AND name = 'Sistemas');

INSERT INTO artworks (artist_id, category_id, collection_id, title, description, price_cents, width_cm, height_cm, edition_size, edition_sold, approved, sold, views) VALUES
  (@marina, @catPintura, @colCampos,   'Campo Azul nº 7',   'Acrílica sobre tela,', 190000, 120, 90, NULL, 0, 1, 0, 860),
  (@marina, @catPintura, @colCampos,   'Travessia',         'Acrílica e pastel oleoso sobre tela,', 160000, 100, 80, NULL, 0, 1, 0, 540),
  (@marina, @catColagem, @colEstudos,  'Quarta Margem',     'Colagem sobre papel algodão,', 89000, 60, 42, NULL, 0, 1, 0, 310),
  (@rafael, @catFoto,    @colCerrado,  'Cerrado #12',       'Impressão fine art em papel de algodão,', 140000, 90, 60, 10, 6, 1, 0, 930),
  (@rafael, @catFoto,    @colCerrado,  'Beira de Asfalto',  'Impressão fine art em papel de algodão,', 140000, 90, 60, 10, 3, 1, 0, 480),
  (@rafael, @catFoto,    @colCerrado,  'Chuva Seca',        'Díptico, impressão fine art,', 195000, 100, 70, 10, 10, 1, 1, 1200),
  (@aiko,   @catDigital, @colSistemas, 'Ruído/Flor',        'Obra generativa única, impressa em metacrilato,', 90000, 50, 50, NULL, 0, 1, 0, 410),
  (@aiko,   @catDigital, @colSistemas, 'Sistema 044',       'Algoritmo determinístico, impressão única,', 120000, 70, 70, NULL, 0, 1, 0, 720),
  (@aiko,   @catDigital, @colSistemas, 'Jardim de Estado',  'Tríptico generativo,', 180000, 120, 40, NULL, 0, 0, 0, 0),
  (@marina, @catPintura, @colEstudos,  'Estudo para Manhã', 'Guache sobre papel,', 68000, 30, 21, NULL, 0, 0, 0, 0);

SET @obraCampoAzul = (SELECT id FROM artworks WHERE title = 'Campo Azul nº 7');
SET @obraCerrado12 = (SELECT id FROM artworks WHERE title = 'Cerrado #12');

INSERT INTO comments (artwork_id, user_id, body, hidden) VALUES
  (@obraCampoAzul, @caio,   'A vibração dessas cores ao vivo deve ser incrível.', 0),
  (@obraCampoAzul, @rafael, 'Marina só evolui. Que série!', 0),
  (@obraCerrado12, @caio,   'O cerrado merece esse olhar.', 0);

INSERT INTO follows (follower_id, artist_id) VALUES (@caio, @marina);

INSERT INTO settings (`key`, `value`) VALUES
  ('gallery_name', 'Galeria Millia'),
  ('commission_pct', '0'),
  ('curation_required', '1');

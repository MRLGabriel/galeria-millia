-- ============================================================
-- Galeria Millia — schema SQLite
-- Galeria de arte com marketplace: artistas vendem obras originais,
-- visitantes compram, seguem artistas, comentam e favoritam.
-- ============================================================

PRAGMA foreign_keys = ON;

-- ---------- Usuários ----------
CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  role          TEXT NOT NULL CHECK (role IN ('admin','artist','visitor')),
  name          TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  headline      TEXT,               -- "Pintura contemporânea & cor"
  bio           TEXT,
  avatar_color  TEXT,               -- cor hex usada no avatar de iniciais
  avatar_url    TEXT,               -- foto de perfil enviada pelo usuário
  cover_url     TEXT,               -- capa da página pública do artista
  blocked       INTEGER NOT NULL DEFAULT 0 CHECK (blocked IN (0,1)),
  email_verified          INTEGER NOT NULL DEFAULT 0 CHECK (email_verified IN (0,1)),
  email_verify_token      TEXT,
  email_verify_expires    TEXT,
  email_verify_last_sent  TEXT,
  two_factor_enabled      INTEGER NOT NULL DEFAULT 0 CHECK (two_factor_enabled IN (0,1)),
  two_factor_code_hash    TEXT,
  two_factor_expires      TEXT,
  password_reset_token    TEXT,
  password_reset_expires  TEXT,
  deactivated             INTEGER NOT NULL DEFAULT 0 CHECK (deactivated IN (0,1)),
  deactivated_at          TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Cartões salvos (carteira) ----------
CREATE TABLE payment_cards (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  brand         TEXT NOT NULL,
  holder_name   TEXT NOT NULL,
  last4         TEXT NOT NULL,
  exp_month     INTEGER NOT NULL,
  exp_year      INTEGER NOT NULL,
  is_default    INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_cards_user ON payment_cards(user_id);

-- ---------- Categorias da galeria ----------
CREATE TABLE categories (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);

-- ---------- Séries / coleções de cada artista ----------
CREATE TABLE collections (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  artist_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name      TEXT NOT NULL,
  UNIQUE (artist_id, name)
);

-- ---------- Obras ----------
CREATE TABLE artworks (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  artist_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  category_id   INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  collection_id INTEGER REFERENCES collections(id) ON DELETE SET NULL,
  title         TEXT NOT NULL,
  technique     TEXT,
  description   TEXT,
  price_cents   INTEGER NOT NULL CHECK (price_cents >= 0),
  width_cm      REAL,
  height_cm     REAL,
  edition_size  INTEGER,                          -- NULL = obra única; N = edição limitada de N cópias
  edition_sold  INTEGER NOT NULL DEFAULT 0,        -- quantas cópias da edição já foram vendidas
  package_weight_kg REAL,
  package_length_cm REAL,
  package_width_cm  REAL,
  package_height_cm REAL,
  approved      INTEGER NOT NULL DEFAULT 0 CHECK (approved IN (0,1)),
  sold          INTEGER NOT NULL DEFAULT 0 CHECK (sold IN (0,1)),
  views         INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_artworks_artist   ON artworks(artist_id);
CREATE INDEX idx_artworks_category ON artworks(category_id);
CREATE INDEX idx_artworks_listing  ON artworks(approved, sold);

-- ---------- Fotos de cada obra (galeria de imagens) ----------
CREATE TABLE artwork_images (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  artwork_id INTEGER NOT NULL REFERENCES artworks(id) ON DELETE CASCADE,
  url        TEXT NOT NULL,
  position   INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_artwork_images_artwork ON artwork_images(artwork_id);

-- ---------- Favoritos (usuário <-> obra) ----------
CREATE TABLE favorites (
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  artwork_id INTEGER NOT NULL REFERENCES artworks(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (user_id, artwork_id)
);

CREATE INDEX idx_favorites_artwork ON favorites(artwork_id);

-- ---------- Seguidores (visitante/artista <-> artista) ----------
CREATE TABLE follows (
  follower_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  artist_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (follower_id, artist_id),
  CHECK (follower_id <> artist_id)
);

CREATE INDEX idx_follows_artist ON follows(artist_id);

-- ---------- Comentários nas obras ----------
CREATE TABLE comments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  artwork_id INTEGER NOT NULL REFERENCES artworks(id) ON DELETE CASCADE,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body       TEXT NOT NULL,
  hidden     INTEGER NOT NULL DEFAULT 0 CHECK (hidden IN (0,1)),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_comments_artwork ON comments(artwork_id);

-- ---------- Blocos da página pública do artista (page builder) ----------
CREATE TABLE artist_page_blocks (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  artist_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type      TEXT NOT NULL CHECK (type IN ('hero','heading','text','quote','image','gallery','button','divider')),
  position  INTEGER NOT NULL DEFAULT 0,
  props     TEXT NOT NULL DEFAULT '{}'   -- JSON: title, subtitle, bg, text, align, obra_ids, label, url...
);

CREATE INDEX idx_page_blocks_artist ON artist_page_blocks(artist_id, position);

-- ---------- Endereços (entrega do comprador OU origem de envio do artista) ----------
CREATE TABLE addresses (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind           TEXT NOT NULL DEFAULT 'delivery' CHECK (kind IN ('delivery','origin')),
  recipient_name TEXT NOT NULL,
  cep            TEXT NOT NULL,
  street         TEXT NOT NULL,
  number         TEXT NOT NULL,
  complement     TEXT,
  neighborhood   TEXT NOT NULL,
  city           TEXT NOT NULL,
  state          TEXT NOT NULL,
  is_default     INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1))
);

CREATE INDEX idx_addresses_user ON addresses(user_id);

-- ---------- Pedidos (carrinho = pedido em status 'cart') ----------
CREATE TABLE orders (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  buyer_id       INTEGER NOT NULL REFERENCES users(id),
  address_id     INTEGER REFERENCES addresses(id),
  status         TEXT NOT NULL DEFAULT 'cart'
                   CHECK (status IN ('cart','pending_payment','paid','shipped','delivered','cancelled')),
  payment_method TEXT CHECK (payment_method IN ('credit_card','pix','boleto')),
  total_cents    INTEGER NOT NULL DEFAULT 0,
  shipping_cents INTEGER NOT NULL DEFAULT 0,
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  paid_at        TEXT
);

CREATE INDEX idx_orders_buyer  ON orders(buyer_id, status);

-- ---------- Itens do pedido ----------
CREATE TABLE order_items (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  artwork_id      INTEGER NOT NULL REFERENCES artworks(id),
  price_cents     INTEGER NOT NULL,      -- preço no momento da compra
  -- Modelo de negócio: a venda repassa 100% pro artista (comissão zero).
  -- A receita da galeria é a mensalidade dos artistas, cobrada fora do sistema.
  commission_pct  REAL NOT NULL DEFAULT 0,
  -- Repasse ao artista: líquido = price_cents - gateway_fee_cents.
  -- gateway_fee_cents fica 0 até o gateway real (Mercado Pago) entrar.
  -- O frete não entra no repasse: fica com a galeria pra custear a etiqueta.
  gateway_fee_cents INTEGER NOT NULL DEFAULT 0,
  payout_done     INTEGER NOT NULL DEFAULT 0 CHECK (payout_done IN (0,1)),
  payout_at       TEXT,
  UNIQUE (order_id, artwork_id)
);

CREATE INDEX idx_order_items_artwork ON order_items(artwork_id);

-- ---------- Configurações gerais (painel admin) ----------
CREATE TABLE settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- ============================================================
-- Triggers
-- ============================================================

-- Mantém orders.total_cents em sincronia com os itens do pedido.
CREATE TRIGGER trg_order_items_ai AFTER INSERT ON order_items BEGIN
  UPDATE orders SET total_cents = (
    SELECT COALESCE(SUM(price_cents), 0) FROM order_items WHERE order_id = NEW.order_id
  ) WHERE id = NEW.order_id;
END;

CREATE TRIGGER trg_order_items_ad AFTER DELETE ON order_items BEGIN
  UPDATE orders SET total_cents = (
    SELECT COALESCE(SUM(price_cents), 0) FROM order_items WHERE order_id = OLD.order_id
  ) WHERE id = OLD.order_id;
END;

-- Quando um pedido é marcado como pago, marca as obras dele como vendidas.
CREATE TRIGGER trg_order_paid AFTER UPDATE OF status ON orders
WHEN NEW.status = 'paid' AND OLD.status <> 'paid' BEGIN
  UPDATE artworks SET
    edition_sold = edition_sold + 1,
    sold = (edition_sold + 1) >= COALESCE(edition_size, 1)
    WHERE id IN (SELECT artwork_id FROM order_items WHERE order_id = NEW.id);
  UPDATE orders SET paid_at = datetime('now') WHERE id = NEW.id;
END;

-- Impede adicionar ao pedido uma obra já esgotada (obra única vendida, ou
-- edição limitada com todas as cópias vendidas).
CREATE TRIGGER trg_order_items_prevent_double_sale
BEFORE INSERT ON order_items
WHEN (SELECT sold FROM artworks WHERE id = NEW.artwork_id) = 1
BEGIN
  SELECT RAISE(ABORT, 'Obra já vendida');
END;

-- ============================================================
-- Seed (dados de demonstração — equivalentes às contas do protótipo)
-- Observação: password_hash aqui está em texto puro só para teste local.
-- Em produção, gere os hashes com bcrypt/argon2 antes de inserir.
-- ============================================================

INSERT INTO users (role, name, email, password_hash, headline, bio, avatar_color, blocked) VALUES
  ('admin',   'Helena Prado',    'admin@galeriamillia.com',  '$2y$10$15E.xWFlAPZTf1Zb5uc0uuRZBYj1J5tBVNZ8azK0j1pIs6MXvG4im', NULL, NULL, '#8A6240', 0),
  ('artist',  'Marina Duarte',   'marina@galeriamillia.com', '$2y$10$ZVW.sAf7lHm90CNlgoiAUeBcHuHukavXtPoabqQC5UKhswY/JWwL2', 'Pintura contemporânea & cor', 'Pesquiso o encontro entre geometria e afeto. Minhas séries partem de paisagens do interior de Goiás reduzidas a campos de cor.', '#A34438', 0),
  ('artist',  'Rafael Nogueira', 'rafael@galeriamillia.com', '$2y$10$ZVW.sAf7lHm90CNlgoiAUeBcHuHukavXtPoabqQC5UKhswY/JWwL2', 'Fotografia do cerrado', 'Fotografo o cerrado e suas bordas: o que resiste entre o asfalto e o mato.', '#3B5B45', 0),
  ('artist',  'Aiko Tanaka',     'aiko@galeriamillia.com',   '$2y$10$ZVW.sAf7lHm90CNlgoiAUeBcHuHukavXtPoabqQC5UKhswY/JWwL2', 'Arte digital generativa', 'Sistemas, ruído e acaso controlado. Cada obra é um algoritmo que só roda uma vez.', '#3B4C6B', 0),
  ('visitor', 'Caio Mendes',     'caio@email.com',           '$2y$10$CT1/tQ/.sQXs7jgHWCFr/.D0dRB5W2pUr/E0VdED8MVKJq/D6jToy', NULL, NULL, '#59544A', 0);

INSERT INTO categories (name) VALUES
  ('Pintura'), ('Fotografia'), ('Escultura'), ('Arte Digital'), ('Colagem');

INSERT INTO collections (artist_id, name) VALUES
  ((SELECT id FROM users WHERE email = 'marina@galeriamillia.com'), 'Série Campos'),
  ((SELECT id FROM users WHERE email = 'marina@galeriamillia.com'), 'Estudos'),
  ((SELECT id FROM users WHERE email = 'rafael@galeriamillia.com'), 'Cerrado'),
  ((SELECT id FROM users WHERE email = 'aiko@galeriamillia.com'), 'Sistemas');

INSERT INTO artworks (artist_id, category_id, collection_id, title, description, price_cents, width_cm, height_cm, edition_size, edition_sold, approved, sold, views) VALUES
  ((SELECT id FROM users WHERE email='marina@galeriamillia.com'), (SELECT id FROM categories WHERE name='Pintura'), (SELECT id FROM collections WHERE name='Série Campos'), 'Campo Azul nº 7', 'Acrílica sobre tela,', 190000, 120, 90, NULL, 0, 1, 0, 860),
  ((SELECT id FROM users WHERE email='marina@galeriamillia.com'), (SELECT id FROM categories WHERE name='Pintura'), (SELECT id FROM collections WHERE name='Série Campos'), 'Travessia', 'Acrílica e pastel oleoso sobre tela,', 160000, 100, 80, NULL, 0, 1, 0, 540),
  ((SELECT id FROM users WHERE email='marina@galeriamillia.com'), (SELECT id FROM categories WHERE name='Colagem'), (SELECT id FROM collections WHERE name='Estudos'), 'Quarta Margem', 'Colagem sobre papel algodão,', 89000, 60, 42, NULL, 0, 1, 0, 310),
  ((SELECT id FROM users WHERE email='rafael@galeriamillia.com'), (SELECT id FROM categories WHERE name='Fotografia'), (SELECT id FROM collections WHERE name='Cerrado'), 'Cerrado #12', 'Impressão fine art em papel de algodão,', 140000, 90, 60, 10, 6, 1, 0, 930),
  ((SELECT id FROM users WHERE email='rafael@galeriamillia.com'), (SELECT id FROM categories WHERE name='Fotografia'), (SELECT id FROM collections WHERE name='Cerrado'), 'Beira de Asfalto', 'Impressão fine art em papel de algodão,', 140000, 90, 60, 10, 3, 1, 0, 480),
  ((SELECT id FROM users WHERE email='rafael@galeriamillia.com'), (SELECT id FROM categories WHERE name='Fotografia'), (SELECT id FROM collections WHERE name='Cerrado'), 'Chuva Seca', 'Díptico, impressão fine art,', 195000, 100, 70, 10, 10, 1, 1, 1200),
  ((SELECT id FROM users WHERE email='aiko@galeriamillia.com'), (SELECT id FROM categories WHERE name='Arte Digital'), (SELECT id FROM collections WHERE name='Sistemas'), 'Ruído/Flor', 'Obra generativa única, impressa em metacrilato,', 90000, 50, 50, NULL, 0, 1, 0, 410),
  ((SELECT id FROM users WHERE email='aiko@galeriamillia.com'), (SELECT id FROM categories WHERE name='Arte Digital'), (SELECT id FROM collections WHERE name='Sistemas'), 'Sistema 044', 'Algoritmo determinístico, impressão única,', 120000, 70, 70, NULL, 0, 1, 0, 720),
  ((SELECT id FROM users WHERE email='aiko@galeriamillia.com'), (SELECT id FROM categories WHERE name='Arte Digital'), (SELECT id FROM collections WHERE name='Sistemas'), 'Jardim de Estado', 'Tríptico generativo,', 180000, 120, 40, NULL, 0, 0, 0, 0),
  ((SELECT id FROM users WHERE email='marina@galeriamillia.com'), (SELECT id FROM categories WHERE name='Pintura'), (SELECT id FROM collections WHERE name='Estudos'), 'Estudo para Manhã', 'Guache sobre papel,', 68000, 30, 21, NULL, 0, 0, 0, 0);

INSERT INTO comments (artwork_id, user_id, body, hidden) VALUES
  ((SELECT id FROM artworks WHERE title='Campo Azul nº 7'), (SELECT id FROM users WHERE email='caio@email.com'), 'A vibração dessas cores ao vivo deve ser incrível.', 0),
  ((SELECT id FROM artworks WHERE title='Campo Azul nº 7'), (SELECT id FROM users WHERE email='rafael@galeriamillia.com'), 'Marina só evolui. Que série!', 0),
  ((SELECT id FROM artworks WHERE title='Cerrado #12'), (SELECT id FROM users WHERE email='caio@email.com'), 'O cerrado merece esse olhar.', 0);

INSERT INTO follows (follower_id, artist_id) VALUES
  ((SELECT id FROM users WHERE email='caio@email.com'), (SELECT id FROM users WHERE email='marina@galeriamillia.com'));

INSERT INTO settings (key, value) VALUES
  ('gallery_name', 'Galeria Millia'),
  ('commission_pct', '0'),
  ('curation_required', '1');

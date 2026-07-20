-- ============================================================
-- Galeria Millia — migração: assinaturas dos artistas (Fase 1)
-- Rode UMA vez no banco de produção (cPanel → phpMyAdmin → banco
-- gabr7283_galeriamillia → aba SQL → cole e execute).
--
-- Cria apenas TABELAS NOVAS + os planos. NÃO altera nada existente,
-- então não afeta quem já publica. As assinaturas e os perks dos
-- artistas atuais são preenchidos sozinhos na primeira carga do site.
-- ============================================================

-- Catálogo de planos. features = JSON com os limites de cada plano.
-- max_obras/max_colecoes = null significa ILIMITADO.
CREATE TABLE plans (
  code               VARCHAR(20)  NOT NULL PRIMARY KEY,   -- 'free','gold','diamond'
  name               VARCHAR(60)  NOT NULL,
  price_cents        INT UNSIGNED NOT NULL DEFAULT 0,     -- mensal: 2990 = R$ 29,90
  price_annual_cents INT UNSIGNED NOT NULL DEFAULT 0,     -- anual (com desconto): 29900 = R$ 299,00
  features           TEXT         NOT NULL,               -- JSON de limites/recursos
  sort_order         INT          NOT NULL DEFAULT 0,
  active             TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anual = 10x o mensal (2 meses grátis). Ajuste os valores se quiser outro desconto.
INSERT INTO plans (code, name, price_cents, price_annual_cents, features, sort_order) VALUES
 ('free',    'Free',    0,    0,     '{"max_obras":10,"max_colecoes":0,"acervo":false,"adote_artista":false}',      1),
 ('gold',    'Gold',    2990, 29900, '{"max_obras":null,"max_colecoes":null,"acervo":true,"adote_artista":false}',  2),
 ('diamond', 'Diamond', 3990, 39900, '{"max_obras":null,"max_colecoes":null,"acervo":true,"adote_artista":true}',  3);

-- Assinatura atual de cada artista (uma por artista). A cobrança recorrente
-- via Mercado Pago (Assinaturas) grava o id do preapproval e o fim do período.
CREATE TABLE subscriptions (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id          INT UNSIGNED NOT NULL,
  plan_code          VARCHAR(20)  NOT NULL DEFAULT 'free',
  period             ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
  status             ENUM('active','pending','past_due','cancelled','paused') NOT NULL DEFAULT 'active',
  mp_preapproval_id  VARCHAR(64)  NULL,             -- id da assinatura no Mercado Pago
  started_at         DATETIME     NULL,
  current_period_end DATETIME     NULL,
  free_until         DATETIME     NULL,             -- perk "2 meses grátis": plano liberado sem cobrança até aqui
  cancel_at          DATETIME     NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sub_artist (artist_id),
  CONSTRAINT fk_sub_artist FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan   FOREIGN KEY (plan_code) REFERENCES plans(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perks de lançamento (early adopters). Concedidos uma vez por artista.
--  instagram_free  = publicações no Instagram da galeria sem custo (primeiros 5)
--  two_months_free = 2 meses de Gold sem cobrança pra montar a galeria (primeiros 10)
CREATE TABLE artist_perks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id  INT UNSIGNED NOT NULL,
  perk_code  ENUM('instagram_free','two_months_free') NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes      VARCHAR(255) NULL,
  UNIQUE KEY uq_perk (artist_id, perk_code),
  CONSTRAINT fk_perk_artist FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

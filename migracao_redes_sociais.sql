-- ============================================================
-- Galeria Millia — migração: links de redes sociais do artista
-- Rode UMA vez no banco de produção (cPanel → phpMyAdmin → banco
-- gabr7283_galeriamillia → aba SQL → cole e execute).
-- ============================================================

ALTER TABLE users
  ADD COLUMN youtube_url   VARCHAR(255) NULL,
  ADD COLUMN instagram_url VARCHAR(255) NULL,
  ADD COLUMN facebook_url  VARCHAR(255) NULL;

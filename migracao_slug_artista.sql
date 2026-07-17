-- ============================================================
-- Galeria Millia — migração: slug do artista (URLs /artista/nome)
-- Rode UMA vez no banco de produção (cPanel → phpMyAdmin → banco
-- gabr7283_galeriamillia → aba SQL → cole e execute).
--
-- Os slugs dos artistas existentes são preenchidos sozinhos na
-- primeira vez que o site carregar (o backend gera a partir do nome).
-- ============================================================

ALTER TABLE users
  ADD COLUMN slug VARCHAR(160) NULL AFTER name,
  ADD UNIQUE KEY uniq_users_slug (slug);

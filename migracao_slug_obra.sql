-- ============================================================
-- Galeria Millia — migração: slug da obra (URLs /obra/nome)
-- Rode UMA vez no banco de produção (cPanel → phpMyAdmin → banco
-- gabr7283_galeriamillia → aba SQL → cole e execute).
--
-- Os slugs das obras existentes são preenchidos sozinhos na primeira
-- vez que o site carregar (o backend gera a partir do título).
-- Títulos repetidos ("Sem Título") viram sem-titulo, sem-titulo-2, ...
-- ============================================================

ALTER TABLE artworks
  ADD COLUMN slug VARCHAR(180) NULL AFTER title,
  ADD UNIQUE KEY uniq_artworks_slug (slug);

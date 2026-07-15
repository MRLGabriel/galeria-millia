-- ============================================================
-- Galeria Millia — migração: campo "Técnica" na obra
-- Rode UMA vez no banco de produção (cPanel → phpMyAdmin → banco
-- gabr7283_galeriamillia → aba SQL → cole e execute).
-- Seguro rodar de novo: se a coluna já existir, o MySQL só acusa erro
-- e nada é alterado.
-- ============================================================

ALTER TABLE artworks
  ADD COLUMN technique VARCHAR(120) NULL AFTER title;

-- ============================================================
-- Galeria Millia — migração: cota de obras por ciclo mensal
-- Rode UMA vez no banco de produção (cPanel → phpMyAdmin → aba SQL).
-- Atualiza as features dos planos para o modelo de COTA (novas obras por
-- ciclo mensal), no lugar do teto total antigo. Não mexe em preços.
-- ============================================================

UPDATE plans SET features = '{"obras_cycle":1,"max_colecoes":0,"acervo":false,"adote_artista":false}'                            WHERE code = 'free';
UPDATE plans SET features = '{"obras_cycle":2,"obras_cycle_annual":5,"max_colecoes":null,"acervo":true,"adote_artista":false}'   WHERE code = 'gold';
UPDATE plans SET features = '{"obras_cycle":5,"obras_cycle_annual":10,"max_colecoes":null,"acervo":true,"adote_artista":true}'   WHERE code = 'diamond';

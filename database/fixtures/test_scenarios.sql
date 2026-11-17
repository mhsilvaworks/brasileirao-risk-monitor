-- ============================================================================
-- FIXTURES CONTROLADAS DE TESTE (NÃO EXECUTAR EM AMBIENTE DE PRODUÇÃO)
-- Finalidade: Validar StandingsService e motor de simulação em cenários
-- determinísticos (classificação, desempate, jogo adiado, placar NULL e STJD).
-- Todos os external_id de partidas de fixture usam faixa >= 900000.
-- ============================================================================

USE brasileirao_risco;

-- Limpa apenas partidas de fixture anteriores (faixa >= 900000) para idempotencia
DELETE FROM partidas WHERE external_id >= 900000;

-- Reseta eventuais punicoes de teste na temporada 2026
UPDATE clubes_temporadas ct
JOIN temporadas t ON t.id = ct.temporada_id
SET ct.ajuste_pontos = 0, ct.motivo_ajuste = NULL
WHERE t.external_id = 2474;

-- CENÁRIO 1: Punição administrativa (-4 pontos no STJD para external_id 1770 - Botafogo)
UPDATE clubes_temporadas ct
JOIN temporadas t ON t.id = ct.temporada_id
JOIN clubes c ON c.id = ct.clube_id
SET ct.ajuste_pontos = -4,
    ct.motivo_ajuste = '[FIXTURE TESTE] Punição administrativa STJD (-4 pts)'
WHERE t.external_id = 2474 AND c.external_id = 1770;

-- CENÁRIO 2: Partidas determinísticas para validar pontos, vitórias, saldo, gols pró,
-- partida adiada (POSTPONED) e partida agendada com placar NULL (SCHEDULED).
INSERT INTO partidas (external_id, temporada_id, rodada, data_hora_utc, status, mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor)
SELECT 900001, t.id, 1, '2026-02-01 19:00:00', 'FINISHED', m.id, v.id, 3, 0, 'HOME_TEAM'
FROM temporadas t, clubes m, clubes v
WHERE t.external_id = 2474 AND m.external_id = 1783 AND v.external_id = 1771; -- FLA 3x0 CRU (FLA: 3 pts, 1V, SG +3, GP 3)

INSERT INTO partidas (external_id, temporada_id, rodada, data_hora_utc, status, mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor)
SELECT 900002, t.id, 1, '2026-02-01 21:30:00', 'FINISHED', m.id, v.id, 2, 0, 'HOME_TEAM'
FROM temporadas t, clubes m, clubes v
WHERE t.external_id = 2474 AND m.external_id = 1769 AND v.external_id = 1765; -- PAL 2x0 FLU (PAL: 3 pts, 1V, SG +2, GP 2 -> perde desempate SG para FLA)

INSERT INTO partidas (external_id, temporada_id, rodada, data_hora_utc, status, mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor)
SELECT 900003, t.id, 1, '2026-02-02 19:00:00', 'FINISHED', m.id, v.id, 2, 1, 'HOME_TEAM'
FROM temporadas t, clubes m, clubes v
WHERE t.external_id = 2474 AND m.external_id = 1770 AND v.external_id = 1777; -- BOT 2x1 BAH (BOT ganha 3 pts, mas com -4 de punição fica com -1 pt)

-- Partida ADIADA (POSTPONED) com placar NULL (não deve contar pontos nem jogos)
INSERT INTO partidas (external_id, temporada_id, rodada, data_hora_utc, status, mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor)
SELECT 900004, t.id, 2, '2026-02-08 19:00:00', 'POSTPONED', m.id, v.id, NULL, NULL, NULL
FROM temporadas t, clubes m, clubes v
WHERE t.external_id = 2474 AND m.external_id = 1783 AND v.external_id = 1769;

-- Partida AGENDADA (SCHEDULED) com placar NULL (deve entrar apenas na simulação Monte Carlo)
INSERT INTO partidas (external_id, temporada_id, rodada, data_hora_utc, status, mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor)
SELECT 900005, t.id, 2, '2026-02-08 21:30:00', 'SCHEDULED', m.id, v.id, NULL, NULL, NULL
FROM temporadas t, clubes m, clubes v
WHERE t.external_id = 2474 AND m.external_id = 1771 AND v.external_id = 1765;
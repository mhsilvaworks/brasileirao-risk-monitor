-- ============================================================================
-- FIXTURES DETERMINÍSTICAS (SOMENTE EM BANCO DE TESTES)
--
-- Pré-requisito: o banco deve ter sido populado por uma sincronização da API
-- football-data.org. Este arquivo cria somente partidas artificiais de teste.
-- Não cadastra clubes/temporadas fictícios e não altera ajustes administrativos.
--
-- IDs 2000000001..2000000005 são reservados para estas partidas de fixture.
-- ============================================================================

USE brasileirao_risco;

START TRANSACTION;

-- Remove somente os cinco IDs reservados a este cenário. Não apaga partidas
-- reais e não altera dados administrativos dos clubes.
DELETE FROM partidas
WHERE external_id BETWEEN 2000000001 AND 2000000005;

-- Identifica a temporada mais recente do Brasileirão já sincronizada e usa
-- os IDs externos oficiais dos clubes para resolver as chaves internas.
-- Se algum clube não fizer parte da temporada, a respectiva fixture não será
-- criada; por isso, execute após sincronizar clubes e temporada pela API.

-- CENÁRIO 1: vitória do Flamengo sobre o Cruzeiro.
INSERT INTO partidas (
    external_id, temporada_id, rodada, data_hora_utc, status,
    mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor
)
SELECT
    2000000001, t.id, 1, '2026-02-01 19:00:00', 'FINISHED',
    mandante.id, visitante.id, 3, 0, 'HOME_TEAM'
FROM competicoes c
JOIN temporadas t ON t.competicao_id = c.id
JOIN clubes mandante ON mandante.external_id = 1783
JOIN clubes visitante ON visitante.external_id = 1771
WHERE c.codigo = 'BSA'
  AND t.ano = (
      SELECT MAX(t2.ano)
      FROM temporadas t2
      WHERE t2.competicao_id = c.id
  );

-- CENÁRIO 2: vitória do Palmeiras sobre o Fluminense.
INSERT INTO partidas (
    external_id, temporada_id, rodada, data_hora_utc, status,
    mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor
)
SELECT
    2000000002, t.id, 1, '2026-02-01 21:30:00', 'FINISHED',
    mandante.id, visitante.id, 2, 0, 'HOME_TEAM'
FROM competicoes c
JOIN temporadas t ON t.competicao_id = c.id
JOIN clubes mandante ON mandante.external_id = 1769
JOIN clubes visitante ON visitante.external_id = 1765
WHERE c.codigo = 'BSA'
  AND t.ano = (
      SELECT MAX(t2.ano)
      FROM temporadas t2
      WHERE t2.competicao_id = c.id
  );

-- CENÁRIO 3: vitória do Botafogo sobre o Bahia.
INSERT INTO partidas (
    external_id, temporada_id, rodada, data_hora_utc, status,
    mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor
)
SELECT
    2000000003, t.id, 1, '2026-02-02 19:00:00', 'FINISHED',
    mandante.id, visitante.id, 2, 1, 'HOME_TEAM'
FROM competicoes c
JOIN temporadas t ON t.competicao_id = c.id
JOIN clubes mandante ON mandante.external_id = 1770
JOIN clubes visitante ON visitante.external_id = 1777
WHERE c.codigo = 'BSA'
  AND t.ano = (
      SELECT MAX(t2.ano)
      FROM temporadas t2
      WHERE t2.competicao_id = c.id
  );

-- CENÁRIO 4: partida adiada com placar NULL.
INSERT INTO partidas (
    external_id, temporada_id, rodada, data_hora_utc, status,
    mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor
)
SELECT
    2000000004, t.id, 2, '2026-02-08 19:00:00', 'POSTPONED',
    mandante.id, visitante.id, NULL, NULL, NULL
FROM competicoes c
JOIN temporadas t ON t.competicao_id = c.id
JOIN clubes mandante ON mandante.external_id = 1783
JOIN clubes visitante ON visitante.external_id = 1769
WHERE c.codigo = 'BSA'
  AND t.ano = (
      SELECT MAX(t2.ano)
      FROM temporadas t2
      WHERE t2.competicao_id = c.id
  );

-- CENÁRIO 5: partida agendada com placar NULL.
INSERT INTO partidas (
    external_id, temporada_id, rodada, data_hora_utc, status,
    mandante_id, visitante_id, gols_mandante, gols_visitante, vencedor
)
SELECT
    2000000005, t.id, 2, '2026-02-08 21:30:00', 'SCHEDULED',
    mandante.id, visitante.id, NULL, NULL, NULL
FROM competicoes c
JOIN temporadas t ON t.competicao_id = c.id
JOIN clubes mandante ON mandante.external_id = 1771
JOIN clubes visitante ON visitante.external_id = 1765
WHERE c.codigo = 'BSA'
  AND t.ano = (
      SELECT MAX(t2.ano)
      FROM temporadas t2
      WHERE t2.competicao_id = c.id
  );

COMMIT;

-- Confira se as cinco fixtures foram criadas. Resultado esperado: 5 linhas.
SELECT external_id, status, gols_mandante, gols_visitante
FROM partidas
WHERE external_id BETWEEN 2000000001 AND 2000000005
ORDER BY external_id;

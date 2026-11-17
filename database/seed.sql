USE brasileirao_risco;

-- 1. Competicao oficial (football-data.org: BSA)
INSERT INTO competicoes (external_id, nome, codigo, emblema_url)
VALUES (2013, 'Campeonato Brasileiro Série A', 'BSA', 'https://crests.football-data.org/bsa.png')
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    codigo = VALUES(codigo),
    emblema_url = VALUES(emblema_url);

-- 2. Temporada 2026 (external_id: 2474)
INSERT INTO temporadas (external_id, competicao_id, ano, data_inicio, data_fim, rodada_atual)
SELECT 2474, id, 2026, '2026-01-28', '2026-12-02', NULL
FROM competicoes
WHERE external_id = 2013
ON DUPLICATE KEY UPDATE
    ano = VALUES(ano),
    data_inicio = VALUES(data_inicio),
    data_fim = VALUES(data_fim);

-- 3. Carga inicial dos 20 clubes reais da Serie A 2026 (IDs externos oficiais da football-data.org)
-- Nota: sigla nao possui restricao UNIQUE (ex: COR pertence a Corinthians e Coritiba).
INSERT INTO clubes (external_id, nome, nome_curto, sigla, escudo_url) VALUES
(1783, 'CR Flamengo', 'Flamengo', 'FLA', 'https://crests.football-data.org/1783.png'),
(1769, 'SE Palmeiras', 'Palmeiras', 'PAL', 'https://crests.football-data.org/1769.png'),
(1771, 'Cruzeiro EC', 'Cruzeiro', 'CRU', 'https://crests.football-data.org/1771.png'),
(1765, 'Fluminense FC', 'Fluminense', 'FLU', 'https://crests.football-data.org/1765.png'),
(1768, 'CA Paranaense', 'Paranaense', 'CAP', 'https://crests.football-data.org/1768.png'),
(1777, 'EC Bahia', 'Bahia', 'BAH', 'https://crests.football-data.org/1777.png'),
(1766, 'CA Mineiro', 'Mineiro', 'CAM', 'https://crests.football-data.org/1766.png'),
(4286, 'RB Bragantino', 'Bragantino', 'RBB', 'https://crests.football-data.org/4286.png'),
(6685, 'Santos FC', 'Santos', 'SAN', 'https://crests.football-data.org/6685.png'),
(1776, 'São Paulo FC', 'São Paulo', 'PAU', 'https://crests.football-data.org/1776.png'),
(1780, 'CR Vasco da Gama', 'Vasco da Gama', 'VAS', 'https://crests.football-data.org/1780.png'),
(1770, 'Botafogo FR', 'Botafogo', 'BOT', 'https://crests.football-data.org/1770.png'),
(4241, 'Coritiba FBC', 'Coritiba', 'COR', 'https://crests.football-data.org/4241.png'),
(4364, 'Mirassol FC', 'Mirassol', 'MIR', 'https://crests.football-data.org/4364.png'),
(1782, 'EC Vitória', 'Vitória', 'VIT', 'https://crests.football-data.org/1782.png'),
(6684, 'SC Internacional', 'Internacional', 'SCI', 'https://crests.football-data.org/6684.png'),
(1779, 'SC Corinthians Paulista', 'Corinthians', 'COR', 'https://crests.football-data.org/1779.png'),
(1767, 'Grêmio FBPA', 'Grêmio', 'FBP', 'https://crests.football-data.org/1767.png'),
(4287, 'Clube do Remo', 'Remo', 'CRE', 'https://crests.football-data.org/4287.png'),
(1772, 'Chapecoense AF', 'Chapecoense', 'CHA', 'https://crests.football-data.org/1772_large.png')
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    nome_curto = VALUES(nome_curto),
    sigla = VALUES(sigla),
    escudo_url = VALUES(escudo_url);

-- 4. Vinculo dos 20 clubes na temporada 2026 com ajuste_pontos padrao = 0 (sem resultados ficticios)
INSERT INTO clubes_temporadas (temporada_id, clube_id, ajuste_pontos, motivo_ajuste)
SELECT t.id, c.id, 0, NULL
FROM temporadas t
CROSS JOIN clubes c
WHERE t.external_id = 2474
  AND c.external_id IN (1783, 1769, 1771, 1765, 1768, 1777, 1766, 4286, 6685, 1776, 1780, 1770, 4241, 4364, 1782, 6684, 1779, 1767, 4287, 1772)
ON DUPLICATE KEY UPDATE
    temporada_id = VALUES(temporada_id);
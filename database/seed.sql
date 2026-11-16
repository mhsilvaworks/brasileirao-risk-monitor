CREATE DATABASE IF NOT EXISTS brasileirao_risco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE brasileirao_risco;

DROP TABLE IF EXISTS partidas;
DROP TABLE IF EXISTS clubes;

CREATE TABLE clubes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    sigla CHAR(3) NOT NULL UNIQUE,
    escudo_url VARCHAR(255) DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE partidas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rodada INT NOT NULL,
    mandante_id INT NOT NULL,
    visitante_id INT NOT NULL,
    gols_mandante INT DEFAULT NULL,
    gols_visitante INT DEFAULT NULL,
    finalizada BOOLEAN NOT NULL DEFAULT FALSE,
    data_jogo DATETIME DEFAULT NULL,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (mandante_id) REFERENCES clubes(id) ON DELETE CASCADE,
    FOREIGN KEY (visitante_id) REFERENCES clubes(id) ON DELETE CASCADE,
    INDEX idx_rodada (rodada),
    INDEX idx_finalizada (finalizada)
) ENGINE=InnoDB;

-- 20 Clubes da Série A
INSERT INTO clubes (id, nome, sigla) VALUES
(1, 'Atlético-MG', 'CAM'), (2, 'Bahia', 'BAH'), (3, 'Botafogo', 'BOT'),
(4, 'Corinthians', 'COR'), (5, 'Cruzeiro', 'CRU'), (6, 'Flamengo', 'FLA'),
(7, 'Fluminense', 'FLU'), (8, 'Fortaleza', 'FOR'), (9, 'Grêmio', 'GRE'),
(10, 'Internacional', 'INT'), (11, 'Juventude', 'JUV'), (12, 'Palmeiras', 'PAL'),
(13, 'Red Bull Bragantino', 'RBB'), (14, 'São Paulo', 'SAO'), (15, 'Vasco da Gama', 'VAS'),
(16, 'Vitória', 'VIT'), (17, 'Santos', 'SAN'), (18, 'Mirassol', 'MIR'),
(19, 'Sport', 'SPT'), (20, 'Ceará', 'CEA');

-- Amostra de partidas finalizadas e futuras para validar cálculos
INSERT INTO partidas (rodada, mandante_id, visitante_id, gols_mandante, gols_visitante, finalizada) VALUES
(1, 6, 12, 2, 1, TRUE),  -- FLA 2x1 PAL
(1, 3, 14, 1, 0, TRUE),  -- BOT 1x0 SAO
(1, 8, 10, 1, 1, TRUE),  -- FOR 1x1 INT
(1, 5, 1, 0, 2, TRUE),   -- CRU 0x2 CAM
(1, 2, 4, 3, 1, TRUE),   -- BAH 3x1 COR
(2, 12, 3, 2, 2, TRUE),  -- PAL 2x2 BOT
(2, 14, 6, 1, 2, TRUE),  -- SAO 1x2 FLA
(2, 10, 5, 2, 0, TRUE),  -- INT 2x0 CRU
(2, 1, 2, 1, 1, TRUE),   -- CAM 1x1 BAH
(2, 4, 8, 0, 1, TRUE),   -- COR 0x1 FOR
-- Jogos pendentes (serão simulados)
(3, 6, 3, NULL, NULL, FALSE),
(3, 12, 8, NULL, NULL, FALSE),
(3, 14, 10, NULL, NULL, FALSE),
(3, 1, 4, NULL, NULL, FALSE),
(3, 2, 5, NULL, NULL, FALSE);
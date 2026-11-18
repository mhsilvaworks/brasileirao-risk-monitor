-- =============================================================================
-- ARENA_ — Migração incremental 001
-- Compatibilidade: MySQL 8.0+
--
-- Objetivo:
--   Atualizar um banco criado pelo schema anterior (PR #38) para o modelo
--   necessário ao MVP, sem apagar nem converter dados existentes.
--
-- IMPORTANTE:
--   1. Faça backup antes de executar em qualquer ambiente com dados.
--   2. Selecione o banco brasileirao_risco antes de executar este arquivo.
--   3. Este script é repetível: verifica colunas e cria tabelas ausentes.
--   4. O schema.sql continua sendo a instalação para banco novo; ele não
--      substitui esta migração em bancos que já possuem tabelas.
-- =============================================================================

USE brasileirao_risco;

-- Probabilidades por clube/temporada: adicionar somente se ainda não existirem.
SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubes_temporadas'
       AND COLUMN_NAME = 'probabilidade_campeao') = 0,
    'ALTER TABLE clubes_temporadas ADD COLUMN probabilidade_campeao DECIMAL(5,2) DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubes_temporadas'
       AND COLUMN_NAME = 'probabilidade_libertadores') = 0,
    'ALTER TABLE clubes_temporadas ADD COLUMN probabilidade_libertadores DECIMAL(5,2) DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubes_temporadas'
       AND COLUMN_NAME = 'probabilidade_sulamericana') = 0,
    'ALTER TABLE clubes_temporadas ADD COLUMN probabilidade_sulamericana DECIMAL(5,2) DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubes_temporadas'
       AND COLUMN_NAME = 'probabilidade_rebaixamento') = 0,
    'ALTER TABLE clubes_temporadas ADD COLUMN probabilidade_rebaixamento DECIMAL(5,2) DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubes_temporadas'
       AND COLUMN_NAME = 'probabilidades_posicoes') = 0,
    'ALTER TABLE clubes_temporadas ADD COLUMN probabilidades_posicoes JSON DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubes_temporadas'
       AND COLUMN_NAME = 'probabilidades_calculadas_em') = 0,
    'ALTER TABLE clubes_temporadas ADD COLUMN probabilidades_calculadas_em DATETIME DEFAULT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

-- Tabelas ausentes no schema anterior. Os IDs internos permanecem INT signed,
-- compatíveis com clubes.id/temporadas.id existentes no banco original.
CREATE TABLE IF NOT EXISTS usuarios (
    id                INT NOT NULL AUTO_INCREMENT,
    nome              VARCHAR(120) NOT NULL,
    email             VARCHAR(190) NOT NULL,
    senha_hash        VARCHAR(255) NOT NULL,
    clube_favorito_id INT DEFAULT NULL,
    criado_em         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email),
    CONSTRAINT fk_usuarios_clube
        FOREIGN KEY (clube_favorito_id) REFERENCES clubes(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS simulacoes_usuarios (
    id           INT NOT NULL AUTO_INCREMENT,
    usuario_id   INT NOT NULL,
    clube_id     INT NOT NULL,
    posicao_alvo TINYINT UNSIGNED NOT NULL,
    cenarios_json JSON NOT NULL,
    criado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sim_usuario (usuario_id),
    INDEX idx_sim_clube (clube_id),
    CONSTRAINT fk_sim_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sim_clube
        FOREIGN KEY (clube_id) REFERENCES clubes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo                  VARCHAR(40) NOT NULL,
    status                VARCHAR(20) NOT NULL,
    mensagem              VARCHAR(500) DEFAULT NULL,
    registros_processados INT UNSIGNED NOT NULL DEFAULT 0,
    iniciado_em           DATETIME NOT NULL COMMENT 'UTC',
    finalizado_em         DATETIME DEFAULT NULL COMMENT 'UTC',
    duracao_ms            INT UNSIGNED DEFAULT NULL,
    detalhes_json         JSON DEFAULT NULL,
    criado_em             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Não altera tipos de PK/FK ou placares já existentes: evita conversões
-- desnecessárias e preserva a compatibilidade com registros do banco anterior.

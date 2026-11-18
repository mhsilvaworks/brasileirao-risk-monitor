-- =============================================================================
-- ARENA_ — Monitor de Risco Brasileirão Série A
-- database/schema.sql — versão reconciliada com a especificação v2.1
--
-- Decisões técnicas registradas neste arquivo:
--
-- [DT-01] competicoes: mantida como tabela auxiliar de normalização, além
--         das 7 tabelas obrigatórias do MVP. Justificativa: o mapeamento de
--         dados (docs/api-recognition/mapeamento-dados.md) exige persistir
--         o external_id da competição (campo id = 2013 da football-data.org)
--         para resolver temporadas por external_id; sem a tabela a FK
--         competicao_id em temporadas ficaria sem referência. A equipe optou
--         por mantê-la como tabela de suporte à integração, não como entidade
--         do domínio de usuário. Se a equipe decidir remover competicoes no
--         futuro, temporadas.competicao_id deve ser convertida em coluna
--         plain INT (sem FK) ou eliminada, e este arquivo deve ser atualizado.
--
-- [DT-02] simulacoes_usuarios: incluída conforme obrigação funcional da
--         especificação v2.1 (RF07, RN05, seções 4.1 e 5.1). O dicionário
--         v1.0 classificava essa tabela como backlog (#45), mas a
--         especificação aprovada exige persistência dos cenários salvos no
--         perfil do usuário. Esta tabela é agora obrigatória.
--
-- [DT-03] clubes_temporadas: NÃO inclui colunas de placar/estatísticas
--         (pontos, jogos, vitorias, etc.) presentes no SQL do documento v2.1
--         (seção 4.2). A classificação é calculada dinamicamente a partir de
--         partidas (regra de negócio RN03 e seção 4 do dicionário v1.0,
--         item 6). Incluir essas colunas criaria redundância e risco de
--         dessincronização. Decisão: manter apenas probabilidades e ajustes
--         administrativos nessa tabela; classificação derivada em
--         StandingsService.
--
-- [DT-04] partidas.status: mantido como VARCHAR(30), não ENUM. A
--         especificação v2.1 (seção 4.2) usa ENUM na DDL ilustrativa, mas
--         o texto funcional (RN04, mapeamento de dados seção 4) exige
--         extensibilidade. VARCHAR é a decisão técnica correta e está
--         alinhada ao dicionário v1.0.
--
-- [DT-05] usuarios.clube_favorito_id: aceita NULL. O documento v2.1 (seção
--         4.2) define NOT NULL com FK, mas RF04 permite troca de clube e
--         o dicionário v1.0 (seção 3.6) define NULL permitido. Optamos por
--         NULL para consistência com o dicionário aprovado.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS brasileirao_risco
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE brasileirao_risco;

-- -----------------------------------------------------------------------------
-- [DT-01] competicoes — tabela de suporte à integração (não listada como uma
--         das 7 do MVP, mas necessária como referência para temporadas.
--         Ver decisão técnica DT-01 acima.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competicoes (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    external_id   INT             NOT NULL,
    nome          VARCHAR(120)    NOT NULL,
    codigo        VARCHAR(20)     NOT NULL,
    emblema_url   VARCHAR(255)    DEFAULT NULL,
    api_updated_at DATETIME       DEFAULT NULL COMMENT 'UTC',
    criado_em     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_competicoes_external_id (external_id),
    UNIQUE KEY uk_competicoes_codigo      (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- temporadas
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS temporadas (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    external_id   INT             NOT NULL,
    competicao_id INT UNSIGNED    NOT NULL,
    ano           SMALLINT UNSIGNED NOT NULL,
    data_inicio   DATE            NOT NULL,
    data_fim      DATE            NOT NULL,
    rodada_atual  TINYINT UNSIGNED DEFAULT NULL,
    api_updated_at DATETIME       DEFAULT NULL COMMENT 'UTC',
    criado_em     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_temporadas_external_id (external_id),
    CONSTRAINT fk_temporadas_competicao
        FOREIGN KEY (competicao_id) REFERENCES competicoes(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- clubes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clubes (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    external_id   INT             NOT NULL,
    nome          VARCHAR(120)    NOT NULL,
    nome_curto    VARCHAR(80)     NOT NULL,
    sigla         VARCHAR(10)     NOT NULL,
    escudo_url    VARCHAR(255)    DEFAULT NULL,
    estadio       VARCHAR(120)    DEFAULT NULL,
    api_updated_at DATETIME       DEFAULT NULL COMMENT 'UTC',
    criado_em     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clubes_external_id (external_id),
    INDEX idx_clubes_sigla (sigla)
    -- sigla NÃO é UNIQUE: Coritiba e Corinthians compartilham "COR" (DT confirmado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- usuarios  (tabela obrigatória do CRUD — ausente no schema anterior)
-- [DT-05] clube_favorito_id nullable — ver decisão técnica acima
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nome               VARCHAR(120)    NOT NULL,
    email              VARCHAR(190)    NOT NULL,
    senha_hash         VARCHAR(255)    NOT NULL,
    clube_favorito_id  INT UNSIGNED    DEFAULT NULL,
    criado_em          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email),
    CONSTRAINT fk_usuarios_clube
        FOREIGN KEY (clube_favorito_id) REFERENCES clubes(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- clubes_temporadas
-- [DT-03] sem colunas de estatística de partida — classificação é derivada
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clubes_temporadas (
    id                           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    temporada_id                 INT UNSIGNED    NOT NULL,
    clube_id                     INT UNSIGNED    NOT NULL,
    ajuste_pontos                INT             NOT NULL DEFAULT 0,
    motivo_ajuste                VARCHAR(255)    DEFAULT NULL,
    probabilidade_campeao        DECIMAL(5,2)    DEFAULT NULL COMMENT '0.00–100.00',
    probabilidade_libertadores   DECIMAL(5,2)    DEFAULT NULL COMMENT '0.00–100.00',
    probabilidade_sulamericana   DECIMAL(5,2)    DEFAULT NULL COMMENT '0.00–100.00',
    probabilidade_rebaixamento   DECIMAL(5,2)    DEFAULT NULL COMMENT '0.00–100.00',
    probabilidades_posicoes      JSON            DEFAULT NULL
        COMMENT 'Chaves "1"–"20", valores DECIMAL percentuais; soma ≈ 100%',
    probabilidades_calculadas_em DATETIME        DEFAULT NULL COMMENT 'UTC',
    criado_em                    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clube_temporada (temporada_id, clube_id),
    CONSTRAINT fk_ct_temporada
        FOREIGN KEY (temporada_id) REFERENCES temporadas(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ct_clube
        FOREIGN KEY (clube_id) REFERENCES clubes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- partidas
-- [DT-04] status VARCHAR(30), não ENUM — ver decisão técnica acima
-- gols: SMALLINT UNSIGNED nullable (alinhado ao dicionário v1.0)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partidas (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    external_id      INT               NOT NULL,
    temporada_id     INT UNSIGNED      NOT NULL,
    rodada           TINYINT UNSIGNED  DEFAULT NULL,
    data_hora_utc    DATETIME          NOT NULL COMMENT 'UTC',
    status           VARCHAR(30)       NOT NULL,
    mandante_id      INT UNSIGNED      NOT NULL,
    visitante_id     INT UNSIGNED      NOT NULL,
    gols_mandante    SMALLINT UNSIGNED DEFAULT NULL,
    gols_visitante   SMALLINT UNSIGNED DEFAULT NULL,
    gols_mandante_ht SMALLINT UNSIGNED DEFAULT NULL,
    gols_visitante_ht SMALLINT UNSIGNED DEFAULT NULL,
    vencedor         VARCHAR(20)       DEFAULT NULL
        COMMENT 'HOME_TEAM | AWAY_TEAM | DRAW | NULL',
    api_updated_at   DATETIME          DEFAULT NULL COMMENT 'UTC',
    criado_em        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_partidas_external_id (external_id),
    INDEX idx_partidas_temporada_rodada (temporada_id, rodada),
    INDEX idx_partidas_status           (status),
    CONSTRAINT fk_partidas_temporada
        FOREIGN KEY (temporada_id) REFERENCES temporadas(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_partidas_mandante
        FOREIGN KEY (mandante_id)  REFERENCES clubes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_partidas_visitante
        FOREIGN KEY (visitante_id) REFERENCES clubes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- simulacoes_usuarios  [DT-02] — obrigatória conforme especificação v2.1
-- Armazena os 3 cenários hipotéticos gerados em JS e salvos pelo usuário.
-- ON DELETE CASCADE: exclusão do usuário remove todos os cenários (RN05).
-- ON DELETE CASCADE de clube: se um clube for removido do sistema, os
--   cenários associados são removidos em cascata (decisão técnica conservadora;
--   alternativa: SET NULL em clube_id — requer torná-lo nullable).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS simulacoes_usuarios (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    usuario_id    INT UNSIGNED      NOT NULL,
    clube_id      INT UNSIGNED      NOT NULL,
    posicao_alvo  TINYINT UNSIGNED  NOT NULL COMMENT '1–20',
    cenarios_json JSON              NOT NULL
        COMMENT '3 cenários hipotéticos gerados pelo JavaScript',
    criado_em     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sim_usuario (usuario_id),
    INDEX idx_sim_clube   (clube_id),
    CONSTRAINT fk_sim_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sim_clube
        FOREIGN KEY (clube_id)   REFERENCES clubes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sync_logs
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo                  VARCHAR(40)     NOT NULL
        COMMENT 'sincronizacao | competicao | temporada | clubes | partidas',
    status                VARCHAR(20)     NOT NULL
        COMMENT 'running | success | partial | failed',
    mensagem              VARCHAR(500)    DEFAULT NULL,
    registros_processados INT UNSIGNED    NOT NULL DEFAULT 0,
    iniciado_em           DATETIME        NOT NULL COMMENT 'UTC',
    finalizado_em         DATETIME        DEFAULT NULL COMMENT 'UTC; NULL enquanto executa',
    duracao_ms            INT UNSIGNED    DEFAULT NULL,
    detalhes_json         JSON            DEFAULT NULL,
    criado_em             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
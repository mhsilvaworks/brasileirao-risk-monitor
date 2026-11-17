CREATE DATABASE IF NOT EXISTS brasileirao_risco
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE brasileirao_risco;

CREATE TABLE IF NOT EXISTS competicoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    emblema_url VARCHAR(255) DEFAULT NULL,
    api_updated_at DATETIME DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_competicoes_external_id (external_id),
    UNIQUE KEY uk_competicoes_codigo (codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS temporadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id INT NOT NULL,
    competicao_id INT NOT NULL,
    ano INT NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    rodada_atual INT DEFAULT NULL,
    api_updated_at DATETIME DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_temporadas_external_id (external_id),
    CONSTRAINT fk_temporadas_competicao FOREIGN KEY (competicao_id) REFERENCES competicoes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clubes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    nome_curto VARCHAR(80) NOT NULL,
    sigla VARCHAR(10) NOT NULL,
    escudo_url VARCHAR(255) DEFAULT NULL,
    estadio VARCHAR(120) DEFAULT NULL,
    api_updated_at DATETIME DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_clubes_external_id (external_id),
    INDEX idx_clubes_sigla (sigla)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clubes_temporadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temporada_id INT NOT NULL,
    clube_id INT NOT NULL,
    ajuste_pontos INT NOT NULL DEFAULT 0,
    motivo_ajuste VARCHAR(255) DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_clube_temporada (temporada_id, clube_id),
    CONSTRAINT fk_ct_temporada FOREIGN KEY (temporada_id) REFERENCES temporadas(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_clube FOREIGN KEY (clube_id) REFERENCES clubes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS partidas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id INT NOT NULL,
    temporada_id INT NOT NULL,
    rodada INT DEFAULT NULL,
    data_hora_utc DATETIME NOT NULL,
    status VARCHAR(30) NOT NULL,
    mandante_id INT NOT NULL,
    visitante_id INT NOT NULL,
    gols_mandante INT DEFAULT NULL,
    gols_visitante INT DEFAULT NULL,
    gols_mandante_ht INT DEFAULT NULL,
    gols_visitante_ht INT DEFAULT NULL,
    vencedor VARCHAR(20) DEFAULT NULL,
    api_updated_at DATETIME DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_partidas_external_id (external_id),
    INDEX idx_partidas_temporada_rodada (temporada_id, rodada),
    INDEX idx_partidas_status (status),
    CONSTRAINT fk_partidas_temporada FOREIGN KEY (temporada_id) REFERENCES temporadas(id) ON DELETE CASCADE,
    CONSTRAINT fk_partidas_mandante FOREIGN KEY (mandante_id) REFERENCES clubes(id) ON DELETE CASCADE,
    CONSTRAINT fk_partidas_visitante FOREIGN KEY (visitante_id) REFERENCES clubes(id) ON DELETE CASCADE
) ENGINE=InnoDB;
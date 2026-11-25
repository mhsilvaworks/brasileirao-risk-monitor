-- =============================================================================
-- ARENA_ — Migração incremental 001
-- Versão: 1.2 — Issue #1, preflight fail-fast
-- Compatibilidade declarada: MySQL 8.0+
--
-- OBJETIVO
--   Atualizar um banco criado pelo schema do PR #38 para o modelo MVP,
--   sem apagar nem converter dados existentes.
--   O PR #38 produzia um banco com: competicoes, temporadas, clubes,
--   clubes_temporadas (sem colunas de probabilidade) e partidas.
--   Esta migração acrescenta:
--     • 6 colunas de probabilidade em clubes_temporadas
--     • tabelas usuarios, simulacoes_usuarios e sync_logs
--     • FK fk_temporadas_competicao com ON DELETE RESTRICT
--
-- BANCO ALVO
--   Nome: brasileirao_risco
--   Para banco novo (sem tabelas): usar database/schema.sql, não esta migração.
--
-- =============================================================================
-- PREFLIGHT AUTOMÁTICO — executado antes de qualquer DDL dos blocos 1–3
-- =============================================================================
--
--   P1. Um banco deve estar selecionado. Esta migration NÃO contém USE:
--       informe o banco explicitamente ao cliente MySQL.
--
--   As tabelas/colunas-base, tipos, nulabilidade, PKs, engines, colunas de
--   probabilidade preexistentes, tabelas novas já existentes, índices/FKs
--   requeridos e órfãos são verificados automaticamente abaixo.
--
--   Se houver divergências, o preflight as lista e provoca erro SQL. O cliente
--   mysql em modo batch encerra com falha antes dos blocos de alteração.
--   Não use --force. DDL MySQL pode fazer commit implícito; não há rollback
--   transacional geral para a sequência.
--
-- =============================================================================
-- LIMITAÇÕES IMPORTANTES
-- =============================================================================
--
--   L1. Colunas preexistentes são validadas pelo preflight (tipo, nulabilidade
--       e default). Tipos incompatíveis bloqueiam a migration; nenhuma conversão
--       automática é feita.
--
--   L2. CREATE TABLE IF NOT EXISTS ainda é usado para tabelas novas, mas o
--       preflight valida as tabelas que já existam. Divergências bloqueiam
--       antes de qualquer DDL; não são corrigidas automaticamente.
--
--   L3. FK (Bloco 3): uma constraint homônima com outra estrutura e uma relação
--       equivalente sob outro nome são bloqueadas no preflight. Só remove uma
--       FK canônica estruturalmente correta cujas regras precisem ser alinhadas.
--
--   L4. Esta migração NÃO altera: tipos de PK/FK existentes, tipos de placares,
--       nulabilidade de external_id ou rodada, colunas de estatística de
--       partida, nome do banco, regras de exclusão de outras FKs existentes,
--       nem qualquer estrutura não listada explicitamente.
--
-- =============================================================================
-- IDEMPOTÊNCIA
-- =============================================================================
--
--   • Bloco 1 (ADD COLUMN): verificação via INFORMATION_SCHEMA antes de cada
--     ADD. Reexecutar não causa erro se a coluna já existir.
--   • Bloco 2 (CREATE TABLE IF NOT EXISTS): reexecutar é seguro.
--   • Bloco 3 (FK): verificação via REFERENTIAL_CONSTRAINTS e KEY_COLUMN_USAGE
--     antes de DROP e ADD. Reexecutar é seguro somente se as pré-condições
--     forem satisfeitas; confira o diagnóstico final da FK.
--   • Idempotência não significa corrigir estruturas divergentes existentes.
--     Elas são rejeitadas pelo preflight.
--   • O estado final da FK também é validado por erro SQL, não apenas por SELECT.
--
-- =============================================================================
-- VALIDAÇÃO APÓS EXECUÇÃO (MySQL 8) — executar e conferir resultados
-- =============================================================================
--
--   -- 1. Confirmar as 6 colunas de probabilidade:
--   SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
--   FROM INFORMATION_SCHEMA.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME = 'clubes_temporadas'
--     AND COLUMN_NAME LIKE 'probabilidad%'
--   ORDER BY COLUMN_NAME;
--   -- Esperado: 6 linhas — probabilidade_campeao, probabilidade_libertadores,
--   --   probabilidade_rebaixamento, probabilidade_sulamericana,
--   --   probabilidades_calculadas_em, probabilidades_posicoes.
--
--   -- 2. Confirmar tabelas criadas:
--   SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME IN ('usuarios', 'simulacoes_usuarios', 'sync_logs')
--   ORDER BY TABLE_NAME;
--   -- Esperado: 3 linhas.
--
--   -- 3. Confirmar a FK com todos os atributos esperados:
--   SELECT
--     rc.CONSTRAINT_NAME,
--     rc.TABLE_NAME          AS tabela_origem,
--     kcu.COLUMN_NAME        AS coluna_origem,
--     kcu.REFERENCED_TABLE_SCHEMA AS schema_referenciado,
--     rc.REFERENCED_TABLE_NAME AS tabela_referenciada,
--     kcu.REFERENCED_COLUMN_NAME AS coluna_referenciada,
--     rc.DELETE_RULE,
--     rc.UPDATE_RULE
--   FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
--   JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
--     ON  kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
--     AND kcu.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
--     AND kcu.TABLE_NAME        = rc.TABLE_NAME
--   WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
--     AND rc.CONSTRAINT_NAME   = 'fk_temporadas_competicao';
--   -- Esperado: 1 linha com tabela_origem=temporadas,
--   --   coluna_origem=competicao_id, tabela_referenciada=competicoes,
--   --   schema_referenciado=DATABASE(), coluna_referenciada=id,
--   --   DELETE_RULE=RESTRICT e UPDATE_RULE=RESTRICT.
-- =============================================================================

-- =============================================================================
-- PREFLIGHT EXECUTÁVEL
--
-- A tabela temporária só existe nesta conexão. A consulta de diagnóstico lista
-- todas as incompatibilidades encontradas. Quando houver pelo menos uma, o
-- SQL dinâmico consulta intencionalmente uma tabela inexistente; isso gera erro
-- real no servidor MySQL e faz o cliente mysql batch parar. PREPARE não aceita
-- SIGNAL, portanto este guard evita stored procedures persistentes e funciona
-- com o executor realmente documentado pelo projeto (mysql < arquivo.sql).
-- =============================================================================

DROP TEMPORARY TABLE IF EXISTS _arena_issue1_preflight_errors;
CREATE TEMPORARY TABLE _arena_issue1_preflight_errors (
    detalhe VARCHAR(255) NOT NULL
);

INSERT INTO _arena_issue1_preflight_errors (detalhe)
SELECT CONCAT('Banco não selecionado; execute mysql <banco> < migration.sql')
WHERE DATABASE() IS NULL
UNION ALL
SELECT CONCAT('Tabela obrigatória ausente: ', required.table_name)
FROM (
    SELECT 'competicoes' AS table_name
    UNION ALL SELECT 'temporadas'
    UNION ALL SELECT 'clubes'
    UNION ALL SELECT 'clubes_temporadas'
) AS required
WHERE NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES t
    WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = required.table_name
)
UNION ALL
SELECT CONCAT('Engine deve ser InnoDB: ', t.TABLE_NAME)
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_NAME IN ('competicoes','temporadas','clubes','clubes_temporadas')
  AND (t.TABLE_TYPE<>'BASE TABLE' OR COALESCE(t.ENGINE,'')<>'InnoDB')
UNION ALL
SELECT CONCAT('Coluna ausente ou incompatível: ', expected.table_name, '.', expected.column_name,
              ' (esperado ', expected.column_type, ', nullable=', expected.is_nullable, ')')
FROM (
    SELECT 'competicoes' table_name, 'id' column_name, 'int' column_type, 'NO' is_nullable, 'PRIMARY' key_name
    UNION ALL SELECT 'temporadas','id','int','NO','PRIMARY'
    UNION ALL SELECT 'temporadas','competicao_id','int','NO',''
    UNION ALL SELECT 'clubes','id','int','NO','PRIMARY'
    UNION ALL SELECT 'clubes_temporadas','id','int','NO','PRIMARY'
    UNION ALL SELECT 'clubes_temporadas','temporada_id','int','NO',''
    UNION ALL SELECT 'clubes_temporadas','clube_id','int','NO',''
) expected
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES t
              WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=expected.table_name)
  AND NOT EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS c
      WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=expected.table_name
        AND c.COLUMN_NAME=expected.column_name
        AND c.COLUMN_TYPE=expected.column_type AND c.IS_NULLABLE=expected.is_nullable
        AND (expected.key_name='' OR EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS s
            WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME=expected.table_name
              AND s.INDEX_NAME=expected.key_name AND s.COLUMN_NAME=expected.column_name
              AND s.SEQ_IN_INDEX=1 AND s.NON_UNIQUE=0
              AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS sp
                   WHERE sp.TABLE_SCHEMA=s.TABLE_SCHEMA AND sp.TABLE_NAME=s.TABLE_NAME
                     AND sp.INDEX_NAME=s.INDEX_NAME)=1
        ))
  )
UNION ALL
SELECT CONCAT('Coluna de probabilidade incompatível: clubes_temporadas.', e.column_name,
              ' (esperado ',e.data_type, IF(e.data_type='decimal','(5,2)',''), ', nullable=YES, default NULL)')
FROM (
    SELECT 'probabilidade_campeao' column_name,'decimal' data_type
    UNION ALL SELECT 'probabilidade_libertadores','decimal'
    UNION ALL SELECT 'probabilidade_sulamericana','decimal'
    UNION ALL SELECT 'probabilidade_rebaixamento','decimal'
    UNION ALL SELECT 'probabilidades_posicoes','json'
    UNION ALL SELECT 'probabilidades_calculadas_em','datetime'
) e
JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='clubes_temporadas'
 AND c.COLUMN_NAME=e.column_name
WHERE c.DATA_TYPE<>e.data_type OR c.IS_NULLABLE<>'YES' OR c.COLUMN_DEFAULT IS NOT NULL
   OR (e.data_type='decimal' AND (c.NUMERIC_PRECISION<>5 OR c.NUMERIC_SCALE<>2))
UNION ALL
SELECT CONCAT('Coluna ausente ou incompatível: ',e.table_name,'.',e.column_name)
FROM (
    SELECT 'usuarios' table_name,'id' column_name,'int' column_type,'NO' nullable,'PRI' idx
    UNION ALL SELECT 'usuarios','nome','varchar(120)','NO',''
    UNION ALL SELECT 'usuarios','email','varchar(190)','NO',''
    UNION ALL SELECT 'usuarios','senha_hash','varchar(255)','NO',''
    UNION ALL SELECT 'usuarios','clube_favorito_id','int','YES',''
    UNION ALL SELECT 'usuarios','criado_em','timestamp','NO',''
    UNION ALL SELECT 'usuarios','atualizado_em','timestamp','NO',''
    UNION ALL SELECT 'simulacoes_usuarios','id','int','NO','PRI'
    UNION ALL SELECT 'simulacoes_usuarios','usuario_id','int','NO',''
    UNION ALL SELECT 'simulacoes_usuarios','clube_id','int','NO',''
    UNION ALL SELECT 'simulacoes_usuarios','posicao_alvo','tinyint unsigned','NO',''
    UNION ALL SELECT 'simulacoes_usuarios','cenarios_json','json','NO',''
    UNION ALL SELECT 'simulacoes_usuarios','criado_em','timestamp','NO',''
    UNION ALL SELECT 'sync_logs','id','bigint unsigned','NO','PRI'
    UNION ALL SELECT 'sync_logs','tipo','varchar(40)','NO',''
    UNION ALL SELECT 'sync_logs','status','varchar(20)','NO',''
    UNION ALL SELECT 'sync_logs','mensagem','varchar(500)','YES',''
    UNION ALL SELECT 'sync_logs','registros_processados','int unsigned','NO',''
    UNION ALL SELECT 'sync_logs','iniciado_em','datetime','NO',''
    UNION ALL SELECT 'sync_logs','finalizado_em','datetime','YES',''
    UNION ALL SELECT 'sync_logs','duracao_ms','int unsigned','YES',''
    UNION ALL SELECT 'sync_logs','detalhes_json','json','YES',''
    UNION ALL SELECT 'sync_logs','criado_em','timestamp','NO',''
) e
JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=e.table_name
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.COLUMN_NAME=e.column_name
WHERE c.COLUMN_NAME IS NULL OR c.COLUMN_TYPE<>e.column_type OR c.IS_NULLABLE<>e.nullable
   OR (e.idx='PRI' AND (
    NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS s
        WHERE s.TABLE_SCHEMA = DATABASE()
          AND s.TABLE_NAME = e.table_name
          AND s.INDEX_NAME = 'PRIMARY'
          AND s.COLUMN_NAME = e.column_name
          AND s.SEQ_IN_INDEX = 1
    )
    OR (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS s
        WHERE s.TABLE_SCHEMA = DATABASE()
          AND s.TABLE_NAME = e.table_name
          AND s.INDEX_NAME = 'PRIMARY'
    ) <> 1
    ))
UNION ALL
SELECT 'Tabela preexistente deve usar InnoDB: usuarios/simulacoes_usuarios/sync_logs'
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME IN ('usuarios','simulacoes_usuarios','sync_logs')
  AND (t.TABLE_TYPE<>'BASE TABLE' OR COALESCE(t.ENGINE,'')<>'InnoDB')
UNION ALL
SELECT 'usuarios.email precisa de índice UNIQUE exclusivamente na coluna email'
WHERE EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
)
AND NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'usuarios'
      AND s.NON_UNIQUE = 0
      AND s.COLUMN_NAME = 'email'
      AND s.SEQ_IN_INDEX = 1
      AND s.SUB_PART IS NULL
      AND (
          SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.STATISTICS s2
          WHERE s2.TABLE_SCHEMA = s.TABLE_SCHEMA
            AND s2.TABLE_NAME = s.TABLE_NAME
            AND s2.INDEX_NAME = s.INDEX_NAME
      ) = 1
)
UNION ALL
SELECT CONCAT('Coluna id precisa de AUTO_INCREMENT: ', e.table_name, '.id')
FROM (SELECT 'usuarios' table_name UNION ALL SELECT 'simulacoes_usuarios' UNION ALL SELECT 'sync_logs') e
JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=e.table_name AND c.COLUMN_NAME='id'
WHERE c.EXTRA NOT LIKE '%auto_increment%'
UNION ALL
SELECT CONCAT(
    'Default/atributo incompatível: ',
    e.table_name,
    '.',
    e.column_name
)
FROM (
    SELECT 'usuarios' table_name, 'nome' column_name, '__NONE__' expected_default, 'NO' expected_on_update
    UNION ALL SELECT 'usuarios','email','__NONE__','NO'
    UNION ALL SELECT 'usuarios','senha_hash','__NONE__','NO'
    UNION ALL SELECT 'usuarios','clube_favorito_id','__NULL__','NO'
    UNION ALL SELECT 'usuarios','criado_em','CURRENT_TIMESTAMP','NO'
    UNION ALL SELECT 'usuarios','atualizado_em','CURRENT_TIMESTAMP','YES'

    UNION ALL SELECT 'simulacoes_usuarios','usuario_id','__NONE__','NO'
    UNION ALL SELECT 'simulacoes_usuarios','clube_id','__NONE__','NO'
    UNION ALL SELECT 'simulacoes_usuarios','posicao_alvo','__NONE__','NO'
    UNION ALL SELECT 'simulacoes_usuarios','cenarios_json','__NONE__','NO'
    UNION ALL SELECT 'simulacoes_usuarios','criado_em','CURRENT_TIMESTAMP','NO'

    UNION ALL SELECT 'sync_logs','tipo','__NONE__','NO'
    UNION ALL SELECT 'sync_logs','status','__NONE__','NO'
    UNION ALL SELECT 'sync_logs','mensagem','__NULL__','NO'
    UNION ALL SELECT 'sync_logs','registros_processados','0','NO'
    UNION ALL SELECT 'sync_logs','iniciado_em','__NONE__','NO'
    UNION ALL SELECT 'sync_logs','finalizado_em','__NULL__','NO'
    UNION ALL SELECT 'sync_logs','duracao_ms','__NULL__','NO'
    UNION ALL SELECT 'sync_logs','detalhes_json','__NULL__','NO'
    UNION ALL SELECT 'sync_logs','criado_em','CURRENT_TIMESTAMP','NO'
) e
JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = e.table_name
 AND c.COLUMN_NAME = e.column_name
WHERE
    (
        e.expected_default IN ('__NONE__', '__NULL__')
        AND c.COLUMN_DEFAULT IS NOT NULL
    )
    OR (
        e.expected_default NOT IN ('__NONE__', '__NULL__')
        AND UPPER(
            REPLACE(
                COALESCE(c.COLUMN_DEFAULT, ''),
                '()',
                ''
            )
        ) <> e.expected_default
    )
    OR (
        e.expected_on_update = 'YES'
        AND LOWER(c.EXTRA) NOT LIKE '%on update current_timestamp%'
    )
    OR (
        e.expected_on_update = 'NO'
        AND LOWER(c.EXTRA) LIKE '%on update current_timestamp%'
    )
UNION ALL
SELECT 'FK usuarios.clube_favorito_id -> clubes.id precisa de ON DELETE SET NULL'
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                  JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
                    ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
                  WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.CONSTRAINT_NAME='fk_usuarios_clube'
                    AND k.TABLE_NAME='usuarios'
                    AND k.COLUMN_NAME='clube_favorito_id' AND k.REFERENCED_TABLE_SCHEMA=DATABASE()
                    AND k.REFERENCED_TABLE_NAME='clubes' AND k.REFERENCED_COLUMN_NAME='id'
                    AND k.ORDINAL_POSITION=1 AND r.DELETE_RULE='SET NULL'
                    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k2
                         WHERE k2.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                           AND k2.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND k2.TABLE_NAME=k.TABLE_NAME)=1)
UNION ALL
SELECT CONCAT('simulacoes_usuarios.', e.column_name, ' precisa de índice de suporte')
FROM (SELECT 'usuario_id' column_name UNION ALL SELECT 'clube_id') e
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='simulacoes_usuarios')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS s
                  WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME='simulacoes_usuarios'
                    AND s.COLUMN_NAME=e.column_name AND s.SEQ_IN_INDEX=1)
UNION ALL
SELECT CONCAT('FK ausente/incompatível em simulacoes_usuarios.', expected.column_name, ' -> ', expected.ref_table, '.id CASCADE')
FROM (
    SELECT 'usuario_id' column_name,'usuarios' ref_table,'fk_sim_usuario' constraint_name
    UNION ALL SELECT 'clube_id','clubes','fk_sim_clube'
) expected
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='simulacoes_usuarios')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                  JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
                    ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
                  WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='simulacoes_usuarios'
                    AND k.CONSTRAINT_NAME=expected.constraint_name
                    AND k.COLUMN_NAME=expected.column_name AND k.REFERENCED_TABLE_SCHEMA=DATABASE()
                    AND k.REFERENCED_TABLE_NAME=expected.ref_table AND k.REFERENCED_COLUMN_NAME='id'
                    AND k.ORDINAL_POSITION=1 AND r.DELETE_RULE='CASCADE'
                    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k2
                         WHERE k2.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                           AND k2.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND k2.TABLE_NAME=k.TABLE_NAME)=1)
UNION ALL
SELECT 'FK canonical fk_temporadas_competicao já existe com relação incompatível'
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
              WHERE r.CONSTRAINT_SCHEMA=DATABASE() AND r.CONSTRAINT_NAME='fk_temporadas_competicao')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                  WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.CONSTRAINT_NAME='fk_temporadas_competicao'
                    AND k.TABLE_NAME='temporadas' AND k.COLUMN_NAME='competicao_id'
                    AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='competicoes'
                    AND k.REFERENCED_COLUMN_NAME='id' AND k.ORDINAL_POSITION=1
                    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k2
                         WHERE k2.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                           AND k2.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                           AND k2.TABLE_NAME=k.TABLE_NAME)=1)
UNION ALL
SELECT 'Relação temporadas.competicao_id -> competicoes.id existe com outro nome; resolva antes da migration'
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
              WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='temporadas'
                AND k.COLUMN_NAME='competicao_id' AND k.REFERENCED_TABLE_SCHEMA=DATABASE()
                AND k.REFERENCED_TABLE_NAME='competicoes' AND k.REFERENCED_COLUMN_NAME='id'
                AND k.CONSTRAINT_NAME<>'fk_temporadas_competicao')
;

-- Executa a consulta de órfãos dinamicamente para que a ausência de uma das
-- tabelas-base seja reportada pelo preflight acima, sem erro de parsing aqui.
SET @orphan_check_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('temporadas','competicoes'))=2
    AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='temporadas'
                  AND COLUMN_NAME='competicao_id'),
    'INSERT INTO _arena_issue1_preflight_errors (detalhe)
     SELECT CONCAT(''Temporada órfã: id='',t.id,'' competicao_id='',t.competicao_id,
                   ''; consulte temporadas LEFT JOIN competicoes antes de corrigir os dados'')
     FROM temporadas t LEFT JOIN competicoes c ON c.id=t.competicao_id WHERE c.id IS NULL',
    'SELECT 1'
);
PREPARE _orphan_check_stmt FROM @orphan_check_sql;
EXECUTE _orphan_check_stmt;
DEALLOCATE PREPARE _orphan_check_stmt;

SELECT detalhe AS preflight_erro FROM _arena_issue1_preflight_errors;
SET @preflight_failed = (SELECT COUNT(*) FROM _arena_issue1_preflight_errors);
SET @preflight_guard = IF(@preflight_failed > 0,
    'SELECT * FROM __ARENA_ISSUE1_PREFLIGHT_FAILED__; -- preflight reprovado; não aplicar DDL',
    'SELECT ''PREFLIGHT OK'' AS preflight_status');
PREPARE _preflight_guard_stmt FROM @preflight_guard;
EXECUTE _preflight_guard_stmt;
DEALLOCATE PREPARE _preflight_guard_stmt;
DROP TEMPORARY TABLE _arena_issue1_preflight_errors;

-- =============================================================================
-- BLOCO 1: Colunas de probabilidade em clubes_temporadas
--   Pré-requisito: tabela clubes_temporadas deve existir (P5).
--   Comportamento se coluna já existir: mantida sem alteração (L1).
-- =============================================================================

-- Nome canônico do campo de timestamp: probabilidades_calculadas_em.
-- Nunca usar "probabilidades_calculadas_on" (nome antigo, corrigido na Issue #1).

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clubes_temporadas'
       AND COLUMN_NAME  = 'probabilidade_campeao') = 0,
    'ALTER TABLE clubes_temporadas
     ADD COLUMN probabilidade_campeao DECIMAL(5,2) DEFAULT NULL
     COMMENT ''0.00–100.00''',
    'SELECT 1 /* probabilidade_campeao ja existe */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clubes_temporadas'
       AND COLUMN_NAME  = 'probabilidade_libertadores') = 0,
    'ALTER TABLE clubes_temporadas
     ADD COLUMN probabilidade_libertadores DECIMAL(5,2) DEFAULT NULL
     COMMENT ''0.00–100.00''',
    'SELECT 1 /* probabilidade_libertadores ja existe */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clubes_temporadas'
       AND COLUMN_NAME  = 'probabilidade_sulamericana') = 0,
    'ALTER TABLE clubes_temporadas
     ADD COLUMN probabilidade_sulamericana DECIMAL(5,2) DEFAULT NULL
     COMMENT ''0.00–100.00''',
    'SELECT 1 /* probabilidade_sulamericana ja existe */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clubes_temporadas'
       AND COLUMN_NAME  = 'probabilidade_rebaixamento') = 0,
    'ALTER TABLE clubes_temporadas
     ADD COLUMN probabilidade_rebaixamento DECIMAL(5,2) DEFAULT NULL
     COMMENT ''0.00–100.00''',
    'SELECT 1 /* probabilidade_rebaixamento ja existe */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clubes_temporadas'
       AND COLUMN_NAME  = 'probabilidades_posicoes') = 0,
    'ALTER TABLE clubes_temporadas
     ADD COLUMN probabilidades_posicoes JSON DEFAULT NULL
     COMMENT ''Chaves "1"–"20"; soma aproximada de 100% por clube''',
    'SELECT 1 /* probabilidades_posicoes ja existe */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @ddl = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clubes_temporadas'
       AND COLUMN_NAME  = 'probabilidades_calculadas_em') = 0,
    'ALTER TABLE clubes_temporadas
     ADD COLUMN probabilidades_calculadas_em DATETIME DEFAULT NULL
     COMMENT ''UTC — momento do calculo probabilistico (Issue #1: nome canonico)''',
    'SELECT 1 /* probabilidades_calculadas_em ja existe */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

-- =============================================================================
-- BLOCO 2: Tabelas ausentes no schema do PR #38
--   Pré-requisito: clubes deve existir (P4) para a FK de usuarios e
--   simulacoes_usuarios. usuarios deve existir para simulacoes_usuarios.
--
--   ATENÇÃO (L2): CREATE TABLE IF NOT EXISTS não atualiza tabelas existentes.
--   Se qualquer tabela já existir com estrutura diferente da definida abaixo,
--   o comando é ignorado e a estrutura existente permanece inalterada.
--   Confirmar o estado real com: SHOW CREATE TABLE <tabela>;
-- =============================================================================

CREATE TABLE IF NOT EXISTS usuarios (
    id                INT            NOT NULL AUTO_INCREMENT,
    nome              VARCHAR(120)   NOT NULL,
    email             VARCHAR(190)   NOT NULL,
    senha_hash        VARCHAR(255)   NOT NULL,
    clube_favorito_id INT            DEFAULT NULL,
    criado_em         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email),
    CONSTRAINT fk_usuarios_clube
        FOREIGN KEY (clube_favorito_id) REFERENCES clubes(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS simulacoes_usuarios (
    id            INT             NOT NULL AUTO_INCREMENT,
    usuario_id    INT             NOT NULL,
    clube_id      INT             NOT NULL,
    posicao_alvo  TINYINT UNSIGNED NOT NULL COMMENT '1–20',
    cenarios_json JSON            NOT NULL
        COMMENT '3 cenarios hipoteticos gerados pelo JavaScript',
    criado_em     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

-- =============================================================================
-- BLOCO 3: FK fk_temporadas_competicao → ON DELETE RESTRICT
--
--   Pré-requisito P2: tabela competicoes deve existir com coluna id INT PK.
--   Pré-requisito P3: tabela temporadas deve ter coluna competicao_id INT.
--
--   A verificação usa REFERENTIAL_CONSTRAINTS + KEY_COLUMN_USAGE para
--   confirmar os atributos estruturais da FK antes de agir:
--     (a) pertence à tabela "temporadas" (TABLE_NAME)
--     (b) usa a coluna "competicao_id" (COLUMN_NAME via KEY_COLUMN_USAGE)
--     (c) referencia "competicoes.id" (tabela e coluna via KEY_COLUMN_USAGE)
--     (d) tem DELETE_RULE = RESTRICT e UPDATE_RULE = RESTRICT para o estado final
--
--   DROP: somente se o nome e toda a estrutura da FK forem os esperados,
--   mas DELETE_RULE ou UPDATE_RULE forem diferentes de RESTRICT.
--
--   Se o nome estiver ocupado por uma FK de outra estrutura, esta migration
--   não a remove nem a substitui. Interrompa e resolva a divergência
--   manualmente antes de executar a migration; confira o diagnóstico final.
--
--   LIMITAÇÃO: a migration não pode verificar que temporadas.competicao_id
--   existe antes do ADD CONSTRAINT — MySQL retornará erro se a coluna não
--   existir. Garantir o pré-requisito P3 manualmente.
-- =============================================================================

-- DROP somente se a constraint nomeada corresponder exatamente à relação
-- temporadas.competicao_id → competicoes.id, mas tiver DELETE_RULE diferente.
SET @fk_exists_wrong_rule = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
      ON  kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
      AND kcu.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
      AND kcu.TABLE_NAME        = rc.TABLE_NAME
    WHERE rc.CONSTRAINT_SCHEMA      = DATABASE()
      AND rc.CONSTRAINT_NAME        = 'fk_temporadas_competicao'
      AND rc.TABLE_NAME             = 'temporadas'
      AND kcu.COLUMN_NAME           = 'competicao_id'
      AND rc.REFERENCED_TABLE_NAME  = 'competicoes'
      AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
      AND kcu.REFERENCED_TABLE_NAME = 'competicoes'
      AND kcu.REFERENCED_COLUMN_NAME = 'id'
      AND kcu.ORDINAL_POSITION      = 1
      AND 1 = (
          SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu_all
          WHERE kcu_all.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND kcu_all.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
            AND kcu_all.TABLE_NAME        = rc.TABLE_NAME
      )
      AND (rc.DELETE_RULE <> 'RESTRICT' OR rc.UPDATE_RULE <> 'RESTRICT')
);

SET @ddl = IF(
    @fk_exists_wrong_rule > 0,
    'ALTER TABLE temporadas DROP FOREIGN KEY fk_temporadas_competicao',
    'SELECT 1 /* FK ausente ou ja correta; DROP nao necessario */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

-- Contar qualquer FK equivalente, ainda que tenha outro nome. Não criar uma
-- segunda FK redundante se a mesma relação já existir com nome diferente.
SET @fk_relation_count = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
      ON  kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
      AND kcu.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
      AND kcu.TABLE_NAME        = rc.TABLE_NAME
    WHERE rc.CONSTRAINT_SCHEMA       = DATABASE()
      AND rc.TABLE_NAME              = 'temporadas'
      AND kcu.COLUMN_NAME            = 'competicao_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
      AND kcu.REFERENCED_TABLE_NAME  = 'competicoes'
      AND kcu.REFERENCED_COLUMN_NAME = 'id'
      AND kcu.ORDINAL_POSITION       = 1
      AND 1 = (
          SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu_all
          WHERE kcu_all.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND kcu_all.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
            AND kcu_all.TABLE_NAME        = rc.TABLE_NAME
      )
);

-- ADD somente se o nome estiver livre e nenhuma FK equivalente já existir.
-- Uma constraint homônima com outra estrutura não é removida automaticamente.
SET @fk_named_count = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME   = 'fk_temporadas_competicao'
);

SET @ddl = IF(
    @fk_named_count = 0 AND @fk_relation_count = 0,
    'ALTER TABLE temporadas
     ADD CONSTRAINT fk_temporadas_competicao
         FOREIGN KEY (competicao_id) REFERENCES competicoes(id)
         ON DELETE RESTRICT
         ON UPDATE RESTRICT',
    'SELECT 1 /* nome da FK ocupado; validar estrutura e diagnostico final */'
);
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

-- Diagnóstico final. Se a FK não tiver o estado exato, também provoca erro SQL.
SET @fk_final_status = (SELECT CASE
      WHEN EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
        JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
          ON  kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
          AND kcu.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
          AND kcu.TABLE_NAME        = rc.TABLE_NAME
        WHERE rc.CONSTRAINT_SCHEMA       = DATABASE()
          AND rc.TABLE_NAME              = 'temporadas'
          AND rc.CONSTRAINT_NAME         = 'fk_temporadas_competicao'
          AND kcu.COLUMN_NAME            = 'competicao_id'
          AND kcu.REFERENCED_TABLE_NAME  = 'competicoes'
          AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
          AND kcu.REFERENCED_COLUMN_NAME = 'id'
          AND kcu.ORDINAL_POSITION       = 1
          AND 1 = (
              SELECT COUNT(*)
              FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu_all
              WHERE kcu_all.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND kcu_all.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
                AND kcu_all.TABLE_NAME        = rc.TABLE_NAME
          )
          AND rc.DELETE_RULE             = 'RESTRICT'
          AND rc.UPDATE_RULE             = 'RESTRICT'
    ) THEN 'OK'
    WHEN EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_temporadas_competicao'
    ) THEN 'ERRO: nome da FK existe com estrutura ou regras incompatíveis'
    WHEN @fk_relation_count > 0
        THEN 'ERRO: relação equivalente existe com outro nome'
    ELSE 'ERRO: FK esperada ausente'
END);
SELECT @fk_final_status AS fk_temporadas_competicao_status;
SET @fk_final_guard = IF(
    @fk_final_status = 'OK',
    'SELECT 1 /* FK final validada */',
    'SELECT * FROM __ARENA_ISSUE1_FK_FINAL_FAILED__'
);
PREPARE _fk_final_guard_stmt FROM @fk_final_guard;
EXECUTE _fk_final_guard_stmt;
DEALLOCATE PREPARE _fk_final_guard_stmt;

-- Fim. Executar também os SELECTs de validação do cabeçalho.

#!/usr/bin/env bash

set -Eeuo pipefail

# =============================================================================
# Teste de integração da migration 001 - Issue #1
#
# Reproduz o schema histórico do PR #38 em um MySQL descartável.
# Não utiliza .env, Docker Compose, banco local ou dados reais.
#
# Testa:
#   1. Criação da estrutura histórica.
#   2. Preservação de dados existentes.
#   3. Aplicação da migration.
#   4. Estrutura final das seis colunas e três tabelas.
#   5. FK com ON DELETE RESTRICT e ON UPDATE RESTRICT.
#   6. Idempotência: segunda execução da migration.
#
# Requisitos: Docker, Git e migration local do projeto.
# =============================================================================

ROOT="$(git rev-parse --show-toplevel)"
MIGRATION="$ROOT/database/migrations/001_alinhar_schema_mvp.sql"

# Commit que introduziu o schema histórico utilizado como base pela migration.
BASELINE_COMMIT="e650e75843afc3a44e8e0ee0e601cb8e4217d887"

CONTAINER="arena-issue1-test-$$"
ROOT_PASSWORD="issue1_test_only_password"

MYSQL_ARGS=(-uroot "-p$ROOT_PASSWORD" --default-character-set=utf8mb4)

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
    echo
    echo "[FALHA] $1"
    exit 1
}

pass() {
    echo "[OK] $1"
}

echo "============================================================"
echo " Teste de integração - Migration Issue #1"
echo "============================================================"

command -v docker >/dev/null 2>&1 || fail "Docker não encontrado."
command -v git >/dev/null 2>&1 || fail "Git não encontrado."
docker info >/dev/null 2>&1 || fail "Docker daemon indisponível."

[[ -f "$MIGRATION" ]] || fail "Migration não encontrada: $MIGRATION"

git -C "$ROOT" cat-file -e \
    "$BASELINE_COMMIT:database/schema.sql" 2>/dev/null \
    || fail "Schema histórico do PR #38 não encontrado no commit informado."

pass "Pré-requisitos verificados."

echo
echo "[1/6] Iniciando MySQL 8.0 descartável..."

docker run -d \
    --rm \
    --name "$CONTAINER" \
    --network none \
    -e MYSQL_ROOT_PASSWORD="$ROOT_PASSWORD" \
    mysql:8.0.46 >/dev/null

READY=0

for attempt in $(seq 1 60); do
    if docker exec "$CONTAINER" \
        mysqladmin ping -uroot "-p$ROOT_PASSWORD" --silent \
        >/dev/null 2>&1; then
        READY=1
        break
    fi
    sleep 2
done

[[ "$READY" -eq 1 ]] || fail "MySQL não iniciou no tempo esperado."

VERSION="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse 'SELECT VERSION();')"

[[ "$VERSION" == 8.0.* ]] || fail "Versão inesperada do MySQL: $VERSION"

pass "MySQL iniciado: $VERSION."

echo
echo "[2/6] Recriando o schema histórico do PR #38..."

git -C "$ROOT" show "$BASELINE_COMMIT:database/schema.sql" \
    | docker exec -i "$CONTAINER" mysql "${MYSQL_ARGS[@]}"

pass "Schema histórico importado."

echo
echo "[3/6] Inserindo dados e verificando o estado inicial..."

docker exec -i "$CONTAINER" mysql "${MYSQL_ARGS[@]}" brasileirao_risco <<'SQL'
INSERT INTO competicoes
    (id, external_id, nome, codigo)
VALUES
    (1, 2013, 'Campeonato Brasileiro', 'BSA');

INSERT INTO temporadas
    (id, external_id, competicao_id, ano, data_inicio, data_fim)
VALUES
    (1, 2474, 1, 2026, '2026-01-01', '2026-12-31');

INSERT INTO clubes
    (id, external_id, nome, nome_curto, sigla)
VALUES
    (1, 1783, 'Clube de Teste A', 'Teste A', 'TSA'),
    (2, 1769, 'Clube de Teste B', 'Teste B', 'TSB');

INSERT INTO clubes_temporadas
    (id, temporada_id, clube_id, ajuste_pontos, motivo_ajuste)
VALUES
    (1, 1, 1, -3, 'Registro histórico de teste');

INSERT INTO partidas
    (id, external_id, temporada_id, rodada, data_hora_utc,
     status, mandante_id, visitante_id, gols_mandante, gols_visitante)
VALUES
    (1, 900001, 1, 1, '2026-02-01 19:00:00',
     'FINISHED', 1, 2, 2, 1);
SQL

# Confirma que a base começa com a FK histórica em CASCADE.
INITIAL_FK="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT rc.DELETE_RULE
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    WHERE rc.CONSTRAINT_SCHEMA = 'brasileirao_risco'
      AND rc.CONSTRAINT_NAME = 'fk_temporadas_competicao';
")"

[[ "$INITIAL_FK" == "CASCADE" ]] \
    || fail "A FK inicial não está no estado histórico CASCADE."

# Confirma os registros antes da migration.
INITIAL_DATA="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT
        (SELECT COUNT(*) FROM brasileirao_risco.clubes_temporadas WHERE id = 1),
        (SELECT COUNT(*) FROM brasileirao_risco.partidas WHERE id = 1),
        (SELECT ajuste_pontos FROM brasileirao_risco.clubes_temporadas WHERE id = 1);
")"

[[ "$INITIAL_DATA" == $'1\t1\t-3' ]] \
    || fail "Não foi possível preparar os dados históricos."

pass "Dados de teste inseridos e FK histórica confirmada."

echo
echo "[4/6] Executando a migration pela primeira vez..."

docker exec -i "$CONTAINER" \
    mysql "${MYSQL_ARGS[@]}" brasileirao_risco < "$MIGRATION"

pass "Primeira execução concluída."

echo
echo "[5/6] Conferindo estrutura, FK e preservação dos dados..."

COLUMN_COUNT="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'brasileirao_risco'
      AND TABLE_NAME = 'clubes_temporadas'
      AND COLUMN_NAME IN (
        'probabilidade_campeao',
        'probabilidade_libertadores',
        'probabilidade_sulamericana',
        'probabilidade_rebaixamento',
        'probabilidades_posicoes',
        'probabilidades_calculadas_em'
      );
")"

[[ "$COLUMN_COUNT" == "6" ]] \
    || fail "Esperadas 6 colunas de probabilidade; encontradas $COLUMN_COUNT."

pass "As seis colunas de probabilidade existem."

TABLE_COUNT="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = 'brasileirao_risco'
      AND TABLE_NAME IN ('usuarios', 'simulacoes_usuarios', 'sync_logs')
      AND TABLE_TYPE = 'BASE TABLE';
")"

[[ "$TABLE_COUNT" == "3" ]] \
    || fail "Esperadas 3 tabelas novas; encontradas $TABLE_COUNT."

pass "As três tabelas novas existem."

FINAL_FK="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT CONCAT(rc.DELETE_RULE, '|', rc.UPDATE_RULE)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
      ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
     AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
     AND kcu.TABLE_NAME = rc.TABLE_NAME
    WHERE rc.CONSTRAINT_SCHEMA = 'brasileirao_risco'
      AND rc.CONSTRAINT_NAME = 'fk_temporadas_competicao'
      AND rc.TABLE_NAME = 'temporadas'
      AND kcu.COLUMN_NAME = 'competicao_id'
      AND kcu.REFERENCED_TABLE_NAME = 'competicoes'
      AND kcu.REFERENCED_COLUMN_NAME = 'id';
")"

[[ "$FINAL_FK" == "RESTRICT|RESTRICT" ]] \
    || fail "FK incorreta. Resultado encontrado: $FINAL_FK"

pass "FK confirmada: ON DELETE RESTRICT e ON UPDATE RESTRICT."

FINAL_DATA="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT
        (SELECT COUNT(*) FROM brasileirao_risco.clubes_temporadas WHERE id = 1),
        (SELECT COUNT(*) FROM brasileirao_risco.partidas WHERE id = 1),
        (SELECT ajuste_pontos FROM brasileirao_risco.clubes_temporadas WHERE id = 1),
        (SELECT gols_mandante FROM brasileirao_risco.partidas WHERE id = 1),
        (SELECT gols_visitante FROM brasileirao_risco.partidas WHERE id = 1);
")"

[[ "$FINAL_DATA" == $'1\t1\t-3\t2\t1' ]] \
    || fail "Os dados históricos foram alterados ou perdidos: $FINAL_DATA"

pass "Dados históricos preservados."

echo
echo "[6/6] Executando a migration pela segunda vez..."

docker exec -i "$CONTAINER" \
    mysql "${MYSQL_ARGS[@]}" brasileirao_risco < "$MIGRATION"

pass "Segunda execução concluída sem erro."

# Verifica novamente os principais resultados após a segunda execução.
SECOND_COLUMN_COUNT="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'brasileirao_risco'
      AND TABLE_NAME = 'clubes_temporadas'
      AND COLUMN_NAME IN (
        'probabilidade_campeao',
        'probabilidade_libertadores',
        'probabilidade_sulamericana',
        'probabilidade_rebaixamento',
        'probabilidades_posicoes',
        'probabilidades_calculadas_em'
      );
")"

[[ "$SECOND_COLUMN_COUNT" == "6" ]] \
    || fail "A segunda execução alterou inesperadamente as colunas."

SECOND_FK="$(docker exec "$CONTAINER" mysql "${MYSQL_ARGS[@]}" -Nse "
    SELECT CONCAT(DELETE_RULE, '|', UPDATE_RULE)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'brasileirao_risco'
      AND CONSTRAINT_NAME = 'fk_temporadas_competicao';
")"

[[ "$SECOND_FK" == "RESTRICT|RESTRICT" ]] \
    || fail "A FK ficou incorreta após a segunda execução."

echo
echo "============================================================"
echo " RESULTADO: TODOS OS TESTES PASSARAM"
echo "============================================================"
echo "MySQL: $VERSION"
echo "Schema-base: PR #38 ($BASELINE_COMMIT)"
echo "Migration: database/migrations/001_alinhar_schema_mvp.sql"
echo "Colunas de probabilidade: 6/6"
echo "Tabelas novas: 3/3"
echo "FK: RESTRICT / RESTRICT"
echo "Dados históricos: preservados"
echo "Idempotência: confirmada"
echo
echo "O container será removido automaticamente."

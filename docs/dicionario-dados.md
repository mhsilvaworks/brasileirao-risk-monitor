#### Validações estruturais das tabelas novas
# Dicionário de Dados — ARENA_ Monitor de Risco Brasileirão (Série A)

**Versão do padrão:** 2.1
**Referência funcional:** `docs/documentacao_projeto_arena.md` v2.1
**Arquitetura:** MVP Final
**Stack:** PHP 8.3, MySQL 8, PDO, football-data.org v4
**Objetivo:** definir um contrato único para banco, serviços, repositórios, sincronização, cálculos e interface.

> **Escopo deste documento:** descreve o schema implementado e a migration incremental. As diferenças em relação à DDL ilustrativa da seção 4.2 do documento v2.1 foram revisadas e aprovadas pela equipe, conforme registro da Issue #1. A seção 10 consolida as decisões aprovadas e suas justificativas. Este documento, em conjunto com `database/schema.sql` e a migration incremental, é a referência do contrato de dados vigente.

---

## 1. Regras gerais de nomenclatura e armazenamento

- Tabelas e colunas em português, `snake_case`, no plural para tabelas.
- Classes PHP em `PascalCase`; métodos e variáveis em `camelCase`.
- Chaves primárias internas: `id` (`INT AUTO_INCREMENT`).
- Identificadores da football-data.org: `external_id` (`INT NOT NULL UNIQUE`). Nunca identificar clube, competição, temporada ou partida pelo nome, código visual ou sigla.
- FKs usam o nome da entidade + `_id`: `clube_id`, `temporada_id`, `competicao_id`.
- Charset/collation: `utf8mb4` / `utf8mb4_unicode_ci`; engine InnoDB.
- Valores monetários não fazem parte do MVP. Percentuais são armazenados de 0.00 a 100.00, nunca como fração 0–1.
- Datas recebidas da API são normalizadas para UTC antes da persistência. Exibição local usa `America/Sao_Paulo`.
- Campo que pode estar ausente na fonte deve permanecer NULL. Não converter ausência em zero, string vazia ou data inventada.
- **Exceção aprovada à regra de ausência:** `partidas.data_hora_utc` permanece `NOT NULL`, pois o contrato/mapeamento da football-data.org trata `utcDate` como obrigatório. Se o payload não trouxer `utcDate`, a partida não deve ser persistida com data inventada ou NULL; a operação deve rejeitar/adiar o registro e sinalizar a inconsistência.
- Não usar `ENUM` para status externos: a API pode introduzir novos estados. Usar VARCHAR e tratar estados desconhecidos de forma segura.
- SQL somente em Repositories/infraestrutura; páginas PHP não devem conter consultas SQL.
- Toda escrita externa deve ser parametrizada com PDO; nunca concatenar valores recebidos do usuário/API em SQL.
- `criado_em` e `atualizado_em` são metadados internos; `api_updated_at` representa a última atualização informada pela fonte externa. Não confundir os três.

---

## 2. Modelo relacional

```text
competicoes 1 ─── N temporadas
temporadas  1 ─── N partidas
temporadas  N ─── N clubes (por clubes_temporadas)
clubes      1 ─── N partidas como mandante
clubes      1 ─── N partidas como visitante
clubes      1 ─── N clubes_temporadas
clubes      N ─── 1 usuarios (clube favorito, opcional)
usuarios    1 ─── N simulacoes_usuarios
clubes      1 ─── N simulacoes_usuarios
```

### Tabelas obrigatórias do MVP (7 — especificação v2.1)

`clubes`, `temporadas`, `clubes_temporadas`, `partidas`, `usuarios`, `simulacoes_usuarios`, `sync_logs`

### Tabela de suporte à integração (decisão técnica DT-01, não listada como tabela do MVP)

`competicoes` — mantida como suporte à FK de `temporadas.competicao_id`. Ver seção 10.

---

## 3. Dicionário por tabela

### 3.1 `competicoes` — suporte à integração (DT-01)

> Não é uma das 7 tabelas do MVP, mas é necessária para a integridade referencial enquanto `temporadas.competicao_id` mantiver a FK. A remoção futura exige converter `competicao_id` para coluna plain INT (sem FK) ou eliminá-la.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID da competição na football-data.org |
| nome | VARCHAR(120) | Sim | — | Nome oficial |
| codigo | VARCHAR(20) | Sim | UNIQUE | Código da competição (ex.: BSA) |
| emblema_url | VARCHAR(255) | Não | NULL permitido | URL do emblema |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte, quando fornecida |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local do registro |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Última alteração local |

**Índices/FKs:** `UNIQUE(external_id)`, `UNIQUE(codigo)`.

---

### 3.2 `temporadas`

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID da temporada na API |
| competicao_id | INT NOT NULL | Sim | FK → competicoes.id | Competição à qual pertence |
| ano | INT | Sim | — | Ano da temporada |
| data_inicio | DATE | Sim | — | Início da temporada |
| data_fim | DATE | Sim | — | Fim previsto/registrado |
| rodada_atual | INT | Não | NULL permitido | Rodada atual, quando informada |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Índices/FKs:** `UNIQUE(external_id)`; FK `competicao_id → competicoes.id ON DELETE RESTRICT ON UPDATE RESTRICT` (política aprovada na Issue #1).

---

### 3.3 `clubes`

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID do clube na API |
| nome | VARCHAR(120) | Sim | — | Nome completo |
| nome_curto | VARCHAR(80) | Sim | — | Nome curto para interface |
| sigla | VARCHAR(10) | Sim | INDEX, não UNIQUE | Sigla exibida |
| escudo_url | VARCHAR(255) | Não | NULL permitido | URL do escudo |
| estadio | VARCHAR(120) | Não | NULL permitido | Estádio, se disponível |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Regras:** siglas podem se repetir (ex.: COR para Corinthians e Coritiba); não criar UNIQUE para `sigla`.

**Índices/FKs:** `UNIQUE(external_id)`; `INDEX(sigla)`.

---

### 3.4 `usuarios`

Contas do portal. Senhas nunca são armazenadas em texto puro.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| nome | VARCHAR(120) | Sim | — | Nome de exibição |
| email | VARCHAR(190) | Sim | UNIQUE | Login do usuário |
| senha_hash | VARCHAR(255) | Sim | — | Resultado de `password_hash()` |
| clube_favorito_id | INT | Não | FK → clubes.id, NULL permitido | Clube favorito (DT-05) |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Cadastro |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Última alteração |

**Regras:** normalizar email (trim e lowercase) antes de persistir; autenticar com `password_verify()`; atualizar hash com `password_needs_rehash()` quando aplicável. Nunca registrar senha ou hash em logs.

**Índices/FKs:** `UNIQUE(email)`; FK `clube_favorito_id → clubes.id ON DELETE SET NULL`.

---

### 3.5 `clubes_temporadas` — sem estatísticas de partida (DT-03)

Tabela associativa: participação de um clube em uma temporada, ajustes administrativos e resultados probabilísticos do motor PHP.

> **DT-03:** NÃO inclui colunas de estatística de partida (pontos, jogos, vitórias, etc.) presentes na DDL ilustrativa da seção 4.2 do documento v2.1. A classificação é calculada dinamicamente a partir de `partidas` pelo `StandingsService`.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| temporada_id | INT | Sim | FK → temporadas.id | Temporada |
| clube_id | INT | Sim | FK → clubes.id | Clube participante |
| ajuste_pontos | INT NOT NULL | Sim | DEFAULT 0 | Ajuste administrativo de pontos |
| motivo_ajuste | VARCHAR(255) | Não | NULL permitido | Justificativa do ajuste |
| probabilidade_campeao | DECIMAL(5,2) | Não | 0.00–100.00 | Probabilidade estimada de título |
| probabilidade_libertadores | DECIMAL(5,2) | Não | 0.00–100.00 | Probabilidade estimada de vaga na Libertadores |
| probabilidade_sulamericana | DECIMAL(5,2) | Não | 0.00–100.00 | Probabilidade estimada de vaga na Sul-Americana |
| probabilidade_rebaixamento | DECIMAL(5,2) | Não | 0.00–100.00 | Probabilidade estimada de rebaixamento |
| probabilidades_posicoes | JSON | Não | NULL permitido | Distribuição das posições finais 1º a 20º |
| probabilidades_calculadas_em | DATETIME | Não | UTC, NULL se não calculadas | Momento do cálculo probabilístico |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

> **Nome canônico (DT-06):** a coluna de timestamp de probabilidades é `probabilidades_calculadas_em`. SQL executável deve usar esse nome; menções ao nome antigo em comentários explicativos são históricas. Ver seção 10.

**Chave única:** `UNIQUE(temporada_id, clube_id)`.

**Índices/FKs:** `UNIQUE(temporada_id, clube_id)`; FKs de temporada e clube com `ON DELETE CASCADE`.

---

### 3.6 `partidas` — status como VARCHAR(30) (DT-04)

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID da partida na API |
| temporada_id | INT | Sim | FK → temporadas.id | Temporada |
| rodada | INT | Não | NULL permitido | Número da rodada |
| data_hora_utc | DATETIME | Sim | UTC | Data/hora de início em UTC |
| status | VARCHAR(30) | Sim | Não usar ENUM (DT-04) | Status recebido/normalizado |
| mandante_id | INT | Sim | FK → clubes.id | Clube mandante |
| visitante_id | INT | Sim | FK → clubes.id | Clube visitante |
| gols_mandante | INT | Não | NULL permitido | Gols do mandante no fim |
| gols_visitante | INT | Não | NULL permitido | Gols do visitante no fim |
| gols_mandante_ht | INT | Não | NULL permitido | Gols do mandante no intervalo |
| gols_visitante_ht | INT | Não | NULL permitido | Gols do visitante no intervalo |
| vencedor | VARCHAR(20) | Não | NULL permitido | HOME_TEAM, AWAY_TEAM, DRAW ou NULL |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Regras de placar:** `NULL` significa resultado indisponível. Nunca transformar em `0 × 0`. Somente `FINISHED`/`AWARDED` com placares válidos alimentam a classificação.

**Índices/FKs:** `UNIQUE(external_id)`; `INDEX(temporada_id, rodada)`; `INDEX(status)`; FKs de temporada, mandante e visitante com ON DELETE CASCADE.

---

### 3.7 `simulacoes_usuarios`

Armazena os conjuntos de 3 cenários hipotéticos gerados pelo JavaScript e salvos pelo usuário.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| usuario_id | INT | Sim | FK → usuarios.id ON DELETE CASCADE | Usuário que salvou |
| clube_id | INT | Sim | FK → clubes.id ON DELETE CASCADE | Clube do cenário |
| posicao_alvo | TINYINT UNSIGNED | Sim | 1–20 | Posição final desejada |
| cenarios_json | JSON NOT NULL | Sim | — | Os 3 cenários hipotéticos gerados pelo JS |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Momento do salvamento |

**Regras:** `ON DELETE CASCADE` em `usuario_id` garante que a exclusão do usuário remova todos os seus cenários (RN05). JSON deve conter exatamente 3 cenários estruturados; validação na camada de serviço.

**Índices/FKs:** `INDEX(usuario_id)`; `INDEX(clube_id)`; FK `usuario_id → usuarios.id ON DELETE CASCADE`; FK `clube_id → clubes.id ON DELETE CASCADE`.

---

### 3.8 `sync_logs`

Auditoria operacional das execuções de sincronização. Não guardar tokens nem payloads sensíveis.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador do evento |
| tipo | VARCHAR(40) | Sim | — | sincronizacao, competicao, temporada, clubes ou partidas |
| status | VARCHAR(20) | Sim | — | running, success, partial ou failed |
| mensagem | VARCHAR(500) | Não | NULL permitido | Resumo seguro do resultado/erro |
| registros_processados | INT UNSIGNED | Sim | DEFAULT 0 | Quantidade de registros tratados |
| iniciado_em | DATETIME | Sim | UTC | Início da operação |
| finalizado_em | DATETIME | Não | UTC, NULL enquanto executa | Fim da operação |
| duracao_ms | INT UNSIGNED | Não | NULL permitido | Duração em milissegundos |
| detalhes_json | JSON | Não | NULL permitido | Metadados técnicos não sensíveis |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação do log |

---

## 4. Regras de integridade e negócio

1. Competição → temporada → clube → partida deve ser sincronizado nessa ordem lógica.
2. Toda sincronização usa UPSERT/atualização por `external_id`; repetir a mesma carga não pode duplicar registros.
3. Antes de inserir partidas, garantir que temporada e clubes relacionados existam.
4. Não apagar dados reais quando a API falhar; preservar a última versão persistida.
5. Fixtures são exclusivas de testes/desenvolvimento e nunca fallback automático de produção.
6. Classificação é calculada a partir de partidas persistidas, não do endpoint `/standings`.
7. Classificação oficial considera apenas `FINISHED`/`AWARDED` com placares válidos e aplica critérios CBF (RN03).
8. Partidas `IN_PLAY`/`PAUSED` não alteram a classificação oficial.
9. Ajuste administrativo é aplicado via `clubes_temporadas.ajuste_pontos`, com motivo documentado.
10. Cálculos Poisson e Monte Carlo ocorrem no PHP. O JavaScript não calcula probabilidades oficiais.
11. Monte Carlo executa 2.000 iterações no MVP; aleatoriedade deve ser injetável/controlável nos testes.
12. A distribuição de posições contém 20 chaves (1–20); percentuais por clube somam aproximadamente 100%.
13. Erros de API, timeout, HTTP 429 e JSON inválido não devem apagar nem zerar dados válidos.
14. IDs internos nunca são confundidos com IDs externos da API.
15. Exclusão de usuário remove em cascata todos os registros em `simulacoes_usuarios` (RN05).
16. `cenarios_json` deve conter exatamente 3 cenários; validar no serviço antes de persistir.

---

## 5. Contratos de acesso e nomes recomendados

### Repositories (SQL isolado)
- `Database::getConnection(): PDO`
- `ClubeRepository::findByExternalId(int $externalId): ?array`
- `ClubeRepository::upsert(array $clube): void`
- `ClubeRepository::findById(int $id): ?array`
- `ClubeRepository::findByTemporada(int $temporadaId): array` — retorna `probabilidades_calculadas_em`
- `UsuarioRepository::findByEmail(string $email): ?array`
- `UsuarioRepository::create(array $usuario): int`
- `UsuarioRepository::updateProfile(int $id, array $dados): bool`
- `UsuarioRepository::delete(int $id): bool`
- `PartidaRepository::upsert(array $partida): void`
- `PartidaRepository::findFinishedBySeason(int $temporadaId): array`
- `SimulacaoRepository::findByUsuario(int $usuarioId): array`
- `SimulacaoRepository::create(array $simulacao): int`
- `SimulacaoRepository::delete(int $id, int $usuarioId): bool`
- `SyncLogRepository::create(array $log): int`
- `SyncLogRepository::finish(int $id, array $resultado): void`
- `ClubeTemporadaRepository::upsertProbabilities(int $temporadaId, int $clubeId, array $probabilidades): void`

---

## 6. Documentos que devem ser consultados

- `docs/documentacao_projeto_arena.md` v2.1: **referência funcional aprovada**.
- `database/schema.sql`: estrutura completa para banco novo.
- `database/migrations/001_alinhar_schema_mvp.sql`: migração incremental para bancos existentes.
- `docs/api-recognition/mapeamento-dados.md`: mapeamento football-data.org v4 → MySQL.
- PR #35, #36, #38, #39: histórico de schema/seed/mapeamento.

---

## 7. Testes: escopo e limites

### O que os testes estáticos em `SchemaContractTest.php` cobrem

São testes de **contrato textual** — verificam o conteúdo dos arquivos `.sql`, `.php` e `.md` como texto, sem executar SQL nem conectar ao banco. Cobrem:
- Nome canônico `probabilidades_calculadas_em` em todos os arquivos afetados.
- Presença das 7 tabelas obrigatórias do MVP e da tabela auxiliar no schema.
- Nome do banco (`brasileirao_risco`).
- `VARCHAR(30)` para `status` em partidas (não ENUM).
- ON DELETE CASCADE em `simulacoes_usuarios`.
- Uso de `INFORMATION_SCHEMA` e `REFERENTIAL_CONSTRAINTS` + `KEY_COLUMN_USAGE` na migration.
- Pré-condições e limitações declaradas no cabeçalho da migration.
- Versão e decisões técnicas do dicionário.
- Uso de prepared statements no repositório.

### O que os testes estáticos NÃO cobrem

- Que a migration execute sem erro em um banco real.
- Que a migration preserve dados existentes.
- Que `CREATE TABLE IF NOT EXISTS` produza exatamente a estrutura esperada.
- Que a verificação de FK funcione corretamente em MySQL 8 com dados reais.
- Que `findByTemporada()` retorne a coluna correta de um banco real.
- Que o preflight ou o SQL dinâmico interrompa a execução; isso requer MySQL real.

### Teste de integração MySQL 8.0.46 — histórico da revisão anterior

Os resultados abaixo registram os testes da revisão anterior à versão 1.2. Os cenários de coluna incompatível, tabela `usuarios` divergente e temporada órfã expuseram falsos sucessos/aplicação parcial e motivaram o preflight fail-fast documentado a seguir. Não representam o comportamento da versão 1.2.

O fluxo histórico e os cenários B–F foram executados em 24/11/2026 usando containers descartáveis sem rede, portas publicadas ou mounts; `/var/lib/mysql` ficou em `tmpfs`. A versão do servidor foi confirmada com `SELECT VERSION()` como `8.0.46`. O banco principal do container foi criado pelo próprio `database/schema.sql` histórico e ficou com `utf8mb4_unicode_ci`. Nenhum container, volume ou banco do Compose do projeto foi usado.

No banco histórico limpo, o estado inicial foi 1 competição, 1 temporada, 20 clubes, 20 vínculos, 0 partidas e 0 temporadas órfãs. A FK era `CASCADE`/`NO ACTION`. A primeira execução da versão atual terminou com código 0 e diagnóstico `OK`; a segunda execução também terminou com código 0 e `OK`. Em ambas as execuções, seis colunas, três tabelas e uma FK equivalente permaneceram presentes, com `DELETE_RULE=RESTRICT` e `UPDATE_RULE=RESTRICT`.

Para verificar preservação, serializei em JSON e ordenei 42 linhas das cinco tabelas históricas, incluindo seus IDs e todos os campos existentes no PR #38. `diff -u` antes/depois não encontrou diferenças na primeira nem na segunda execução. O SHA-256 das três capturas foi idêntico: `691d4fa82ed4a1c5dbd986c312b48145db9ed0adef239fb59c370df09018fdb6`.

Os resultados adicionais estão registrados abaixo. Diagnóstico textual `ERRO` com exit code 0 é comportamento observado: é necessário validar o status retornado, não apenas o código do cliente.

```bash
# Execute o bloco em um shell próprio; erro interrompe o roteiro.
set -euo pipefail

# 1. Gerar um identificador único para esta execução e confirmar explicitamente
#    que o banco ainda não existe. Se existir, pare e gere outro nome; não reutilize
#    nem apague banco existente.
TEST_DB="arena_migration_test_issue1_$(date -u +%Y%m%d_%H%M%S)_$$"
EXISTING_DB="$(mysql -N -B -u root -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${TEST_DB}';")"
if [[ -n "${EXISTING_DB}" ]]; then
  echo "ERRO: ${TEST_DB} já existe; escolha outro identificador."
  exit 1
fi
mysql -u root -e "CREATE DATABASE ${TEST_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
git show e650e75:database/schema.sql | sed "s/brasileirao_risco/${TEST_DB}/g" | mysql -u root "${TEST_DB}"
git show e650e75:database/seed.sql | sed "s/brasileirao_risco/${TEST_DB}/g" | mysql -u root "${TEST_DB}"

# 2. Registrar contagens de dados de base e aplicar migration no banco explicitamente selecionado.
mysql -u root "${TEST_DB}" -e "SELECT (SELECT COUNT(*) FROM competicoes) competicoes, (SELECT COUNT(*) FROM clubes) clubes, (SELECT COUNT(*) FROM temporadas) temporadas, (SELECT COUNT(*) FROM clubes_temporadas) clubes_temporadas, (SELECT COUNT(*) FROM partidas) partidas;"
mysql -u root "${TEST_DB}" < database/migrations/001_alinhar_schema_mvp.sql

# 3. Validar estado resultante (usar queries do cabeçalho da migration).
#    A migration não contém USE; o argumento do banco mantém o teste isolado.
mysql -u root "${TEST_DB}" -e "
  SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='${TEST_DB}'
    AND TABLE_NAME='clubes_temporadas'
    AND COLUMN_NAME LIKE 'probabilidad%'
  ORDER BY COLUMN_NAME;"
# Esperado: 6 linhas

# 4. Reexecutar para testar idempotência e comparar as contagens de dados com o passo 2.
mysql -u root "${TEST_DB}" < database/migrations/001_alinhar_schema_mvp.sql
# Esperado: sem erros
mysql -u root "${TEST_DB}" -e "SELECT (SELECT COUNT(*) FROM competicoes) competicoes, (SELECT COUNT(*) FROM clubes) clubes, (SELECT COUNT(*) FROM temporadas) temporadas, (SELECT COUNT(*) FROM clubes_temporadas) clubes_temporadas, (SELECT COUNT(*) FROM partidas) partidas;"

# 5. Compare as cinco contagens antes/depois. A migration não deve criar,
#    remover nem duplicar esses registros. A versão anterior imprimia ERRO
#    sem garantir exit code diferente de zero; a versão 1.2 falha por SQL.
#    Não remova automaticamente o banco; descarte-o somente por decisão explícita.
```

### Resultados dos cenários B–F em MySQL 8.0.46

Cada cenário usou um schema descartável independente, carregado do schema e seed históricos do commit `e650e75843afc3a44e8e0ee0e601cb8e4217d887`.

| Cenário | Resultado real | Avaliação |
|---|---|---|
| B — FK já correta | `OK`, exit code 0; uma FK permaneceu. O log geral do MySQL registrou os ramos preparados `SELECT 1` para DROP e ADD e nenhum `ALTER TABLE temporadas`; a FK não foi recriada. | Aprovado |
| C — relação equivalente sob outro nome | Diagnóstico `ERRO: relação equivalente existe com outro nome`, exit code 0; permaneceu somente a FK alternativa, sem duplicata. | Comportamento esperado; operador deve tratar como não concluído |
| D — nome canônico ocupado por relação com destino `clubes.id` | Diagnóstico `ERRO: nome da FK existe com estrutura ou regras incompatíveis`, exit code 0; a FK incompatível continuou apontando para `clubes.id`, sem criação da relação esperada. | Aprovado quanto à não destruição; operador deve tratar como não concluído |
| E — temporada órfã | `ERROR 1452` na linha 398, ao adicionar a FK; exit code 1. A execução foi interrompida antes do SELECT diagnóstico final. As seis colunas e três tabelas já tinham sido aplicadas; a FK ficou ausente e as duas temporadas (incluindo a órfã) permaneceram. | Falha identificável, mas aplicação parcial confirmada |
| F — coluna `probabilidade_campeao VARCHAR(20)` preexistente | A migration ignorou o tipo errado, criou as demais colunas/tabelas, retornou `OK` e exit code 0. | Defeito confirmado: falso sucesso com schema incompatível |
| F — `usuarios` preexistente com apenas `id` e `nome VARCHAR(20)` | `CREATE TABLE IF NOT EXISTS` preservou a tabela divergente; as outras tabelas foram criadas, FK validada, diagnóstico `OK`, exit code 0. | Defeito confirmado: falso sucesso com tabela incompatível |
| F — tabela-base `clubes_temporadas` ausente | `ERROR 1146` na linha 171, exit code 1; falha antes da criação das seis colunas e das três tabelas novas. | Falha direta, sem diagnóstico final |
| F — coluna `temporadas.competicao_id` ausente | `ERROR 1072` na linha 398, exit code 1; as seis colunas e três tabelas já estavam aplicadas, a FK não foi criada e o SELECT final não executou. | Falha parcial confirmada |

Na execução inicial da revisão anterior, variações de engine e PK/índices incompatíveis ainda não tinham sido testadas nem verificadas automaticamente.

### Reteste complementar do preflight 1.2 — 24/11/2026

Executado em MySQL Server **8.0.46** dentro de um container criado só para esta tarefa, com rede `none`, `/var/lib/mysql` em `tmpfs`, sem portas publicadas e sem mounts. Nenhum volume nem container do Compose foi usado. O container temporário foi identificado como `arena-migration-issue1-preflight-20261124-a91f` (ID `32458c857fd29117f0e07d6c114a16b367b3e67e683418ebcb877b1294ea0d9d`). O container de integração existente e o container `brasileirao-risk-monitor-db-1` foram deixados intactos.

| Cenário | Resultado observado na versão 1.2 | Avaliação |
|---|---|---|
| 1 — `probabilidade_campeao VARCHAR(20)` preexistente | Diagnóstico identifica tipo incompatível; cliente retorna exit code 1. Apenas a coluna inválida já preparada pelo cenário existia; nenhuma das outras cinco probabilidades nem as três tabelas novas foi criada. | Aprovado |
| 2 — `usuarios` incompleta (`id`, `nome VARCHAR(20)`) | Lista colunas ausentes/incompatíveis, índice único e FK ausentes; exit code 1. Zero colunas de probabilidade e zero tabelas novas foram criadas; a tabela original ficou intacta. | Aprovado |
| 3 — `clubes_temporadas` ausente | Diagnóstico `Tabela obrigatória ausente`; exit code 1. Zero tabelas novas criadas. | Aprovado |
| 4 — `temporadas.competicao_id` ausente | Diagnóstico da coluna ausente/incompatível; exit code 1. Zero tabelas novas criadas. | Aprovado |
| 5 — temporada com `competicao_id=999999` sem competição | Diagnóstico inclui ID da temporada e instrução para consultar com `LEFT JOIN`; exit code 1. A coluna órfã foi preservada e nenhuma coluna probabilística/tabela nova foi criada. | Aprovado |
| 6 — schema e seed históricos válidos | Preflight `PREFLIGHT OK`; migration conclui com `fk_temporadas_competicao_status=OK`, convertendo a FK histórica para `DELETE_RULE=RESTRICT`, `UPDATE_RULE=RESTRICT`. Contagens: 1 competição, 1 temporada, 20 clubes, 20 vínculos, 0 partidas. | Aprovado |
| 7 — segunda execução | Preflight aprovado e status final `OK`; contagens e snapshot dos dados permaneceram iguais. | Aprovado |
| FK equivalente sob nome alternativo | Preflight retorna erro antes dos DDLs; exit code 1. A FK alternativa permanece única; não foram adicionadas colunas ou tabelas. | Aprovado |
| Nome canônico associado a `temporadas.competicao_id → clubes.id` | Preflight retorna erro antes dos DDLs; exit code 1. A constraint incompatível permanece intacta; sem alterações adicionais. | Aprovado |

Nos cenários históricos válidos, comparei snapshots JSON dos valores de todas as colunas que existiam nas cinco tabelas do PR #38. O SHA-256 antes e depois da primeira execução, e novamente após a segunda execução, foi `2f684a7d2c2ae5885111ab81699a88599a234a5b1e2dbbd83a37cf637ed908ab`.

O guard de falha usa uma consulta dinâmica à tabela sentinela inexistente, após imprimir as linhas de diagnóstico. No cliente `mysql` em modo batch usado pelo projeto, o servidor retorna erro SQL e a execução termina com exit code 1 antes dos DDLs da migration. A opção `--force` não pode ser usada, pois instrui o cliente a continuar após erros.

Ainda não foram exercitadas todas as variações possíveis de precisão temporal, collation/charset, constraints com múltiplas colunas, triggers ou privilégios insuficientes. O preflight foca os atributos que a migration e as tabelas criadas dependem diretamente; incompatibilidades fora dessas verificações podem exigir inspeção específica.

---

## 8. Migration incremental — pré-condições, limites e validação

### Quando usar

Somente em bancos criados pelo schema do PR #38 (banco `brasileirao_risco` com `competicoes`, `temporadas`, `clubes`, `clubes_temporadas` e `partidas`). Para banco novo, usar `database/schema.sql`.

### Como executar e como o preflight interrompe

O repositório não possui runner PHP, script Composer ou workflow de CI para migrations. O procedimento documentado é executar o SQL diretamente pelo cliente oficial `mysql` em modo batch, selecionando explicitamente o banco (`mysql brasileirao_risco < database/migrations/001_alinhar_schema_mvp.sql`). O arquivo não contém `USE`. Não use a opção `--force`.

Antes dos blocos 1–3, o preflight cria uma tabela **temporária da sessão**, registra e mostra as divergências encontradas e prepara uma consulta de guarda. Se houver qualquer erro, a guarda tenta consultar uma tabela sentinela inexistente; o servidor retorna erro SQL e o cliente `mysql` batch encerra com código diferente de zero antes dos DDLs de alteração. A tabela temporária desaparece quando a conexão termina. A migration não cria procedure, trigger ou outro objeto persistente para esse controle.

O preflight verifica:

- Seleção de um banco; existência das tabelas históricas `competicoes`, `temporadas`, `clubes` e `clubes_temporadas` e engine InnoDB.
- Tipos, nulabilidade e PKs simples de `competicoes.id`, `temporadas.id`, `temporadas.competicao_id`, `clubes.id`, `clubes_temporadas.id`, `temporada_id` e `clube_id`.
- Se qualquer coluna de probabilidade já existir, tipo, precisão/escala quando DECIMAL, nulabilidade e default NULL. Colunas ausentes são aceitas para o Bloco 1 adicioná-las.
- Se `usuarios`, `simulacoes_usuarios` ou `sync_logs` já existirem: colunas contratuais, tipos, nulabilidade, PK, engine, índice único de email e relacionamentos com regras de exclusão esperadas. O preflight não tenta converter tabela divergente.
- A estrutura da FK canônica `fk_temporadas_competicao`; uma relação sob outro nome é reportada e bloqueia para evitar duplicação. A FK canônica com a relação histórica correta pode ter regras de exclusão/atualização diferentes; o Bloco 3 ajusta para RESTRICT/RESTRICT.
- Temporadas órfãs (`temporadas.competicao_id` sem `competicoes.id`). O diagnóstico informa os IDs e orienta a consulta de LEFT JOIN; nenhum registro é alterado ou removido.

Falha do preflight significa que nenhum DDL dos três blocos foi executado. Se uma falha ocorrer depois que a guarda aprovar, DDL MySQL pode provocar commits implícitos; não há rollback transacional global. Faça backup e execute primeiro em cópia descartável.

### O que a migration faz (Bloco 1)

Adiciona as seis colunas ausentes a `clubes_temporadas`. Colunas preexistentes passam pelo preflight e são preservadas se compatíveis.

Em uma instalação nova, essas seis colunas aparecem antes de `criado_em` e `atualizado_em`; na migration incremental, `ADD COLUMN` as acrescenta ao final da tabela histórica. Os nomes e contratos de tipo/null/default são os mesmos quando a coluna é criada, mas a ordem física das colunas difere. Repositories devem selecionar colunas pelo nome, nunca depender de `SELECT *` ou da posição ordinal.

Uma coluna preexistente incompatível bloqueia a migration antes de qualquer alteração. Nenhuma conversão automática é tentada; a resolução precisa de uma migration específica com estratégia de dados aprovada.

### O que a migration faz (Bloco 2)

Cria `usuarios`, `simulacoes_usuarios` e `sync_logs` via `CREATE TABLE IF NOT EXISTS` quando estiverem ausentes. Se uma delas já existir, o preflight compara a estrutura contratual antes de permitir que a migration continue.

Uma tabela preexistente incompatível reprova o preflight e não é alterada automaticamente. `CREATE TABLE IF NOT EXISTS` não é usado como mecanismo de atualização.
#### Validações estruturais das tabelas novas

Além da existência das colunas, o preflight deve verificar se as estruturas das tabelas `usuarios`, `simulacoes_usuarios` e `sync_logs` correspondem integralmente ao contrato definido em `database/schema.sql`.

As verificações incluem:

- **Chaves primárias:** devem ser simples, compostas exclusivamente pela coluna `id` de cada tabela.
- **Índice de email:** `usuarios.email` deve possuir um índice `UNIQUE` composto exclusivamente por essa coluna. Um índice composto que apenas comece por `email` não atende ao contrato.
- **AUTO_INCREMENT:** as colunas `id` das três tabelas devem manter esse atributo.
- **Valores padrão:** devem corresponder à definição do schema, incluindo `CURRENT_TIMESTAMP` nos campos de criação, `ON UPDATE CURRENT_TIMESTAMP` em `usuarios.atualizado_em` e valor padrão `0` em `sync_logs.registros_processados`.
- **Ausência de default:** `sync_logs.iniciado_em` deve permanecer sem valor padrão, pois representa o início explícito da operação.
- **Nulabilidade:** os campos obrigatórios e opcionais devem respeitar as definições de `NOT NULL` e `NULL` do schema.

O preflight deve rejeitar estruturas incompatíveis antes da execução dos DDLs. Não deve tentar corrigir automaticamente índices, chaves, defaults ou atributos divergentes.

**Limite da verificação:** a comparação deve considerar os metadados disponibilizados pelo MySQL em `INFORMATION_SCHEMA`. Quando o MySQL representar de forma equivalente a ausência de default e `DEFAULT NULL` para uma coluna nullable, essa diferença textual não deve ser tratada isoladamente como incompatibilidade.

### O que a migration faz (Bloco 3)

Confere a tabela/coluna de origem (`temporadas.competicao_id`), o schema/tabela/coluna referenciados (`DATABASE().competicoes.id`), que a FK tenha uma única coluna e as regras `DELETE_RULE` e `UPDATE_RULE`, cruzando `REFERENTIAL_CONSTRAINTS` e `KEY_COLUMN_USAGE`. No MySQL 8.0.46, uma FK histórica que omite `ON UPDATE` aparece como `UPDATE_RULE=NO ACTION`; para atingir o contrato explícito, a migration recria a relação com `ON DELETE RESTRICT ON UPDATE RESTRICT`. Adiciona a FK esperada quando o nome está livre e não existe relação equivalente sob outro nome.

Se o nome canônico estiver associado a outra relação, ou se houver relação equivalente sob outro nome, o preflight falha antes do primeiro DDL. A FK histórica canônica correta pode ser substituída por RESTRICT/RESTRICT.

### Idempotência

Com o preflight aprovado, os blocos podem ser reexecutados. Estruturas divergentes são rejeitadas, não corrigidas. O resultado textual final da FK deve ser `OK`; uma inconsistência no estado final gera erro SQL. Isso não oferece rollback geral se um DDL posterior falhar.

### Validação após execução

Executar as queries do cabeçalho do arquivo `001_alinhar_schema_mvp.sql` e confirmar os resultados esperados descritos lá.

---

## 9. Fora do modelo obrigatório do MVP

- Histórico de classificação.
- Auditoria comparativa.
- Qualquer extensão de `simulacoes_usuarios` além da persistência básica de 3 cenários.

---

## 10. Decisões técnicas aprovadas

As decisões abaixo foram formalmente aprovadas na Issue #1. Elas representam o modelo de dados vigente, mesmo quando diferem da DDL ilustrativa da seção 4.2 do documento v2.1. A aprovação mantém o schema implementado; não determina alteração retroativa nem conversão automática de bancos.

| ID | Decisão aprovada | Justificativa e impacto |
|---|---|---|
| DT-01 | Manter `competicoes` como tabela auxiliar de integração, além das 7 tabelas obrigatórias do MVP. | `temporadas.competicao_id` precisa de referência íntegra; o mapeamento v4 usa o `external_id` da competição. |
| DT-02 | Manter `simulacoes_usuarios` como tabela obrigatória. | RF07, RN05 e o perfil do usuário exigem persistência dos cenários salvos. |
| DT-03 | Manter `clubes_temporadas` sem colunas duplicadas de estatísticas de partidas (pontos, jogos, vitórias etc.). | A classificação é derivada de `partidas` pelo `StandingsService`, evitando redundância e divergência entre fontes. |
| DT-04 | Manter `partidas.status` como `VARCHAR(30)`, não `ENUM`. | A API pode introduzir estados novos; o armazenamento deve preservar estados externos desconhecidos. |
| DT-05 | Manter `usuarios.clube_favorito_id` nullable, com FK `ON DELETE SET NULL`. | O RF04 permite perfil sem clube favorito; excluir um clube não deve excluir o usuário. |
| DT-06 | Manter `clubes_temporadas.probabilidades_calculadas_em` como nome canônico. | É o nome adotado pelo schema implementado no PR #49. SQL executável deve usar `_em`; `_on` é apenas referência histórica ao erro corrigido. |
| DT-07 | Manter o nome do banco `brasileirao_risco`. | É o nome adotado desde o PR #38. Renomear exigiria plano de migração coordenado entre ambientes. |
| DT-08 | Manter `external_id` obrigatório e único nas entidades integradas à football-data.org. | O identificador externo é a chave de integração; registros sem identificador válido devem ser rejeitados/adiados, nunca relacionados por nome ou sigla. |
| DT-09 | Manter em `temporadas` os campos de integração `external_id`, `competicao_id`, ano, datas, rodada e `api_updated_at`. | São necessários ao mapeamento e à sincronização da football-data.org. |
| DT-10 | Manter `clubes.sigla` como `VARCHAR(10)`, indexada e não única. | Siglas podem se repetir e o modelo não deve impor unicidade nem limitar artificialmente o comprimento a três caracteres. |
| DT-11 | Manter a ausência de `clubes.cor_primaria`. | O campo não integra o contrato de dados aprovado; uma necessidade futura deverá ser tratada em mudança específica. |
| DT-12 | Manter `partidas.data_hora_utc` como `DATETIME NOT NULL`, com o nome atual. | O mapeamento da fonte considera `utcDate` obrigatório. Payload sem esse campo deve ser rejeitado/adiado, sem inventar data ou gravar NULL. |
| DT-13 | Manter gols de partidas como `INT NULL` (incluindo placares do intervalo). | A ausência de placar deve continuar representada por NULL; não converter indisponibilidade em zero. |
| DT-14 | Manter a ausência de `partidas.minuto_jogo`. | O campo não é necessário ao contrato atual do MVP; inclusão futura exige requisito e origem de dado definidos. |
| DT-15 | Manter a estrutura ampliada de `sync_logs` documentada neste dicionário. | Os campos e estados dão suporte ao acompanhamento operacional das sincronizações e ao diagnóstico de falhas. |
| DT-16 | Manter as regras CASCADE documentadas para FKs de partidas, `clubes_temporadas` e `simulacoes_usuarios`. | As regras refletem a política de dependência dos registros relacionados e o comportamento de exclusão definido para o MVP. |
| DT-17 | Manter `temporadas.competicao_id → competicoes.id` com `ON DELETE RESTRICT` e `ON UPDATE RESTRICT`. | Política aprovada expressamente na Issue #1: impedir exclusão/alteração de uma competição enquanto houver temporadas referenciando-a. |

### Registro de aprovação e limites

- A aprovação foi registrada na Issue #1 e confirma as escolhas acima como contrato vigente do projeto.
- A aprovação não autoriza apagar dados, alterar tipos automaticamente ou executar DDL destrutivo em bancos persistentes.
- `database/schema.sql` define a criação de bancos novos; `database/migrations/001_alinhar_schema_mvp.sql` alinha bancos legados compatíveis com o escopo documentado.
- Mudanças futuras em qualquer decisão desta seção exigem revisão de impacto, atualização do dicionário/schema/migrations, testes correspondentes e registro explícito no PR e nas issues afetadas.
- Divergências ilustrativas da DDL v2.1 que não estejam reproduzidas como pendências nesta seção não devem ser reabertas por interpretação isolada da DDL antiga; este contrato aprovado prevalece para a implementação atual.

---

## 11. Critério de mudança deste padrão

Qualquer mudança de nome, tipo, nulabilidade, chave, relacionamento ou significado de campo deve:
1. atualizar este documento;
2. atualizar `database/schema.sql` e scripts relacionados;
3. revisar Repositories e Services dependentes;
4. adicionar/ajustar testes;
5. registrar a decisão no Pull Request e nas issues afetadas.

**Este documento é a referência funcional do modelo de dados. Se houver divergência entre ele e o schema implementado, registrar como pendente e corrigir antes de criar consultas dependentes da coluna divergente.**

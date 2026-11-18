# Dicionário de Dados — ARENA_ Monitor de Risco Brasileirão (Série A)

**Versão do padrão:** 2.0  
**Referência funcional:** `docs/documentacao_projeto_arena.md` v2.1  
**Arquitetura:** MVP Final  
**Stack:** PHP 8.3, MySQL 8, PDO, football-data.org v4  
**Objetivo:** definir um contrato único para banco, serviços, repositórios, sincronização, cálculos e interface.

> Este documento foi reconciliado com a especificação v2.1 no contexto da **Issue #1**. As seções alteradas em relação ao dicionário v1.0 estão marcadas com `[ALTERADO]` ou `[NOVO]`. Decisões técnicas que divergem do texto do documento v2.1 estão registradas na seção 10.

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
- Não usar `ENUM` para status externos: a API pode introduzir novos estados. Usar VARCHAR e tratar estados desconhecidos de forma segura.
- SQL somente em Repositories/infraestrutura; páginas PHP não devem conter consultas SQL.
- Toda escrita externa deve ser parametrizada com PDO; nunca concatenar valores recebidos do usuário/API em SQL.
- `criado_em` e `atualizado_em` são metadados internos; `api_updated_at` representa a última atualização informada pela fonte externa. Não confundir os três.

---

## 2. Modelo relacional oficial [ALTERADO]

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

### Tabela de suporte à integração (decisão técnica DT-01)

`competicoes` — mantida como suporte à FK de `temporadas.competicao_id`. Ver seção 10.

---

## 3. Dicionário por tabela

### 3.1 `competicoes` [ALTERADO — status: suporte à integração, não tabela do MVP]

> **Decisão técnica DT-01:** mantida como tabela auxiliar de normalização para resolver `temporadas.competicao_id`. Não é uma das 7 tabelas do MVP, mas é necessária para a integridade referencial enquanto `temporadas` mantiver a FK. Ver seção 10.

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
| competicao_id | INT | Sim | FK → competicoes.id | Competição à qual pertence |
| ano | INT | Sim | — | Ano da temporada |
| data_inicio | DATE | Sim | — | Início da temporada |
| data_fim | DATE | Sim | — | Fim previsto/registrado |
| rodada_atual | INT | Não | NULL permitido | Rodada atual, quando informada |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Índices/FKs:** `UNIQUE(external_id)`; FK `competicao_id → competicoes.id ON DELETE RESTRICT`.

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

### 3.4 `usuarios` [NOVO — ausente no schema anterior; obrigatória conforme v2.1]

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

**Regras:** normalizar email (trim e lowercase) antes de persistir; autenticar com `password_verify()`; atualizar hash com `password_needs_rehash()` quando aplicável. Nunca registrar senha ou hash em logs. Clube favorito pode ser NULL (DT-05).

**Índices/FKs:** `UNIQUE(email)`; FK `clube_favorito_id → clubes.id ON DELETE SET NULL`.

---

### 3.5 `clubes_temporadas` [ALTERADO — adiciona colunas de probabilidade]

Tabela associativa: participação de um clube em uma temporada, ajustes administrativos e resultados probabilísticos do motor PHP.

> **Decisão técnica DT-03:** NÃO inclui colunas de estatística de partida (pontos, jogos, vitórias, etc.) que constam na DDL ilustrativa da seção 4.2 do documento v2.1. A classificação é calculada dinamicamente a partir da tabela `partidas` pelo `StandingsService`, evitando redundância e risco de dessincronização.

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
| probabilidades_calculadas_em | DATETIME | Não | UTC | Momento do cálculo probabilístico |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Chave única:** `UNIQUE(temporada_id, clube_id)`.

**Regras:** `ajuste_pontos` pode ser positivo ou negativo. Probabilidades entre 0 e 100; NULL significa não calculado. JSON de posições deve ter chaves `"1"` a `"20"` com valores percentuais cuja soma por clube seja aproximadamente 100%.

Exemplo válido de `probabilidades_posicoes`:
```json
{"1": 5.00, "2": 5.00, "3": 5.00, "4": 5.00, "5": 5.00,
 "6": 5.00, "7": 5.00, "8": 5.00, "9": 5.00, "10": 5.00,
 "11": 5.00, "12": 5.00, "13": 5.00, "14": 5.00, "15": 5.00,
 "16": 5.00, "17": 5.00, "18": 5.00, "19": 5.00, "20": 5.00}
```

**Índices/FKs:** `UNIQUE(temporada_id, clube_id)`; FKs de temporada e clube com `ON DELETE CASCADE`.

---

### 3.6 `partidas` [ALTERADO — tipos de gols mantidos como INT para compatibilidade]

Partidas da temporada, incluindo jogos agendados, em andamento e finalizados.

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

**Regras de status:** `VARCHAR(30)`, não ENUM. Estados conhecidos: `FINISHED`, `AWARDED`, `IN_PLAY`, `PAUSED`, `SCHEDULED`, `TIMED`, `SUSPENDED`, `POSTPONED`, `CANCELLED`. Estados desconhecidos devem ser persistidos sem causar erro.

**Regras de placar:** `NULL` significa resultado indisponível. Nunca transformar em `0 × 0`. Somente `FINISHED`/`AWARDED` com placares válidos alimentam a classificação.

**Índices/FKs:** `UNIQUE(external_id)`; `INDEX(temporada_id, rodada)`; `INDEX(status)`; FKs de temporada, mandante e visitante.

---

### 3.7 `simulacoes_usuarios` [NOVO — obrigatória conforme especificação v2.1]

Armazena os conjuntos de 3 cenários hipotéticos gerados pelo JavaScript e salvos pelo usuário no perfil.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | Sim | PK | Identificador interno |
| usuario_id | INT | Sim | FK → usuarios.id | Usuário que salvou |
| clube_id | INT | Sim | FK → clubes.id | Clube do cenário |
| posicao_alvo | TINYINT UNSIGNED | Sim | 1–20 | Posição final desejada |
| cenarios_json | JSON NOT NULL | Sim | — | Os 3 cenários hipotéticos gerados pelo JS |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Momento do salvamento |

**Regras:** `ON DELETE CASCADE` em `usuario_id` garante que a exclusão do usuário remova todos os seus cenários (RN05). JSON deve conter exatamente 3 cenários estruturados; validação na camada de serviço antes da persistência.

Exemplo válido de `cenarios_json`:
```json
[
  {"id": 1, "tipo": "Regular", "descricao": "Vitórias simples em casa", "resultados": []},
  {"id": 2, "tipo": "Confrontos Diretos", "descricao": "Foco nos jogos de 6 pontos", "resultados": []},
  {"id": 3, "tipo": "Arrancada", "descricao": "Vitórias com saldo de gols", "resultados": []}
]
```

**Índices/FKs:** `INDEX(usuario_id)`; `INDEX(clube_id)`; FK `usuario_id → usuarios.id ON DELETE CASCADE`; FK `clube_id → clubes.id ON DELETE CASCADE`.

---

### 3.8 `sync_logs`

Auditoria operacional das execuções de sincronização. Não guardar tokens nem payloads sensíveis.

| Campo | Tipo MySQL | Obrigatório | Chave/regra | Descrição |
|---|---|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador do evento |
| tipo | VARCHAR(40) | Sim | — | Operação: sincronizacao, competicao, temporada, clubes ou partidas |
| status | VARCHAR(20) | Sim | — | running, success, partial ou failed |
| mensagem | VARCHAR(500) | Não | NULL permitido | Resumo seguro do resultado/erro |
| registros_processados | INT UNSIGNED | Sim | DEFAULT 0 | Quantidade de registros tratados |
| iniciado_em | DATETIME | Sim | UTC | Início da operação |
| finalizado_em | DATETIME | Não | UTC, NULL enquanto executa | Fim da operação |
| duracao_ms | INT UNSIGNED | Não | NULL permitido | Duração em milissegundos |
| detalhes_json | JSON | Não | NULL permitido | Metadados técnicos não sensíveis |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação do log |

**Regras:** registrar início, sucesso, falha e execução parcial. Nunca incluir token, senha, cabeçalhos de autenticação, dados pessoais ou corpo integral de resposta externa.

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
15. **[NOVO]** Exclusão de usuário remove em cascata todos os registros em `simulacoes_usuarios` (RN05).
16. **[NOVO]** `cenarios_json` deve conter exatamente 3 cenários; validar no serviço antes de persistir.

---

## 5. Contratos de acesso e nomes recomendados

### Repositories (SQL isolado)
- `Database::getConnection(): PDO`
- `ClubeRepository::findByExternalId(int $externalId): ?array`
- `ClubeRepository::upsert(array $clube): void`
- `ClubeRepository::findById(int $id): ?array`
- `ClubeRepository::findBySeason(int $temporadaId): array`
- `UsuarioRepository::findByEmail(string $email): ?array`
- `UsuarioRepository::findById(int $id): ?array`
- `UsuarioRepository::create(array $usuario): int`
- `UsuarioRepository::updateProfile(int $id, array $dados): bool`
- `UsuarioRepository::updatePasswordHash(int $id, string $hash): bool`
- `UsuarioRepository::delete(int $id): bool`
- `PartidaRepository::upsert(array $partida): void`
- `PartidaRepository::findFinishedBySeason(int $temporadaId): array`
- `PartidaRepository::findLiveByClub(int $clubeId): array`
- `TemporadaRepository::upsert(array $temporada): void`
- `CompeticaoRepository::upsert(array $competicao): void`
- `SyncLogRepository::create(array $log): int`
- `SyncLogRepository::finish(int $id, array $resultado): void`
- `ClubeTemporadaRepository::upsertProbabilities(int $temporadaId, int $clubeId, array $probabilidades): void`
- **[NOVO]** `SimulacaoRepository::findByUsuario(int $usuarioId): array`
- **[NOVO]** `SimulacaoRepository::create(array $simulacao): int`
- **[NOVO]** `SimulacaoRepository::delete(int $id, int $usuarioId): bool`

### Services / infraestrutura
- `SyncService::syncCompetition(): array`
- `SyncService::syncSeason(int $seasonExternalId): array`
- `SyncService::syncClubs(int $seasonExternalId): array`
- `SyncService::syncMatches(int $seasonExternalId): array`
- `SyncService::syncAll(): array`
- `StandingsService::calculate(int $temporadaId): array`
- `ProbabilidadeService::calculateSeason(int $temporadaId): array`
- `MonteCarloSimulator::simulate(int $iterations = 2000): array`
- `Logger::info(string $message, array $context = []): void`
- `Logger::warning(string $message, array $context = []): void`
- `Logger::error(string $message, array $context = []): void`
- `SessionGuard::requireAuth(): void`

---

## 6. Documentos que devem ser consultados

- `docs/documentacao_projeto_arena.md` v2.1: **referência funcional aprovada**.
- `database/schema.sql`: estrutura completa para banco novo.
- `database/migrations/001_alinhar_schema_mvp.sql`: migração incremental, repetível e não destrutiva para bancos existentes.
- `database/seed.sql`: dados de inicialização; verificar coerência com PR #39.
- `docs/api-recognition/mapeamento-dados.md`: mapeamento football-data.org v4 → MySQL.
- `docs/api-recognition/contrato-provedores.md`: contrato dos provedores e regras de fixtures/API.
- PR #35, #36, #38: mapeamento de API, interfaces e schema/seed inicial.
- PR #39: seed sem dados esportivos fixos (integrado à main em 18/11/2026).
- Issues #1, #3, #4, #7, #8, #11, #12, #13, #18, #19, #20, #40 e #41.

---

## 7. Testes mínimos de contrato [ALTERADO]

- Chaves externas repetidas atualizam o registro existente (idempotência).
- Siglas duplicadas são aceitas (ex.: dois clubes com `COR`).
- IDs externos não são confundidos com IDs internos.
- Campos opcionais permanecem NULL quando ausentes.
- Status desconhecido pode ser persistido sem quebrar a aplicação.
- Partidas ao vivo/adiadas/canceladas não entram na classificação final.
- `FINISHED`/`AWARDED` com placares válidos entram na classificação.
- Probabilidades fora de 0–100 são rejeitadas/normalizadas pelo serviço.
- JSON de posições possui chaves `"1"`–`"20"` e soma aproximada de 100%.
- Falha HTTP/429/timeout mantém os dados reais já persistidos.
- Logs não contêm token, senha, hash ou cookie.
- Usuário é autenticado por hash e não acessa dados de outra conta.
- **[NOVO]** Exclusão de usuário remove em cascata todos os registros em `simulacoes_usuarios`.
- **[NOVO]** `simulacoes_usuarios.cenarios_json` armazena exatamente 3 cenários estruturados.
- **[NOVO]** Schema de banco novo e migração incremental de banco existente são executados separadamente e sem apagar dados.

---

## 8. Instalação e migração do banco

- **Banco novo:** executar `database/schema.sql`. O arquivo cria as tabelas completas.
- **Banco já existente (schema anterior do PR #38):** fazer backup e executar `database/migrations/001_alinhar_schema_mvp.sql`.
- A migração verifica cada coluna de probabilidade em `clubes_temporadas` por meio de `INFORMATION_SCHEMA`, cria somente as ausentes e cria as tabelas novas com `CREATE TABLE IF NOT EXISTS`.
- A migração não altera tipos de PK/FK nem converte placares existentes. Os IDs internos permanecem `INT` signed para manter compatibilidade com as tabelas existentes.
- Reexecutar `schema.sql` não atualiza a definição de tabelas já existentes; por isso, não substitui a migração.
- Executar primeiro em uma cópia de desenvolvimento e validar FKs, colunas e contagens antes de aplicar em ambiente com dados reais.

--- 

## 9. Fora do modelo obrigatório do MVP

- Histórico de classificação (#47).
- Auditoria comparativa (#48).
- Qualquer extensão de `simulacoes_usuarios` além da persistência básica de 3 cenários.

---

## 10. Decisões técnicas registradas [NOVO]

| ID | Decisão | Justificativa | Impacto |
|---|---|---|---|
| DT-01 | `competicoes` mantida como tabela de suporte | `temporadas.competicao_id` requer referência; mapeamento de dados v4 exige persistir o `external_id` da competição | Não altera as 7 tabelas obrigatórias do MVP; documentada como suporte à integração |
| DT-02 | `simulacoes_usuarios` incluída como obrigatória | Especificação v2.1 (RF07, RN05, perfil.php) exige persistência dos cenários salvos | Dicionário v1.0 (seção 8) estava desatualizado em relação à especificação aprovada |
| DT-03 | `clubes_temporadas` sem colunas de estatística de partida | Classificação derivada de `partidas` evita redundância e dessincronização; DDL ilustrativa da seção 4.2 do documento v2.1 não é normativa | `StandingsService` permanece como única fonte de verdade para classificação |
| DT-04 | `partidas.status` como `VARCHAR(30)`, não `ENUM` | A especificação funcional (mapeamento de dados, seção 4) exige extensibilidade; DDL ilustrativa do documento v2.1 usa ENUM incorretamente | Status desconhecidos são preservados sem erro de banco |
| DT-05 | `usuarios.clube_favorito_id` permite NULL | Dicionário v1.0 e RF04 permitem troca de clube e perfil sem clube vinculado; DDL ilustrativa do documento v2.1 define NOT NULL | FK com `ON DELETE SET NULL`; serviço deve tratar NULL na exibição do clube favorito |

---

## 11. Critério de mudança deste padrão

Qualquer mudança de nome, tipo, nulabilidade, chave, relacionamento ou significado de campo deve:
1. atualizar este documento;
2. atualizar `database/schema.sql` e scripts relacionados;
3. revisar Repositories e Services dependentes;
4. adicionar/ajustar testes;
5. registrar a decisão no Pull Request e nas issues afetadas.

**Este documento é a referência funcional do modelo de dados. Se houver divergência entre ele e o schema implementado, registrar e corrigir antes de criar novas consultas dependentes da coluna divergente.**
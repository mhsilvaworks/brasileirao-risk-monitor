# Dicionário de Dados — Monitor de Risco Brasileirão (Série A)

**Versão do padrão:** 1.0  
**Arquitetura:** MVP v5.0  
**Stack:** PHP 8.3, MySQL 8, PDO, football-data.org v4  
**Objetivo:** definir um contrato único para banco, serviços, repositórios, sincronização, cálculos e interface.

> Este documento descreve o **modelo lógico oficial esperado**. Ele não afirma que todas as colunas já estejam implementadas no banco atual. A Issue #1 deve alinhar `database/schema.sql` a este padrão. Antes de alterar SQL, confira o schema real e registre a migração/correção necessária.

## 1. Regras gerais de nomenclatura e armazenamento

- Tabelas e colunas em português, `snake_case`, no plural para tabelas.
- Classes PHP em `PascalCase`; métodos e variáveis em `camelCase`.
- Chaves primárias internas: `id` (INT UNSIGNED AUTO_INCREMENT).
- Identificadores da football-data.org: `external_id` (INT NOT NULL UNIQUE). Nunca identificar clube, competição, temporada ou partida pelo nome, código visual ou sigla.
- FKs usam o nome da entidade + `_id`: `clube_id`, `temporada_id`, `competicao_id`.
- Charset/collation: `utf8mb4` / `utf8mb4_unicode_ci`; engine InnoDB.
- Valores monetários não fazem parte do MVP. Percentuais são armazenados de 0.00 a 100.00, nunca como fração 0–1.
- Datas recebidas da API são normalizadas para UTC antes da persistência. Exibição local usa `America/Sao_Paulo`.
- Campo que pode estar ausente na fonte deve permanecer NULL. Não converter ausência em zero, string vazia ou data inventada.
- Não usar `ENUM` para status externos: a API pode introduzir novos estados. Usar VARCHAR e tratar estados desconhecidos de forma segura.
- SQL somente em Repositories/infraestrutura; páginas PHP não devem conter consultas SQL.
- Toda escrita externa deve ser parametrizada com PDO; nunca concatenar valores recebidos do usuário/API em SQL.
- `criado_em` e `atualizado_em` são metadados internos; `api_updated_at` representa a última atualização informada pela fonte externa. Não confundir os três.

## 2. Modelo relacional oficial

```text
competicoes 1 ─── N temporadas
temporadas 1 ─── N partidas
temporadas N ─── N clubes (por clubes_temporadas)
clubes 1 ─── N partidas como mandante
clubes 1 ─── N partidas como visitante
clubes 1 ─── N clubes_temporadas
clubes 1 ─── N usuarios (clube favorito, opcional)
```

As sete tabelas oficiais do MVP são: `competicoes`, `temporadas`, `clubes`, `clubes_temporadas`, `partidas`, `usuarios` e `sync_logs`.

## 3. Dicionário por tabela

### 3.1 `competicoes`

Representa a competição consultada na fonte externa.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
| id | INT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID da competição na football-data.org |
| nome | VARCHAR(120) | Sim | — | Nome oficial |
| codigo | VARCHAR(20) | Sim | UNIQUE | Código da competição, por exemplo BSA |
| emblema_url | VARCHAR(255) | Não | NULL permitido | URL do emblema |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte, quando fornecida |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local do registro |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Última alteração local |

**Regras:** `external_id` é a identidade externa; `codigo` identifica o código oficial e pode ter UNIQUE; não duplicar competição em cada temporada.

**Índices/FKs:** UNIQUE(`external_id`), UNIQUE(`codigo`).

### 3.2 `temporadas`

Representa uma edição/temporada de uma competição.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
| id | INT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID da temporada na API |
| competicao_id | INT UNSIGNED | Sim | FK → competicoes.id | Competição à qual pertence |
| ano | SMALLINT UNSIGNED | Sim | — | Ano da temporada |
| data_inicio | DATE | Sim | — | Início da temporada |
| data_fim | DATE | Sim | — | Fim previsto/registrado |
| rodada_atual | TINYINT UNSIGNED | Não | NULL permitido | Rodada atual, quando informada |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Regras:** `external_id` deve ser único globalmente conforme o contrato da fonte; não presumir que `ano` seja identificador suficiente. `competicao_id` deve apontar para uma competição existente.

**Índices/FKs:** UNIQUE(`external_id`); FK `competicao_id` → `competicoes.id`, ON DELETE RESTRICT (ou CASCADE somente se a equipe justificar a exclusão em cadeia).

### 3.3 `clubes`

Cadastro mestre dos clubes retornados pela API.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
| id | INT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID do clube na API |
| nome | VARCHAR(120) | Sim | — | Nome completo |
| nome_curto | VARCHAR(80) | Sim | — | Nome curto para interface |
| sigla | VARCHAR(10) | Sim | INDEX, não UNIQUE | Sigla exibida |
| escudo_url | VARCHAR(255) | Não | NULL permitido | URL do escudo |
| estadio | VARCHAR(120) | Não | NULL permitido | Estádio, se disponível |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Regras:** siglas podem se repetir (ex.: COR); não criar UNIQUE para `sigla`. Relacionamentos sempre usam `id` interno ou `external_id` externo, nunca nome/sigla.

**Índices/FKs:** UNIQUE(`external_id`); INDEX(`sigla`).

### 3.4 `clubes_temporadas`

Tabela associativa: participação de um clube em uma temporada, ajustes administrativos e resultados probabilísticos do motor.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
| id | INT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador interno |
| temporada_id | INT UNSIGNED | Sim | FK → temporadas.id | Temporada |
| clube_id | INT UNSIGNED | Sim | FK → clubes.id | Clube participante |
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

**Chave única:** UNIQUE(`temporada_id`, `clube_id`).  
**Regras:** `ajuste_pontos` pode ser positivo ou negativo. Probabilidades devem ficar entre 0 e 100. NULL significa ainda não calculado/indisponível; não usar 0 como substituto. O campo JSON deve usar chaves de posição como strings de "1" a "20" e valores numéricos percentuais.

Exemplo válido de `probabilidades_posicoes`:

```json
{
  "1": 5.00,
  "2": 5.00,
  "3": 5.00,
  "4": 5.00,
  "5": 5.00,
  "6": 5.00,
  "7": 5.00,
  "8": 5.00,
  "9": 5.00,
  "10": 5.00,
  "11": 5.00,
  "12": 5.00,
  "13": 5.00,
  "14": 5.00,
  "15": 5.00,
  "16": 5.00,
  "17": 5.00,
  "18": 5.00,
  "19": 5.00,
  "20": 5.00
}
```

A soma por clube deve ser aproximadamente 100.00 após arredondamento. As quatro probabilidades de zona são métricas independentes e não devem ser somadas entre si.

### 3.5 `partidas`

Partidas da temporada, incluindo jogos agendados, em andamento e finalizados.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
| id | INT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador interno |
| external_id | INT NOT NULL | Sim | UNIQUE | ID da partida na API |
| temporada_id | INT UNSIGNED | Sim | FK → temporadas.id | Temporada |
| rodada | TINYINT UNSIGNED | Não | NULL permitido | Número da rodada |
| data_hora_utc | DATETIME | Sim | UTC | Data/hora de início em UTC |
| status | VARCHAR(30) | Sim | Não usar ENUM | Status recebido/normalizado |
| mandante_id | INT UNSIGNED | Sim | FK → clubes.id | Clube mandante |
| visitante_id | INT UNSIGNED | Sim | FK → clubes.id | Clube visitante |
| gols_mandante | SMALLINT UNSIGNED | Não | NULL permitido | Gols do mandante no fim |
| gols_visitante | SMALLINT UNSIGNED | Não | NULL permitido | Gols do visitante no fim |
| gols_mandante_ht | SMALLINT UNSIGNED | Não | NULL permitido | Gols do mandante no intervalo |
| gols_visitante_ht | SMALLINT UNSIGNED | Não | NULL permitido | Gols do visitante no intervalo |
| vencedor | VARCHAR(20) | Não | NULL permitido | HOME_TEAM, AWAY_TEAM, DRAW ou NULL |
| api_updated_at | DATETIME | Não | UTC | Atualização da fonte |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Criação local |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Alteração local |

**Regras de status:** armazenar o status da fonte sem restringir o banco a uma lista fechada. A regra de negócio reconhece, no mínimo, FINISHED e AWARDED como resultados válidos para a classificação; IN_PLAY e PAUSED são exibidos no widget ao vivo; SCHEDULED, TIMED, SUSPENDED, POSTPONED, CANCELLED e estados desconhecidos não entram na classificação como partida finalizada. Se a fonte introduzir outro status, persistir o texto e tratar com segurança.

**Regras de placar:** placar NULL significa resultado ainda não disponível. Nunca transformar NULL em 0 x 0. Somente partidas FINISHED/AWARDED com placares válidos alimentam a classificação oficial.

**Índices/FKs:** UNIQUE(`external_id`); INDEX(`temporada_id`, `rodada`); INDEX(`status`); FKs de temporada, mandante e visitante. Validar que mandante e visitante sejam clubes diferentes.

### 3.6 `usuarios`

Contas do portal. Senhas nunca são armazenadas em texto puro.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
| id | INT UNSIGNED AUTO_INCREMENT | Sim | PK | Identificador interno |
| nome | VARCHAR(120) | Sim | — | Nome de exibição |
| email | VARCHAR(190) | Sim | UNIQUE | Login do usuário |
| senha_hash | VARCHAR(255) | Sim | — | Resultado de password_hash() |
| clube_favorito_id | INT UNSIGNED | Não | FK → clubes.id, NULL permitido | Clube favorito |
| criado_em | TIMESTAMP | Sim | DEFAULT CURRENT_TIMESTAMP | Cadastro |
| atualizado_em | TIMESTAMP | Sim | ON UPDATE CURRENT_TIMESTAMP | Última alteração |

**Regras:** normalizar email (trim e lowercase) antes de persistir; validar unicidade no banco; autenticar com `password_verify()`; atualizar hash com `password_needs_rehash()` quando aplicável. Nunca registrar senha, hash, cookie ou identificador de sessão em logs. Clube favorito pode ser NULL.

### 3.7 `sync_logs`

Auditoria operacional das execuções de sincronização. Não guardar tokens nem payloads sensíveis.

| Campo | Tipo MySQL padrão | Obrigatório | Chave/regra | Descrição |
|---|---|---:|---|---|
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

**Regras:** registrar início, sucesso, falha e execução parcial. A mensagem deve ser útil sem incluir token, senha, cabeçalhos de autenticação, dados pessoais ou corpo integral da resposta externa. O stdout/stderr do container complementa o registro no banco; não substituir logs persistentes por echo na interface.

## 4. Regras de integridade e negócio

1. Competição → temporada → clube → partida deve ser sincronizado nessa ordem lógica.
2. Toda sincronização usa UPSERT/atualização por `external_id`; repetir a mesma carga não pode duplicar registros.
3. Antes de inserir partidas, garantir que temporada e clubes relacionados existam.
4. Não apagar dados reais quando a API falhar; preservar a última versão persistida.
5. Fixtures são exclusivas de testes/desenvolvimento e nunca fallback automático de produção.
6. Classificação é calculada a partir de partidas persistidas, não do endpoint `/standings`.
7. Classificação oficial considera apenas FINISHED/AWARDED com placares válidos e aplica os critérios CBF definidos em `StandingsService`.
8. Partidas IN_PLAY/PAUSED não alteram a classificação oficial.
9. Ajuste administrativo é aplicado via `clubes_temporadas.ajuste_pontos`, com motivo documentado.
10. Cálculos Poisson e Monte Carlo ocorrem no PHP. O JavaScript não calcula probabilidades oficiais.
11. Monte Carlo executa 2.000 iterações no MVP; aleatoriedade deve ser injetável/controlável nos testes.
12. A distribuição de posições contém 20 chaves (1–20); percentuais por clube somam aproximadamente 100%.
13. Erros de API, timeout, HTTP 429 e JSON inválido não devem apagar nem zerar dados válidos.
14. IDs internos nunca são confundidos com IDs externos da API.

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

Os métodos acima são nomes-padrão sugeridos, não uma exigência de implementar todos em uma única issue. Ajustar assinaturas ao padrão real do projeto e registrar qualquer divergência.

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

Use interfaces quando existir mais de uma implementação real (ex.: `DataProviderInterface` para API e fixtures). Não criar classes abstratas, heranças ou camadas genéricas sem necessidade concreta.

## 6. Documentos que devem ser consultados

- `database/schema.sql`: estrutura física vigente; alinhar à seção 3 deste dicionário.
- `database/seed.sql`: dados de inicialização; conferir se está coerente com a decisão vigente de não tratar dados esportivos hardcoded como fonte de produção.
- `docs/api-recognition/mapeamento-dados.md`: mapeamento football-data.org v4 → MySQL.
- `docs/api-recognition/contrato-provedores.md`: contrato dos provedores e regras de fixtures/API.
- PR #35: mapeamento e decisões da API.
- PR #36: `DataProviderInterface`, `ApiDataProvider`, `FixtureDataProvider`, `ApiRequestException` e testes iniciais.
- PR #38: schema/seed inicial e separação de fixtures.
- PR #39: revisão do seed e fluxo de inicialização. Em 17/11/2026, PR #39 está aberto e ainda não foi integrado; a branch main consultada ainda contém INSERTs iniciais de competição/temporada/clubes em `database/seed.sql`. Não assumir que a remoção já está em produção até o merge.
- Issues #1, #3, #4, #7, #8, #11, #12, #13, #18, #19, #20, #40 e #41: banco, persistência, classificação, sincronização, probabilidades e logs.

## 7. Testes mínimos de contrato

- Chaves externas repetidas atualizam o registro existente (idempotência).
- Siglas duplicadas são aceitas.
- IDs externos não são confundidos com IDs internos.
- Campos opcionais permanecem NULL quando ausentes.
- Status desconhecido pode ser persistido sem quebrar a aplicação.
- Partidas ao vivo/adiadas/canceladas não entram na classificação final.
- FINISHED/AWARDED com placares válidos entram na classificação.
- Probabilidades fora de 0–100 são rejeitadas/normalizadas pelo serviço, nunca gravadas silenciosamente.
- JSON de posições possui chaves 1–20 e soma aproximada de 100%.
- Falha HTTP/429/timeout mantém os dados reais já persistidos.
- Logs não contêm token, senha, hash ou cookie.
- Usuário é autenticado por hash e não consegue acessar dados de outra conta.

## 8. Fora do modelo obrigatório do MVP

A tabela `simulacoes_usuarios` pertence ao backlog (#45). Não adicioná-la ao schema obrigatório sem aprovação da equipe. Histórico de classificação (#47) e auditoria comparativa (#48) também são extensões, não requisitos das sete tabelas oficiais.

## 9. Critério de mudança deste padrão

Qualquer mudança de nome, tipo, nulabilidade, chave, relacionamento ou significado de campo deve:
1. atualizar este documento;
2. atualizar `database/schema.sql` e scripts relacionados;
3. revisar Repositories e Services dependentes;
4. adicionar/ajustar testes;
5. registrar a decisão no Pull Request e nas issues afetadas.

**Este documento é a referência funcional do modelo de dados. Se houver divergência entre ele e o schema implementado, abrir/corrigir a Issue #1 antes de criar novas consultas dependentes da coluna divergente.**

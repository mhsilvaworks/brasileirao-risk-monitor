# Mapeamento Detalhado de Dados — football-data.org (v4) → MySQL

**Projeto:** Brasileirão Risk Monitor  
**Fonte de Dados:** `https://api.football-data.org/v4`  
**Competição:** Campeonato Brasileiro Série A (`code: "BSA"`, `id: 2013`)  
**Temporada Base do Reconhecimento:** 2026 (`season.id: 2474`, `2026-01-28` a `2026-12-02`)  
**Data da Validação:** 16/11/2026 (Rodada atual na API: `35` | Partidas disputadas: `341/380`)

---

## 1. Visão Geral e Regras Globais de Integração

1. **Identidade Única (`external_id`):** Nenhum registro será identificado por nome, sigla (`tla`) ou ordem de listagem. Todos os relacionamentos externos utilizam o `id` inteiro fornecido pela API (`UNSIGNED INT`).
2. **Idempotência no `SyncService`:** As operações de sincronização de clubes e partidas devem utilizar `INSERT ... ON DUPLICATE KEY UPDATE` (upsert) com base na chave única `external_id`.
3. **Preservação de `NULL` em Placares:** Placares de partidas ainda não iniciadas ou sem resultado computado retornam `null` na API. É proibido converter `null` para `0` na camada de ingestão ou banco de dados, pois `0 x 0` representa um empate real em andamento ou encerrado.
4. **Padronização de Datas e Fuso Horário:** Todos os timestamps da API (`utcDate`, `lastUpdated`) chegam no formato ISO 8601 em UTC (`Z`). O banco deve armazenar em UTC (`DATETIME` ou `TIMESTAMP`) e a camada de apresentação converte para `America/Sao_Paulo` (`UTC-3`).

---

## 2. Endpoint 1: Competição (`GET /competitions/BSA`)

### 2.1. Estrutura e Mapeamento de Campos

| Caminho JSON na API | Exemplo Real | Tipo API | Nullable | Coluna MySQL | Tipo MySQL | Regra de Transformação / Validação |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `2013` | `integer` | Não | `external_id` | `INT UNSIGNED NOT NULL UNIQUE` | Identificador fixo da competição na API. |
| `name` | `"Campeonato Brasileiro Série A"` | `string` | Não | `nome` | `VARCHAR(120) NOT NULL` | Trim de espaços extras. |
| `code` | `"BSA"` | `string` | Não | `codigo` | `VARCHAR(10) NOT NULL UNIQUE` | Código curto usado nas rotas da API. |
| `emblem` | `"https://crests.football-data.org/bsa.png"` | `string` | Sim | `emblema_url` | `VARCHAR(255) NULL` | Validar URL (`FILTER_VALIDATE_URL`). |
| `currentSeason.id` | `2474` | `integer` | Não | `temporada_external_id` | `INT UNSIGNED NOT NULL` | Define a temporada ativa sincronizada. |
| `currentSeason.startDate` | `"2026-01-28"` | `string` | Não | `data_inicio` | `DATE NOT NULL` | Formato `YYYY-MM-DD` direto para `DATE`. |
| `currentSeason.endDate` | `"2026-12-02"` | `string` | Não | `data_fim` | `DATE NOT NULL` | Formato `YYYY-MM-DD` direto para `DATE`. |
| `currentSeason.currentMatchday` | `35` | `integer` | **Sim** | `rodada_atual` | `TINYINT UNSIGNED NULL` | Em temporadas históricas (ex.: 2017) pode retornar `null`. |
| `lastUpdated` | `"2026-10-31T12:49:16Z"` | `string` | Sim | `api_updated_at` | `DATETIME NULL` | Converter ISO 8601 (`Y-m-d\TH:i:s\Z`) para `Y-m-d H:i:s`. |

### 2.2. Inconsistências e Pontos de Atenção
* O objeto `seasons[]` retorna o histórico desde 2017, mas o sistema deve ler exclusivamente `currentSeason` para evitar mistura de temporadas no mesmo ciclo de sincronização.
* O campo `currentSeason.currentMatchday` indica a rodada corrente da tabela, mas **não garante** que todas as rodadas anteriores estejam 100% encerradas (ex.: na rodada 35 de 2026, há 341 jogos disputados em vez de 340, indicando jogos antecipados ou adiados).

---

## 3. Endpoint 2: Clubes (`GET /competitions/BSA/teams`)

### 3.1. Estrutura e Mapeamento de Campos

| Caminho JSON (`teams[]`) | Exemplo Real | Tipo API | Nullable | Coluna MySQL (`clubes`) | Tipo MySQL | Regra de Transformação / Validação |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `1765` | `integer` | Não | `external_id` | `INT UNSIGNED NOT NULL UNIQUE` | **Chave primária de integração.** Obrigatório para upsert. |
| `name` | `"Fluminense FC"` | `string` | Não | `nome` | `VARCHAR(120) NOT NULL` | Nome oficial registrado na federação/API. |
| `shortName` | `"Fluminense"` | `string` | **Sim** | `nome_curto` | `VARCHAR(60) NOT NULL` | Caso venha `null` ou vazio, preencher com `name`. |
| `tla` | `"FLU"` | `string` | **Sim** | `sigla` | `CHAR(3) NOT NULL` | Caso venha `null`, gerar 3 primeiras letras de `shortName` em maiúsculo. **Não aplicar índice `UNIQUE`**. |
| `crest` | `"https://crests.football-data.org/1765.png"` | `string` | Sim | `escudo_url` | `VARCHAR(255) NULL` | Aceitar `.png` e `.svg`. |
| `venue` | `"Estadio Jornalista Mário Filho"` | `string` | Sim | `estadio` | `VARCHAR(120) NULL` | Opcional; útil para enriquecimento visual. |
| `lastUpdated` | `"2024-07-29T17:24:37Z"` | `string` | Sim | `api_updated_at` | `DATETIME NULL` | Converter ISO 8601 UTC para `DATETIME`. |

### 3.2. Inconsistências Encontradas nos Dados Reais (Temporada 2026)
1. **Colisão de Siglas (`tla`):**
   * `Coritiba FBC` (`id: 4241`) retorna `tla: "COR"`.
   * `SC Corinthians Paulista` (`id: 1779`) também retorna `tla: "COR"`.
   * **Ação no Banco:** A coluna `sigla` **não** pode ter restrição `UNIQUE` no `schema.sql`.
2. **Padrão de Nomenclatura de Siglas:**
   * Alguns clubes possuem siglas não intuitivas no Brasil (ex.: `São Paulo FC` → `"PAU"`, `Grêmio FBPA` → `"FBP"`, `Clube do Remo` → `"CRE"`). Manter o valor original da API na sincronização e, se necessário, permitir override visual apenas no frontend/camada de exibição.
3. **Variação na URL de Escudos (`crest`):**
   * A maioria segue o padrão `https://crests.football-data.org/{id}.png`, mas a `Chapecoense AF` (`id: 1772`) retorna `https://crests.football-data.org/1772_large.png`. Nunca montar a URL dinamicamente pelo `id`; sempre salvar a string exata de `crest`.
4. **Payload Extenso (`squad` e `coach`):**
   * Cada item de `teams[]` traz o elenco completo (`squad[]`) e comissão técnica (`coach`), aumentando o tamanho do JSON. O `ApiDataProvider` deve descartar `squad`, `coach`, `staff` e `runningCompetitions` no parsing para economizar memória.

---

## 4. Endpoint 3: Partidas (`GET /competitions/BSA/matches`)

### 4.1. Estrutura e Mapeamento de Campos

| Caminho JSON (`matches[]`) | Exemplo Real | Tipo API | Nullable | Coluna MySQL (`partidas`) | Tipo MySQL | Regra de Transformação / Validação |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `554740` | `integer` | Não | `external_id` | `INT UNSIGNED NOT NULL UNIQUE` | **Chave única de integração.** |
| `season.id` | `2474` | `integer` | Não | `temporada_external_id` | `INT UNSIGNED NOT NULL` | Garantir que pertence à temporada ativa (`2474`). |
| `matchday` | `1` | `integer` | **Sim** | `rodada` | `TINYINT UNSIGNED NOT NULL` | Valores de `1` a `38`. |
| `utcDate` | `"2026-01-28T22:00:00Z"` | `string` | Não | `data_hora_utc` | `DATETIME NOT NULL` | Parse via `DateTimeImmutable($utcDate, new DateTimeZone('UTC'))` → `format('Y-m-d H:i:s')`. |
| `status` | `"FINISHED"` | `string` | Não | `status` | `VARCHAR(20) NOT NULL` | Validar contra lista de status conhecidos (ver seção 4.2). |
| `homeTeam.id` | `1766` | `integer` | **Sim*** | `mandante_id` | `INT UNSIGNED NOT NULL` | Traduzir `homeTeam.id` (`external_id`) para `clubes.id` (PK interna). |
| `awayTeam.id` | `1769` | `integer` | **Sim*** | `visitante_id` | `INT UNSIGNED NOT NULL` | Traduzir `awayTeam.id` (`external_id`) para `clubes.id` (PK interna). |
| `score.fullTime.home` | `2` ou `null` | `integer` | **Sim** | `gols_mandante` | `TINYINT UNSIGNED NULL` | Se `null`, gravar `NULL`. Se inteiro, cast `(int)`. |
| `score.fullTime.away` | `2` ou `null` | `integer` | **Sim** | `gols_visitante` | `TINYINT UNSIGNED NULL` | Se `null`, gravar `NULL`. Se inteiro, cast `(int)`. |
| `score.halfTime.home` | `1` ou `null` | `integer` | **Sim** | `gols_mandante_ht` | `TINYINT UNSIGNED NULL` | Opcional; útil para auditoria de placar no intervalo. |
| `score.halfTime.away` | `1` ou `null` | `integer` | **Sim** | `gols_visitante_ht` | `TINYINT UNSIGNED NULL` | Opcional; útil para auditoria de placar no intervalo. |
| `score.winner` | `"DRAW"` ou `null` | `string` | **Sim** | `vencedor` | `VARCHAR(15) NULL` | Valores: `"HOME_TEAM"`, `"AWAY_TEAM"`, `"DRAW"` ou `null`. |
| `lastUpdated` | `"2026-11-16T00:20:29Z"` | `string` | Sim | `api_updated_at` | `DATETIME NULL` | Controle de alteração da partida. |

*\*Nota: Em ligas de pontos corridos como o Brasileirão (`REGULAR_SEASON`), `homeTeam.id` e `awayTeam.id` sempre vêm preenchidos; ainda assim, o `SyncService` deve validar a existência de ambos na tabela `clubes` antes de persistir a partida.*

### 4.2. Máquina de Estados (`status`) e Regras de Negócio

| Status na API | Categoria Interna | Entra no Cálculo de Classificação (`StandingsService`)? | Entra no Monitoramento Ao Vivo (Polling 1 min)? | Comportamento Esperado no Sistema |
| :--- | :--- | :--- | :--- | :--- |
| `IN_PLAY` | **Ao Vivo** | Sim (provisório, se configurado) ou Apenas Exibição | **Sim** | Partida com bola rolando; exibir badge "AO VIVO" e atualizar placar. |
| `PAUSED` | **Ao Vivo** | Sim (provisório, se configurado) ou Apenas Exibição | **Sim** | Intervalo de jogo; manter monitoramento ativo até `FINISHED`. |
| `FINISHED` | **Encerrada** | **Sim (Definitivo)** | Não | Placar consolidado (`fullTime` obrigatório não-nulo). |
| `AWARDED` | **Encerrada (W.O.)** | **Sim (Definitivo)** | Não | Resultado definido administrativamente (ex.: W.O. 3x0); considerar `score.fullTime`. |
| `SCHEDULED` | **Agendada** | Não | Não (exceto próximo ao horário) | Partida prevista na tabela, ainda sem horário definitivo confirmado. |
| `TIMED` | **Agendada** | Não | Não (exceto próximo ao horário) | Partida com data e horário confirmados (`utcDate` definitivo). |
| `POSTPONED` | **Exceção** | Não | Não | Partida adiada; ignorar placar e sinalizar alerta na rodada. |
| `SUSPENDED` | **Exceção** | Não (até retomada/decisão) | Não | Partida interrompida; não consolidar pontos até mudança para `FINISHED` ou `AWARDED`. |
| `CANCELLED` | **Exceção** | Não | Não | Partida cancelada; desconsiderar nos cálculos. |

### 4.3. Inconsistências e Cuidados Críticos
* **Ordem de Sincronização Obrigatória:** O `SyncService` deve sincronizar `/teams` **antes** de `/matches`, garantindo que todos os `external_id` de `homeTeam.id` e `awayTeam.id` já existam na tabela `clubes` para resolução das chaves estrangeiras (`mandante_id` e `visitante_id`).
* **Objeto `odds` bloqueado:** No plano gratuito, `odds` sempre retorna `{"msg": "Activate Odds-Package in User-Panel to retrieve odds."}`. Ignorar esse nó completamente.
* **Horário de Brasília vs UTC:** Jogos noturnos no Brasil (ex.: `21:30` BRT) aparecem na API no dia seguinte em UTC (ex.: `00:30:00Z`). Sempre filtrar partidas do dia considerando o fuso `America/Sao_Paulo`, nunca a data crua em UTC.

---

## 5. Endpoint 4: Classificação (`GET /competitions/BSA/standings`)

### 5.1. Estrutura e Mapeamento de Campos (Referência de Auditoria)

Este endpoint **não alimenta diretamente** a tabela principal do sistema, pois o cálculo oficial do projeto é executado pelo `StandingsService` (para suportar `ajuste_pontos` manual, como punições do STJD, e simulações de risco). Ele é utilizado como **oráculo de validação** em testes automatizados e auditoria.

| Caminho JSON (`standings[0].table[]`) | Exemplo Real | Tipo API | Nullable | Campo Equivalente (`StandingsService`) | Regra de Comparação |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `position` | `1` | `integer` | Não | `posicao` | Ordem de 1 a 20. |
| `team.id` | `1783` | `integer` | Não | `clube_external_id` | Chave de cruzamento entre API e cálculo interno. |
| `playedGames` | `34` | `integer` | Não | `jogos` | Total de partidas com status `FINISHED` ou `AWARDED`. |
| `won` | `23` | `integer` | Não | `vitorias` | Critério 1 de desempate no Brasileirão. |
| `draw` | `7` | `integer` | Não | `empates` | — |
| `lost` | `4` | `integer` | Não | `derrotas` | — |
| `points` | `76` | `integer` | Não | `pontos_base` | `pontos_final = pontos_base + ajuste_pontos`. |
| `goalsFor` | `71` | `integer` | Não | `gols_pro` | Critério 3 de desempate no Brasileirão. |
| `goalsAgainst` | `29` | `integer` | Não | `gols_contra` | — |
| `goalDifference` | `42` | `integer` | Não | `saldo_gols` | Critério 2 de desempate (`gols_pro - gols_contra`). |
| `form` | `null` | `string` | **Sim** | `ultimos_jogos` | Retorna `null` na API para o Brasileirão; calcular internamente pelas últimas 5 partidas. |

### 5.2. Divergências Conhecidas entre API e Regras da CBF
1. **Critérios de Desempate:** A API ordena internamente por `points` → `goalDifference` → `goalsFor`. No Campeonato Brasileiro (CBF), o primeiro critério de desempate após pontos é o **número de vitórias (`won`)**, antes do saldo de gols (`goalDifference`). O `StandingsService` deve aplicar a regra oficial da CBF:
   * `Pontos` → `Vitórias` → `Saldo de Gols` → `Gols Pró` → `Confronto Direto`.
2. **Deduções de Pontos:** Conforme documentação oficial da football-data.org, consultas filtradas podem omitir punições administrativas. Por isso, o campo `ajuste_pontos` (`SIGNED INT DEFAULT 0`) na tabela de clubes/participação é indispensável.
3. **Campo `form` Nulo:** Como `form` vem `null` para todos os 20 clubes na `BSA`, o histórico recente (ex.: `V-E-V-D-V`) deve ser derivado das últimas 5 partidas finalizadas de cada clube no banco local.

---

## 6. Limitações de Cota, Rate Limit e Estratégia de Sincronização

| Recurso / Restrição | Plano Free (Atual — € 0) | Plano Free w/ Livescores (€ 12/mês) | Impacto no Projeto |
| :--- | :--- | :--- | :--- |
| **Rate Limit** | **10 requisições / minuto** | **20 requisições / minuto** | Não fazer requisições individuais por clube ou por rodada; usar sempre endpoints em lote (`/teams` e `/matches`). |
| **Placares Ao Vivo** | Não (atraso na atualização) | Sim (tempo real) | O job de 1 minuto funcionará tecnicamente, mas no plano Free os gols só aparecem após a atualização tardia da fonte. |
| **HTTP Status de Bloqueio** | `429 Too Many Requests` | `429 Too Many Requests` | O `ApiDataProvider` deve tratar HTTP `429` lendo o header `X-RequestCounter-Reset` e acionando fallback (`FixtureDataProvider` ou cache local). |

### Orçamento de Requisições por Ciclo de Sincronização
* **Sincronização Completa (Carga Inicial / Diária):** `3 requisições` (`/competitions/BSA` + `/teams` + `/matches`) → **30% do limite por minuto** (folga de 7 requisições).
* **Sincronização Incremental / Monitoramento Ao Vivo (a cada 1 min):** Apenas `1 requisição` (`GET /competitions/BSA/matches`) → **10% do limite por minuto**, suficiente para atualizar placares e status de todos os jogos da rodada simultaneamente.
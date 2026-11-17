# Mapeamento Detalhado de Dados — football-data.org (v4) → MySQL

**Projeto:** Brasileirão Risk Monitor  
**Fonte:** https://api.football-data.org/v4  
**Competição:** Campeonato Brasileiro Série A (code `BSA`, id `2013`)  
**Temporada validada:** 2026 (season.id `2474`, de `2026-01-28` a `2026-12-02`)  
**Validação registrada:** 16/11/2026 — rodada informada: 35; partidas finalizadas observadas: 341/380.

> Os exemplos JSON em `docs/api-recognition/` são snapshots da consulta realizada em 16/11/2026. Não devem ser tratados como dados atuais permanentes. A temporada e os identificadores devem ser lidos da resposta da API, sem fixar o ID 2474 no código.

---

## 1. Regras globais

1. **Identidade externa:** usar o campo `id` da API como `external_id`. Nunca identificar clubes por nome, sigla ou posição no array.
2. **Idempotência:** sincronizar por chave única `external_id`, usando upsert. A repetição da mesma sincronização não pode duplicar clubes ou partidas.
3. **Placar nulo:** preservar `null` recebido da API. Não converter ausência de placar em zero.
4. **Placar ao vivo:** em uma partida `IN_PLAY`, `0 x 0` pode ser o placar corrente inicial. Isso não significa que a partida terminou empatada.
5. **Data e hora:** interpretar timestamps ISO 8601 em UTC. Persistir em UTC e converter para `America/Sao_Paulo` somente na apresentação.
6. **Temporada:** enviar o ano de início no parâmetro `season` dos endpoints que o aceitam (por exemplo, `season=2026`). Guardar também o `currentSeason.id` retornado pela API como identificador externo.
7. **Payload:** consumir somente os campos necessários ao domínio. Não persistir elenco, treinador, staff, odds ou competições relacionadas nesta etapa.
8. **Falha da fonte:** em falha HTTP, timeout ou limite 429, manter os últimos dados reais persistidos. Não substituir dados de produção por fixtures fictícias.
9. **Fixtures:** `FixtureDataProvider` é destinado a testes e desenvolvimento controlado; seus dados devem ser identificados como fixtures e nunca apresentados como dados reais em produção.

---

## 2. Competição — `GET /competitions/BSA`

| Campo JSON | Exemplo observado | Coluna/uso | Regra |
|---|---|---|---|
| `id` | `2013` | `competicoes.external_id` | Inteiro externo único |
| `name` | Campeonato Brasileiro Série A | `nome` | Remover espaços externos |
| `code` | `BSA` | `codigo` | Código usado nas rotas |
| `emblem` | URL do emblema | `emblema_url` | Nullable; validar URL |
| `currentSeason.id` | `2474` | `temporadas.external_id` | Identificador externo da temporada |
| `currentSeason.startDate` | `2026-01-28` | `data_inicio` | Tipo DATE |
| `currentSeason.endDate` | `2026-12-02` | `data_fim` | Tipo DATE |
| `currentSeason.currentMatchday` | `35` | `rodada_atual` | Nullable |
| `lastUpdated` | ISO 8601 UTC | `api_updated_at` | Converter para DATETIME UTC |

O objeto `seasons[]` contém temporadas históricas. A sincronização de uma temporada deve usar somente a temporada selecionada, não misturar os arrays de anos diferentes. `currentMatchday` não garante que todas as partidas das rodadas anteriores estejam encerradas.

---

## 3. Clubes — `GET /competitions/BSA/teams?season=2026`

| Campo JSON (`teams[]`) | Coluna em `clubes` | Regra |
|---|---|---|
| `id` | `external_id` | Obrigatório, único |
| `name` | `nome` | Obrigatório |
| `shortName` | `nome_curto` | Se ausente/vazio, usar `name` |
| `tla` | `sigla` | Preservar valor original; não criar UNIQUE |
| `crest` | `escudo_url` | Preservar URL recebida; pode ser PNG/SVG e variar o padrão |
| `venue` | `estadio` | Opcional |
| `lastUpdated` | `api_updated_at` | Nullable; converter UTC |

### Observações verificadas

- Há colisão de siglas: Coritiba FBC e SC Corinthians Paulista podem retornar `COR`. Portanto, `sigla` não pode ser UNIQUE.
- Algumas siglas não correspondem à abreviação usual no Brasil. Preservar o valor da fonte; eventual nome de exibição deve ser separado do dado original.
- Não construir `crest` a partir do ID. Salvar a URL recebida.
- Descartar `squad`, `coach`, `staff` e `runningCompetitions` no mapeamento de domínio, pois não fazem parte do escopo.

---

## 4. Partidas — `GET /competitions/BSA/matches?season=2026`

| Campo JSON (`matches[]`) | Coluna em `partidas` | Regra |
|---|---|---|
| `id` | `external_id` | Obrigatório, único |
| `season.id` | `temporada_external_id` | Validar temporada selecionada |
| `matchday` | `rodada` | Nullable; não inventar rodada |
| `utcDate` | `data_hora_utc` | Obrigatório; armazenar UTC |
| `status` | `status` | VARCHAR(30), preservar estado recebido |
| `homeTeam.id` | `mandante_id` | Resolver external_id para PK interna |
| `awayTeam.id` | `visitante_id` | Resolver external_id para PK interna |
| `score.fullTime.home` | `gols_mandante` | Nullable; preservar NULL |
| `score.fullTime.away` | `gols_visitante` | Nullable; preservar NULL |
| `score.halfTime.home` | `gols_mandante_ht` | Opcional e nullable |
| `score.halfTime.away` | `gols_visitante_ht` | Opcional e nullable |
| `score.winner` | `vencedor` | Nullable: HOME_TEAM, AWAY_TEAM, DRAW |
| `lastUpdated` | `api_updated_at` | Nullable; converter UTC |

Antes de persistir uma partida, o serviço deve confirmar que os dois clubes já existem. A ordem de sincronização é clubes antes de partidas. Se um clube não for encontrado, registrar o problema e não persistir uma partida com referência inválida.

### Status e tratamento

A coluna deve ser textual (`VARCHAR(30)`), não um ENUM rígido. Preservar o status original e classificar seu comportamento na camada de domínio.

| Status | Interpretação | Classificação definitiva | Monitoramento futuro |
|---|---|---|---|
| `IN_PLAY` | Em andamento | Não consolidar como resultado final | Sim |
| `PAUSED` | Pausa/intervalo | Não consolidar como resultado final | Sim |
| `FINISHED` | Encerrada | Sim, se placar válido | Não |
| `AWARDED` | Resultado atribuído | Sim, conforme placar/regulamento | Não |
| `SCHEDULED` | Agendada | Não | Não |
| `TIMED` | Agendada com horário | Não | Não |
| `POSTPONED` | Adiada | Não | Não |
| `SUSPENDED` | Suspensa | Não até decisão/encerramento | Exige tratamento específico |
| `CANCELLED` | Cancelada | Não | Não |
| `EXTRA_TIME` | Prorrogação, se retornada | Não consolidar antes do estado final | Sim, se ocorrer |
| `PENALTY_SHOOTOUT` | Disputa de pênaltis, se retornada | Não consolidar antes do estado final | Sim, se ocorrer |

A lista é uma referência de estados conhecidos, não uma validação fechada. Status desconhecidos devem ser preservados e registrados para análise, em vez de causar perda de dados. O filtro `LIVE`, quando usado pela API, é um agrupador de partidas ao vivo, não deve ser gravado como status individual.

O monitoramento automático e a consulta periódica de um minuto ficam para a etapa final do projeto. Nesta etapa serão implementadas somente as bases de integração e persistência.

### Horário local

Não filtrar “jogos de hoje” pela data UTC crua. Converter para `America/Sao_Paulo` na camada de apresentação e em consultas que dependam do dia local.

---

## 5. Classificação — `GET /competitions/BSA/standings?season=2026`

Este endpoint será referência de auditoria, não fonte direta da tabela principal. O `StandingsService` calculará a classificação a partir das partidas finalizadas e dos ajustes administrativos.

| Campo JSON (`standings[].table[]`) | Uso interno |
|---|---|
| `position` | Posição informada pela API, somente comparação |
| `team.id` | Cruzamento por ID externo |
| `playedGames` | Jogos disputados |
| `won`, `draw`, `lost` | Vitórias, empates e derrotas |
| `points` | Pontos-base para auditoria |
| `goalsFor`, `goalsAgainst`, `goalDifference` | Gols pró, contra e saldo |
| `form` | Pode ser null; forma recente será derivada localmente |

A ordenação interna deve seguir a regra de classificação do projeto e o regulamento aplicável à temporada: pontos, vitórias, saldo de gols, gols pró e critérios subsequentes previstos no regulamento, incluindo confronto direto quando aplicável. A tabela da API não deve substituir essa regra.

As deduções administrativas devem ser representadas por ajuste associado à participação do clube na temporada, não como atributo permanente do clube. Recomenda-se a entidade `clubes_temporadas` com `ajuste_pontos`.

---

## 6. Cotas e estratégia de requisição

O limite e os recursos disponíveis dependem do plano contratado e podem mudar. Conferir a página oficial antes de definir valores em produção: https://www.football-data.org/pricing.

- Não fazer uma chamada por clube ou por partida: usar endpoints em lote.
- Carga inicial: competição, clubes e partidas; classificação pode ser consultada para auditoria.
- Monitoramento ao vivo a cada minuto é requisito de etapa posterior e depende de um plano que forneça livescores adequados.
- Tratar HTTP 429 respeitando os cabeçalhos de limite/retentativa retornados pela API. Não presumir que um cabeçalho específico estará sempre presente.
- Em timeout, erro HTTP ou 429, manter os últimos dados reais persistidos e registrar a falha.
- O token deve permanecer no backend, em variável de ambiente; nunca enviar a chave ao JavaScript ou versioná-la.

## 7. Contrato de integração previsto

O contrato `DataProviderInterface` fornecerá os payloads de competição, clubes, partidas e classificação. `ApiDataProvider` consultará a football-data.org; `FixtureDataProvider` lerá snapshots JSON controlados para testes/desenvolvimento. Ambos retornarão a mesma estrutura de payload, permitindo que o `SyncService` não dependa da origem.

A camada de provedor não persiste no banco. O `SyncService` valida, normaliza e coordena a persistência, com clubes antes de partidas. A falha da API não autoriza substituir os dados persistidos por fixtures.

## 8. Arquivos de reconhecimento

Os arquivos JSON desta pasta são evidências/snapshots da validação inicial. Podem conter payloads maiores que o necessário; o provedor deve extrair apenas os campos mapeados acima. Não incluir tokens, cabeçalhos de autenticação ou arquivos `.env`.

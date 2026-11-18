-- Este arquivo foi mantido para compatibilidade com o fluxo de inicialização local.
--
-- NÃO inserir aqui competição, temporada, clubes, partidas ou classificação reais.
-- Esses dados pertencem exclusivamente à football-data.org e devem ser obtidos
-- pelo ApiDataProvider e persistidos pelo SyncService (POST /api/sync).
--
-- O schema.sql cria somente a estrutura. Após a implementação do SyncService,
-- a carga inicial e as atualizações serão realizadas pela API, sem depender de
-- uma lista fixa de clubes, IDs de temporada ou resultados versionados no Git.
--
-- Fixtures determinísticas ficam em database/fixtures/ e são exclusivas de
-- ambientes de teste. Nunca devem ser usadas como fallback da API em produção.

USE brasileirao_risco;

-- Intencionalmente sem INSERT: a fonte de verdade dos dados esportivos é a API.

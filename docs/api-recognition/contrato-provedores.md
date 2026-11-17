# Contrato dos provedores de dados

A primeira etapa de integração separa a origem dos dados da lógica de sincronização.

## Contrato

`App\Contracts\DataProviderInterface` define quatro operações:

- `getCompetition()`: dados da competição;
- `getTeams($competitionCode, $seasonYear)`: clubes de uma temporada;
- `getMatches($competitionCode, $seasonYear)`: partidas da temporada;
- `getStandings($competitionCode, $seasonYear)`: classificação de referência para auditoria.

Todas retornam o payload JSON decodificado como array associativo. Nenhum método grava no banco.

## Implementações

- `ApiDataProvider`: consulta a football-data.org v4. Recebe o token pelo construtor, envia `X-Auth-Token` no backend e usa `season=AAAA` nos endpoints de clubes, partidas e classificação.
- `FixtureDataProvider`: lê snapshots JSON locais e determinísticos. É destinado a testes/desenvolvimento e não deve ser selecionado como fallback automático em produção.
- `ApiRequestException`: expõe o status HTTP e, quando fornecido, o cabeçalho `X-RequestCounter-Reset`, sem incluir o token ou o corpo da resposta na mensagem.

## Responsabilidades que permanecem para a próxima etapa

O `SyncService` ainda deverá validar os payloads, sincronizar competição/temporada e clubes antes das partidas, resolver IDs externos para chaves internas, executar upserts/transações e manter o último conjunto real persistido quando a API falhar.

O polling de partidas ao vivo e a atualização automática do placar a cada minuto permanecem fora desta primeira implementação e serão desenvolvidos na etapa final.

# Fixtures de teste

Este diretório contém cenários artificiais para validar serviços e regras de negócio.
As fixtures **não são fonte de dados da aplicação** e nunca devem ser usadas em produção.

## Pré-requisitos

1. Use uma **instância MySQL exclusiva para desenvolvimento/testes**, separada da
   instância de produção. O schema atual cria o banco local com o nome
   `brasileirao_risco`.
2. Crie a estrutura com `database/schema.sql`.
3. Popule competição, temporada e clubes por meio do fluxo de sincronização da
   football-data.org (`ApiDataProvider` + `SyncService`). O `seed.sql` não
   cadastra mais dados esportivos fixos.
4. Confirme que os clubes necessários ao cenário estão presentes na temporada.

## Execução

Execute o comando abaixo **somente na instância MySQL de testes**:

```bash
mysql -u root -p brasileirao_risco < database/fixtures/test_scenarios.sql
```

As fixtures inserem somente cinco partidas artificiais com IDs reservados entre
`2000000001` e `2000000005`; não apagam partidas reais, não cadastram clubes
fictícios e não modificam ajustes administrativos.

A execução depende de uma temporada e dos clubes reais já sincronizados. Se algum
clube não estiver cadastrado, a respectiva partida de teste não será inserida.
Ao final, o script lista as partidas criadas para conferência.

## Fonte de dados

- Produção: football-data.org, consumida pelo `ApiDataProvider` e persistida pelo
  `SyncService`.
- Testes de integração: fixtures SQL controladas, executadas manualmente em uma
  instância MySQL isolada.
- Testes unitários do provedor: snapshots JSON lidos pelo `FixtureDataProvider`.

Em nenhuma hipótese uma falha da API deve provocar fallback automático para
fixtures em ambiente de produção.

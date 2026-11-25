<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Testes de contrato textual para schema, migration, repositório e dicionário.
 *
 * ESCOPO DECLARADO
 * ----------------
 * Estes são testes ESTÁTICOS — verificam o conteúdo dos arquivos .sql, .php e
 * .md como texto, sem executar SQL, sem conectar ao banco de dados e sem
 * verificar comportamento em tempo de execução.
 *
 * O que estes testes COBREM:
 *   - Nome canônico da coluna (probabilidades_calculadas_em) em todos os
 *     arquivos afetados.
 *   - Presença das 7 tabelas obrigatórias do MVP e da tabela auxiliar.
 *   - Nome do banco de dados (brasileirao_risco).
 *   - Tipo VARCHAR(30) para partidas.status (não ENUM).
 *   - ON DELETE CASCADE em simulacoes_usuarios.usuario_id.
 *   - Que a migration usa INFORMATION_SCHEMA.COLUMNS para verificar colunas.
 *   - Que a migration usa REFERENTIAL_CONSTRAINTS + KEY_COLUMN_USAGE para
 *     verificar a origem e o destino completos da FK, incluindo coluna
 *     referenciada, antes de agir.
 *   - Que o preflight executável valida estruturas e ocorre antes dos blocos de alteração.
 *   - Que incompatibilidades produzem erro SQL em vez de um SELECT textual de ERRO.
 *   - Que a migration documenta os limites do CREATE TABLE IF NOT EXISTS.
 *   - Que a migration documenta as limitações de escopo do que não altera.
 *   - Versão 2.1 e decisões técnicas DT-01 a DT-07 no dicionário.
 *   - Separação explícita de tabelas MVP vs. auxiliar.
 *   - Declaração de pendências no dicionário.
 *   - Uso de prepared statements no repositório.
 *
 * O que estes testes NÃO COBREM (pendências de integração):
 *   - Que a migration execute sem erro em MySQL 8 real.
 *   - Que dados existentes sejam preservados após a migration.
 *   - Que CREATE TABLE IF NOT EXISTS produza a estrutura exata esperada.
 *   - Que a verificação de FK funcione com dados reais no banco.
 *   - Que findByTemporada() retorne a coluna correta de um banco real.
 *   Ver seção 7 do dicionário-dados.md para o roteiro de teste de integração.
 *
 * Padrão de estilo: métodos sem anotação @test usam o prefixo "test" conforme
 * o autodetect do PHPUnit 11. Sem anotação @test para evitar depreciações do
 * PHPUnit 11 (que prefere #[Test]).
 */
final class SchemaContractTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function readFile(string $relativePath): string
    {
        $path = self::projectRoot() . '/' . ltrim($relativePath, '/');
        self::assertFileExists($path, "Arquivo de projeto ausente: $relativePath");

        return (string) file_get_contents($path);
    }

    // =========================================================================
    // 1. Nome canônico: probabilidades_calculadas_em
    // =========================================================================

    /**
     * schema.sql deve definir a coluna com o nome canônico.
     */
    public function testSchemaSqlUsaNomeCanonicoProb(): void
    {
        $schema = self::readFile('database/schema.sql');

        $this->assertStringContainsString(
            'probabilidades_calculadas_em',
            $schema,
            'schema.sql deve conter a coluna probabilidades_calculadas_em'
        );

        $this->assertStringNotContainsString(
            'probabilidades_calculadas_on',
            $schema,
            'schema.sql não deve conter probabilidades_calculadas_on (nome antigo/incorreto)'
        );
    }

    /**
     * ClubeRepository.php deve selecionar ct.probabilidades_calculadas_em.
     *
     * Nota: o arquivo pode mencionar "probabilidades_calculadas_on" em comentários
     * PHPDoc para documentar que o nome antigo é incorreto — assim como a migration
     * o menciona como referência histórica. O que importa é que o SQL executável
     * (dentro de strings de PREPARE/query) use o nome canônico. Esta asserção
     * verifica isso inspecionando especificamente o trecho SQL da query.
     */
    public function testClubeRepositoryUsaNomeCanonicoProb(): void
    {
        $repo = self::readFile('src/Repositories/ClubeRepository.php');

        // O SQL executável (a string de prepare) deve conter o nome canônico
        $this->assertStringContainsString(
            'ct.probabilidades_calculadas_em',
            $repo,
            'ClubeRepository deve selecionar ct.probabilidades_calculadas_em em findByTemporada'
        );

        // O nome antigo não deve aparecer como coluna SQL (ct.probabilidades_calculadas_on)
        // Pode aparecer em comentários como referência histórica, o que é legítimo.
        $this->assertStringNotContainsString(
            'ct.probabilidades_calculadas_on',
            $repo,
            'ClubeRepository não deve selecionar ct.probabilidades_calculadas_on no SQL (nome antigo)'
        );
    }

    /**
     * A migration deve usar o nome canônico no COLUMN_NAME verificado e no ADD COLUMN.
     * Pode mencionar o nome antigo em comentários históricos, mas o SQL executável
     * (dentro de strings de PREPARE) deve usar o nome canônico.
     */
    public function testMigrationUsaNomeCanonicoNoSqlExecutavel(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        // O COLUMN_NAME verificado no INFORMATION_SCHEMA deve ser o nome canônico
        $this->assertStringContainsString(
            "COLUMN_NAME  = 'probabilidades_calculadas_em'",
            $migration,
            'A migration deve verificar COLUMN_NAME = probabilidades_calculadas_em'
        );

        // O ADD COLUMN deve usar o nome canônico
        $this->assertStringContainsString(
            'ADD COLUMN probabilidades_calculadas_em',
            $migration,
            'A migration deve adicionar coluna com nome canônico probabilidades_calculadas_em'
        );
    }

    /**
     * O dicionário deve registrar o nome canônico e a decisão DT-06.
     */
    public function testDicionarioRegistraNomeCanonicoEDT06(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        $this->assertStringContainsString(
            'probabilidades_calculadas_em',
            $dic,
            'O dicionário deve documentar probabilidades_calculadas_em como nome canônico'
        );

        $this->assertStringContainsString(
            'DT-06',
            $dic,
            'O dicionário deve registrar a decisão técnica DT-06 (nome da coluna)'
        );
    }

    // =========================================================================
    // 2. Tabelas obrigatórias do MVP e tabela auxiliar
    // =========================================================================

    /**
     * schema.sql deve conter CREATE TABLE para as 7 tabelas obrigatórias do MVP.
     */
    public function testSchemaSqlContemSeteTabelasMVP(): void
    {
        $schema = self::readFile('database/schema.sql');

        $tabelasObrigatorias = [
            'clubes',
            'temporadas',
            'clubes_temporadas',
            'partidas',
            'usuarios',
            'simulacoes_usuarios',
            'sync_logs',
        ];

        foreach ($tabelasObrigatorias as $tabela) {
            $this->assertMatchesRegularExpression(
                '/CREATE TABLE IF NOT EXISTS\s+`?' . preg_quote($tabela, '/') . '`?/i',
                $schema,
                "schema.sql deve conter CREATE TABLE para '$tabela' (tabela obrigatória do MVP)"
            );
        }
    }

    /**
     * schema.sql deve conter a tabela auxiliar competicoes (DT-01).
     */
    public function testSchemaSqlContemTabelaAuxiliarCompeticoes(): void
    {
        $schema = self::readFile('database/schema.sql');

        $this->assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS\s+`?competicoes`?/i',
            $schema,
            'schema.sql deve conter CREATE TABLE para competicoes (tabela auxiliar DT-01)'
        );
    }

    /**
     * O dicionário deve separar explicitamente tabelas MVP e tabela auxiliar.
     */
    public function testDicionarioSeparaTabelasMVPDeAuxiliar(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        $this->assertStringContainsString(
            'Tabelas obrigatórias do MVP',
            $dic,
            'O dicionário deve ter seção nomeando as tabelas obrigatórias do MVP'
        );

        // competicoes deve ser classificada como suporte/auxiliar, não MVP
        $this->assertMatchesRegularExpression(
            '/competicoes.*?(suporte|auxiliar)/is',
            $dic,
            'O dicionário deve classificar competicoes como suporte/auxiliar, não tabela do MVP'
        );
    }

    // =========================================================================
    // 3. Nome do banco de dados
    // =========================================================================

    /**
     * schema.sql deve referenciar brasileirao_risco, não arena_brasileirao (DT-07).
     */
    public function testSchemaSqlUsaNomeDoBancoCorreto(): void
    {
        $schema = self::readFile('database/schema.sql');

        $this->assertStringContainsString(
            'brasileirao_risco',
            $schema,
            'schema.sql deve referenciar o banco brasileirao_risco (DT-07)'
        );

        $this->assertStringNotContainsString(
            'arena_brasileirao',
            $schema,
            'schema.sql não deve referenciar arena_brasileirao (nome da DDL ilustrativa v2.1)'
        );
    }

    /**
     * A migration deve operar somente no banco selecionado pelo comando MySQL.
     * Isso permite validar a migration em uma base descartável sem redirecioná-la
     * para brasileirao_risco por uma instrução USE interna.
     */
    public function testMigrationNaoTrocaBancoSelecionado(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        $this->assertDoesNotMatchRegularExpression(
            '/^\\s*USE\\s+brasileirao_risco\\s*;/mi',
            $migration,
            'A migration não deve trocar o banco selecionado pelo operador'
        );

        $this->assertStringContainsString(
            'TABLE_SCHEMA = DATABASE()',
            $migration,
            'As validações da migration devem usar o schema selecionado'
        );
    }

    /**
     * O dicionário deve registrar DT-07 (divergência no nome do banco).
     */
    public function testDicionarioRegistraDT07NomeDoBanco(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        $this->assertStringContainsString(
            'DT-07',
            $dic,
            'O dicionário deve registrar DT-07 (divergência no nome do banco de dados)'
        );
    }

    // =========================================================================
    // 4. partidas.status: VARCHAR(30), não ENUM
    // =========================================================================

    /**
     * O bloco CREATE TABLE de partidas no schema.sql deve definir status como VARCHAR(30).
     */
    public function testSchemaSqlUsaVarcharParaStatus(): void
    {
        $schema = self::readFile('database/schema.sql');

        preg_match('/CREATE TABLE IF NOT EXISTS\s+`?partidas`?.*?ENGINE=/si', $schema, $m);
        $blocoPartidas = $m[0] ?? '';

        $this->assertNotEmpty(
            $blocoPartidas,
            'schema.sql deve conter bloco CREATE TABLE partidas'
        );

        $this->assertMatchesRegularExpression(
            '/status\s+VARCHAR\s*\(\s*30\s*\)/i',
            $blocoPartidas,
            'partidas.status deve ser VARCHAR(30) no schema (DT-04)'
        );

        $this->assertStringNotContainsStringIgnoringCase(
            "ENUM('SCHEDULED'",
            $blocoPartidas,
            'partidas.status não deve ser ENUM no schema (DT-04)'
        );
    }

    // =========================================================================
    // 5. ON DELETE CASCADE em simulacoes_usuarios.usuario_id (RN05)
    // =========================================================================

    /**
     * schema.sql deve definir ON DELETE CASCADE para a FK usuario_id em simulacoes_usuarios.
     */
    public function testSchemaSqlCascadeSimulacoesUsuarioId(): void
    {
        $schema = self::readFile('database/schema.sql');

        preg_match(
            '/CREATE TABLE IF NOT EXISTS\s+`?simulacoes_usuarios`?.*?ENGINE=/si',
            $schema,
            $m
        );
        $bloco = $m[0] ?? '';

        $this->assertNotEmpty(
            $bloco,
            'schema.sql deve conter bloco CREATE TABLE simulacoes_usuarios'
        );

        // A FK em usuario_id deve ter ON DELETE CASCADE
        // Verificar que fk_sim_usuario aponta para usuarios e tem CASCADE
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY\s*\(\s*`?usuario_id`?\s*\)\s*REFERENCES\s*`?usuarios`?[^;]*ON DELETE CASCADE/si',
            $bloco,
            'simulacoes_usuarios deve ter FK usuario_id → usuarios.id ON DELETE CASCADE (RN05)'
        );
    }

    /**
     * O dicionário deve documentar o cascade de simulacoes (RN05).
     */
    public function testDicionarioDocumentaCascadeRN05(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        $this->assertMatchesRegularExpression(
            '/RN05|exclus[aã]o.*?cascata|ON DELETE CASCADE.*?simulac/i',
            $dic,
            'O dicionário deve documentar a exclusão em cascata de simulacoes_usuarios (RN05)'
        );
    }

    // =========================================================================
    // 6. Migration: uso correto de INFORMATION_SCHEMA para colunas
    // =========================================================================

    /**
     * A migration deve verificar cada coluna de probabilidade via
     * INFORMATION_SCHEMA.COLUMNS antes de adicionar.
     */
    public function testMigrationVerificaColunasViaInformationSchema(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        $this->assertStringContainsString(
            'INFORMATION_SCHEMA.COLUMNS',
            $migration,
            'A migration deve usar INFORMATION_SCHEMA.COLUMNS para verificar colunas'
        );

        // Deve verificar as 6 colunas individualmente
        $colunas = [
            'probabilidade_campeao',
            'probabilidade_libertadores',
            'probabilidade_sulamericana',
            'probabilidade_rebaixamento',
            'probabilidades_posicoes',
            'probabilidades_calculadas_em',
        ];

        foreach ($colunas as $col) {
            $this->assertStringContainsString(
                "COLUMN_NAME  = '$col'",
                $migration,
                "A migration deve verificar INFORMATION_SCHEMA para a coluna '$col'"
            );
        }
    }

    // =========================================================================
    // 7. Migration: verificação completa da FK
    // =========================================================================

    /**
     * A migration deve verificar a FK via REFERENTIAL_CONSTRAINTS E KEY_COLUMN_USAGE,
     * confirmando: tabela/coluna de origem, tabela/coluna referenciada e
     * DELETE_RULE e UPDATE_RULE. A coluna de destino precisa ser exatamente id.
     *
     * Uma busca simples por "competicoes" ou pelo nome da constraint não basta;
     * é necessário cruzar RC com KCU para conferir a definição esperada.
     */
    public function testMigrationVerificaFKOrigemDestinoERegraViaRCeKCU(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        // Deve usar REFERENTIAL_CONSTRAINTS
        $this->assertStringContainsString(
            'REFERENTIAL_CONSTRAINTS',
            $migration,
            'A migration deve usar INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'
        );

        // Deve cruzar com KEY_COLUMN_USAGE (para verificar coluna e tabela referenciada)
        $this->assertStringContainsString(
            'KEY_COLUMN_USAGE',
            $migration,
            'A migration deve cruzar REFERENTIAL_CONSTRAINTS com KEY_COLUMN_USAGE '
            . 'para verificar os atributos completos da FK'
        );

        // Deve verificar que é a tabela temporadas
        $this->assertMatchesRegularExpression(
            "/rc\.TABLE_NAME\s*=\s*'temporadas'/",
            $migration,
            "A migration deve verificar rc.TABLE_NAME = 'temporadas'"
        );

        // Deve verificar que a coluna é competicao_id
        $this->assertMatchesRegularExpression(
            "/kcu\.COLUMN_NAME\s*=\s*'competicao_id'/",
            $migration,
            "A migration deve verificar kcu.COLUMN_NAME = 'competicao_id'"
        );

        // Deve verificar que a tabela referenciada é competicoes
        $this->assertMatchesRegularExpression(
            "/rc\.REFERENCED_TABLE_NAME\s*=\s*'competicoes'/",
            $migration,
            "A migration deve verificar rc.REFERENCED_TABLE_NAME = 'competicoes'"
        );

        $this->assertMatchesRegularExpression(
            "/kcu\.REFERENCED_COLUMN_NAME\s*=\s*'id'/",
            $migration,
            "A migration deve verificar kcu.REFERENCED_COLUMN_NAME = 'id'"
        );

        $this->assertMatchesRegularExpression(
            "/kcu\.REFERENCED_TABLE_SCHEMA\s*=\s*DATABASE\(\)/",
            $migration,
            'A migration deve confirmar que a tabela referenciada está no banco selecionado'
        );

        // Deve verificar DELETE_RULE
        $this->assertStringContainsString(
            'DELETE_RULE',
            $migration,
            'A migration deve verificar DELETE_RULE da FK'
        );

        $this->assertStringContainsString(
            'UPDATE_RULE',
            $migration,
            'A migration deve verificar UPDATE_RULE e exigir o comportamento esperado'
        );

        $this->assertMatchesRegularExpression(
            "/ON DELETE RESTRICT\\s+ON UPDATE RESTRICT/i",
            $migration,
            'A FK criada deve declarar explicitamente as duas regras RESTRICT'
        );

        $this->assertMatchesRegularExpression(
            "/rc\.DELETE_RULE\s*<>\s*'RESTRICT'\s*OR\s*rc\.UPDATE_RULE\s*<>\s*'RESTRICT'/",
            $migration,
            'A migration deve substituir a FK canônica se DELETE_RULE ou UPDATE_RULE divergirem'
        );

        $this->assertMatchesRegularExpression(
            "/rc\.DELETE_RULE\s*=\s*'RESTRICT'.*?rc\.UPDATE_RULE\s*=\s*'RESTRICT'/s",
            $migration,
            'A validação final só pode reportar OK se as duas regras forem RESTRICT'
        );

        $this->assertStringContainsString(
            'kcu_all',
            $migration,
            'A migration deve rejeitar FK composta, confirmando que há apenas uma coluna'
        );

        $this->assertStringContainsString(
            '@fk_named_count = 0',
            $migration,
            'A migration só deve adicionar a constraint se o nome estiver livre no schema'
        );

        $this->assertStringContainsString(
            '@fk_relation_count = 0',
            $migration,
            'A migration não deve criar FK duplicada se a relação já existir com outro nome'
        );

        $this->assertStringContainsString(
            'FK existe com estrutura ou regras incompatíveis',
            $migration,
            'A migration deve diagnosticar uma constraint homônima incompatível'
        );

        $this->assertStringContainsString(
            'relação equivalente existe com outro nome',
            $migration,
            'A migration deve diagnosticar a relação existente sob outro nome'
        );
    }

    /**
     * A migration deve verificar o nome da constraint (fk_temporadas_competicao).
     */
    public function testMigrationVerificaNomeDaConstraint(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        $this->assertStringContainsString(
            "'fk_temporadas_competicao'",
            $migration,
            "A migration deve verificar o nome da constraint 'fk_temporadas_competicao'"
        );
    }

    // =========================================================================
    // 8. Migration: pré-condições declaradas
    // =========================================================================

    /**
     * O preflight deve validar automaticamente as estruturas usadas pela migration.
     */
    public function testMigrationExecutaPreflightAntesDosBlocosDeAlteracao(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');
        $preflight = substr($migration, 0, strpos($migration, '-- BLOCO 1:'));
        $firstAlteration = strpos($migration, '-- BLOCO 1:');
        $this->assertNotFalse($firstAlteration);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $preflight);
        $this->assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $preflight);
        $this->assertStringContainsString('INFORMATION_SCHEMA.STATISTICS', $preflight);
        $this->assertStringContainsString('Temporada órfã', $preflight);
        $this->assertStringContainsString('CREATE TEMPORARY TABLE _arena_issue1_preflight_errors', $preflight);
        $this->assertStringContainsString('__ARENA_ISSUE1_PREFLIGHT_FAILED__', $preflight);
        $this->assertLessThan($firstAlteration, strpos($migration, 'EXECUTE _preflight_guard_stmt'));
    }

    // =========================================================================
    // 9. Migration: limitações declaradas (L1, L2, L3, L4)
    // =========================================================================

    /**
     * A migration deve declarar que CREATE TABLE IF NOT EXISTS não atualiza
     * tabelas existentes com estrutura divergente.
     */
    public function testMigrationDeclaraLimitacaoCreateTableIfNotExists(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        // A migration deve ter blocos CREATE TABLE IF NOT EXISTS
        $this->assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS',
            $migration,
            'A migration deve usar CREATE TABLE IF NOT EXISTS'
        );

        // Deve haver comentário explícito sobre a limitação de não atualizar
        // estruturas existentes. Testamos via str_contains para evitar
        // problemas de encoding de caracteres multibyte em diferentes ambientes.
        $limitacaoDeclarada = str_contains($migration, 'L2')
            && (str_contains($migration, 'ignorado silenciosamente')
                || str_contains($migration, 'SHOW CREATE TABLE'));

        $this->assertTrue(
            $limitacaoDeclarada,
            'A migration deve declarar a limitação L2: CREATE TABLE IF NOT EXISTS não '
            . 'atualiza tabelas existentes (procurar por "L2" e "ignorado silenciosamente" '
            . 'ou "SHOW CREATE TABLE")'
        );
    }

    /**
     * A migration deve declarar as 4 limitações L1–L4.
     */
    public function testMigrationDeclaraLimitacoesL1aL4(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        foreach (['L1', 'L2', 'L3', 'L4'] as $lim) {
            $this->assertStringContainsString(
                $lim . '.',
                $migration,
                "A migration deve declarar a limitação $lim"
            );
        }
    }

    /**
     * A migration deve declarar que não altera tipos, placares e colunas não listadas.
     */
    public function testMigrationDeclaraEscopoDoQueNaoAltera(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        // A migration deve declarar o que NÃO altera (via L4)
        $this->assertStringContainsString(
            'L4',
            $migration,
            'A migration deve declarar L4: o que não altera'
        );
    }

    // =========================================================================
    // 10. Migration: idempotência honestamente declarada
    // =========================================================================

    /**
     * A migration deve declarar idempotência com a ressalva correta:
     * não causa erro em reexecução, mas não corrige divergências existentes.
     */
    public function testMigrationDeclaraIdempotenciaComRessalva(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        $this->assertStringContainsString(
            'IDEMPOTÊNCIA',
            $migration,
            'A migration deve ter seção IDEMPOTÊNCIA'
        );

        // Deve haver ressalva sobre o que idempotência NÃO significa
        $ressalvaDeclarada = str_contains($migration, 'divergentes existentes')
            || str_contains($migration, 'divergentes')
            && str_contains($migration, 'existentes');

        $this->assertTrue(
            $ressalvaDeclarada,
            'A migration deve ressalvar que idempotência não significa corrigir '
            . 'estruturas divergentes existentes'
        );
    }

    // =========================================================================
    // 11. Dicionário: versão e decisões técnicas
    // =========================================================================

    /**
     * O dicionário deve estar na versão 2.1.
     */
    public function testDicionarioVersao21(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        $this->assertMatchesRegularExpression(
            '/Versão do padrão.*?2\.1/i',
            $dic,
            'O dicionário deve estar na versão 2.1'
        );
    }

    /**
     * O dicionário deve listar as 7 decisões técnicas DT-01 a DT-07.
     */
    public function testDicionarioContemTodasDecisoesTecnicasDT01aDT07(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        for ($i = 1; $i <= 7; $i++) {
            $this->assertStringContainsString(
                "DT-0$i",
                $dic,
                "O dicionário deve registrar a decisão técnica DT-0$i"
            );
        }
    }

    /**
     * O dicionário deve declarar que o schema implementado não é integralmente
     * equivalente à DDL v2.1, e listar divergências.
     */
    public function testDicionarioDeclaraDivergenciasComDDLv21(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        // Deve afirmar que não é integralmente equivalente ou similar
        $this->assertMatchesRegularExpression(
            '/não é integralmente equivalente|divergênc/i',
            $dic,
            'O dicionário deve declarar que o schema não é integralmente equivalente à DDL v2.1'
        );
    }

    /**
     * O dicionário deve identificar as decisões técnicas como aprovadas.
     */
    public function testDicionarioListaDecisoesAprovadas(): void
    {
        $dic = self::readFile('docs/dicionario-dados.md');

        $this->assertStringContainsString(
            '## 10. Decisões técnicas aprovadas',
            $dic,
            'O dicionário deve identificar separadamente as decisões técnicas aprovadas'
        );

        $this->assertStringContainsString(
            'formalmente aprovadas na Issue #1',
            $dic,
            'O dicionário deve registrar a aprovação das decisões técnicas'
        );
    }

    // =========================================================================
    // 12. Repositório: prepared statements (RNF02)
    // =========================================================================

    /**
     * ClubeRepository.php deve usar PDO::prepare em todas as consultas.
     */
    public function testClubeRepositoryUsaPreparedStatements(): void
    {
        $repo = self::readFile('src/Repositories/ClubeRepository.php');

        $this->assertStringContainsString(
            '->prepare(',
            $repo,
            'ClubeRepository deve usar PDO::prepare para prepared statements (RNF02)'
        );

        // Não deve haver concatenação de variáveis diretamente no SQL
        $this->assertDoesNotMatchRegularExpression(
            '/WHERE\s+\w+\s*=\s*["\']?\s*\.\s*\$/i',
            $repo,
            'ClubeRepository não deve concatenar variáveis diretamente no SQL (RNF02)'
        );
    }

    // =========================================================================
    // 13. Migration: contém queries de validação pós-execução
    // =========================================================================

    /**
     * A migration deve conter as queries de validação para o operador
     * confirmar o estado resultante sem depender da execução em banco real.
     */
    public function testMigrationContemQueriesDeValidacao(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');

        // Deve conter query de validação das 6 colunas
        $this->assertStringContainsString(
            "LIKE 'probabilidad%'",
            $migration,
            'A migration deve conter query de validação das colunas de probabilidade'
        );

        // Deve conter query de validação das tabelas criadas
        $this->assertMatchesRegularExpression(
            "/IN\s*\(\s*'usuarios'.*?'simulacoes_usuarios'.*?'sync_logs'\s*\)/is",
            $migration,
            'A migration deve conter query de validação das 3 tabelas criadas'
        );

        // Deve conter query de validação com origem/destino e diagnóstico final.
        $this->assertStringContainsString(
            'tabela_origem',
            $migration,
            'A migration deve conter query de validação da tabela de origem da FK'
        );

        $this->assertStringContainsString(
            'coluna_referenciada',
            $migration,
            'A migration deve exibir a coluna referenciada da FK na validação'
        );

        $this->assertStringContainsString(
            'fk_temporadas_competicao_status',
            $migration,
            'A migration deve informar se a FK ficou correta ou incompatível'
        );
    }

    /**
     * A migration deve usar erro SQL real para reprovar o preflight.
     */
    public function testMigrationUsaErroSqlQuandoPreflightReprova(): void
    {
        $migration = self::readFile('database/migrations/001_alinhar_schema_mvp.sql');
        $dictionary = self::readFile('docs/dicionario-dados.md');

        $this->assertStringContainsString(
            '__ARENA_ISSUE1_PREFLIGHT_FAILED__',
            $migration,
            'A migration deve causar erro SQL quando o preflight reprova'
        );
        $this->assertStringContainsString(
            'modo batch',
            $dictionary,
            'O dicionário deve documentar que o cliente mysql batch interrompe no erro'
        );
    }

    public function testMigrationValidaUniqueEmailComoIndiceDeUmaColuna(): void
    {
        $migration = self::readFile(
            'database/migrations/001_alinhar_schema_mvp.sql'
        );

        $this->assertStringContainsString(
            's.SUB_PART IS NULL',
            $migration
        );

        $this->assertStringContainsString(
            'AND s.NON_UNIQUE = 0',
            $migration
        );

        $this->assertStringContainsString(
            'AND s.COLUMN_NAME = \'email\'',
            $migration
        );

        $this->assertStringContainsString(
            ') = 1',
            $migration
        );
    }

    public function testMigrationRejeitaChavePrimariaComposta(): void
    {
        $migration = self::readFile(
            'database/migrations/001_alinhar_schema_mvp.sql'
        );

        $this->assertStringContainsString(
            "s.INDEX_NAME = 'PRIMARY'",
            $migration
        );

        $this->assertStringContainsString(
            ') <> 1',
            $migration
        );
    }

    public function testMigrationValidaDefaultsDasTabelasNovas(): void
    {
        $migration = self::readFile(
            'database/migrations/001_alinhar_schema_mvp.sql'
        );

        foreach ([
            'usuarios',
            'simulacoes_usuarios',
            'sync_logs',
            'CURRENT_TIMESTAMP',
            'registros_processados',
            'iniciado_em',
            '__NONE__',
            '__NULL__',
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $migration,
                "A validação de defaults deve contemplar: {$expected}"
            );
        }
    }

    public function testMigrationValidaOnUpdateCurrentTimestamp(): void
    {
        $migration = self::readFile(
            'database/migrations/001_alinhar_schema_mvp.sql'
        );

        $this->assertStringContainsString(
            'on update current_timestamp',
            strtolower($migration)
        );

        $this->assertStringContainsString(
            "'atualizado_em'",
            $migration
        );
    }

    public function testMigrationVerificaAusenciaDeDefaultEmIniciadoEm(): void
    {
        $migration = self::readFile(
            'database/migrations/001_alinhar_schema_mvp.sql'
        );

        $this->assertStringContainsString(
            "'iniciado_em','__NONE__'",
            $migration
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StandingsService;
use PHPUnit\Framework\TestCase;

class StandingsServiceTest extends TestCase
{
    public function testCalculaPontuacaoIgnoraJogosNaoFinalizadosEAplicaDesempates(): void
    {
        $clubes = [
            ['id' => 1, 'external_id' => 1783, 'nome' => 'CR Flamengo', 'nome_curto' => 'Flamengo', 'sigla' => 'FLA', 'ajuste_pontos' => 0],
            ['id' => 2, 'external_id' => 1769, 'nome' => 'SE Palmeiras', 'nome_curto' => 'Palmeiras', 'sigla' => 'PAL', 'ajuste_pontos' => 0],
            ['id' => 3, 'external_id' => 1779, 'nome' => 'SC Corinthians', 'nome_curto' => 'Corinthians', 'sigla' => 'COR', 'ajuste_pontos' => -2],
        ];

        $partidas = [
            // Flamengo 2 x 1 Palmeiras (FINISHED) -> FLA 3 pts, PAL 0 pts
            ['mandante_id' => 1, 'visitante_id' => 2, 'gols_mandante' => 2, 'gols_visitante' => 1, 'status' => 'FINISHED'],
            // Palmeiras 3 x 0 Corinthians (AWARDED) -> PAL 3 pts (SG +2, GP 4), COR 0 pts
            ['mandante_id' => 2, 'visitante_id' => 3, 'gols_mandante' => 3, 'gols_visitante' => 0, 'status' => 'AWARDED'],
            // Corinthians 1 x 0 Flamengo (FINISHED) -> COR 3 - 2 = 1 pt, FLA 3 pts (SG 0, GP 2)
            ['mandante_id' => 3, 'visitante_id' => 1, 'gols_mandante' => 1, 'gols_visitante' => 0, 'status' => 'FINISHED'],
            // Jogo em andamento (IN_PLAY) -> deve ser ignorado!
            ['mandante_id' => 1, 'visitante_id' => 3, 'gols_mandante' => 5, 'gols_visitante' => 0, 'status' => 'IN_PLAY'],
            // Jogo agendado com NULL -> deve ser ignorado!
            ['mandante_id' => 2, 'visitante_id' => 1, 'gols_mandante' => null, 'gols_visitante' => null, 'status' => 'TIMED'],
        ];

        $service = new StandingsService();
        $tabela = $service->calculateFromData($clubes, $partidas);

        $this->assertCount(3, $tabela);

        // Palmeiras e Flamengo empatados com 3 pts e 1 vitória, mas Palmeiras tem saldo +2 contra 0 do Flamengo
        $this->assertSame('Palmeiras', $tabela[0]['nome_curto']);
        $this->assertSame(1, $tabela[0]['posicao']);
        $this->assertSame(3, $tabela[0]['pontos']);
        $this->assertSame(2, $tabela[0]['saldo_gols']);

        $this->assertSame('Flamengo', $tabela[1]['nome_curto']);
        $this->assertSame(2, $tabela[1]['posicao']);
        $this->assertSame(3, $tabela[1]['pontos']);
        $this->assertSame(0, $tabela[1]['saldo_gols']);

        // Corinthians: 1 vitória (3 pts) - 2 pts de punição = 1 pt
        $this->assertSame('Corinthians', $tabela[2]['nome_curto']);
        $this->assertSame(3, $tabela[2]['posicao']);
        $this->assertSame(1, $tabela[2]['pontos']);
    }

    public function testDesempatePorConfrontoDiretoQuandoEmpatadosEmPontosVitoriasSaldoEGolsPro(): void
    {
    $clubes = [
        ['id' => 10, 'nome' => 'Clube A', 'nome_curto' => 'Clube A', 'sigla' => 'CLA'],
        ['id' => 20, 'nome' => 'Clube B', 'nome_curto' => 'Clube B', 'sigla' => 'CLB'],
        ['id' => 30, 'nome' => 'Clube C', 'nome_curto' => 'Clube C', 'sigla' => 'CLC'],
        ['id' => 40, 'nome' => 'Clube D', 'nome_curto' => 'Clube D', 'sigla' => 'CLD'],
    ];

    $partidas = [
        // Clube A vence Clube B por 1x0.
        ['mandante_id' => 10, 'visitante_id' => 20, 'gols_mandante' => 1, 'gols_visitante' => 0, 'status' => 'FINISHED'],

        // Clube C vence Clube A por 1x0.
        ['mandante_id' => 30, 'visitante_id' => 10, 'gols_mandante' => 1, 'gols_visitante' => 0, 'status' => 'FINISHED'],

        // Clube B vence Clube C por 1x0.
        ['mandante_id' => 20, 'visitante_id' => 30, 'gols_mandante' => 1, 'gols_visitante' => 0, 'status' => 'FINISHED'],

        // Clube D também vence Clube C por 1x0.
        ['mandante_id' => 40, 'visitante_id' => 30, 'gols_mandante' => 1, 'gols_visitante' => 0, 'status' => 'FINISHED'],
    ];

    $service = new StandingsService();
    $tabela = $service->calculateFromData($clubes, $partidas);

    $clubeA = array_search(10, array_column($tabela, 'clube_id'), true);
    $clubeB = array_search(20, array_column($tabela, 'clube_id'), true);

    $this->assertNotFalse($clubeA);
    $this->assertNotFalse($clubeB);

    $this->assertSame(3, $tabela[$clubeA]['pontos']);
    $this->assertSame(3, $tabela[$clubeB]['pontos']);

    $this->assertSame(1, $tabela[$clubeA]['vitorias']);
    $this->assertSame(1, $tabela[$clubeB]['vitorias']);

    $this->assertSame(0, $tabela[$clubeA]['saldo_gols']);
    $this->assertSame(0, $tabela[$clubeB]['saldo_gols']);

    $this->assertSame(1, $tabela[$clubeA]['gols_pro']);
    $this->assertSame(1, $tabela[$clubeB]['gols_pro']);

    $this->assertLessThan($clubeB, $clubeA);
    }
    public function testIgnoraPartidasComStatusNaoOficialOuPlacarNulo(): void
    {
    $clubes = [
        ['id' => 1, 'nome' => 'Clube A', 'nome_curto' => 'Clube A', 'sigla' => 'CLA'],
        ['id' => 2, 'nome' => 'Clube B', 'nome_curto' => 'Clube B', 'sigla' => 'CLB'],
    ];

    $partidas = [];

    foreach (['IN_PLAY', 'PAUSED', 'TIMED', 'POSTPONED', 'CANCELLED', 'UNKNOWN'] as $status) {
        $partidas[] = [
            'mandante_id' => 1,
            'visitante_id' => 2,
            'gols_mandante' => 5,
            'gols_visitante' => 0,
            'status' => $status,
        ];
    }

    $partidas[] = [
        'mandante_id' => 1,
        'visitante_id' => 2,
        'gols_mandante' => null,
        'gols_visitante' => null,
        'status' => 'FINISHED',
    ];

    $partidas[] = [
        'mandante_id' => 2,
        'visitante_id' => 1,
        'gols_mandante' => 1,
        'gols_visitante' => null,
        'status' => 'AWARDED',
    ];

    $service = new StandingsService();
    $tabela = $service->calculateFromData($clubes, $partidas);

    $this->assertCount(2, $tabela);

    foreach ($tabela as $clube) {
        $this->assertSame(0, $clube['jogos']);
        $this->assertSame(0, $clube['pontos']);
        $this->assertSame(0, $clube['gols_pro']);
        $this->assertSame(0, $clube['gols_contra']);
        }
    }
}

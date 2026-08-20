<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SituacaoEvento;
use App\Models\Atividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Evento de demonstracao: a "Copa CCC 2026", com dois dias.
 *
 * Dia 1 — Esportes: o participante escolhe entre 1 e 2 modalidades.
 * Dia 2 — Trilha: o participante escolhe se vai ou nao, no maximo uma opcao.
 *
 * Pode ser executado quantas vezes for preciso: nada e duplicado.
 */
class EventoDemoSeeder extends Seeder
{
    private const SLUG = 'copa-ccc-2026';

    public function run(): void
    {
        $primeiroDia = Carbon::parse('2026-10-17');
        $segundoDia = Carbon::parse('2026-10-18');

        $evento = Evento::query()->firstOrCreate(
            ['slug' => self::SLUG],
            [
                'codigo_publico' => (string) Str::ulid(),
                'nome' => 'Copa CCC 2026',
                'descricao' => 'Dois dias de atividades: modalidades esportivas no sábado e trilha no domingo.',
                'data_inicio' => $primeiroDia->toDateString(),
                'data_fim' => $segundoDia->toDateString(),
                'inscricoes_abrem_em' => Carbon::parse('2026-08-01 00:00'),
                'inscricoes_fecham_em' => Carbon::parse('2026-10-10 23:59'),
                'capacidade' => 200,
                'valor_centavos' => 12000,
                'moeda' => 'BRL',
                'prazo_pagamento_minutos' => 1440,
                'situacao' => SituacaoEvento::InscricoesAbertas,
                'regulamento' => 'Regulamento da Copa CCC 2026. O participante declara estar em condições de saúde para praticar as atividades escolhidas.',
                'versao_termos' => '2026.1',
                'contato_email' => 'contato@copaccc.example.com',
                'contato_telefone' => '(11) 90000-0000',
                'configuracoes' => [],
            ],
        );

        $diaEsportes = $this->dia($evento, 1, 'Dia 1 — Esportes', $primeiroDia);
        $diaTrilha = $this->dia($evento, 2, 'Dia 2 — Trilha', $segundoDia);

        $modalidades = $this->grupo($diaEsportes, [
            'nome' => 'Modalidades esportivas',
            'descricao' => 'Escolha de 1 a 2 modalidades. Atenção aos horários: modalidades que se sobrepõem não podem ser escolhidas juntas.',
            'obrigatorio' => true,
            'min_selecoes' => 1,
            'max_selecoes' => 2,
            'posicao' => 1,
        ]);

        $trilha = $this->grupo($diaTrilha, [
            'nome' => 'Trilha',
            'descricao' => 'Participação opcional. No máximo uma trilha.',
            'obrigatorio' => false,
            'min_selecoes' => 0,
            'max_selecoes' => 1,
            'posicao' => 1,
        ]);

        $this->atividade($modalidades, 'Futebol', $primeiroDia, '08:00', '10:00', 1, ['capacidade' => 40]);
        $this->atividade($modalidades, 'Vôlei', $primeiroDia, '09:00', '11:00', 2, ['capacidade' => 24]);
        $this->atividade($modalidades, 'Handebol', $primeiroDia, '10:00', '12:00', 3, ['capacidade' => 24]);
        $this->atividade($modalidades, 'Basquete', $primeiroDia, '14:00', '16:00', 4, ['capacidade' => 20]);

        $this->atividade($trilha, 'Trilha leve', $segundoDia, '07:00', '10:00', 1, ['capacidade' => 60]);
        $this->atividade($trilha, 'Trilha longa', $segundoDia, '07:00', '13:00', 2, [
            'capacidade' => 30,
            'idade_minima' => 16,
        ]);
    }

    private function dia(Evento $evento, int $posicao, string $nome, Carbon $data): DiaEvento
    {
        return DiaEvento::query()->firstOrCreate(
            ['evento_id' => $evento->id, 'posicao' => $posicao],
            ['nome' => $nome, 'data' => $data->toDateString(), 'ativo' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function grupo(DiaEvento $dia, array $dados): GrupoAtividade
    {
        return GrupoAtividade::query()->firstOrCreate(
            ['dia_evento_id' => $dia->id, 'nome' => $dados['nome']],
            $dados + ['ativo' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function atividade(
        GrupoAtividade $grupo,
        string $nome,
        Carbon $data,
        string $comeca,
        string $termina,
        int $posicao,
        array $extras = [],
    ): Atividade {
        return Atividade::query()->firstOrCreate(
            ['grupo_atividade_id' => $grupo->id, 'nome' => $nome],
            array_merge([
                'comeca_em' => Carbon::parse($data->toDateString().' '.$comeca),
                'termina_em' => Carbon::parse($data->toDateString().' '.$termina),
                'posicao' => $posicao,
                'ativo' => true,
                'configuracoes' => [],
            ], $extras),
        );
    }
}

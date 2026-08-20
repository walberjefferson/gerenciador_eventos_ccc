<?php

declare(strict_types=1);

use App\Enums\SituacaoEvento;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\ConflitoAtividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use App\Models\GrupoParticipante;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Executa a operacao dentro de um ponto de retorno e devolve a excecao do
 * banco. Assim varias restricoes podem ser testadas no mesmo teste sem que a
 * transacao do teste fique invalida.
 */
function violacaoDoBanco(callable $operacao): ?QueryException
{
    try {
        DB::transaction($operacao);
    } catch (QueryException $excecao) {
        return $excecao;
    }

    return null;
}

describe('estrutura do evento', function () {
    it('encadeia evento, dia, grupo de atividades e atividade', function () {
        $evento = Evento::factory()->create();
        $dia = DiaEvento::factory()->for($evento)->create(['posicao' => 1]);
        $grupo = GrupoAtividade::factory()->for($dia)->create();
        $atividade = Atividade::factory()->for($grupo)->create();

        expect($dia->evento->id)->toBe($evento->id)
            ->and($evento->diasEvento->pluck('id')->all())->toBe([$dia->id])
            ->and($evento->gruposAtividades->pluck('id')->all())->toBe([$grupo->id])
            ->and($grupo->atividades->pluck('id')->all())->toBe([$atividade->id])
            ->and($atividade->grupoAtividade->diaEvento->evento->id)->toBe($evento->id);
    });

    it('lista os dias na ordem de exibicao', function () {
        $evento = Evento::factory()->create();
        DiaEvento::factory()->for($evento)->create(['posicao' => 2, 'nome' => 'Segundo']);
        DiaEvento::factory()->for($evento)->create(['posicao' => 1, 'nome' => 'Primeiro']);

        expect($evento->diasEvento()->pluck('nome')->all())->toBe(['Primeiro', 'Segundo']);
    });

    it('gera um codigo publico ao criar o evento', function () {
        $evento = Evento::factory()->create(['codigo_publico' => null]);

        expect($evento->codigo_publico)->toHaveLength(26);
    });

    it('guarda a situacao como Enum e expoe um rotulo em portugues', function () {
        $evento = Evento::factory()->create();

        expect($evento->situacao)->toBe(SituacaoEvento::InscricoesAbertas)
            ->and($evento->situacao->rotulo())->toBe('Inscrições abertas')
            ->and($evento->situacao->aceitaInscricoes())->toBeTrue()
            ->and(SituacaoEvento::Rascunho->aceitaInscricoes())->toBeFalse();
    });

    it('liga o grupo de participantes a cidade', function () {
        $cidade = Cidade::factory()->create();
        $grupo = GrupoParticipante::factory()->for($cidade)->create();

        expect($grupo->cidade->id)->toBe($cidade->id)
            ->and($cidade->gruposParticipantes->pluck('id')->all())->toBe([$grupo->id]);
    });
});

describe('filtros de consulta', function () {
    it('encontra apenas eventos com inscricoes abertas agora', function () {
        $aberto = Evento::factory()->create();
        $rascunho = Evento::factory()->rascunho()->create();
        $aindaNaoAbriu = Evento::factory()->inscricoesAindaNaoAbriram()->create();
        $jaFechou = Evento::factory()->inscricoesJaFecharam()->create();

        $encontrados = Evento::query()->comInscricoesAbertas()->pluck('id')->all();

        expect($encontrados)->toBe([$aberto->id])
            ->and($encontrados)->not->toContain($rascunho->id)
            ->and($encontrados)->not->toContain($aindaNaoAbriu->id)
            ->and($encontrados)->not->toContain($jaFechou->id);
    });

    it('responde se as inscricoes estao abertas neste instante', function () {
        expect(Evento::factory()->create()->inscricoesEstaoAbertas())->toBeTrue()
            ->and(Evento::factory()->rascunho()->create()->inscricoesEstaoAbertas())->toBeFalse()
            ->and(Evento::factory()->inscricoesJaFecharam()->create()->inscricoesEstaoAbertas())->toBeFalse();
    });

    it('filtra apenas os registros ativos', function () {
        Cidade::factory()->inativa()->create();
        $ativa = Cidade::factory()->create();

        expect(Cidade::query()->ativos()->pluck('id')->all())->toBe([$ativa->id]);

        $dia = DiaEvento::factory()->create();
        DiaEvento::factory()->for($dia->evento)->inativo()->create(['posicao' => 2]);

        expect(DiaEvento::query()->ativos()->pluck('id')->all())->toBe([$dia->id]);
    });

    it('encontra as atividades e os grupos de um evento atravessando dia e grupo', function () {
        $atividade = Atividade::factory()->create();
        $eventoId = $atividade->grupoAtividade->diaEvento->evento_id;
        Atividade::factory()->create();

        expect(Atividade::query()->doEvento($eventoId)->pluck('id')->all())->toBe([$atividade->id])
            ->and(GrupoAtividade::query()->doEvento($eventoId)->pluck('id')->all())
            ->toBe([$atividade->grupo_atividade_id]);
    });
});

describe('contagem de vagas', function () {
    it('soma reservadas e confirmadas e devolve o que sobrou', function () {
        $evento = Evento::factory()->comCapacidade(10)->create();
        $evento->forceFill(['vagas_reservadas' => 3, 'vagas_confirmadas' => 4])->save();

        expect($evento->vagasOcupadas())->toBe(7)
            ->and($evento->vagasDisponiveis())->toBe(3)
            ->and($evento->temVagaDisponivel())->toBeTrue();
    });

    it('trata capacidade nula como sem limite', function () {
        $evento = Evento::factory()->create(['capacidade' => null]);

        expect($evento->vagasDisponiveis())->toBeNull()
            ->and($evento->temVagaDisponivel())->toBeTrue();
    });

    it('reconhece o evento lotado', function () {
        $evento = Evento::factory()->comCapacidade(2)->create();
        $evento->forceFill(['vagas_reservadas' => 1, 'vagas_confirmadas' => 1])->save();

        expect($evento->vagasDisponiveis())->toBe(0)
            ->and($evento->temVagaDisponivel())->toBeFalse();
    });
});

describe('o banco recusa dados invalidos', function () {
    it('nao aceita mais vagas ocupadas do que a capacidade do evento', function () {
        $evento = Evento::factory()->comCapacidade(2)->create();

        $erro = violacaoDoBanco(fn () => DB::table('eventos')
            ->where('id', $evento->id)
            ->update(['vagas_reservadas' => 3]));

        expect($erro?->getMessage())->toContain('eventos_capacidade_check');
    });

    it('nao aceita contador de vagas negativo', function () {
        $evento = Evento::factory()->create();

        $erro = violacaoDoBanco(fn () => DB::table('eventos')
            ->where('id', $evento->id)
            ->update(['vagas_reservadas' => -1]));

        expect($erro?->getMessage())->toContain('eventos_vagas_nao_negativas_check');
    });

    it('nao aceita evento que termina antes de comecar', function () {
        $erro = violacaoDoBanco(fn () => Evento::factory()->create([
            'data_inicio' => '2026-10-18',
            'data_fim' => '2026-10-17',
        ]));

        expect($erro?->getMessage())->toContain('eventos_periodo_check');
    });

    it('nao aceita janela de inscricao que fecha antes de abrir', function () {
        $erro = violacaoDoBanco(fn () => Evento::factory()->create([
            'inscricoes_abrem_em' => Carbon::parse('2026-09-10 10:00'),
            'inscricoes_fecham_em' => Carbon::parse('2026-09-01 10:00'),
        ]));

        expect($erro?->getMessage())->toContain('eventos_inscricoes_periodo_check');
    });

    it('nao aceita dois dias com a mesma posicao no mesmo evento', function () {
        $dia = DiaEvento::factory()->create(['posicao' => 1]);

        $erro = violacaoDoBanco(fn () => DiaEvento::factory()
            ->for($dia->evento)
            ->create(['posicao' => 1]));

        expect($erro?->getMessage())->toContain('dias_evento_evento_id_posicao_unique');
    });

    it('nao aceita grupo obrigatorio com minimo zero', function () {
        $erro = violacaoDoBanco(fn () => GrupoAtividade::factory()->create([
            'obrigatorio' => true,
            'min_selecoes' => 0,
        ]));

        expect($erro?->getMessage())->toContain('grupos_atividades_obrigatorio_check');
    });

    it('nao aceita maximo de escolhas menor que o minimo', function () {
        $erro = violacaoDoBanco(fn () => GrupoAtividade::factory()->create([
            'min_selecoes' => 2,
            'max_selecoes' => 1,
        ]));

        expect($erro?->getMessage())->toContain('grupos_atividades_max_check');
    });

    it('nao aceita atividade que termina antes de comecar', function () {
        $erro = violacaoDoBanco(fn () => Atividade::factory()->create([
            'comeca_em' => Carbon::parse('2026-10-17 10:00'),
            'termina_em' => Carbon::parse('2026-10-17 09:00'),
        ]));

        expect($erro?->getMessage())->toContain('atividades_horario_check');
    });

    it('nao aceita mais vagas ocupadas do que a capacidade da atividade', function () {
        $atividade = Atividade::factory()->comCapacidade(1)->create();

        $erro = violacaoDoBanco(fn () => DB::table('atividades')
            ->where('id', $atividade->id)
            ->update(['vagas_confirmadas' => 2]));

        expect($erro?->getMessage())->toContain('atividades_capacidade_check');
    });

    it('nao aceita par de conflito fora de ordem', function () {
        $primeira = Atividade::factory()->create();
        $segunda = Atividade::factory()->create();

        $erro = violacaoDoBanco(fn () => DB::table('conflitos_atividades')->insert([
            'atividade_a_id' => $segunda->id,
            'atividade_b_id' => $primeira->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        expect($erro?->getMessage())->toContain('conflitos_atividades_par_normalizado_check');
    });

    it('coloca o par de conflito em ordem antes de gravar', function () {
        $primeira = Atividade::factory()->create();
        $segunda = Atividade::factory()->create();

        $conflito = ConflitoAtividade::factory()->create([
            'atividade_a_id' => $segunda->id,
            'atividade_b_id' => $primeira->id,
        ]);

        expect($conflito->atividade_a_id)->toBe($primeira->id)
            ->and($conflito->atividade_b_id)->toBe($segunda->id);
    });

    it('nao aceita o mesmo par de conflito duas vezes', function () {
        $primeira = Atividade::factory()->create();
        $segunda = Atividade::factory()->create();

        ConflitoAtividade::factory()->create([
            'atividade_a_id' => $primeira->id,
            'atividade_b_id' => $segunda->id,
        ]);

        $erro = violacaoDoBanco(fn () => ConflitoAtividade::factory()->create([
            'atividade_a_id' => $segunda->id,
            'atividade_b_id' => $primeira->id,
        ]));

        expect($erro?->getMessage())->toContain('conflitos_atividades_atividade_a_id_atividade_b_id_unique');
    });

    it('nao aceita duas cidades com o mesmo nome no mesmo estado', function () {
        Cidade::factory()->create(['nome' => 'Santos', 'uf' => 'SP']);

        $erro = violacaoDoBanco(fn () => Cidade::factory()->create(['nome' => 'Santos', 'uf' => 'SP']));

        expect($erro?->getMessage())->toContain('cidades_nome_uf_unique');
    });
});

describe('regras de horario e idade da atividade', function () {
    it('reconhece sobreposicao e nao considera limites que se encostam', function () {
        $futebol = Atividade::factory()->noHorario(
            Carbon::parse('2026-10-17 08:00'),
            Carbon::parse('2026-10-17 10:00'),
        )->create();

        $volei = Atividade::factory()->noHorario(
            Carbon::parse('2026-10-17 09:00'),
            Carbon::parse('2026-10-17 11:00'),
        )->create();

        $handebol = Atividade::factory()->noHorario(
            Carbon::parse('2026-10-17 10:00'),
            Carbon::parse('2026-10-17 12:00'),
        )->create();

        expect($futebol->sobrepoe($volei))->toBeTrue()
            ->and($volei->sobrepoe($futebol))->toBeTrue()
            ->and($futebol->sobrepoe($handebol))->toBeFalse()
            ->and($handebol->sobrepoe($futebol))->toBeFalse();
    });

    it('calcula a idade na data da atividade, nao na data da inscricao', function () {
        $trilha = Atividade::factory()->noHorario(
            Carbon::parse('2026-10-18 07:00'),
            Carbon::parse('2026-10-18 13:00'),
        )->comFaixaEtaria(16, null)->create();

        // Faz 16 anos um dia antes da atividade.
        expect($trilha->aceitaIdade(Carbon::parse('2010-10-17')))->toBeTrue()
            // Faz 16 anos um dia depois da atividade.
            ->and($trilha->aceitaIdade(Carbon::parse('2010-10-19')))->toBeFalse();
    });

    it('respeita a idade maxima', function () {
        $atividade = Atividade::factory()->noHorario(
            Carbon::parse('2026-10-17 08:00'),
            Carbon::parse('2026-10-17 10:00'),
        )->comFaixaEtaria(null, 12)->create();

        expect($atividade->aceitaIdade(Carbon::parse('2015-01-01')))->toBeTrue()
            ->and($atividade->aceitaIdade(Carbon::parse('2013-01-01')))->toBeFalse();
    });

    it('sem faixa etaria aceita qualquer idade', function () {
        $atividade = Atividade::factory()->create();

        expect($atividade->aceitaIdade(Carbon::parse('1950-01-01')))->toBeTrue()
            ->and($atividade->aceitaIdade(Carbon::parse('2020-01-01')))->toBeTrue();
    });
});

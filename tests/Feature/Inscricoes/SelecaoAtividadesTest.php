<?php

declare(strict_types=1);

use App\Exceptions\Inscricoes\SelecaoAtividadesInvalidaException;
use App\Models\Atividade;
use App\Models\GrupoAtividade;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Corresponde ao ActivitySelectionTest exigido pelo briefing.
 *
 * Cobre RN-03 (grupo obrigatorio), RN-04 (minimo e maximo por grupo),
 * RN-05 (a atividade precisa ser deste evento) e RN-08 (faixa etaria).
 */

/**
 * Devolve as mensagens que a recusa da selecao carrega.
 *
 * @return array<int, string>
 */
function recusaDaSelecao(callable $tentativa): array
{
    try {
        $tentativa();
    } catch (SelecaoAtividadesInvalidaException $recusa) {
        return $recusa->mensagens();
    }

    return [];
}

describe('RN-03 — grupo obrigatorio', function () {
    it('recusa quem nao escolheu nada no grupo obrigatorio', function () {
        $cenario = Cenario::montar();

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever(['atividades' => []]));

        expect($mensagens)->toContain('Você precisa escolher pelo menos 1 modalidade esportiva.')
            ->and(Inscricao::count())->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0);
    });

    it('recusa quem escolheu so a atividade opcional', function () {
        $cenario = Cenario::montar();

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->trilha->id],
        ]));

        expect($mensagens)->toContain('Você precisa escolher pelo menos 1 modalidade esportiva.');
    });

    it('recusa quem ficou abaixo do minimo de um grupo obrigatorio de duas escolhas', function () {
        $cenario = Cenario::montar();
        $cenario->esportes->update(['min_selecoes' => 2, 'max_selecoes' => 3]);

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
        ]));

        expect($mensagens)->toContain('Você precisa escolher pelo menos 2 modalidade esportiva.');
    });

    it('aceita quem escolheu o minimo do grupo obrigatorio', function () {
        $cenario = Cenario::montar();

        $inscricao = $cenario->inscrever(['atividades' => [$cenario->futebol->id]]);

        expect($inscricao->atividades)->toHaveCount(1);
    });

    it('ignora grupo obrigatorio que foi desativado', function () {
        $cenario = Cenario::montar();
        $cenario->esportes->update(['ativo' => false]);

        $inscricao = $cenario->inscrever(['atividades' => [$cenario->trilha->id]]);

        expect($inscricao->atividades->pluck('id')->all())->toBe([$cenario->trilha->id]);
    });
});

describe('RN-04 — minimo e maximo por grupo', function () {
    it('recusa quem passou do maximo do grupo', function () {
        $cenario = Cenario::montar();

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->volei->id, $cenario->natacao->id],
        ]));

        expect($mensagens)->toContain('Você pode escolher no máximo 2 opções em modalidade esportiva.')
            ->and(Inscricao::count())->toBe(0);
    });

    it('recusa quem passou do maximo do grupo opcional', function () {
        $cenario = Cenario::montar();
        $outraTrilha = $cenario->atividade($cenario->trilhas, 'Trilha do Rio', 19, 21);

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id, $outraTrilha->id],
        ]));

        expect($mensagens)->toContain('Você pode escolher no máximo 1 opções em trilha.');
    });

    it('em grupo opcional com minimo, aceita nenhuma escolha mas recusa escolha incompleta', function () {
        $cenario = Cenario::montar();
        $cenario->trilhas->update(['min_selecoes' => 2, 'max_selecoes' => 3]);

        // Nenhuma escolha no grupo opcional: permitido.
        $inscricao = $cenario->inscrever(['atividades' => [$cenario->futebol->id]]);
        expect($inscricao->atividades)->toHaveCount(1);

        // Uma unica escolha, abaixo do minimo do grupo: recusado.
        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever($cenario->outraPessoa(2, [
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ])));

        expect($mensagens)->toContain('Você precisa escolher pelo menos 2 opções em trilha.');
    });

    it('aceita a quantidade exata do maximo', function () {
        $cenario = Cenario::montar();

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->volei->id],
        ]);

        expect($inscricao->atividades)->toHaveCount(2);
    });
});

describe('RN-05 — a atividade precisa ser deste evento', function () {
    it('recusa atividade de outro evento', function () {
        $cenario = Cenario::montar();
        $outroCenario = Cenario::montar();

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$outroCenario->futebol->id],
        ]));

        expect($mensagens)->toContain('Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção.')
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($outroCenario->futebol->fresh()->vagas_reservadas)->toBe(0);
    });

    it('recusa atividade desativada', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['ativo' => false]);

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
        ]));

        expect($mensagens)->toContain('Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção.');
    });

    it('recusa atividade de grupo desativado', function () {
        $cenario = Cenario::montar();
        $grupoDesativado = GrupoAtividade::factory()->for($cenario->dia)->inativo()->create([
            'nome' => 'Oficinas', 'posicao' => 3,
        ]);
        $oficina = Atividade::factory()->for($grupoDesativado)->create([
            'nome' => 'Oficina de nós',
            'comeca_em' => $cenario->hora(20),
            'termina_em' => $cenario->hora(21),
        ]);

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $oficina->id],
        ]));

        expect($mensagens)->toContain('Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção.');
    });

    it('recusa atividade que nao existe', function () {
        $cenario = Cenario::montar();

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever(['atividades' => [999999]]));

        expect($mensagens)->toContain('Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção.');
    });
});

describe('RN-08 — faixa etaria na data da atividade', function () {
    it('recusa quem ainda nao tem a idade minima no dia da atividade', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['idade_minima' => 18]);

        // Faz 18 anos um dia depois do futebol: no dia da atividade tem 17.
        $nascimento = $cenario->futebol->comeca_em->copy()->subYears(18)->addDay();

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
            'data_nascimento' => $nascimento->toDateString(),
        ]));

        expect($mensagens)->toContain('Futebol é permitida a partir de 18 anos.')
            ->and(Inscricao::count())->toBe(0);
    });

    it('aceita quem faz a idade minima no proprio dia da atividade', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['idade_minima' => 18]);

        $nascimento = $cenario->futebol->comeca_em->copy()->subYears(18);

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
            'data_nascimento' => $nascimento->toDateString(),
        ]);

        expect($inscricao->atividades)->toHaveCount(1);
    });

    it('recusa quem passou da idade maxima no dia da atividade', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['idade_maxima' => 15]);

        $nascimento = $cenario->futebol->comeca_em->copy()->subYears(16);

        $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
            'data_nascimento' => $nascimento->toDateString(),
        ]));

        expect($mensagens)->toContain('Futebol é permitida até 15 anos.');
    });

    it('aceita quem esta dentro da faixa', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['idade_minima' => 12, 'idade_maxima' => 17]);

        $nascimento = $cenario->futebol->comeca_em->copy()->subYears(14);

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
            'data_nascimento' => $nascimento->toDateString(),
        ]);

        expect($inscricao->atividades)->toHaveCount(1);
    });

    it('nao trava menor de idade quando a atividade nao exige idade', function () {
        $cenario = Cenario::montar();

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id],
            'data_nascimento' => Carbon::now()->subYears(10)->toDateString(),
        ]);

        expect($inscricao->situacao->value)->toBe('aguardando_pagamento');
    });
});

it('junta todas as recusas da selecao em uma unica resposta', function () {
    $cenario = Cenario::montar();
    $cenario->natacao->update(['idade_minima' => 18]);

    $mensagens = recusaDaSelecao(fn () => $cenario->inscrever([
        'atividades' => [$cenario->futebol->id, $cenario->volei->id, $cenario->natacao->id],
        'data_nascimento' => Carbon::now()->subYears(10)->toDateString(),
    ]));

    expect($mensagens)->toContain('Você pode escolher no máximo 2 opções em modalidade esportiva.')
        ->and($mensagens)->toContain('Natação é permitida a partir de 18 anos.')
        ->and(count($mensagens))->toBeGreaterThan(1);
});

it('devolve as recusas da selecao como erro do campo atividades no formulario', function () {
    $cenario = Cenario::montar();

    $this->postJson('/inscricoes', $cenario->payload(['atividades' => []]))
        ->assertStatus(422)
        ->assertJsonPath('errors.atividades.0', 'Você precisa escolher pelo menos 1 modalidade esportiva.');
});

<?php

declare(strict_types=1);

use App\Exceptions\Inscricoes\SelecaoAtividadesInvalidaException;
use App\Models\ConflitoAtividade;
use App\Models\Inscricao;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Corresponde ao ActivityConflictTest exigido pelo briefing.
 *
 * Cobre RN-06 (choque de horario) e RN-07 (par que a organizacao declarou
 * incompativel).
 */

/**
 * @return array<int, string>
 */
function recusaDoConflito(callable $tentativa): array
{
    try {
        $tentativa();
    } catch (SelecaoAtividadesInvalidaException $recusa) {
        return $recusa->mensagens();
    }

    return [];
}

describe('RN-06 — choque de horario', function () {
    it('recusa duas atividades que se sobrepoem pela metade', function () {
        $cenario = Cenario::montar();

        // Futebol 09:00-11:00 e Natacao 10:00-12:00.
        $mensagens = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->natacao->id],
        ]));

        expect($mensagens)->toContain('Futebol e Natação acontecem no mesmo horário. Escolha apenas uma das duas.')
            ->and(Inscricao::count())->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0);
    });

    it('recusa atividade que acontece inteira dentro de outra', function () {
        $cenario = Cenario::montar();

        // Aquecimento 09:30-10:00 cabe dentro do Futebol 09:00-11:00.
        $mensagens = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->aquecimento->id],
        ]));

        expect($mensagens)->toContain('Futebol e Aquecimento acontecem no mesmo horário. Escolha apenas uma das duas.');
    });

    it('recusa tambem quando a que contem a outra vem depois na lista', function () {
        $cenario = Cenario::montar();

        $mensagens = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->aquecimento->id, $cenario->futebol->id],
        ]));

        expect($mensagens)->not->toBeEmpty();
    });

    it('permite atividades cujos horarios apenas se encostam', function () {
        $cenario = Cenario::montar();

        // Futebol termina 11:00 e o Volei comeca 11:00: nao ha sobreposicao.
        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->volei->id],
        ]);

        expect($inscricao->atividades->pluck('nome')->all())->toBe(['Futebol', 'Vôlei'])
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->volei->fresh()->vagas_reservadas)->toBe(1);
    });

    it('permite atividades em horarios totalmente separados', function () {
        $cenario = Cenario::montar();

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ]);

        expect($inscricao->atividades)->toHaveCount(2);
    });

    it('compara o instante completo, entao enxerga choque que atravessa a meia-noite', function () {
        $cenario = Cenario::montar();
        $vigilia = $cenario->atividade($cenario->trilhas, 'Vigília', 22, 26);
        $madrugada = $cenario->atividade($cenario->trilhas, 'Observação de estrelas', 25, 27);
        $cenario->trilhas->update(['max_selecoes' => 3]);

        $mensagens = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $vigilia->id, $madrugada->id],
        ]));

        expect($mensagens)->toContain('Vigília e Observação de estrelas acontecem no mesmo horário. Escolha apenas uma das duas.');
    });
});

describe('RN-07 — conflito declarado pela organizacao', function () {
    it('recusa o par declarado, com o motivo escrito pela organizacao', function () {
        $cenario = Cenario::montar();

        ConflitoAtividade::create([
            'atividade_a_id' => $cenario->futebol->id,
            'atividade_b_id' => $cenario->volei->id,
            'motivo' => 'As duas usam a mesma quadra.',
        ]);

        $mensagens = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->volei->id],
        ]));

        expect($mensagens)->toContain('Futebol e Vôlei não podem ser escolhidas juntas: As duas usam a mesma quadra.')
            ->and(Inscricao::count())->toBe(0);
    });

    it('recusa nos dois sentidos do par, independentemente da ordem da escolha', function () {
        $cenario = Cenario::montar();

        ConflitoAtividade::create([
            'atividade_a_id' => $cenario->volei->id,
            'atividade_b_id' => $cenario->futebol->id,
            'motivo' => 'As duas usam a mesma quadra.',
        ]);

        $naOrdem = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->volei->id],
        ]));

        $naOrdemInversa = recusaDoConflito(fn () => $cenario->inscrever($cenario->outraPessoa(3, [
            'atividades' => [$cenario->volei->id, $cenario->futebol->id],
        ])));

        expect($naOrdem)->toContain('Futebol e Vôlei não podem ser escolhidas juntas: As duas usam a mesma quadra.')
            ->and($naOrdemInversa)->toContain('Futebol e Vôlei não podem ser escolhidas juntas: As duas usam a mesma quadra.');
    });

    it('recusa mesmo sem motivo escrito', function () {
        $cenario = Cenario::montar();

        ConflitoAtividade::create([
            'atividade_a_id' => $cenario->futebol->id,
            'atividade_b_id' => $cenario->volei->id,
            'motivo' => null,
        ]);

        $mensagens = recusaDoConflito(fn () => $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->volei->id],
        ]));

        expect($mensagens)->toContain('Futebol e Vôlei não podem ser escolhidas juntas.');
    });

    it('nao atrapalha quem escolheu apenas uma das duas do par', function () {
        $cenario = Cenario::montar();

        ConflitoAtividade::create([
            'atividade_a_id' => $cenario->futebol->id,
            'atividade_b_id' => $cenario->volei->id,
            'motivo' => 'As duas usam a mesma quadra.',
        ]);

        $inscricao = $cenario->inscrever(['atividades' => [$cenario->futebol->id]]);

        expect($inscricao->atividades)->toHaveCount(1);
    });

    it('devolve o conflito como erro do campo atividades no formulario', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload([
            'atividades' => [$cenario->futebol->id, $cenario->natacao->id],
        ]))->assertStatus(422)->assertJsonPath(
            'errors.atividades.0',
            'Futebol e Natação acontecem no mesmo horário. Escolha apenas uma das duas.'
        );
    });
});

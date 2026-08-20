<?php

declare(strict_types=1);

use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O relogio da inscricao.
 *
 * O que se prova aqui: o prazo nasce do momento da inscricao somado ao tempo
 * que o organizador configurou; o preco e congelado na criacao (mudar a tabela
 * do evento depois nao muda a conta de quem ja se inscreveu); e a cobranca
 * vence exatamente junto com a inscricao — nunca antes, nunca depois.
 */
it('marca o prazo como o momento da inscricao mais os minutos configurados', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');

    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 90]);
    $inscricao = $cenario->inscrever();

    expect($inscricao->prazo_pagamento->equalTo($inscricao->created_at->copy()->addMinutes(90)))->toBeTrue()
        ->and($inscricao->prazo_pagamento->toDateTimeString())->toBe('2026-03-10 13:30:00');

    Carbon::setTestNow();
});

it('usa o prazo configurado em cada evento, e nao um prazo fixo do sistema', function () {
    $rapido = Cenario::montar(['prazo_pagamento_minutos' => 15]);
    $folgado = Cenario::montar(['prazo_pagamento_minutos' => 2880]);

    $inscricaoRapida = $rapido->inscrever();
    $inscricaoFolgada = $folgado->inscrever();

    expect($inscricaoRapida->created_at->diffInMinutes($inscricaoRapida->prazo_pagamento))->toEqual(15.0)
        ->and($inscricaoFolgada->created_at->diffInMinutes($inscricaoFolgada->prazo_pagamento))->toEqual(2880.0);
});

it('congela o valor da inscricao: mudar o preco do evento depois nao mexe em quem ja se inscreveu', function () {
    $cenario = Cenario::montar(['valor_centavos' => 15000]);
    $inscricao = $cenario->inscrever();

    $cenario->evento->update(['valor_centavos' => 29900]);

    expect($inscricao->fresh()->valor_centavos)->toBe(15000)
        ->and($inscricao->pagamentoPendente()->valor_centavos)->toBe(15000)
        ->and($cenario->evento->fresh()->valor_centavos)->toBe(29900);
});

it('faz a cobranca vencer exatamente junto com a inscricao', function () {
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 45]);
    $inscricao = $cenario->inscrever();

    $pagamento = $inscricao->pagamentoPendente();

    expect($pagamento->expira_em->equalTo($inscricao->prazo_pagamento))->toBeTrue();
});

it('so considera vencida a inscricao depois que o prazo passa', function () {
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 30]);
    $inscricao = $cenario->inscrever();

    expect($inscricao->prazoVencido())->toBeFalse()
        ->and(Inscricao::query()->vencidas()->count())->toBe(0);

    $this->travel(29)->minutes();

    expect(Inscricao::query()->vencidas()->count())->toBe(0);

    $this->travel(2)->minutes();

    expect($inscricao->fresh()->prazoVencido())->toBeTrue()
        ->and(Inscricao::query()->vencidas()->count())->toBe(1);
});

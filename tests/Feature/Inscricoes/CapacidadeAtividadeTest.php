<?php

declare(strict_types=1);

use App\Actions\Inscricoes\ExpirarInscricoesVencidas;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Corresponde ao ActivityCapacityTest exigido pelo briefing.
 *
 * Cobre RN-09 (vagas da atividade), RN-10 (vagas do evento) e a varredura sob
 * demanda: antes de recusar alguem, o sistema devolve as vagas presas por
 * quem ja perdeu o prazo de pagamento.
 */
describe('RN-09 — vagas da atividade', function () {
    it('concede a ultima vaga a exatamente uma pessoa', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['capacidade' => 1]);

        $cenario->inscrever();

        expect(fn () => $cenario->inscrever($cenario->outraPessoa(1)))
            ->toThrow(VagasEsgotadasException::class, 'As vagas de Futebol acabaram. Escolha outra opção.');

        expect(Inscricao::count())->toBe(1)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1);
    });

    it('devolve a vaga do evento quando a atividade e que lotou', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['capacidade' => 1]);

        $cenario->inscrever();

        try {
            $cenario->inscrever($cenario->outraPessoa(2));
        } catch (VagasEsgotadasException) {
            // esperado
        }

        // A transacao inteira voltou atras: a vaga do evento nao ficou presa.
        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1);
    });

    it('nao limita atividade sem capacidade definida', function () {
        $cenario = Cenario::montar();

        foreach (range(1, 5) as $indice) {
            $cenario->inscrever($cenario->outraPessoa($indice));
        }

        expect($cenario->futebol->fresh()->vagas_reservadas)->toBe(5)
            ->and($cenario->futebol->fresh()->capacidade)->toBeNull();
    });

    it('conta apenas a atividade escolhida', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['capacidade' => 1]);

        $cenario->inscrever();
        $cenario->inscrever($cenario->outraPessoa(3, ['atividades' => [$cenario->volei->id]]));

        expect($cenario->futebol->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->volei->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(2);
    });

    it('soma reservadas e confirmadas ao decidir se ainda ha vaga', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['capacidade' => 2]);

        // Contador nunca e mexido pelo model: so por comando atomico. Aqui a
        // gravacao direta simula uma vaga ja paga.
        DB::table('atividades')->where('id', $cenario->futebol->id)->update(['vagas_confirmadas' => 1]);

        $cenario->inscrever();

        expect(fn () => $cenario->inscrever($cenario->outraPessoa(4)))
            ->toThrow(VagasEsgotadasException::class);
    });
});

describe('RN-10 — vagas do evento', function () {
    it('concede a ultima vaga do evento a exatamente uma pessoa', function () {
        $cenario = Cenario::montar(['capacidade' => 1]);

        $cenario->inscrever();

        expect(fn () => $cenario->inscrever($cenario->outraPessoa(5)))
            ->toThrow(VagasEsgotadasException::class, 'As vagas para este evento acabaram.');

        expect(Inscricao::count())->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1);
    });

    it('nao prende vaga de atividade quando o evento ja esta lotado', function () {
        $cenario = Cenario::montar(['capacidade' => 1]);

        $cenario->inscrever(['atividades' => [$cenario->volei->id]]);

        try {
            $cenario->inscrever($cenario->outraPessoa(6));
        } catch (VagasEsgotadasException) {
            // esperado
        }

        expect($cenario->futebol->fresh()->vagas_reservadas)->toBe(0);
    });

    it('nao limita evento sem capacidade definida', function () {
        $cenario = Cenario::montar();

        foreach (range(10, 13) as $indice) {
            $cenario->inscrever($cenario->outraPessoa($indice));
        }

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(4);
    });
});

describe('varredura sob demanda', function () {
    it('concede a vaga presa apenas por reserva vencida, sem esperar o agendador', function () {
        $cenario = Cenario::montar(['capacidade' => 1, 'prazo_pagamento_minutos' => 60]);

        $primeira = $cenario->inscrever();

        // A pessoa nao pagou e o prazo passou; o agendador ainda nao rodou.
        Inscricao::whereKey($primeira->id)->update([
            'prazo_pagamento' => Carbon::now()->subMinute(),
        ]);

        $segunda = $cenario->inscrever($cenario->outraPessoa(20));

        expect($segunda->exists)->toBeTrue()
            ->and($primeira->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
            ->and($primeira->fresh()->expirada_em)->not->toBeNull()
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            ->and(Inscricao::count())->toBe(2);
    });

    it('funciona tambem quando quem lotou foi a atividade', function () {
        $cenario = Cenario::montar();
        $cenario->futebol->update(['capacidade' => 1]);

        $primeira = $cenario->inscrever();
        Inscricao::whereKey($primeira->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

        $segunda = $cenario->inscrever($cenario->outraPessoa(21));

        expect($segunda->exists)->toBeTrue()
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1);
    });

    it('recusa de verdade quando nao ha reserva vencida para devolver', function () {
        $cenario = Cenario::montar(['capacidade' => 1]);

        $cenario->inscrever();

        expect(fn () => $cenario->inscrever($cenario->outraPessoa(22)))
            ->toThrow(VagasEsgotadasException::class);

        expect(Inscricao::count())->toBe(1);
    });

    it('nao mexe em inscricao vencida de outro evento', function () {
        $cenario = Cenario::montar(['capacidade' => 1]);
        $outro = Cenario::montar(['capacidade' => 1]);

        $daqui = $cenario->inscrever();
        $dela = $outro->inscrever();

        Inscricao::whereKey($daqui->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);
        Inscricao::whereKey($dela->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

        $cenario->inscrever($cenario->outraPessoa(23));

        expect($daqui->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
            ->and($dela->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
    });
});

describe('devolucao de vagas na expiracao', function () {
    it('devolve a vaga do evento e a de cada atividade', function () {
        $cenario = Cenario::montar();

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ]);

        Inscricao::whereKey($inscricao->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

        $expiradas = app(ExpirarInscricoesVencidas::class)();

        expect($expiradas)->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(0);
    });

    it('rodar a varredura duas vezes nao altera nada na segunda', function () {
        $cenario = Cenario::montar();
        $inscricao = $cenario->inscrever();
        Inscricao::whereKey($inscricao->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

        app(ExpirarInscricoesVencidas::class)();
        $expiradoEm = $inscricao->fresh()->expirada_em;

        $segunda = app(ExpirarInscricoesVencidas::class)();

        expect($segunda)->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($inscricao->fresh()->expirada_em?->toDateTimeString())->toBe($expiradoEm?->toDateTimeString());
    });

    it('nao apaga nenhum registro ao expirar', function () {
        $cenario = Cenario::montar();
        $inscricao = $cenario->inscrever();
        Inscricao::whereKey($inscricao->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

        app(ExpirarInscricoesVencidas::class)();

        expect(Inscricao::count())->toBe(1)
            ->and($inscricao->fresh()->atividades)->toHaveCount(1);
    });

    it('nao expira quem ainda esta dentro do prazo', function () {
        $cenario = Cenario::montar();
        $inscricao = $cenario->inscrever();

        expect(app(ExpirarInscricoesVencidas::class)())->toBe(0)
            ->and($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1);
    });
});

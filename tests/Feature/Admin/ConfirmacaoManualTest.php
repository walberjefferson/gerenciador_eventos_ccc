<?php

declare(strict_types=1);

use App\Actions\Inscricoes\CancelarInscricaoAdministrativa;
use App\Actions\Inscricoes\ExpirarInscricoesVencidas;
use App\Actions\Pagamentos\ConfirmarPagamentoManual;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoConfirmada;
use App\Exceptions\Pagamentos\ConfirmacaoManualRecusadaException;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Admin\Cenario as CenarioAdmin;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A confirmacao manual de pagamento.
 *
 * E a unica acao do sistema que declara "entrou dinheiro" sem que nenhuma
 * fonte externa tenha reconhecido nada. Os testes daqui cercam exatamente
 * isso: quem pode declarar, o que fica registrado, e o que o sistema se recusa
 * a fazer mesmo com um administrador pedindo.
 */
function confirmarNaMao(
    Inscricao $inscricao,
    ?User $responsavel = null,
    MetodoPagamento $metodo = MetodoPagamento::Dinheiro,
    string $observacao = 'Recebido em dinheiro na secretaria, recibo 118.',
): bool {
    return app(ConfirmarPagamentoManual::class)(
        $inscricao,
        $responsavel ?? CenarioAdmin::usuarioCom('administrador'),
        $metodo,
        $observacao,
    );
}

describe('confirmacao de quem esta aguardando', function () {
    it('confirma a inscricao e marca a cobranca como paga', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();
        $pagamento = $inscricao->pagamentoPendente();

        expect(confirmarNaMao($inscricao))->toBeTrue();

        $inscricao->refresh();

        expect($inscricao->situacao)->toBe(SituacaoInscricao::Confirmada)
            ->and($inscricao->confirmada_em)->not->toBeNull()
            ->and($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Pago)
            ->and($pagamento->fresh()->pago_em)->not->toBeNull();

        // A vaga presa virou vaga paga: o total ocupado nao mudou.
        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(1)
            ->and($cenario->futebol->fresh()->vagas_confirmadas)->toBe(1);
    });

    it('grava a origem manual, o responsavel e a observacao, sem inventar identificador de provedor', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();
        $pagamento = $inscricao->pagamentoPendente();
        $idExternoOriginal = $pagamento->id_externo;

        $responsavel = CenarioAdmin::usuarioCom('administrador');

        confirmarNaMao($inscricao, $responsavel, MetodoPagamento::Transferencia, 'Transferência recebida em 20/08.');

        $pagamento->refresh();

        expect($pagamento->metadados['origem'])->toBe('manual')
            ->and($pagamento->metadados['metodo_declarado'])->toBe('transferencia')
            ->and($pagamento->metadados['observacao'])->toBe('Transferência recebida em 20/08.')
            ->and($pagamento->metadados['responsavel']['id'])->toBe($responsavel->id)
            ->and($pagamento->metadados['responsavel']['email'])->toBe($responsavel->email)
            ->and($pagamento->metodo)->toBe(MetodoPagamento::Transferencia)
            // Nada de identificador de provedor forjado: o que havia continua
            // sendo o que havia.
            ->and($pagamento->id_externo)->toBe($idExternoOriginal);
    });

    it('anuncia InscricaoConfirmada uma unica vez, o mesmo anuncio de sempre', function () {
        CenarioAdmin::semearPapeis();
        Event::fake([InscricaoConfirmada::class]);

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        expect(confirmarNaMao($inscricao))->toBeTrue()
            ->and(confirmarNaMao($inscricao->fresh()))->toBeFalse();

        Event::assertDispatchedTimes(InscricaoConfirmada::class, 1);
    });

    it('abre uma cobranca propria quando nao ha nenhuma em aberto', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        // O Pix venceu e ninguem pediu segunda via, mas a inscricao ainda esta
        // de pe: o prazo dela e outro.
        Pagamento::query()
            ->where('inscricao_id', $inscricao->id)
            ->update(['situacao' => SituacaoPagamento::Expirado->value]);

        expect(confirmarNaMao($inscricao))->toBeTrue();

        $manual = Pagamento::query()
            ->where('inscricao_id', $inscricao->id)
            ->where('gateway', 'manual')
            ->first();

        expect($manual)->not->toBeNull()
            ->and($manual->situacao)->toBe(SituacaoPagamento::Pago)
            ->and($manual->id_externo)->toBeNull()
            ->and($manual->valor_centavos)->toBe((int) $inscricao->valor_centavos)
            ->and($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Confirmada);
    });
});

describe('o que o sistema recusa', function () {
    it('recusa inscricao expirada, porque a vaga ja voltou para a fila', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10, 'prazo_pagamento_minutos' => 60]);
        $inscricao = $cenario->inscrever();

        Inscricao::whereKey($inscricao->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);
        app(ExpirarInscricoesVencidas::class)();

        expect(fn () => confirmarNaMao($inscricao->fresh()))
            ->toThrow(ConfirmacaoManualRecusadaException::class, 'Esta inscricao expirou');

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
            ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(0);
    });

    it('recusa inscricao cancelada', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        app(CancelarInscricaoAdministrativa::class)($inscricao, 'Desistiu');

        expect(fn () => confirmarNaMao($inscricao->fresh()))
            ->toThrow(ConfirmacaoManualRecusadaException::class, 'foi cancelada');

        expect($cenario->evento->fresh()->vagas_confirmadas)->toBe(0);
    });

    it('exige observacao nao vazia', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        expect(fn () => confirmarNaMao($inscricao, null, MetodoPagamento::Dinheiro, '   '))
            ->toThrow(InvalidArgumentException::class, 'Descreva como o pagamento foi recebido.');

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
    });

    it('recusa declarar na mao um pagamento que e do provedor', function () {
        CenarioAdmin::semearPapeis();

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        expect(fn () => confirmarNaMao($inscricao, null, MetodoPagamento::Pix, 'Pix caiu na conta.'))
            ->toThrow(InvalidArgumentException::class);

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
    });
});

describe('quem pode declarar', function () {
    it('da a permissao ao administrador e nega ao organizador', function () {
        CenarioAdmin::semearPapeis();

        expect(CenarioAdmin::usuarioCom('administrador')->can('pagamentos.confirmar-manual'))->toBeTrue()
            ->and(CenarioAdmin::usuarioCom('organizador')->can('pagamentos.confirmar-manual'))->toBeFalse();
    });
});

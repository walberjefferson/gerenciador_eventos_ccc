<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentGateway;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoConfirmada;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\WebhookPagamento;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A rede de seguranca do aviso automatico.
 *
 * O cenario e o pior caso realista: a pessoa pagou, o provedor registrou o
 * pagamento, mas o aviso nunca chegou aqui — rede fora do ar, fila parada,
 * deploy no momento errado. Sem reconciliacao, essa pessoa perderia a vaga que
 * ja pagou. Com ela, o sistema pergunta ao provedor e confirma sozinho.
 */

/**
 * @return array{0: Cenario, 1: Inscricao, 2: Pagamento, 3: FakePaymentGateway}
 */
function cobrancaPagaSemAviso(int $prazoEmMinutos = 10): array
{
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => $prazoEmMinutos]);

    $inscricao = $cenario->inscrever([
        'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
    ]);

    $pagamento = $inscricao->pagamentoPendente();

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);

    // Pago no provedor. Nenhum aviso e entregue: e exatamente isso que a
    // reconciliacao existe para resolver.
    $gateway->simulatePayment((string) $pagamento->id_externo);

    return [$cenario, $inscricao, $pagamento, $gateway];
}

it('confirma a inscricao de quem pagou mesmo quando o aviso nunca chega', function () {
    [$cenario, $inscricao, $pagamento] = cobrancaPagaSemAviso();

    expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and(WebhookPagamento::query()->count())->toBe(0);

    $this->artisan('pagamentos:reconciliar')->assertSuccessful();

    expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($inscricao->fresh()->confirmada_em)->not->toBeNull()
        ->and($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($pagamento->fresh()->pago_em)->not->toBeNull()
        // A vaga presa vira vaga paga: o total ocupado nao muda.
        ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(1)
        ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->futebol->fresh()->vagas_confirmadas)->toBe(1)
        ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->trilha->fresh()->vagas_confirmadas)->toBe(1)
        // Confirmar nao depende de aviso: nenhum webhook foi inventado.
        ->and(WebhookPagamento::query()->count())->toBe(0);
});

it('rodar a reconciliacao duas vezes nao conta vaga em dobro', function () {
    [$cenario, $inscricao] = cobrancaPagaSemAviso();

    Event::fake([InscricaoConfirmada::class]);

    $this->artisan('pagamentos:reconciliar')->assertSuccessful();

    $confirmadaEm = $inscricao->fresh()->confirmada_em;

    $this->travel(1)->minutes();

    $this->artisan('pagamentos:reconciliar')
        ->expectsOutputToContain('Cobrancas consultadas: 0')
        ->assertSuccessful();

    expect($inscricao->fresh()->confirmada_em->equalTo($confirmadaEm))->toBeTrue()
        ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(1)
        ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->futebol->fresh()->vagas_confirmadas)->toBe(1)
        ->and($cenario->trilha->fresh()->vagas_confirmadas)->toBe(1)
        ->and(Pagamento::query()->where('situacao', SituacaoPagamento::Pago->value)->count())->toBe(1);

    Event::assertDispatchedTimes(InscricaoConfirmada::class, 1);
});

it('nao incomoda o provedor com cobranca que ainda esta longe do vencimento', function () {
    // Prazo de 24 horas: fora da margem padrao de 15 minutos.
    [, $inscricao] = cobrancaPagaSemAviso(1440);

    $this->artisan('pagamentos:reconciliar')
        ->expectsOutputToContain('Cobrancas consultadas: 0')
        ->assertSuccessful();

    expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);

    // Com a margem aberta, a mesma cobranca entra na consulta e e confirmada.
    $this->artisan('pagamentos:reconciliar', ['--margem' => 2000])->assertSuccessful();

    expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Confirmada);
});

it('fecha aqui a cobranca que o provedor ja deu por vencida, sem devolver vaga por conta propria', function () {
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 10]);
    $inscricao = $cenario->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $gateway->simulateExpiration((string) $pagamento->id_externo);

    $this->artisan('pagamentos:reconciliar')
        ->expectsOutputToContain('Encerradas: 1')
        ->assertSuccessful();

    expect($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Expirado)
        // Quem devolve vaga e a expiracao da inscricao, nao a reconciliacao.
        ->and($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1);

    // E quando o prazo da inscricao passa, a vaga volta normalmente.
    $this->travel(11)->minutes();
    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
        ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
        ->and($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Expirado);
});

it('nao confirma quem de fato nao pagou', function () {
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 10]);
    $inscricao = $cenario->inscrever();

    $this->artisan('pagamentos:reconciliar')
        ->expectsOutputToContain('Confirmadas: 0')
        ->assertSuccessful();

    expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($inscricao->pagamentoPendente()->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(0);
});

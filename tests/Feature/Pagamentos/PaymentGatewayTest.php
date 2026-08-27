<?php

declare(strict_types=1);

use App\Actions\Pagamentos\CancelarPagamento;
use App\Actions\Pagamentos\CriarPagamentoDaInscricao;
use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\DTOs\Payments\WebhookRequestData;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoPagamento;
use App\Models\Pagamento;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Support\Carbon;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O contrato de pagamento e o provedor simulado.
 *
 * O que se prova aqui: o sistema conversa com um contrato, nunca com um
 * fornecedor; e o provedor simulado percorre o ciclo inteiro de uma cobranca
 * sem mover um centavo.
 */
function cobrancaDeTeste(int $valorCentavos = 12_500): CreatePaymentData
{
    return new CreatePaymentData(
        externalReference: 'INSCRICAO-TESTE',
        amountCents: $valorCentavos,
        currency: 'BRL',
        method: MetodoPagamento::Pix->value,
        description: 'Inscricao no evento de teste',
        payerName: 'Maria da Silva',
        payerEmail: 'maria.silva@example.com',
        payerDocument: '52998224725',
        expiresAt: Carbon::now()->addDay(),
    );
}

it('resolve o provedor de pagamento a partir da configuracao, sem o dominio citar fornecedor', function () {
    expect(config('payments.default'))->toBe('fake')
        ->and(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class)
        ->and(app(PaymentGateway::class)->name())->toBe('fake');
});

it('emite uma cobranca pix pendente com o texto de copia e cola', function () {
    $resultado = app(PaymentGateway::class)->createPayment(cobrancaDeTeste());

    expect($resultado->status)->toBe('pending')
        ->and($resultado->amountCents)->toBe(12_500)
        ->and($resultado->externalId)->toStartWith('fake_')
        ->and($resultado->pixPayload)->toContain('br.gov.bcb.pix')
        // O formato EMV termina sempre com o campo de verificacao 6304 + 4 digitos.
        ->and($resultado->pixPayload)->toMatch('/6304[0-9A-F]{4}$/');
});

it('consulta a cobranca no proprio provedor e ve o pagamento acontecer', function () {
    $gateway = app(PaymentGateway::class);
    $cobranca = $gateway->createPayment(cobrancaDeTeste());

    expect($gateway->getPayment($cobranca->externalId)->status)->toBe('pending');

    $gateway->simulatePayment($cobranca->externalId);

    $situacao = $gateway->getPayment($cobranca->externalId);

    expect($situacao->isPaid())->toBeTrue()
        ->and($situacao->paidAt)->not->toBeNull()
        ->and($situacao->amountCents)->toBe(12_500);
});

it('simula prazo vencido, falha, cancelamento e estorno', function () {
    $gateway = app(PaymentGateway::class);

    $vencida = $gateway->createPayment(cobrancaDeTeste());
    $gateway->simulateExpiration($vencida->externalId);
    expect($gateway->getPayment($vencida->externalId)->status)->toBe('expired');

    $falhada = $gateway->createPayment(cobrancaDeTeste());
    $gateway->simulateFailure($falhada->externalId);
    expect($gateway->getPayment($falhada->externalId)->status)->toBe('failed');

    $cancelada = $gateway->createPayment(cobrancaDeTeste());
    $gateway->cancelPayment($cancelada->externalId);
    expect($gateway->getPayment($cancelada->externalId)->status)->toBe('canceled');

    $estornada = $gateway->createPayment(cobrancaDeTeste());
    $gateway->simulatePayment($estornada->externalId);
    $estorno = $gateway->refundPayment($estornada->externalId, 5_000);

    expect($estorno->refundedAmountCents)->toBe(5_000)
        ->and($gateway->getPayment($estornada->externalId)->status)->toBe('refunded');
});

it('recusa estornar uma cobranca que ninguem pagou', function () {
    $gateway = app(PaymentGateway::class);
    $cobranca = $gateway->createPayment(cobrancaDeTeste());

    expect(fn () => $gateway->refundPayment($cobranca->externalId))
        ->toThrow(RuntimeException::class);
});

it('so aceita aviso com assinatura correta', function () {
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $cobranca = $gateway->createPayment(cobrancaDeTeste());
    $aviso = $gateway->emitWebhook($cobranca->externalId, 'paid');

    expect($gateway->verifyWebhookSignature($aviso))->toBeTrue();

    $adulterado = new WebhookRequestData(
        rawBody: $aviso->rawBody.' ',
        payload: $aviso->payload,
        signature: $aviso->signature,
    );

    expect($gateway->verifyWebhookSignature($adulterado))->toBeFalse()
        ->and($gateway->verifyWebhookSignature(WebhookRequestData::fromPayload($aviso->payload)))->toBeFalse();
});

it('apenas traduz o aviso recebido, sem tocar no dominio, e sempre em lista', function () {
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $cobranca = $gateway->createPayment(cobrancaDeTeste());

    // O contrato devolve uma LISTA de eventos, porque um aviso de provedor
    // real pode trazer varios pagamentos de uma vez. O simulado manda um.
    $traduzidos = $gateway->parseWebhook($gateway->emitWebhook($cobranca->externalId, 'paid'));
    $traduzido = $traduzidos[0];

    expect($traduzidos)->toHaveCount(1)
        ->and($traduzido->externalId)->toBe($cobranca->externalId)
        ->and($traduzido->status)->toBe('paid')
        ->and($traduzido->eventType)->toBe('payment.paid')
        ->and($traduzido->isActionable())->toBeTrue()
        ->and(SituacaoPagamento::deStatusExterno((string) $traduzido->status))
        ->toBe(SituacaoPagamento::Pago);
});

it('emite a cobranca da inscricao com o mesmo prazo dela', function () {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    $pagamento = $inscricao->pagamentoPendente();

    expect($pagamento)->not->toBeNull()
        ->and($pagamento->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($pagamento->metodo)->toBe(MetodoPagamento::Pix)
        ->and($pagamento->gateway)->toBe('fake')
        ->and($pagamento->valor_centavos)->toBe($inscricao->valor_centavos)
        ->and($pagamento->pix_copia_e_cola)->toContain('br.gov.bcb.pix')
        ->and($pagamento->expira_em->timestamp)->toBe($inscricao->prazo_pagamento->timestamp);
});

it('nao emite uma segunda cobranca para a mesma inscricao', function () {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    $novamente = app(CriarPagamentoDaInscricao::class)($inscricao);

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(1)
        ->and($novamente->id)->toBe($inscricao->pagamentoPendente()->id);
});

it('fecha a cobranca sem mexer em contador de vaga e nao deixa fechar duas vezes', function () {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    $reservadasAntes = $cenario->evento->refresh()->vagas_reservadas;

    expect(app(CancelarPagamento::class)($pagamento))->toBeTrue()
        ->and(app(CancelarPagamento::class)($pagamento->refresh()))->toBeFalse()
        ->and($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Cancelado)
        ->and($pagamento->cancelado_em)->not->toBeNull()
        ->and($cenario->evento->refresh()->vagas_reservadas)->toBe($reservadasAntes);
});

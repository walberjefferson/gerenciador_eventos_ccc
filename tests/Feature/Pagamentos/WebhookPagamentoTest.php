<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\WebhookRequestData;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Enums\SituacaoWebhook;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\WebhookPagamento;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O aviso automatico do provedor de pagamento.
 *
 * O que se prova aqui: so aviso assinado tem efeito; o mesmo aviso repetido
 * confirma uma vez so e nao conta vaga em dobro; e a inscricao so vira
 * "confirmada" por este caminho — nunca por parametro vindo do navegador.
 */

/**
 * Entrega o aviso exatamente como um servidor externo entregaria: o corpo
 * original, byte a byte, e a assinatura no cabecalho.
 */
function entregarAviso(WebhookRequestData $aviso): TestResponse
{
    return test()->call(
        'POST',
        '/'.ltrim((string) config('payments.webhook.path'), '/'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => (string) $aviso->signature,
        ],
        $aviso->rawBody,
    );
}

/**
 * @return array{0: Cenario, 1: Inscricao, 2: Pagamento}
 */
function cenarioComCobranca(): array
{
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    return [$cenario, $inscricao, $inscricao->pagamentoPendente()];
}

it('nao produz efeito algum com aviso de assinatura invalida', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobranca();

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $gateway->simulatePayment($pagamento->id_externo);
    $legitimo = $gateway->emitWebhook($pagamento->id_externo, 'paid');

    $forjado = new WebhookRequestData(
        rawBody: $legitimo->rawBody,
        payload: $legitimo->payload,
        signature: 'assinatura-inventada',
    );

    // A resposta e 200 de proposito (decisao D-18): quem tenta forjar um aviso
    // nao pode descobrir, pela resposta, que acertou o endereco.
    entregarAviso($forjado)->assertOk();

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(0);

    // A tentativa fica registrada: aviso forjado e informacao de seguranca.
    $registro = WebhookPagamento::query()->latest('id')->first();

    expect($registro->assinatura_valida)->toBeFalse()
        ->and($registro->situacao)->toBe(SituacaoWebhook::Ignorado)
        ->and($registro->id_evento_externo)->toBeNull();
});

it('confirma a inscricao quando o aviso assinado diz que a cobranca foi paga', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobranca();

    expect($cenario->evento->refresh()->vagas_reservadas)->toBe(1)
        ->and($cenario->futebol->refresh()->vagas_reservadas)->toBe(1);

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $gateway->simulatePayment($pagamento->id_externo);

    entregarAviso($gateway->emitWebhook($pagamento->id_externo, 'paid'))
        ->assertOk()
        ->assertJson(['recebido' => true]);

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($pagamento->pago_em)->not->toBeNull()
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($inscricao->confirmada_em)->not->toBeNull()
        // A vaga presa virou vaga paga: o total ocupado nao mudou.
        ->and($cenario->evento->refresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->evento->vagas_confirmadas)->toBe(1)
        ->and($cenario->futebol->refresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->futebol->vagas_confirmadas)->toBe(1)
        ->and(WebhookPagamento::query()->latest('id')->first()->situacao)
        ->toBe(SituacaoWebhook::Processado);
});

it('processa o mesmo aviso duas vezes e confirma uma vez so', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobranca();

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $gateway->simulatePayment($pagamento->id_externo);

    // O mesmo identificador de aviso: e assim que um provedor real reenvia
    // quando nao recebe a confirmacao a tempo.
    $aviso = $gateway->emitWebhook($pagamento->id_externo, 'paid', 'evt_repetido');

    entregarAviso($aviso)->assertOk();
    $confirmadaEm = $inscricao->refresh()->confirmada_em;

    entregarAviso($aviso)->assertOk()->assertJson(['repetido' => true]);

    expect(WebhookPagamento::query()->where('id_evento_externo', 'evt_repetido')->count())->toBe(1)
        ->and($inscricao->refresh()->confirmada_em->timestamp)->toBe($confirmadaEm->timestamp)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(1)
        ->and($cenario->evento->vagas_reservadas)->toBe(0)
        ->and($cenario->futebol->refresh()->vagas_confirmadas)->toBe(1)
        ->and($cenario->futebol->vagas_reservadas)->toBe(0)
        ->and(Pagamento::query()->where('situacao', SituacaoPagamento::Pago->value)->count())->toBe(1);
});

it('ignora com elegancia o aviso de uma cobranca que nao existe aqui', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobranca();

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $outra = $gateway->createPayment(cobrancaDeTeste());
    $gateway->simulatePayment($outra->externalId);

    entregarAviso($gateway->emitWebhook($outra->externalId, 'paid'))->assertOk();

    expect(WebhookPagamento::query()->latest('id')->first()->situacao)->toBe(SituacaoWebhook::Ignorado)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(0);
});

it('encerra a cobranca quando o provedor avisa que o prazo venceu', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobranca();

    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $gateway->simulateExpiration($pagamento->id_externo);

    entregarAviso($gateway->emitWebhook($pagamento->id_externo, 'expired'))->assertOk();

    // A cobranca fecha; devolver a vaga e trabalho da rotina de expiracao.
    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Expirado)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and(Pagamento::query()->count())->toBe(1);
});

it('percorre o ciclo completo pela rota de simulacao, como um pagamento de verdade', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobranca();

    test()->postJson("/dev/pagamentos/{$pagamento->id_externo}/pagar")->assertOk();

    expect($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(1);
});

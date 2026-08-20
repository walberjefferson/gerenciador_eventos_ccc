<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentGateway;
use App\Models\WebhookPagamento;

/**
 * A porta de simulacao nao pode existir em producao.
 *
 * Se ela existisse, qualquer pessoa que descobrisse o endereco confirmaria a
 * propria inscricao sem pagar. Por isso ha duas travas: o ambiente e a chave
 * de configuracao — e este arquivo prova as duas.
 */
function idExternoDeUmaCobranca(): string
{
    return app(PaymentGateway::class)->createPayment(cobrancaDeTeste())->externalId;
}

it('responde as rotas de simulacao no ambiente de teste com a chave ligada', function () {
    $idExterno = idExternoDeUmaCobranca();

    test()->getJson("/dev/pagamentos/{$idExterno}")
        ->assertOk()
        ->assertJson(['status' => 'pending']);
});

it('devolve 404 quando a chave de simulacao esta desligada', function () {
    $idExterno = idExternoDeUmaCobranca();

    config(['payments.fake.simulation_enabled' => false]);

    test()->getJson("/dev/pagamentos/{$idExterno}")->assertNotFound();
    test()->postJson("/dev/pagamentos/{$idExterno}/pagar")->assertNotFound();
});

it('devolve 404 fora de local e testing, mesmo com a chave ligada', function () {
    $idExterno = idExternoDeUmaCobranca();

    // O ambiente e a primeira trava: em producao as rotas nem chegam a ser
    // registradas, e o middleware recusa de novo se alguem as registrar.
    app()->instance('env', 'production');

    expect(app()->environment('production'))->toBeTrue();

    test()->getJson("/dev/pagamentos/{$idExterno}")->assertNotFound();
    test()->postJson("/dev/pagamentos/{$idExterno}/pagar")->assertNotFound();
    test()->postJson("/dev/pagamentos/{$idExterno}/estornar")->assertNotFound();
});

it('mantem a porta publica do webhook disponivel em qualquer ambiente', function () {
    app()->instance('env', 'production');

    // Sem assinatura valida o aviso nao produz efeito, mas a resposta e 200
    // (decisao D-18) e a rota existe: e por ela que a instituicao financeira
    // avisa o sistema em producao.
    test()->postJson('/'.ltrim((string) config('payments.webhook.path'), '/'), ['teste' => true])
        ->assertOk();

    expect(WebhookPagamento::query()->where('assinatura_valida', false)->count())->toBe(1);
});

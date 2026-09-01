<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentGateway;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Enums\SituacaoWebhook;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\WebhookPagamento;
use App\Services\Payments\Efi\EfiClient;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use PDOException;
use Tests\Feature\Inscricoes\Cenario;
use Tests\Feature\Pagamentos\Efi\EfiClientFake;

/**
 * O aviso automatico da Efi.
 *
 * O que se prova aqui: a assinatura viaja no endereco e nao no cabecalho, e
 * ainda assim e conferida em tempo constante; um aviso com dois pagamentos
 * gera dois efeitos e nao um; o identificador da transferencia e guardado
 * antes que se perca; a Efi acrescenta um sufixo ao endereco e a rota aceita;
 * aviso forjado recebe 200 e morre; e falha nossa devolve erro, para a Efi
 * reentregar em vez de jogar o aviso fora.
 *
 * Nada disso fala com a Efi: o cliente dela e um duplo.
 */
function prepararEfi(): EfiClientFake
{
    config([
        'payments.default' => 'efi',
        'payments.efi.environment' => 'homologacao',
        'payments.efi.client_id' => 'Client_Id_de_teste',
        'payments.efi.client_secret' => 'Client_Secret_de_teste',
        'payments.efi.pix_key' => '71cdf9ba-c695-4e3c-b010-abb521a3f1be',
        'payments.efi.webhook_hmac' => 'segredo-do-webhook-de-teste',
    ]);

    $cliente = new EfiClientFake;

    app()->instance(EfiClient::class, $cliente);
    app()->forgetInstance(PaymentGateway::class);

    return $cliente;
}

/**
 * Entrega o aviso como a Efi entregaria: corpo cru e a assinatura no endereco.
 *
 * @param  array<string, mixed>  $payload
 */
function entregarAvisoEfi(array $payload, ?string $hmac = 'segredo-do-webhook-de-teste', string $sufixo = ''): TestResponse
{
    $endereco = '/'.ltrim((string) config('payments.webhook.path'), '/').$sufixo;

    if ($hmac !== null) {
        $endereco .= '?hmac='.urlencode($hmac).'&ignorar=';
    }

    return test()->call(
        'POST',
        $endereco,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode($payload),
    );
}

/**
 * @return array<string, mixed>
 */
function pixRecebido(string $txid, string $valor = '125.00', ?string $endToEndId = null): array
{
    return [
        'endToEndId' => $endToEndId ?? 'E18036150202608271200'.mb_substr($txid, -6),
        'txid' => $txid,
        'chave' => '71cdf9ba-c695-4e3c-b010-abb521a3f1be',
        'valor' => $valor,
        'horario' => '2026-08-27T12:00:00.000Z',
        'infoPagador' => 'pagando a inscricao',
    ];
}

/**
 * @return array{0: Cenario, 1: Inscricao, 2: Pagamento}
 */
function cenarioComCobrancaEfi(): array
{
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    return [$cenario, $inscricao, $inscricao->pagamentoPendente()];
}

beforeEach(function () {
    prepararEfi();
});

it('confirma a inscricao quando a efi avisa que o pix caiu', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobrancaEfi();

    expect($pagamento->gateway)->toBe('efi')
        ->and($pagamento->id_externo)->toMatch('/^[a-zA-Z0-9]{26,35}$/');

    entregarAvisoEfi(['pix' => [pixRecebido($pagamento->id_externo)]])
        ->assertOk()
        ->assertJson(['recebido' => true]);

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($pagamento->pago_em)->not->toBeNull()
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($cenario->evento->refresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->evento->vagas_confirmadas)->toBe(1);
});

it('guarda o identificador da transferencia, que so chega neste aviso', function () {
    [, , $pagamento] = cenarioComCobrancaEfi();

    entregarAvisoEfi([
        'pix' => [pixRecebido($pagamento->id_externo, endToEndId: 'E18036150202608271200ABC123')],
    ])->assertOk();

    // Sem coluna nova: metadados ja e jsonb. E ele, e nao o identificador da
    // cobranca, que uma devolucao futura vai exigir.
    expect($pagamento->refresh()->metadados['end_to_end_id'])->toBe('E18036150202608271200ABC123')
        ->and($pagamento->metadados['referencia_externa'])->not->toBeNull();
});

it('guarda quem pagou, com o CPF reduzido ao que serve para conferir', function () {
    [, , $pagamento] = cenarioComCobrancaEfi();

    $aviso = pixRecebido($pagamento->id_externo);
    $aviso['gnExtras'] = ['pagador' => [
        'cpf' => '12345678901',
        'nome' => 'MARIA DE SOUZA',
        'codigoBanco' => '18036150',
    ]];

    entregarAvisoEfi(['pix' => [$aviso]])->assertOk();

    // Comparacao chave a chave, e nao do array inteiro: o jsonb do Postgres
    // devolve os campos na ordem DELE (por tamanho do nome, depois alfabetica),
    // que nunca e a ordem em que foram escritos.
    $pagador = $pagamento->refresh()->metadados['pagador'];

    expect($pagador['nome'])->toBe('MARIA DE SOUZA')
        ->and($pagador['documento'])->toBe('***.456.789-**')
        ->and($pagador['tipo_documento'])->toBe('cpf')
        ->and($pagador['banco'])->toBe('18036150')
        ->and($pagador['mensagem'])->toBe('pagando a inscricao');

    // A prova pelo avesso, e a que mais importa: o numero inteiro nao existe em
    // lugar nenhum do banco. Nem no pagamento, nem no aviso guardado.
    $guardado = (string) json_encode([
        WebhookPagamento::query()->sole()->payload,
        $pagamento->metadados,
    ]);

    expect($guardado)->not->toContain('12345678901')
        ->and($guardado)->not->toContain('123.456.789');
});

it('guarda o CNPJ de quem pagou por inteiro, porque ele e publico', function () {
    [, , $pagamento] = cenarioComCobrancaEfi();

    $aviso = pixRecebido($pagamento->id_externo);
    $aviso['gnExtras'] = ['pagador' => [
        'cnpj' => '09089356000118',
        'nome' => 'CONSULTORIA TECNICA EFI',
        'codigoBanco' => '09089356',
    ]];

    entregarAvisoEfi(['pix' => [$aviso]])->assertOk();

    expect($pagamento->refresh()->metadados['pagador']['documento'])->toBe('09089356000118')
        ->and($pagamento->metadados['pagador']['tipo_documento'])->toBe('cnpj');
});

it('nao inventa pagador quando o aviso nao diz quem pagou', function () {
    [, , $pagamento] = cenarioComCobrancaEfi();

    $aviso = pixRecebido($pagamento->id_externo);
    unset($aviso['infoPagador']);

    entregarAvisoEfi(['pix' => [$aviso]])->assertOk();

    expect($pagamento->refresh()->metadados)->not->toHaveKey('pagador');
});

it('nao perde dinheiro quando um unico aviso traz dois pagamentos', function () {
    [$cenario, $primeira, $pagamentoUm] = cenarioComCobrancaEfi();

    $segunda = $cenario->inscrever($cenario->outraPessoa(7));
    $pagamentoDois = $segunda->pagamentoPendente();

    expect(WebhookPagamento::query()->count())->toBe(0);

    entregarAvisoEfi(['pix' => [
        pixRecebido($pagamentoUm->id_externo),
        pixRecebido($pagamentoDois->id_externo),
    ]])->assertOk();

    // Dois eventos, dois registros, dois efeitos. Guardar so o primeiro
    // deixaria a segunda pessoa pagando e sem vaga.
    expect(WebhookPagamento::query()->count())->toBe(2)
        ->and(WebhookPagamento::query()->where('situacao', SituacaoWebhook::Processado->value)->count())->toBe(2)
        ->and($pagamentoUm->refresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($pagamentoDois->refresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($primeira->refresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($segunda->refresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(2);
});

it('cada registro guarda so o seu pedaco do aviso', function () {
    [$cenario, , $pagamentoUm] = cenarioComCobrancaEfi();
    $pagamentoDois = $cenario->inscrever($cenario->outraPessoa(8))->pagamentoPendente();

    entregarAvisoEfi(['pix' => [
        pixRecebido($pagamentoUm->id_externo),
        pixRecebido($pagamentoDois->id_externo),
    ]])->assertOk();

    $registros = WebhookPagamento::query()->orderBy('id')->get();

    expect($registros[0]->payload['pix'])->toHaveCount(1)
        ->and($registros[0]->payload['pix'][0]['txid'])->toBe($pagamentoUm->id_externo)
        ->and($registros[1]->payload['pix'][0]['txid'])->toBe($pagamentoDois->id_externo)
        // A chave Pix da conta que recebe nao fica guardada em texto claro.
        ->and($registros[0]->payload['pix'][0]['chave'])->toBe('[removido]');
});

it('recebe o aviso tambem no endereco com o sufixo que a efi acrescenta sozinha', function () {
    [, $inscricao, $pagamento] = cenarioComCobrancaEfi();

    entregarAvisoEfi(['pix' => [pixRecebido($pagamento->id_externo)]], sufixo: '/pix')
        ->assertOk();

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::Confirmada);
});

it('nao produz efeito algum com assinatura errada, e ainda assim responde 200', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobrancaEfi();

    // Decisao D-18: responder 401 diria a quem tenta forjar avisos que ele
    // acertou o endereco e errou so a assinatura.
    entregarAvisoEfi(['pix' => [pixRecebido($pagamento->id_externo)]], hmac: 'chute')->assertOk();

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(0);

    $registro = WebhookPagamento::query()->latest('id')->first();

    expect($registro->assinatura_valida)->toBeFalse()
        ->and($registro->situacao)->toBe(SituacaoWebhook::Ignorado);
});

it('recusa o aviso sem assinatura nenhuma', function () {
    [, $inscricao, $pagamento] = cenarioComCobrancaEfi();

    entregarAvisoEfi(['pix' => [pixRecebido($pagamento->id_externo)]], hmac: null)->assertOk();

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and(WebhookPagamento::query()->latest('id')->first()->assinatura_valida)->toBeFalse();
});

it('confere a assinatura em tempo constante, e nunca com comparacao comum', function () {
    $codigo = (string) file_get_contents(app_path('Services/Payments/Efi/EfiPaymentGateway.php'));

    // A comparacao comum para no primeiro caractere diferente, e o tempo que
    // ela leva conta quantos caracteres o chute acertou. Quem tenta forjar o
    // aviso descobre o segredo caractere a caractere.
    expect($codigo)->toContain('hash_equals($segredo, $request->signature)')
        ->and($codigo)->not->toContain('$segredo === $request->signature')
        ->and($codigo)->not->toContain('$segredo == $request->signature')
        ->and($codigo)->not->toContain('$request->signature === $segredo')
        ->and($codigo)->not->toContain('$request->signature == $segredo');
});

it('recusa a assinatura que erra por um caractere so', function () {
    [, $inscricao, $pagamento] = cenarioComCobrancaEfi();

    entregarAvisoEfi(
        ['pix' => [pixRecebido($pagamento->id_externo)]],
        hmac: 'segredo-do-webhook-de-testX',
    )->assertOk();

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and(WebhookPagamento::query()->latest('id')->first()->assinatura_valida)->toBeFalse();
});

it('confirma uma vez so quando a efi reentrega o mesmo aviso', function () {
    [$cenario, $inscricao, $pagamento] = cenarioComCobrancaEfi();

    $aviso = ['pix' => [pixRecebido($pagamento->id_externo, endToEndId: 'E18036150202608271200REPET')]];

    entregarAvisoEfi($aviso)->assertOk();
    $confirmadaEm = $inscricao->refresh()->confirmada_em;

    // A Efi reentrega ate nove vezes quando nao recebe 2XX a tempo. A trava e
    // o identificador da transferencia, que vem igual em toda reentrega.
    entregarAvisoEfi($aviso)->assertOk()->assertJson(['repetido' => true]);

    expect(WebhookPagamento::query()->where('id_evento_externo', 'E18036150202608271200REPET')->count())->toBe(1)
        ->and($inscricao->refresh()->confirmada_em->timestamp)->toBe($confirmadaEm->timestamp)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(1)
        ->and(Pagamento::query()->where('situacao', SituacaoPagamento::Pago->value)->count())->toBe(1);
});

it('ignora com elegancia o aviso de uma cobranca que nao existe aqui', function () {
    [$cenario, $inscricao] = cenarioComCobrancaEfi();

    entregarAvisoEfi(['pix' => [pixRecebido('01JQZZZZZZZZZZZZZZZZZZZZZZ')]])->assertOk();

    expect(WebhookPagamento::query()->latest('id')->first()->situacao)->toBe(SituacaoWebhook::Ignorado)
        ->and($inscricao->refresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($cenario->evento->refresh()->vagas_confirmadas)->toBe(0);
});

it('devolve erro quando a falha e nossa, para a efi reentregar o aviso', function () {
    [, , $pagamento] = cenarioComCobrancaEfi();

    // Banco fora do ar no momento de guardar o aviso. Responder 200 aqui
    // jogaria fora a unica chance de receber o aviso de novo: a Efi tenta ate
    // nove vezes ao longo de cerca de cinco horas, de graca.
    WebhookPagamento::creating(function (): void {
        throw new QueryException(
            'pgsql',
            'insert into "webhooks_pagamento"',
            [],
            new PDOException('server closed the connection unexpectedly'),
        );
    });

    entregarAvisoEfi(['pix' => [pixRecebido($pagamento->id_externo)]])->assertStatus(500);

    expect($pagamento->refresh()->situacao)->toBe(SituacaoPagamento::Pendente);

    WebhookPagamento::flushEventListeners();
});

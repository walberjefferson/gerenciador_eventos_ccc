<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoPagamento;
use App\Exceptions\Payments\EfiException;
use App\Exceptions\Payments\EstornoNaoSuportadoException;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use App\Services\Payments\Efi\EfiClient;
use App\Services\Payments\Efi\EfiPaymentGateway;
use App\Services\Payments\Efi\TraducaoDeStatus;
use Illuminate\Support\Carbon;
use Tests\Feature\Pagamentos\Efi\EfiClientFake;

/**
 * A cobranca pela Efi.
 *
 * O que se prova aqui: a fronteira fala o idioma da instituicao financeira
 * sem deixar nada dele vazar para dentro. Identificador no formato exigido,
 * dinheiro convertido sem erro de centavo, codigo Pix devolvido para a tela,
 * erro traduzido para portugues e sem segredo no texto.
 *
 * Tudo isso roda SEM credencial, SEM certificado e SEM rede: o cliente da Efi
 * e trocado por um duplo. Nao e conveniencia — o SDK usa cliente HTTP proprio,
 * que o Http::fake() do Laravel nao alcanca. Sem o duplo, esta suite nao
 * existiria.
 */
function credenciaisDeMentira(): void
{
    config([
        'payments.efi.environment' => 'homologacao',
        'payments.efi.client_id' => 'Client_Id_de_teste',
        'payments.efi.client_secret' => 'Client_Secret_de_teste',
        'payments.efi.pix_key' => '71cdf9ba-c695-4e3c-b010-abb521a3f1be',
        'payments.efi.webhook_hmac' => 'segredo-do-webhook-de-teste',
    ]);
}

/**
 * @return array{0: EfiPaymentGateway, 1: EfiClientFake}
 */
function gatewayEfi(): array
{
    credenciaisDeMentira();

    $cliente = new EfiClientFake;

    return [new EfiPaymentGateway($cliente, new ConfiguracaoEfi), $cliente];
}

function cobrancaEfi(int $centavos = 12_345): CreatePaymentData
{
    return new CreatePaymentData(
        externalReference: 'INSCRICAO-TESTE',
        amountCents: $centavos,
        currency: 'BRL',
        method: MetodoPagamento::Pix->value,
        description: 'Inscricao no evento de teste',
        payerName: 'Maria da Silva',
        payerEmail: 'maria.silva@example.com',
        payerDocument: '529.982.247-25',
        expiresAt: Carbon::now()->addDay(),
    );
}

it('e escolhido por configuracao, sem o dominio citar o fornecedor', function () {
    credenciaisDeMentira();
    config(['payments.default' => 'efi']);

    app()->forgetInstance(PaymentGateway::class);
    app()->instance(EfiClient::class, new EfiClientFake);

    $gateway = app(PaymentGateway::class);

    expect($gateway)->toBeInstanceOf(EfiPaymentGateway::class)
        ->and($gateway->name())->toBe('efi');
});

it('emite a cobranca com identificador no formato que a efi exige', function () {
    [$gateway, $cliente] = gatewayEfi();

    $resultado = $gateway->createPayment(cobrancaEfi());

    expect($resultado->externalId)->toMatch('/^[a-zA-Z0-9]{26,35}$/')
        ->and($resultado->status)->toBe('pending')
        ->and($resultado->amountCents)->toBe(12_345)
        ->and($cliente->criacoes)->toBe(1);
});

it('gera mil identificadores e nenhum foge do formato nem se repete', function () {
    [$gateway, $cliente] = gatewayEfi();

    for ($i = 0; $i < 1_000; $i++) {
        $gateway->createPayment(cobrancaEfi());
    }

    $gerados = $cliente->txidsRecebidos;

    expect($gerados)->toHaveCount(1_000)
        ->and(array_unique($gerados))->toHaveCount(1_000);

    foreach ($gerados as $txid) {
        expect($txid)->toMatch('/^[a-zA-Z0-9]{26,35}$/');
    }
});

it('converte centavos para o texto decimal da efi sem errar arredondamento', function (int $centavos, string $esperado) {
    [$gateway, $cliente] = gatewayEfi();

    $gateway->createPayment(cobrancaEfi($centavos));

    expect($cliente->corposRecebidos[0]['valor']['original'])->toBe($esperado);
})->with([
    'cinco centavos' => [5, '0.05'],
    'noventa e nove centavos' => [99, '0.99'],
    'cento e vinte e tres reais e quarenta e cinco' => [12_345, '123.45'],
    'mil reais redondos' => [100_000, '1000.00'],
    'um centavo' => [1, '0.01'],
    'dez reais' => [1_000, '10.00'],
]);

it('nao usa numero de ponto flutuante em nenhum ponto do caminho do dinheiro', function () {
    $codigo = file_get_contents(app_path('Services/Payments/Efi/EfiPaymentGateway.php'));

    expect($codigo)->not->toContain('(float)')
        ->and($codigo)->not->toContain('(double)')
        ->and($codigo)->not->toContain('floatval')
        ->and($codigo)->not->toContain('number_format');
});

it('devolve o codigo pix copia e cola da propria resposta da cobranca', function () {
    [$gateway] = gatewayEfi();

    $resultado = $gateway->createPayment(cobrancaEfi());

    expect($resultado->pixPayload)->toContain('br.gov.bcb.pix')
        ->and($resultado->pixPayload)->toContain($resultado->externalId);

    // Nao existe segunda viagem a rede so para buscar o QR Code: o texto do
    // Pix ja vem na resposta da cobranca, e a tela desenha o QR no navegador.
    $codigo = file_get_contents(app_path('Services/Payments/Efi/EfiPaymentGateway.php'));

    expect($codigo)->not->toContain('qrcode')
        ->and($codigo)->not->toContain('QRCode');
});

it('manda os dados que a efi exige de quem vai pagar, com o documento so em numeros', function () {
    [$gateway, $cliente] = gatewayEfi();

    $gateway->createPayment(cobrancaEfi());
    $corpo = $cliente->corposRecebidos[0];

    expect($corpo['devedor']['cpf'])->toBe('52998224725')
        ->and($corpo['devedor']['nome'])->toBe('Maria da Silva')
        ->and($corpo['chave'])->toBe('71cdf9ba-c695-4e3c-b010-abb521a3f1be')
        ->and($corpo['calendario']['expiracao'])->toBeGreaterThan(0)
        ->and($corpo['solicitacaoPagador'])->toContain('Inscricao no evento');
});

it('recusa cobrar de quem nao tem cpf ou cnpj valido', function () {
    [$gateway] = gatewayEfi();

    expect(fn () => $gateway->createPayment(new CreatePaymentData(
        externalReference: 'INSCRICAO-TESTE',
        amountCents: 1_000,
        currency: 'BRL',
        method: MetodoPagamento::Pix->value,
        description: 'Inscricao',
        payerName: 'Sem Documento',
        payerEmail: 'sem@example.com',
        payerDocument: '123',
    )))->toThrow(EfiException::class);
});

it('tenta de novo, uma unica vez, quando a efi diz que o identificador ja existe', function () {
    [$gateway, $cliente] = gatewayEfi();
    $cliente->encomendarTxidDuplicado();

    $resultado = $gateway->createPayment(cobrancaEfi());

    expect($cliente->criacoes)->toBe(2)
        ->and($cliente->txidsRecebidos[0])->not->toBe($cliente->txidsRecebidos[1])
        ->and($resultado->externalId)->toBe($cliente->txidsRecebidos[1]);
});

it('desiste na segunda recusa por identificador repetido, em vez de insistir para sempre', function () {
    [$gateway, $cliente] = gatewayEfi();
    $cliente->encomendarTxidDuplicado();
    $cliente->encomendarTxidDuplicado();

    expect(fn () => $gateway->createPayment(cobrancaEfi()))->toThrow(EfiException::class);
    expect($cliente->criacoes)->toBe(2);
});

it('traduz excesso de requisicoes e queda de rede sem vazar segredo na mensagem', function () {
    [$gateway, $cliente] = gatewayEfi();
    $cliente->encomendarExcessoDeRequisicoes();

    $erro = null;

    try {
        $gateway->createPayment(cobrancaEfi());
    } catch (EfiException $capturado) {
        $erro = $capturado;
    }

    expect($erro)->not->toBeNull()
        ->and($erro->ehExcessoDeRequisicoes())->toBeTrue()
        ->and($erro->getMessage())->toContain('Tente novamente')
        ->and($erro->getMessage())->not->toContain('Client_Id_de_teste')
        ->and($erro->getMessage())->not->toContain('Client_Secret_de_teste')
        ->and($erro->getMessage())->not->toContain('segredo-do-webhook-de-teste');

    $cliente->encomendarQuedaDeRede();

    expect(fn () => $gateway->createPayment(cobrancaEfi()))->toThrow(EfiException::class);
});

it('consulta a cobranca e traduz cada situacao da efi para o vocabulario do dominio', function () {
    [$gateway, $cliente] = gatewayEfi();
    $cobranca = $gateway->createPayment(cobrancaEfi());

    expect($gateway->getPayment($cobranca->externalId)->status)->toBe('pending');

    $cliente->pagar($cobranca->externalId);
    $paga = $gateway->getPayment($cobranca->externalId);

    expect($paga->isPaid())->toBeTrue()
        ->and($paga->paidAt)->not->toBeNull()
        ->and($paga->amountCents)->toBe(12_345)
        ->and(SituacaoPagamento::deStatusExterno($paga->status))->toBe(SituacaoPagamento::Pago);
});

it('continua chamando de pendente a cobranca que a efi ainda mostra como ativa depois do prazo', function () {
    [$gateway, $cliente] = gatewayEfi();

    // A Efi nao tem situacao de cobranca vencida: passado o prazo, a consulta
    // continua respondendo ATIVA. Quem decide que venceu e o prazo da
    // inscricao — traduzir para "expired" aqui fecharia cobranca que a Efi
    // ainda aceita pagar.
    $cobranca = $gateway->createPayment(cobrancaEfi());
    $cliente->cobrancas[$cobranca->externalId]['calendario']['expiracao'] = 1;

    Carbon::setTestNow(Carbon::now()->addDays(3));

    expect($gateway->getPayment($cobranca->externalId)->status)->toBe('pending')
        ->and(TraducaoDeStatus::daCobranca(TraducaoDeStatus::ATIVA))->toBe('pending');

    Carbon::setTestNow();
});

it('cancela a cobranca pedindo a remocao dela, e nao reclama se ela ja estava fechada', function () {
    [$gateway, $cliente] = gatewayEfi();
    $cobranca = $gateway->createPayment(cobrancaEfi());

    $gateway->cancelPayment($cobranca->externalId);

    expect($gateway->getPayment($cobranca->externalId)->status)->toBe('canceled');

    // A Efi recusa remover cobranca que ja saiu do ar. Para quem chamou, o
    // objetivo ja esta cumprido: ninguem consegue mais pagar.
    $cliente->erroNaRemocao = new EfiException(
        'A Efi recusou a operacao: situacao invalida.',
        codigoHttp: 409,
        identificador: 'status_cobranca_invalido',
    );

    expect(fn () => $gateway->cancelPayment($cobranca->externalId))
        ->not->toThrow(EfiException::class);
});

it('deixa subir o erro de verdade quando o cancelamento falha por outro motivo', function () {
    [$gateway, $cliente] = gatewayEfi();
    $cobranca = $gateway->createPayment(cobrancaEfi());

    $cliente->erroNaRemocao = new EfiException(
        'A Efi recusou as credenciais ou o certificado desta aplicacao.',
        codigoHttp: 401,
    );

    expect(fn () => $gateway->cancelPayment($cobranca->externalId))->toThrow(EfiException::class);
});

it('recusa devolver dinheiro em voz alta, porque a politica de reembolso ainda nao existe', function () {
    [$gateway] = gatewayEfi();
    $cobranca = $gateway->createPayment(cobrancaEfi());

    expect(fn () => $gateway->refundPayment($cobranca->externalId))
        ->toThrow(EstornoNaoSuportadoException::class);
});

it('recusa operar sem certificado e sem credencial, dizendo o que falta e sem revelar caminho', function () {
    config([
        'payments.efi.client_id' => '',
        'payments.efi.client_secret' => '',
        'payments.efi.pix_key' => '',
        'payments.efi.cert_path' => '/caminho/secreto/do/certificado.pem',
    ]);

    $configuracao = new ConfiguracaoEfi;
    $erro = null;

    try {
        $configuracao->exigirCompleta();
    } catch (EfiException $capturado) {
        $erro = $capturado;
    }

    expect($erro)->not->toBeNull()
        ->and($erro->getMessage())->toContain('EFI_CERT_PATH')
        ->and($erro->getMessage())->not->toContain('/caminho/secreto')
        ->and($configuracao->estaCompleta())->toBeFalse();
});

it('nunca deixa a url base ser decidida por configuracao', function () {
    config(['payments.efi.environment' => 'producao']);
    expect((new ConfiguracaoEfi)->urlBase())->toBe('https://pix.api.efipay.com.br');

    config(['payments.efi.environment' => 'homologacao']);
    expect((new ConfiguracaoEfi)->urlBase())->toBe('https://pix-h.api.efipay.com.br');

    // Valor desconhecido cai para homologacao: diante de configuracao
    // ambigua, o lado que nao move dinheiro de verdade.
    config(['payments.efi.environment' => 'sei-la']);
    expect((new ConfiguracaoEfi)->urlBase())->toBe('https://pix-h.api.efipay.com.br');
});

it('le toda a configuracao da efi em um lugar so', function () {
    $arquivos = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $arquivo) {
        if ($arquivo->isFile() && $arquivo->getExtension() === 'php') {
            $conteudo = (string) file_get_contents($arquivo->getPathname());

            if (str_contains($conteudo, "config('payments.efi")) {
                $arquivos[] = basename($arquivo->getPathname());
            }
        }
    }

    // E o que torna a fase seguinte barata: trocar a fonte da configuracao do
    // arquivo de ambiente para o banco muda o corpo de uma classe, e mais nada.
    expect($arquivos)->toBe(['ConfiguracaoEfi.php']);
});

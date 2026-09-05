<?php

use App\DTOs\Payments\CreatePaymentData;
use App\Enums\MetodoPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Os dados de uma cobranca qualquer, para os testes que precisam de UMA e nao
 * se importam com qual.
 *
 * Ela mora aqui, e nao dentro do arquivo que a usava primeiro, por um motivo
 * que so aparece quando a suite roda em paralelo: cada processo do paratest
 * carrega apenas os arquivos de teste que vai executar. Uma funcao definida
 * dentro de `PaymentGatewayTest.php` e chamada de `WebhookPagamentoTest.php`
 * funciona por acidente quando tudo roda no mesmo processo — e some no
 * instante em que os dois arquivos caem em processos diferentes.
 *
 * `tests/Pest.php` e carregado por TODOS os processos. E o lugar de qualquer
 * auxiliar que atravesse arquivo.
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

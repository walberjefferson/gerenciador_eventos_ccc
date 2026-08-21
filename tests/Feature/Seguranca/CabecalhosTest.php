<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Os cabecalhos de seguranca — as instrucoes que o navegador obedece antes de
 * qualquer codigo nosso rodar.
 *
 * A parte mais importante e a Content-Security-Policy: e ela que impede um
 * script injetado numa tela de rodar. O teste abaixo trava a decisao que
 * sustenta essa defesa — **nao existe `unsafe-inline` em `script-src`** — para
 * que ninguem a afrouxe um dia so para destravar uma tela quebrada.
 */

/**
 * Le uma diretiva da politica pelo nome, ja separada em pedacos.
 *
 * @return list<string>
 */
function diretivaDaPolitica(string $politica, string $nome): array
{
    foreach (explode(';', $politica) as $pedaco) {
        $pedaco = trim($pedaco);

        if (str_starts_with($pedaco, $nome.' ') || $pedaco === $nome) {
            return array_values(array_filter(explode(' ', $pedaco)));
        }
    }

    return [];
}

function paginaPublica(): TestResponse
{
    $cenario = Cenario::montar();

    return test()->get('/eventos/'.$cenario->evento->slug);
}

it('manda os cabecalhos basicos em toda pagina', function () {
    paginaPublica()
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
});

it('manda os cabecalhos basicos tambem fora do grupo web', function () {
    // A porta do provedor de pagamento nao passa pelo grupo "web". Cabecalho
    // que so existe dentro de um grupo e cabecalho que um dia vai faltar.
    $resposta = test()->call(
        'POST',
        '/'.ltrim((string) config('payments.webhook.path'), '/'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'forjada'],
        json_encode(['event' => 'payment.paid']),
    );

    $resposta->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    // Mas a politica de conteudo nao vai em resposta que nao e pagina: ali ela
    // nao protege nada e so engorda a resposta.
    expect($resposta->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('nao permite script escrito na propria pagina sem numero de uso unico', function () {
    $politica = (string) paginaPublica()->headers->get('Content-Security-Policy');

    $script = diretivaDaPolitica($politica, 'script-src');

    expect($script)->not->toContain("'unsafe-inline'")
        ->and($script)->not->toContain("'unsafe-eval'")
        ->and($script)->toContain("'self'");

    // Existe exatamente um numero de uso unico, sorteado nesta resposta.
    $nonces = array_values(array_filter($script, fn (string $fonte): bool => str_starts_with($fonte, "'nonce-")));

    expect($nonces)->toHaveCount(1);
});

it('sorteia um numero de uso unico diferente a cada resposta', function () {
    $cenario = Cenario::montar();
    $endereco = '/eventos/'.$cenario->evento->slug;

    $primeiro = (string) test()->get($endereco)->headers->get('Content-Security-Policy');
    $segundo = (string) test()->get($endereco)->headers->get('Content-Security-Policy');

    expect($primeiro)->not->toBe($segundo);
});

it('entrega a tabela de rotas da pagina com o mesmo numero de uso unico', function () {
    $resposta = paginaPublica();

    $politica = (string) $resposta->headers->get('Content-Security-Policy');
    $script = diretivaDaPolitica($politica, 'script-src');
    $nonce = '';

    foreach ($script as $fonte) {
        if (str_starts_with($fonte, "'nonce-")) {
            $nonce = trim($fonte, "'");
            $nonce = substr($nonce, strlen('nonce-'));
        }
    }

    expect($nonce)->not->toBe('');

    // Se o Ziggy sair sem o numero da vez, nenhum link do sistema funciona em
    // producao — e nada quebra em desenvolvimento. Por isso a checagem aqui.
    expect($resposta->getContent())->toContain('nonce="'.$nonce.'"');
});

it('fecha as portas que nao interessam a ninguem', function () {
    $politica = (string) paginaPublica()->headers->get('Content-Security-Policy');

    expect($politica)
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("default-src 'self'");
});

it('deixa passar o que a interface realmente usa', function () {
    $politica = (string) paginaPublica()->headers->get('Content-Security-Policy');

    // O QR Code do Pix chega pronto do servidor como SVG dentro do HTML, e as
    // fontes vem do fonts.bunny.net. Sem estas permissoes, a tela de pagamento
    // e a identidade visual quebrariam so em producao.
    expect(diretivaDaPolitica($politica, 'img-src'))->toContain('data:')
        ->and(diretivaDaPolitica($politica, 'font-src'))->toContain('https://fonts.bunny.net')
        ->and(diretivaDaPolitica($politica, 'style-src'))->toContain('https://fonts.bunny.net');
});

it('so promete HTTPS eterno quando a resposta veio por HTTPS', function () {
    $cenario = Cenario::montar();

    // Em desenvolvimento (http), mandar HSTS faria o navegador guardar por um
    // ano que o endereco e sempre seguro — e a pessoa passaria dias sem abrir
    // o proprio ambiente.
    $semTls = test()->get('http://localhost/eventos/'.$cenario->evento->slug);

    expect($semTls->headers->has('Strict-Transport-Security'))->toBeFalse();

    $comTls = test()->get('https://localhost/eventos/'.$cenario->evento->slug);

    expect((string) $comTls->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=31536000')
        ->toContain('includeSubDomains');
});

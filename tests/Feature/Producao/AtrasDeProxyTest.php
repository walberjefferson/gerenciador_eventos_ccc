<?php

declare(strict_types=1);

use App\Models\Inscricao;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O sistema atras do proxy reverso.
 *
 * Em producao o Traefik recebe a requisicao em HTTPS, termina o TLS e repassa
 * ao conteiner em HTTP simples pela rede interna, dizendo em cabecalhos como
 * era a requisicao original ("X-Forwarded-Proto: https", "X-Forwarded-Host").
 *
 * Se o framework nao confiar nesses cabecalhos, ele acredita que a requisicao
 * chegou em http — e o participante e quem paga a conta:
 *
 * - **O link do e-mail para de funcionar.** Quem gera o link e o trabalhador da
 *   fila, fora de qualquer requisicao, usando APP_URL (https). A assinatura
 *   cobre a URL inteira, esquema incluido. Conferida numa requisicao que o
 *   framework le como http, ela nao bate: 403 no link recem-recebido.
 * - **O HSTS nao sai**, porque CabecalhosDeSeguranca so o emite em HTTPS.
 *
 * Estes testes montam exatamente esse cenario: link gerado como o worker
 * geraria, requisicao entregue como o Traefik entregaria.
 */
const DOMINIO_DE_PRODUCAO = 'inscricoes.cccista.com.br';

/**
 * O link como o trabalhador da fila o produz: fora de requisicao, a partir de
 * APP_URL, sempre em https.
 */
function linkGeradoPeloWorker(Inscricao $inscricao): string
{
    // Fora de uma requisicao HTTP, o gerador de URLs monta o endereco a partir
    // de APP_URL — e e assim que o Laravel se inicializa em linha de comando.
    // Reproduzimos exatamente isso, para que o link nasca com o mesmo esquema
    // e o mesmo dominio com que nasce dentro do conteiner do trabalhador.
    config(['app.url' => 'https://'.DOMINIO_DE_PRODUCAO]);
    URL::setRequest(Request::create('https://'.DOMINIO_DE_PRODUCAO));

    $link = URL::temporarySignedRoute(
        'inscricoes.acompanhar',
        Carbon::now()->addDay(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    expect($link)->toStartWith('https://'.DOMINIO_DE_PRODUCAO.'/');

    return $link;
}

/**
 * A mesma URL como ela chega ao conteiner: http na rede interna, com os
 * cabecalhos do Traefik descrevendo a requisicao original.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function comoOTraefikEntrega(string $urlPublica): array
{
    return [
        str_replace('https://', 'http://', $urlPublica),
        [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => DOMINIO_DE_PRODUCAO,
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '203.0.113.7',
        ],
    ];
}

it('valida a URL assinada que o trabalhador gerou quando o proxy diz que a requisicao era https', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    [$url, $cabecalhos] = comoOTraefikEntrega(linkGeradoPeloWorker($inscricao));

    // Nao e 403: a assinatura bateu. Esta e a prova desta fase.
    $this->get($url, $cabecalhos)->assertOk();
});

it('recusa o mesmo link quando a requisicao nao vem marcada como https — o defeito que a configuracao corrige', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    // Mesmo link, entregue sem os cabecalhos do proxy: e assim que o framework
    // enxergava TODA requisicao antes de confiar no Traefik.
    [$url] = comoOTraefikEntrega(linkGeradoPeloWorker($inscricao));

    $this->get($url)->assertForbidden();
});

it('reconhece a requisicao como segura e emite o HSTS atras do proxy', function (): void {
    $resposta = $this->get('http://'.DOMINIO_DE_PRODUCAO.'/', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => DOMINIO_DE_PRODUCAO,
    ]);

    $resposta->assertHeader(
        'Strict-Transport-Security',
        'max-age=31536000; includeSubDomains',
    );
});

it('nao emite HSTS quando a requisicao realmente chega em http', function (): void {
    $this->get('http://'.DOMINIO_DE_PRODUCAO.'/')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('mantem a CSP da fase 9 integra atras do proxy', function (): void {
    $resposta = $this->get('http://'.DOMINIO_DE_PRODUCAO.'/', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => DOMINIO_DE_PRODUCAO,
    ]);

    $politica = (string) $resposta->headers->get('Content-Security-Policy');

    expect($politica)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{24}'/");

    // A defesa inteira depende de script-src nao ter unsafe-inline. O proxy nao
    // pode ter afrouxado isso.
    $script = Str::before(Str::after($politica, 'script-src '), ';');
    expect($script)->not->toContain('unsafe-inline');
});

it('gera as URLs com https atras do proxy', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    [$url, $cabecalhos] = comoOTraefikEntrega(linkGeradoPeloWorker($inscricao));

    $this->get($url, $cabecalhos);

    // Dentro da requisicao, route() precisa continuar produzindo https: e o que
    // vai para os formularios e para os links da propria pagina.
    expect(route('inscricoes.acompanhar', ['codigo_publico' => $inscricao->codigo_publico]))
        ->toStartWith('https://'.DOMINIO_DE_PRODUCAO.'/');
});

it('responde no endpoint de saude usado como healthcheck do conteiner', function (): void {
    $this->get('/up')->assertOk();
});

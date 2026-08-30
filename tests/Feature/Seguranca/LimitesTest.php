<?php

declare(strict_types=1);

use App\Enums\SituacaoWebhook;
use App\Models\User;
use App\Models\WebhookPagamento;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Os limites das portas abertas para a internet.
 *
 * O que se prova aqui nao e "o limite existe", e sim que ele foi calibrado com
 * cabeca: quem estoura le uma explicacao em portugues em vez da pagina crua do
 * framework; o vizinho de IP nao paga pelo excesso alheio; e — o ponto mais
 * delicado — o aviso do provedor de pagamento continua respondendo 200 mesmo a
 * assinatura invalida (D-18), porque ganhar um limite nao pode virar, sem
 * ninguem perceber, um jeito de contar a quem forja aviso que ele acertou o
 * endereco.
 */

/**
 * Um envio qualquer do formulario publico.
 *
 * O conteudo nao importa para o limite: o contador sobe antes de a requisicao
 * chegar na validacao. Manda-se o payload vazio de proposito, para o teste
 * medir o limite e nada mais.
 *
 * @param  array<string, string>  $cabecalhos
 */
function enviarInscricao(array $cabecalhos = []): TestResponse
{
    return test()->postJson('/inscricoes', [], $cabecalhos);
}

function limiteDeInscricaoPorMinuto(): int
{
    return (int) config('inscricoes.limites.criar_por_minuto');
}

describe('POST /inscricoes', function () {
    it('deixa passar a rajada normal de uma familia inteira no mesmo IP', function () {
        $cenario = Cenario::montar();

        // Cinco pessoas da mesma casa, uma atras da outra, pelo mesmo acesso.
        for ($indice = 1; $indice <= 5; $indice++) {
            test()->postJson('/inscricoes', $cenario->outraPessoa($indice))
                ->assertCreated();
        }
    });

    it('recusa em portugues quando o limite por minuto estoura', function () {
        for ($tentativa = 1; $tentativa <= limiteDeInscricaoPorMinuto(); $tentativa++) {
            expect(enviarInscricao()->status())->not->toBe(429);
        }

        $recusa = enviarInscricao();

        $recusa->assertStatus(429)
            ->assertHeader('Retry-After');

        expect((string) $recusa->json('message'))
            ->toContain('inscrições demais')
            ->toContain('Aguarde')
            // Nada de "Too Many Attempts": a pessoa precisa entender o que fazer.
            ->not->toContain('Too Many');
    });

    it('devolve a recusa ao formulario da tela como aviso, e nao como erro cru', function () {
        for ($tentativa = 1; $tentativa <= limiteDeInscricaoPorMinuto(); $tentativa++) {
            enviarInscricao();
        }

        $recusa = test()->post('/inscricoes', [], ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        // O formulario Inertia recebe um erro de campo, que a tela ja sabe
        // mostrar como aviso geral da revisao — sem pagina de erro no meio.
        $recusa->assertRedirect();
        $recusa->assertSessionHasErrors('evento');

        expect((string) session('errors')->first('evento'))->toContain('Aguarde');
    });

    it('nao castiga quem vem de outro endereco', function () {
        for ($tentativa = 1; $tentativa <= limiteDeInscricaoPorMinuto() + 1; $tentativa++) {
            enviarInscricao();
        }

        expect(enviarInscricao()->status())->toBe(429);

        // Outro acesso, outra conta: o limite e por endereco, nao global.
        $vizinho = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->postJson('/inscricoes', []);

        expect($vizinho->status())->not->toBe(429);
    });
});

describe('POST /webhooks/pagamentos', function () {
    it('tem limite por endereco na rota', function () {
        $rota = Route::getRoutes()->getByName('webhooks.pagamentos');

        expect($rota)->not->toBeNull()
            ->and($rota->gatherMiddleware())->toContain('throttle:webhooks-pagamento');
    });

    it('continua respondendo 200 a assinatura invalida, mesmo com o limite ligado (D-18)', function () {
        $caminho = '/'.ltrim((string) config('payments.webhook.path'), '/');

        $resposta = test()->call(
            'POST',
            $caminho,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'assinatura-forjada'],
            json_encode(['event' => 'payment.paid', 'id' => 'evt_forjado']),
        );

        $resposta->assertOk()->assertJson(['recebido' => true]);

        // O aviso forjado ficou registrado como invalido e nao virou trabalho.
        expect(WebhookPagamento::query()->count())->toBe(1)
            ->and(WebhookPagamento::query()->first()->assinatura_valida)->toBeFalse()
            ->and(WebhookPagamento::query()->first()->situacao)->toBe(SituacaoWebhook::Ignorado);
    });

    it('tem teto alto o bastante para um pico legitimo de avisos', function () {
        // Um dia movimentado manda dezenas de confirmacoes seguidas. O teto
        // precisa ficar bem acima disso, senao o limite vira perda de aviso —
        // e aviso perdido e inscricao paga que nao confirma.
        expect((int) config('inscricoes.limites.webhook_por_minuto'))
            ->toBeGreaterThanOrEqual(100);
    });

    it('recusa a enxurrada sem contar nada sobre a assinatura', function () {
        $caminho = '/'.ltrim((string) config('payments.webhook.path'), '/');
        $teto = (int) config('inscricoes.limites.webhook_por_minuto');

        config(['inscricoes.limites.webhook_por_minuto' => 3]);

        $enviar = fn (): TestResponse => test()->call(
            'POST',
            $caminho,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'assinatura-forjada'],
            json_encode(['event' => 'payment.paid']),
        );

        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            $enviar()->assertOk();
        }

        // Estourado o limite, a recusa vem antes de o aviso ser lido: quem
        // forja assinatura nao descobre nada com ela.
        $enviar()->assertStatus(429);

        config(['inscricoes.limites.webhook_por_minuto' => $teto]);
    });
});

describe('login do painel', function () {
    it('barra a insistencia no mesmo e-mail antes de chegar ao limite por IP', function () {
        $usuario = User::factory()->create(['email' => 'gestor@exemplo.test']);

        // O limite proprio do Laravel: cinco tentativas por e-mail.
        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            test()->post('/login', ['email' => $usuario->email, 'password' => 'senha-errada']);
        }

        $recusa = test()->post('/login', ['email' => $usuario->email, 'password' => 'senha-errada']);

        $recusa->assertSessionHasErrors('email');

        // A tela de login do painel ainda e a do kit inicial do Laravel, em
        // ingles, e a recusa acompanha esse texto. E coerente com a tela e nao
        // e uma porta publica: quem le isso e a organizacao, nao o participante.
        // A traducao do painel inteiro e assunto de outra fase.
        expect((string) session('errors')->first('email'))
            ->toContain('Too many login attempts');
    });

    it('barra quem varre e-mails diferentes do mesmo endereco', function () {
        $teto = (int) config('inscricoes.limites.login_por_minuto');

        // Cada tentativa com um e-mail novo escapa do limite por e-mail; o
        // teto por IP e o unico que enxerga a varredura.
        for ($tentativa = 1; $tentativa <= $teto; $tentativa++) {
            $resposta = test()->post('/login', [
                'email' => "alvo{$tentativa}@exemplo.test",
                'password' => 'chute',
            ]);

            expect($resposta->status())->not->toBe(429);
        }

        test()->post('/login', ['email' => 'alvo-final@exemplo.test', 'password' => 'chute'])
            ->assertStatus(429);
    });
});

describe('recuperacao de acesso (D-48)', function () {
    it('mantem o teto grosso da rota intacto', function () {
        $rota = Route::getRoutes()->getByName('inscricoes.acesso.enviar');

        // O limite que vale e o contado dentro do controller, para preservar a
        // resposta neutra. O da rota e so um teto contra enxurrada, e nao pode
        // ser apertado sem quebrar a neutralidade.
        expect($rota->gatherMiddleware())->toContain('throttle:60,1');
    });
});

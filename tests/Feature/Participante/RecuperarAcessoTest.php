<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Http\Controllers\AcessoInscricaoController;
use App\Mail\LinkDeAcessoInscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A recuperacao do link de acesso.
 *
 * O teste central nao e "o e-mail chegou": e "a resposta e exatamente a mesma
 * com e sem inscricao". Se um dia alguem fizer a tela dizer "nao encontramos
 * esse e-mail", este arquivo tem de ficar vermelho.
 */
beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('acesso-ip-minuto:127.0.0.1');
    RateLimiter::clear('acesso-ip-hora:127.0.0.1');
});

it('mostra o formulario de acesso', function (): void {
    $this->get('/acesso')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inscricoes/RecuperarAcesso')
            ->where('evento', null)
            ->where('mensagem', null)
        );
});

it('usa o evento da vitrine apenas como contexto', function (): void {
    $cenario = Cenario::montar();

    $this->get('/acesso?evento='.$cenario->evento->slug)
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inscricoes/RecuperarAcesso')
            ->where('evento.nome', $cenario->evento->nome)
            ->where('evento.slug', $cenario->evento->slug)
            ->etc()
        );
});

it('responde a mesma coisa com e sem inscricao para o e-mail informado', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $comInscricao = $this->post('/acesso', ['email' => $inscricao->email]);
    $semInscricao = $this->post('/acesso', ['email' => 'ninguem-por-aqui@example.com']);

    expect($semInscricao->getStatusCode())->toBe($comInscricao->getStatusCode())
        ->and($semInscricao->headers->get('Location'))->toBe($comInscricao->headers->get('Location'));

    $comInscricao->assertSessionHas('mensagem', AcessoInscricaoController::MENSAGEM_NEUTRA);
    $semInscricao->assertSessionHas('mensagem', AcessoInscricaoController::MENSAGEM_NEUTRA);
});

it('envia um unico e-mail listando as inscricoes daquele endereco', function (): void {
    $primeira = Cenario::montar()->inscrever();

    // A mesma pessoa inscrita em outro evento: uma mensagem so precisa dar
    // conta das duas.
    $segunda = Cenario::montar()->inscrever([
        'email' => $primeira->email,
        'documento' => Cenario::cpfValido(4242),
    ]);

    expect($segunda->id)->not->toBe($primeira->id);

    $this->post('/acesso', ['email' => $primeira->email])->assertRedirect();

    Mail::assertSentCount(1);
    Mail::assertSent(LinkDeAcessoInscricao::class, function (LinkDeAcessoInscricao $email) use ($primeira): bool {
        return $email->hasTo($primeira->email) && count($email->inscricoes) === 2;
    });
});

it('nao envia nada quando nao ha inscricao para o e-mail', function (): void {
    Cenario::montar()->inscrever();

    $this->post('/acesso', ['email' => 'nao-existe@example.com'])
        ->assertRedirect()
        ->assertSessionHas('mensagem', AcessoInscricaoController::MENSAGEM_NEUTRA);

    Mail::assertNothingSent();
});

it('deixa a inscricao cancelada de fora do e-mail', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => Carbon::now(),
    ]);

    $this->post('/acesso', ['email' => $inscricao->email])
        ->assertRedirect()
        ->assertSessionHas('mensagem', AcessoInscricaoController::MENSAGEM_NEUTRA);

    Mail::assertNothingSent();
});

it('manda um link assinado que abre a pagina de acompanhamento', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->post('/acesso', ['email' => $inscricao->email])->assertRedirect();

    $link = null;

    Mail::assertSent(LinkDeAcessoInscricao::class, function (LinkDeAcessoInscricao $email) use (&$link): bool {
        $link = $email->inscricoes[0]['link'];

        return true;
    });

    expect($link)->toContain('/acompanhar')->toContain('signature=');

    $this->get((string) $link)
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->component('Inscricoes/Acompanhar')->etc());
});

it('o link enviado para de valer depois do prazo de validade', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->post('/acesso', ['email' => $inscricao->email])->assertRedirect();

    $link = null;
    Mail::assertSent(LinkDeAcessoInscricao::class, function (LinkDeAcessoInscricao $email) use (&$link): bool {
        $link = $email->inscricoes[0]['link'];

        return true;
    });

    $validade = (int) config('inscricoes.validade_link_acesso');

    $this->travel($validade - 1)->days();
    $this->get((string) $link)->assertOk();

    $this->travel(2)->days();
    $this->get((string) $link)->assertForbidden();

    $this->travelBack();
});

it('nao leva dado pessoal alem do necessario dentro do e-mail', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->post('/acesso', ['email' => $inscricao->email])->assertRedirect();

    Mail::assertSent(LinkDeAcessoInscricao::class, function (LinkDeAcessoInscricao $email) use ($inscricao): bool {
        $corpo = $email->render();

        expect($corpo)
            ->not->toContain('52998224725')
            ->not->toContain('529.982.247-25')
            ->not->toContain((string) $inscricao->telefone)
            ->not->toContain((string) $inscricao->valor_centavos);

        return true;
    });
});

it('mantem a resposta neutra depois de estourar o limite de tentativas', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $limite = (int) explode(',', (string) config('inscricoes.limites.acesso_por_minuto'))[0];

    for ($tentativa = 0; $tentativa < $limite; $tentativa++) {
        $this->post('/acesso', ['email' => $inscricao->email])
            ->assertRedirect()
            ->assertSessionHas('mensagem', AcessoInscricaoController::MENSAGEM_NEUTRA);
    }

    Mail::assertSentCount($limite);

    $this->post('/acesso', ['email' => $inscricao->email])
        ->assertRedirect()
        ->assertSessionHas('mensagem', AcessoInscricaoController::MENSAGEM_NEUTRA);

    // Passado o limite, nada mais e enviado — e a tela nao conta isso.
    Mail::assertSentCount($limite);
});

it('recusa e-mail com formato invalido, sem enviar nada', function (): void {
    $this->from('/acesso')
        ->post('/acesso', ['email' => 'isso-nao-e-email'])
        ->assertRedirect('/acesso')
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});

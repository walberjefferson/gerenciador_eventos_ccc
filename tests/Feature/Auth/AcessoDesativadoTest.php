<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Admin\Cenario;

/**
 * Conta desativada nao entra — e cai se ja estava dentro.
 *
 * Sao duas conferencias porque sao dois momentos diferentes, e uma nao
 * substitui a outra: barrar so no login deixaria quem esta com a tela aberta
 * continuar trabalhando ate resolver sair, e desativar alguem costuma ser
 * urgente exatamente quando essa pessoa esta com a tela aberta.
 */
it('recusa o login de conta desativada com a mesma frase de senha errada', function (): void {
    $desativado = User::factory()->create();
    $desativado->forceFill(['ativo' => false])->save();

    $comSenhaErrada = $this->post('/login', [
        'email' => User::factory()->create()->email,
        'password' => 'senha-errada',
    ]);

    $desativadoTentando = $this->post('/login', [
        'email' => $desativado->email,
        'password' => 'password',
    ]);

    $this->assertGuest();

    // A mesma mensagem, no mesmo campo, com o mesmo codigo. A tela de login
    // nao pode virar um verificador de e-mails: dizer "sua conta foi
    // desativada" conta a quem tenta adivinhar senha que aquele e-mail existe.
    $desativadoTentando->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $comSenhaErrada->assertSessionHasErrors(['email' => trans('auth.failed')]);

    expect($desativadoTentando->getStatusCode())->toBe($comSenhaErrada->getStatusCode());
});

it('deixa entrar quem esta ativo', function (): void {
    $usuario = User::factory()->create();

    $this->post('/login', ['email' => $usuario->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($usuario);
});

it('volta a deixar entrar quando a conta e reativada', function (): void {
    $usuario = User::factory()->create();
    $usuario->forceFill(['ativo' => false])->save();

    $this->post('/login', ['email' => $usuario->email, 'password' => 'password']);
    $this->assertGuest();

    $usuario->forceFill(['ativo' => true])->save();

    $this->post('/login', ['email' => $usuario->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($usuario);
});

it('derruba na requisicao seguinte a sessao de quem foi desativado com a tela aberta', function (): void {
    Cenario::semearPapeis();

    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)->get('/admin/painel')->assertOk();

    // Alguem desativou a conta enquanto a tela estava aberta.
    $administrador->forceFill(['ativo' => false])->save();

    $this->actingAs($administrador)->get('/admin/painel')->assertRedirect('/login');

    $this->assertGuest();
});

it('derruba a sessao tambem fora do painel, em qualquer rota autenticada', function (): void {
    // A trava mora no apelido "auth", e nao numa lista de rotas: rota nova
    // nasce protegida sem ninguem precisar lembrar disso.
    $usuario = User::factory()->create();
    $usuario->forceFill(['ativo' => false])->save();

    $this->actingAs($usuario)->get('/settings/profile')->assertRedirect('/login');

    $this->assertGuest();
});

it('mantem o apelido auth valendo para toda rota administrativa', function (): void {
    $rotas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($rota): bool => str_starts_with((string) $rota->getName(), 'admin.'));

    expect($rotas)->not->toBeEmpty();

    // Nenhuma rota administrativa pode ficar de fora: o "auth" delas e o
    // apelido que tambem confere se a conta continua ativa.
    $semAuth = $rotas
        ->reject(fn ($rota): bool => in_array('auth', $rota->gatherMiddleware(), true))
        ->map(fn ($rota): string => (string) $rota->getName())
        ->values()
        ->all();

    expect($semAuth)->toBe([]);
});

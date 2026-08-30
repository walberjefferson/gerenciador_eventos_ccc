<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Admin\Cenario;

beforeEach(function (): void {
    Cenario::semearPapeis();
});

it('cria a conta com o papel de administrador', function (): void {
    $this->artisan('usuario:criar-administrador', ['email' => 'maria@exemplo.test', '--nome' => 'Maria'])
        ->expectsQuestion('Senha (nao aparece na tela, minimo de 8 caracteres)', 'senha-bem-comprida')
        ->expectsOutputToContain('Conta criada para maria@exemplo.test')
        ->assertSuccessful();

    $usuario = User::query()->where('email', 'maria@exemplo.test')->sole();

    expect($usuario->name)->toBe('Maria')
        ->and($usuario->hasRole('administrador'))->toBeTrue()
        ->and($usuario->email_verified_at)->not->toBeNull()
        ->and(Hash::check('senha-bem-comprida', $usuario->password))->toBeTrue();
});

it('aceita criar um organizador', function (): void {
    $this->artisan('usuario:criar-administrador', [
        'email' => 'joao@exemplo.test',
        '--nome' => 'Joao',
        '--papel' => 'organizador',
    ])
        ->expectsQuestion('Senha (nao aparece na tela, minimo de 8 caracteres)', 'senha-bem-comprida')
        ->assertSuccessful();

    $usuario = User::query()->where('email', 'joao@exemplo.test')->sole();

    expect($usuario->hasRole('organizador'))->toBeTrue()
        ->and($usuario->can('pagamentos.confirmar-manual'))->toBeFalse();
});

it('recusa e-mail que ja tem conta', function (): void {
    User::factory()->create(['email' => 'repetido@exemplo.test']);

    $this->artisan('usuario:criar-administrador', ['email' => 'repetido@exemplo.test', '--nome' => 'Outro'])
        ->expectsOutputToContain('Ja existe uma conta com o e-mail repetido@exemplo.test')
        ->assertFailed();

    expect(User::query()->where('email', 'repetido@exemplo.test')->count())->toBe(1);
});

it('recusa papel que nao existe', function (): void {
    $this->artisan('usuario:criar-administrador', [
        'email' => 'ninguem@exemplo.test',
        '--nome' => 'Ninguem',
        '--papel' => 'tesoureiro',
    ])
        ->expectsOutputToContain('O papel "tesoureiro" nao existe')
        ->assertFailed();

    expect(User::query()->where('email', 'ninguem@exemplo.test')->exists())->toBeFalse();
});

it('recusa senha curta demais', function (): void {
    $this->artisan('usuario:criar-administrador', ['email' => 'curta@exemplo.test', '--nome' => 'Curta'])
        ->expectsQuestion('Senha (nao aparece na tela, minimo de 8 caracteres)', '123')
        ->expectsOutputToContain('ao menos 8 caracteres')
        ->assertFailed();

    expect(User::query()->where('email', 'curta@exemplo.test')->exists())->toBeFalse();
});

it('nunca ecoa a senha na saida do comando', function (): void {
    $this->artisan('usuario:criar-administrador', ['email' => 'silencio@exemplo.test', '--nome' => 'Silencio'])
        ->expectsQuestion('Senha (nao aparece na tela, minimo de 8 caracteres)', 'senha-bem-comprida')
        ->doesntExpectOutputToContain('senha-bem-comprida')
        ->assertSuccessful();
});

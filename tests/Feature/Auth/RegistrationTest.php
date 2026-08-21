<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * O cadastro publico foi fechado (DA-11).
 *
 * Este arquivo existia para provar que qualquer visitante conseguia criar
 * conta. Agora prova o contrario: a porta nao existe mais. Um teste que
 * guarda a porta fechada vale mais do que a ausencia de teste — se alguem
 * reintroduzir a rota sem querer, a suite avisa.
 */
it('nao tem mais tela de cadastro publico', function (): void {
    $this->get('/register')->assertNotFound();
});

it('recusa o envio de um cadastro publico', function (): void {
    $this->post('/register', [
        'name' => 'Visitante Curioso',
        'email' => 'curioso@exemplo.test',
        'password' => 'senha-secreta',
        'password_confirmation' => 'senha-secreta',
    ])->assertNotFound();

    expect(User::query()->where('email', 'curioso@exemplo.test')->exists())->toBeFalse();
    $this->assertGuest();
});

it('nao conhece mais a rota chamada register', function (): void {
    expect(Route::has('register'))->toBeFalse();
});

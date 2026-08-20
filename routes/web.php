<?php

use App\Http\Controllers\EventoPublicoController;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\InscricaoPublicaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Pagina publica do evento. Aberta a qualquer visitante, sem login.
Route::get('eventos/{slug}', [EventoPublicoController::class, 'show'])->name('eventos.show');

// Formulario publico de inscricao, em quatro etapas na propria tela.
Route::get('eventos/{slug}/inscricao', [InscricaoPublicaController::class, 'create'])->name('inscricoes.criar');

// Envio do formulario de inscricao. Responde JSON: as telas publicas entram
// na fase do site do participante.
Route::post('inscricoes', [InscricaoController::class, 'store'])->name('inscricoes.store');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

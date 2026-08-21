<?php

use App\Http\Controllers\AcessoInscricaoController;
use App\Http\Controllers\AcompanhamentoController;
use App\Http\Controllers\Admin\PainelController;
use App\Http\Controllers\EventoPublicoController;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\InscricaoPublicaController;
use App\Http\Controllers\PagamentoController;
use App\Http\Controllers\SegundaViaPagamentoController;
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

// Cobranca Pix. O codigo publico nunca autentica sozinho: sem a assinatura
// valida na URL, o middleware responde 403.
Route::get('inscricoes/{codigo_publico}/pagamento', [PagamentoController::class, 'show'])
    ->middleware('signed')
    ->name('inscricoes.pagamento');

// Consulta curta que a tela da cobranca faz de tempos em tempos para saber se
// o pagamento foi reconhecido. Tambem assinada, pelo mesmo motivo.
Route::get('inscricoes/{codigo_publico}/situacao', [PagamentoController::class, 'situacao'])
    ->middleware('signed')
    ->name('inscricoes.situacao');

// A pagina do participante: linha do tempo, historico da cobranca e o
// caminho de volta ao Pix. Assinada, pelo mesmo motivo da cobranca.
Route::get('inscricoes/{codigo_publico}/acompanhar', [AcompanhamentoController::class, 'show'])
    ->middleware('signed')
    ->name('inscricoes.acompanhar');

// Segunda via do Pix, a pedido do participante. Assinada e com limite de
// tentativas: a Action e idempotente, mas ninguem pede cobranca em serie.
Route::post('inscricoes/{codigo_publico}/segunda-via', [SegundaViaPagamentoController::class, 'store'])
    ->middleware(['signed', 'throttle:'.config('inscricoes.limites.segunda_via')])
    ->name('inscricoes.segunda-via');

// Recuperacao do link de acesso. O limite de tentativas por IP e por e-mail
// e contado dentro do controller, para que a resposta continue neutra; o
// throttle da rota e apenas um teto grosso contra enxurrada de pedidos.
Route::get('acesso', [AcessoInscricaoController::class, 'create'])->name('inscricoes.acesso');

Route::post('acesso', [AcessoInscricaoController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('inscricoes.acesso.enviar');

/*
|--------------------------------------------------------------------------
| Lado administrativo
|--------------------------------------------------------------------------
|
| Tres travas, nesta ordem: "auth" exige estar logado, "verified" exige o
| e-mail confirmado e "permission" exige a permissao daquela tela. Nenhuma
| rota daqui pode ficar so com "auth": estar logado nao diz o que a pessoa
| pode fazer.
|
*/
Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('painel', [PainelController::class, 'index'])
            ->middleware('permission:painel.ver')
            ->name('painel');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

<?php

use App\Http\Controllers\AcessoInscricaoController;
use App\Http\Controllers\AcompanhamentoController;
use App\Http\Controllers\Admin\AcaoInscricaoController;
use App\Http\Controllers\Admin\AtividadeController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\CidadeController;
use App\Http\Controllers\Admin\ConflitoAtividadeController;
use App\Http\Controllers\Admin\DiaEventoController;
use App\Http\Controllers\Admin\EventoController;
use App\Http\Controllers\Admin\ExportarInscricoesController;
use App\Http\Controllers\Admin\GrupoAtividadeController;
use App\Http\Controllers\Admin\GrupoParticipanteController;
use App\Http\Controllers\Admin\InscricaoAdminController;
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
//
// O limite por IP e definido em AppServiceProvider, com resposta em portugues:
// e a unica porta publica de escrita sem assinatura na URL, entao e por ela que
// um script tentaria criar inscricao em serie.
Route::post('inscricoes', [InscricaoController::class, 'store'])
    ->middleware('throttle:inscricoes')
    ->name('inscricoes.store');

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

        // Catalogo global: cidades e grupos de participantes. Sao listas que
        // valem para todos os eventos, por isso vivem sob a mesma permissao.
        Route::middleware('permission:catalogo.gerenciar')
            ->prefix('catalogo')
            ->name('catalogo.')
            ->group(function (): void {
                Route::get('cidades', [CidadeController::class, 'index'])->name('cidades');
                Route::post('cidades', [CidadeController::class, 'store'])->name('cidades.store');
                Route::put('cidades/{cidade}', [CidadeController::class, 'update'])->name('cidades.update');
                Route::delete('cidades/{cidade}', [CidadeController::class, 'destroy'])->name('cidades.destroy');

                Route::get('grupos-participantes', [GrupoParticipanteController::class, 'index'])
                    ->name('grupos-participantes');
                Route::post('grupos-participantes', [GrupoParticipanteController::class, 'store'])
                    ->name('grupos-participantes.store');
                Route::put('grupos-participantes/{grupo_participante}', [GrupoParticipanteController::class, 'update'])
                    ->name('grupos-participantes.update');
                Route::delete('grupos-participantes/{grupo_participante}', [GrupoParticipanteController::class, 'destroy'])
                    ->name('grupos-participantes.destroy');
            });

        // Estrutura do evento. Tudo o que pendura no evento — dias, grupos,
        // atividades e conflitos — vive dentro da URL dele, para que nenhuma
        // tela alcance a programacao de outro evento trocando um numero.
        Route::middleware('permission:eventos.gerenciar')
            ->prefix('eventos')
            ->name('eventos.')
            ->group(function (): void {
                Route::get('/', [EventoController::class, 'index'])->name('index');
                Route::get('novo', [EventoController::class, 'create'])->name('create');
                Route::post('/', [EventoController::class, 'store'])->name('store');
                Route::get('{evento}/editar', [EventoController::class, 'edit'])->name('edit');
                Route::put('{evento}', [EventoController::class, 'update'])->name('update');
                Route::delete('{evento}', [EventoController::class, 'destroy'])->name('destroy');

                Route::get('{evento}/estrutura', [EventoController::class, 'estrutura'])->name('estrutura');

                Route::post('{evento}/dias', [DiaEventoController::class, 'store'])->name('dias.store');
                Route::put('{evento}/dias/{dia_evento}', [DiaEventoController::class, 'update'])->name('dias.update');
                Route::delete('{evento}/dias/{dia_evento}', [DiaEventoController::class, 'destroy'])->name('dias.destroy');

                Route::post('{evento}/grupos', [GrupoAtividadeController::class, 'store'])->name('grupos.store');
                Route::put('{evento}/grupos/{grupo_atividade}', [GrupoAtividadeController::class, 'update'])->name('grupos.update');
                Route::delete('{evento}/grupos/{grupo_atividade}', [GrupoAtividadeController::class, 'destroy'])->name('grupos.destroy');

                Route::post('{evento}/atividades', [AtividadeController::class, 'store'])->name('atividades.store');
                Route::put('{evento}/atividades/{atividade}', [AtividadeController::class, 'update'])->name('atividades.update');
                Route::delete('{evento}/atividades/{atividade}', [AtividadeController::class, 'destroy'])->name('atividades.destroy');

                Route::post('{evento}/conflitos', [ConflitoAtividadeController::class, 'store'])->name('conflitos.store');
                Route::delete('{evento}/conflitos/{conflito_atividade}', [ConflitoAtividadeController::class, 'destroy'])
                    ->name('conflitos.destroy');
            });

        // A lista de inscricoes. Ver e uma permissao; agir sobre uma inscricao
        // sao outras, cobradas mais adiante, perto de cada acao.
        Route::middleware('permission:inscricoes.ver')
            ->prefix('inscricoes')
            ->name('inscricoes.')
            ->group(function (): void {
                Route::get('/', [InscricaoAdminController::class, 'index'])->name('index');

                // Antes da rota com {inscricao}: senao "exportar" seria lido
                // como o codigo de uma inscricao que nao existe.
                Route::get('exportar', ExportarInscricoesController::class)
                    ->middleware('permission:inscricoes.exportar')
                    ->name('exportar');

                Route::get('{inscricao}', [InscricaoAdminController::class, 'show'])->name('show');

                // Cancelar devolve vaga; confirmar na mao declara que entrou
                // dinheiro. Cada uma cobra a sua propria permissao, e a
                // confirmacao manual e exclusiva do administrador (DA-13).
                Route::post('{inscricao}/cancelar', [AcaoInscricaoController::class, 'cancelar'])
                    ->middleware('permission:inscricoes.cancelar')
                    ->name('cancelar');

                Route::post('{inscricao}/confirmar-pagamento', [AcaoInscricaoController::class, 'confirmarPagamento'])
                    ->middleware('permission:pagamentos.confirmar-manual')
                    ->name('confirmar-pagamento');
            });

        // O rastro das acoes administrativas. So leitura, e so administrador:
        // nao existe rota para criar, alterar nem apagar registro de auditoria.
        Route::get('auditoria', [AuditoriaController::class, 'index'])
            ->middleware('permission:auditoria.ver')
            ->name('auditoria');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

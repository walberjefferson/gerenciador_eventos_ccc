<?php

use App\Http\Controllers\AcessoInscricaoController;
use App\Http\Controllers\AcompanhamentoController;
use App\Http\Controllers\Admin\AcaoInscricaoController;
use App\Http\Controllers\Admin\AtividadeController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\AvisosPagamentoController;
use App\Http\Controllers\Admin\CidadeController;
use App\Http\Controllers\Admin\ConflitoAtividadeController;
use App\Http\Controllers\Admin\CredenciaisPagamentoController;
use App\Http\Controllers\Admin\DiaEventoController;
use App\Http\Controllers\Admin\EventoController;
use App\Http\Controllers\Admin\ExportarInscricoesController;
use App\Http\Controllers\Admin\GrupoAtividadeController;
use App\Http\Controllers\Admin\GrupoParticipanteController;
use App\Http\Controllers\Admin\InscricaoAdminController;
use App\Http\Controllers\Admin\PainelController;
use App\Http\Controllers\Admin\PapelController;
use App\Http\Controllers\Admin\PortariaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\EventoPublicoController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IngressoParticipanteController;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\InscricaoPublicaController;
use App\Http\Controllers\PagamentoController;
use App\Http\Controllers\SegundaViaPagamentoController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A porta da rua: qual e o evento, quando e e como se inscrever. O nome
// "home" da rota e usado pelas telas de autenticacao do starter kit.
Route::get('/', HomeController::class)->name('home');

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

// O ingresso em PDF, para imprimir e levar. Assinada, como todas as do
// participante — e, dentro do controller, so entrega a quem esta confirmado.
Route::get('inscricoes/{codigo_publico}/ingresso', [IngressoParticipanteController::class, 'show'])
    ->middleware('signed')
    ->name('inscricoes.ingresso');

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
        // A porta de entrada do painel. Ela EXISTE como rota, e nao como um
        // `Route::redirect` fixo, por duas razoes:
        //
        // 1. O destino depende do papel. Quem tem "painel.ver" vai para os
        //    numeros do evento; quem so tem o portao vai para a portaria. Com o
        //    desvio fixo, o voluntario da portaria entrava pelo endereco mais
        //    obvio do sistema e levava 403.
        // 2. O `Route::redirect` herdava o prefixo de nome do grupo e virava
        //    uma rota `admin.` sem permissao nenhuma — a unica do painel nessa
        //    condicao. O AutorizacaoTest existe justamente para pegar isso.
        //
        // O `permission:` com barra vertical e um OU: basta uma das duas. Quem
        // nao tem nenhuma nao tem destino no painel, e recebe 403 aqui mesmo.
        Route::get('/', [PainelController::class, 'entrada'])
            ->middleware('permission:painel.ver|presenca.registrar')
            ->name('inicio');

        Route::get('painel', [PainelController::class, 'index'])
            ->middleware('permission:painel.ver')
            ->name('painel');

        // O portao, no dia do evento. E a unica tela que o papel "portaria"
        // alcanca, e ela cobra permissoes diferentes em cada acao:
        //
        // - ver e conferir pedem "presenca.registrar";
        // - desfazer pede "presenca.desfazer", que a portaria NAO tem (o
        //   motivo esta escrito no PapeisSeeder).
        //
        // A conferencia leva throttle. O codigo tem ~60 bits de entropia e
        // adivinhar um valido por tentativa e inviavel, mas rota de conferencia
        // sem limite e convite a varredura — e o teto e alto o bastante para
        // dois voluntarios conferindo sem parar no mesmo portao.
        Route::prefix('portaria')
            ->name('portaria.')
            ->group(function (): void {
                Route::get('/', [PortariaController::class, 'index'])
                    ->middleware('permission:presenca.registrar')
                    ->name('index');

                Route::post('validar', [PortariaController::class, 'validar'])
                    ->middleware([
                        'permission:presenca.registrar',
                        'throttle:'.config('inscricoes.limites.validar_ingresso'),
                    ])
                    ->name('validar');

                Route::post('ingressos/{ingresso}/desfazer', [PortariaController::class, 'desfazer'])
                    ->middleware('permission:presenca.desfazer')
                    ->name('desfazer');
            });

        // Catalogo global: setores e grupos de participantes. Sao listas que
        // valem para todos os eventos, por isso vivem sob a mesma permissao.
        //
        // A URL e o parametro dizem "setor", que e como a comunidade chama isso.
        // O Model, a tabela e a coluna continuam sendo `Cidade`/`cidades`/
        // `cidade_id`: o renome nao atravessa para o banco. O type-hint
        // `Cidade $setor` resolve porque o binding do Laravel casa pelo NOME do
        // parametro, nao pelo da classe.
        //
        // Nao ha redirecionamento de `catalogo/cidades`: o sistema nao esta
        // publicado e ninguem tem esse endereco guardado.
        Route::middleware('permission:catalogo.gerenciar')
            ->prefix('catalogo')
            ->name('catalogo.')
            ->group(function (): void {
                Route::get('setores', [CidadeController::class, 'index'])->name('setores');
                Route::post('setores', [CidadeController::class, 'store'])->name('setores.store');
                Route::put('setores/{setor}', [CidadeController::class, 'update'])->name('setores.update');
                Route::delete('setores/{setor}', [CidadeController::class, 'destroy'])->name('setores.destroy');

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

        // Quem entra no painel, com que papel, e ate quando. A permissao
        // "usuarios.gerenciar" existe desde a Fase 6a e ate aqui nenhuma rota a
        // cobrava: ela nasceu junto com o papel de administrador e ficou orfa
        // enquanto promover alguem so acontecia por comando no servidor.
        //
        // O CADASTRO PELA TELA EXISTE desde que o dono do produto reverteu a
        // D-51 nesta parte: quem responde pelo sistema passou a cadastrar a
        // equipe sem depender de alguem com acesso ao container. O comando
        // `php artisan usuario:criar-administrador` CONTINUA, e continua sendo
        // o unico caminho para a PRIMEIRA conta — sem ninguem cadastrado, nao
        // ha quem abra esta tela.
        //
        // NAO HA ROTA DE EXCLUSAO, e a ausencia e decisao: usuario nao se
        // apaga, porque a auditoria guarda `usuario_id` e apagar deixaria o
        // rastro apontando para o vazio. Quem sai da equipe e DESATIVADO.
        Route::middleware('permission:usuarios.gerenciar')
            ->prefix('usuarios')
            ->name('usuarios.')
            ->group(function (): void {
                Route::get('/', [UsuarioController::class, 'index'])->name('index');

                Route::post('/', [UsuarioController::class, 'store'])->name('store');

                // Nome, e-mail, papel e — se vier preenchida — a senha, no
                // mesmo envio. O papel continua passando pela mesma trava da
                // rota dedicada abaixo: ele nao entra por uma porta mais fraca
                // so por estar num formulario maior.
                Route::put('{usuario}', [UsuarioController::class, 'update'])->name('update');

                // O caminho preferido para "nao consigo entrar": manda o link
                // de redefinicao sem que ninguem chegue a saber a senha alheia.
                Route::post('{usuario}/redefinir-senha', [UsuarioController::class, 'enviarRedefinicao'])
                    ->name('redefinir-senha');

                Route::put('{usuario}/papel', [UsuarioController::class, 'atualizarPapel'])->name('papel');

                // Situacao vai em rota propria, e nao junto do papel: sao duas
                // decisoes diferentes, e desativar alguem nao pode acontecer de
                // carona numa troca de papel.
                Route::put('{usuario}/situacao', [UsuarioController::class, 'atualizarSituacao'])->name('situacao');
            });

        // O que cada papel alcanca. So leitura: papel e permissao nascem no
        // PapeisSeeder, nao na tela (D-50).
        Route::get('papeis', [PapelController::class, 'index'])
            ->middleware('permission:usuarios.gerenciar')
            ->name('papeis');

        // O rastro das acoes administrativas. So leitura, e so administrador:
        // nao existe rota para criar, alterar nem apagar registro de auditoria.
        Route::get('auditoria', [AuditoriaController::class, 'index'])
            ->middleware('permission:auditoria.ver')
            ->name('auditoria');

        // Os avisos que o provedor de pagamento mandou. So leitura, e so
        // administrador: nao ha rota de escrita nenhuma aqui — nem para
        // reprocessar. Esta tela le o que o webhook ja gravou.
        //
        // Vem antes do grupo de credenciais so por leitura: as duas comecam
        // com "pagamentos/", e ler as duas juntas mostra que sao portas
        // diferentes, com permissoes diferentes.
        Route::get('pagamentos/avisos', [AvisosPagamentoController::class, 'index'])
            ->middleware('permission:pagamentos.avisos-ver')
            ->name('pagamentos.avisos');

        // A credencial do provedor de pagamento. E a porta mais estreita do
        // painel: "pagamentos.credenciais" so existe no papel administrador,
        // e quem organiza o evento recebe 403 — nao uma tela vazia.
        //
        // O ambiente vai na URL, e nao no corpo, para que o rastro do servidor
        // registre em qual deles se mexeu mesmo quando o envio falha.
        Route::middleware('permission:pagamentos.credenciais')
            ->prefix('pagamentos/credenciais')
            ->name('pagamentos.credenciais')
            ->group(function (): void {
                Route::get('/', [CredenciaisPagamentoController::class, 'index']);

                Route::post('{ambiente}', [CredenciaisPagamentoController::class, 'salvar'])
                    ->name('.salvar');

                // Trocar o ambiente ativo. A confirmacao explicita e cobrada
                // no controller, e nao so na tela.
                Route::post('{ambiente}/ativar', [CredenciaisPagamentoController::class, 'ativar'])
                    ->name('.ativar');

                Route::post('{ambiente}/testar', [CredenciaisPagamentoController::class, 'testar'])
                    ->name('.testar');
            });
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

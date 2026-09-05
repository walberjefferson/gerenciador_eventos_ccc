<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CidadeRequest;
use App\Models\Cidade;
use App\Models\Responsavel;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * O catalogo de setores.
 *
 * A classe, o Model e a tabela continuam se chamando "cidade": o renome para
 * "setor" vale para a tela e para a URL, nao para o banco. Por isso o
 * parametro se chama `$setor` e o tipo dele e `Cidade`.
 *
 * Lista simples, com uma regra que importa mais que todas as outras: setor que
 * ja esta em uso nao e apagado. A chave estrangeira do banco recusaria de
 * qualquer jeito, mas com uma mensagem que ninguem entende — entao a recusa
 * acontece aqui, em portugues, com a saida certa oferecida junto: desativar.
 *
 * Setor desativado some do formulario publico e continua valendo para quem ja
 * se inscreveu. Nada de historico se perde.
 */
class CidadeController extends Controller
{
    use RegistraAuditoria;

    public function index(): Response
    {
        $this->authorize('viewAny', Cidade::class);

        return inertia('Admin/Catalogo/Setores', [
            'cidades' => Cidade::query()
                ->withCount('gruposParticipantes')
                // Os responsaveis vem junto porque a tela mostra quem atende
                // cada setor E porque `estaPreparadaParaReceber()` le a relacao
                // carregada: sem isto seria uma consulta por linha da lista.
                ->with('responsaveis:id,nome,chave_pix,ativo')
                ->orderBy('uf')
                ->orderBy('nome')
                ->get()
                ->map(fn (Cidade $cidade): array => [
                    'id' => $cidade->id,
                    'nome' => $cidade->nome,
                    'uf' => $cidade->uf,
                    'ativo' => $cidade->ativo,
                    'grupos' => $cidade->grupos_participantes_count,
                    'responsaveis' => $cidade->responsaveis
                        ->map(fn (Responsavel $responsavel): array => [
                            'id' => (int) $responsavel->id,
                            'nome' => $responsavel->nome,
                            'apto' => $responsavel->estaApto(),
                        ])
                        ->all(),
                    'preparado_para_receber' => $cidade->estaPreparadaParaReceber(),
                ])
                ->all(),
            // Todo o cadastro de responsaveis, para a tela oferecer o vinculo.
            // A chave e LIDA — e dela que sai a resposta de "esta apto?" —, mas
            // nao viaja para a tela: aqui ela nao seria mostrada, e o que nao e
            // mostrado nao precisa sair do servidor. Quem quer ver chave abre
            // Catalogo -> Responsaveis.
            'responsaveis' => Responsavel::query()
                ->orderBy('nome')
                ->get(['id', 'nome', 'ativo', 'chave_pix'])
                ->map(fn (Responsavel $responsavel): array => [
                    'id' => (int) $responsavel->id,
                    'nome' => $responsavel->nome,
                    'ativo' => $responsavel->ativo,
                    'apto' => $responsavel->estaApto(),
                ])
                ->all(),
            'ufs' => CidadeRequest::UFS,
            'sucesso' => session('sucesso'),
        ]);
    }

    public function store(CidadeRequest $request): RedirectResponse
    {
        $this->authorize('create', Cidade::class);

        $setor = Cidade::create($request->dadosDaCidade());
        $setor->responsaveis()->sync($request->responsaveis());

        // A entidade da auditoria continua sendo 'cidade': ela e a chave do
        // rastro ja gravado, e renomea-la partiria o historico em dois.
        $this->auditarCriacao($setor, 'cidade');

        return back()->with('sucesso', "Setor {$setor->nome} cadastrado.");
    }

    public function update(CidadeRequest $request, Cidade $setor): RedirectResponse
    {
        $this->authorize('update', $setor);

        $antes = $setor->getRawOriginal();

        $setor->update($request->dadosDaCidade());

        // O vinculo so e mexido quando a tela mandou a lista. Um formulario que
        // nao fala de responsaveis nao pode esvaziar o setor por omissao —
        // seria tirar o setor inteiro do ar sem ninguem pedir.
        if ($request->has('responsaveis')) {
            $setor->responsaveis()->sync($request->responsaveis());
        }

        $this->auditarAlteracao($setor, $antes, 'cidade');

        return back()->with('sucesso', "Setor {$setor->nome} atualizado.");
    }

    public function destroy(Cidade $setor): RedirectResponse
    {
        $this->authorize('delete', $setor);

        $grupos = $setor->gruposParticipantes()->count();

        if ($grupos > 0) {
            return back()->withErrors([
                'exclusao' => "Este setor não pode ser excluído porque tem {$grupos} grupo(s) de participantes ligado(s) a ele. "
                    .'Desative o setor para que ele pare de aparecer no formulário, sem apagar o histórico de quem já se inscreveu.',
            ]);
        }

        $nome = $setor->nome;

        $setor->delete();

        $this->auditarRemocao($setor, 'cidade');

        return back()->with('sucesso', "Setor {$nome} excluído.");
    }
}

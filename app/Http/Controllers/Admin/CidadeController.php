<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CidadeRequest;
use App\Models\Cidade;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * O catalogo de cidades.
 *
 * Lista simples, com uma regra que importa mais que todas as outras: cidade
 * que ja esta em uso nao e apagada. A chave estrangeira do banco recusaria de
 * qualquer jeito, mas com uma mensagem que ninguem entende — entao a recusa
 * acontece aqui, em portugues, com a saida certa oferecida junto: desativar.
 *
 * Cidade desativada some do formulario publico e continua valendo para quem ja
 * se inscreveu. Nada de historico se perde.
 */
class CidadeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Cidade::class);

        return inertia('Admin/Catalogo/Cidades', [
            'cidades' => Cidade::query()
                ->withCount('gruposParticipantes')
                ->orderBy('uf')
                ->orderBy('nome')
                ->get()
                ->map(fn (Cidade $cidade): array => [
                    'id' => $cidade->id,
                    'nome' => $cidade->nome,
                    'uf' => $cidade->uf,
                    'ativo' => $cidade->ativo,
                    'grupos' => $cidade->grupos_participantes_count,
                ])
                ->all(),
            'ufs' => CidadeRequest::UFS,
            'sucesso' => session('sucesso'),
        ]);
    }

    public function store(CidadeRequest $request): RedirectResponse
    {
        $this->authorize('create', Cidade::class);

        $cidade = Cidade::create($request->dadosDaCidade());

        return back()->with('sucesso', "Cidade {$cidade->nome} cadastrada.");
    }

    public function update(CidadeRequest $request, Cidade $cidade): RedirectResponse
    {
        $this->authorize('update', $cidade);

        $cidade->update($request->dadosDaCidade());

        return back()->with('sucesso', "Cidade {$cidade->nome} atualizada.");
    }

    public function destroy(Cidade $cidade): RedirectResponse
    {
        $this->authorize('delete', $cidade);

        $grupos = $cidade->gruposParticipantes()->count();

        if ($grupos > 0) {
            return back()->withErrors([
                'exclusao' => "Esta cidade não pode ser excluída porque tem {$grupos} grupo(s) de participantes ligado(s) a ela. "
                    .'Desative a cidade para que ela pare de aparecer no formulário, sem apagar o histórico de quem já se inscreveu.',
            ]);
        }

        $nome = $cidade->nome;

        $cidade->delete();

        return back()->with('sucesso', "Cidade {$nome} excluída.");
    }
}

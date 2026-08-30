<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrupoParticipanteRequest;
use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * O catalogo de grupos de participantes.
 *
 * Mesma ideia dos setores, com um detalhe a mais: grupo que ja tem gente
 * inscrita nao e apagado de jeito nenhum. Apagar o grupo apagaria a resposta
 * que a pessoa deu no formulario, e neste sistema historico nao se perde.
 *
 * As props continuam se chamando `cidades` e `cidade_id`, como as colunas: o
 * renome para "setor" vale para o que a pessoa le, nao para o contrato. E aqui
 * o rotulo ainda traz a UF, porque esta e a tela onde ela e cadastrada.
 */
class GrupoParticipanteController extends Controller
{
    use RegistraAuditoria;

    public function index(): Response
    {
        $this->authorize('viewAny', GrupoParticipante::class);

        return inertia('Admin/Catalogo/GruposParticipantes', [
            'grupos' => GrupoParticipante::query()
                ->with('cidade')
                ->withCount('inscricoes')
                ->orderBy('nome')
                ->get()
                ->map(fn (GrupoParticipante $grupo): array => [
                    'id' => $grupo->id,
                    'nome' => $grupo->nome,
                    'ativo' => $grupo->ativo,
                    'cidade_id' => $grupo->cidade_id,
                    // O setor do grupo, como a tela de catalogo o mostra.
                    'cidade' => $grupo->cidade === null ? '' : $grupo->cidade->nome.'/'.$grupo->cidade->uf,
                    'inscricoes' => $grupo->inscricoes_count,
                ])
                ->all(),
            'cidades' => Cidade::query()
                ->orderBy('uf')
                ->orderBy('nome')
                ->get()
                ->map(fn (Cidade $cidade): array => [
                    'id' => $cidade->id,
                    'nome' => $cidade->nome.'/'.$cidade->uf,
                    'ativo' => $cidade->ativo,
                ])
                ->all(),
            'sucesso' => session('sucesso'),
        ]);
    }

    public function store(GrupoParticipanteRequest $request): RedirectResponse
    {
        $this->authorize('create', GrupoParticipante::class);

        $grupo = GrupoParticipante::create($request->dadosDoGrupo());

        $this->auditarCriacao($grupo, 'grupo-participante');

        return back()->with('sucesso', "Grupo {$grupo->nome} cadastrado.");
    }

    public function update(GrupoParticipanteRequest $request, GrupoParticipante $grupoParticipante): RedirectResponse
    {
        $this->authorize('update', $grupoParticipante);

        $antes = $grupoParticipante->getRawOriginal();

        $grupoParticipante->update($request->dadosDoGrupo());

        $this->auditarAlteracao($grupoParticipante, $antes, 'grupo-participante');

        return back()->with('sucesso', "Grupo {$grupoParticipante->nome} atualizado.");
    }

    public function destroy(GrupoParticipante $grupoParticipante): RedirectResponse
    {
        $this->authorize('delete', $grupoParticipante);

        $inscricoes = $grupoParticipante->inscricoes()->count();

        if ($inscricoes > 0) {
            return back()->withErrors([
                'exclusao' => "Este grupo não pode ser excluído porque {$inscricoes} inscrição(ões) apontam para ele. "
                    .'Desative o grupo: ele some do formulário e o histórico de quem já se inscreveu continua inteiro.',
            ]);
        }

        $nome = $grupoParticipante->nome;

        $grupoParticipante->delete();

        $this->auditarRemocao($grupoParticipante, 'grupo-participante');

        return back()->with('sucesso', "Grupo {$nome} excluído.");
    }
}

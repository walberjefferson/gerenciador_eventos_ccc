<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\CuidaDaEstruturaDoEvento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AtividadeRequest;
use App\Models\Atividade;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Illuminate\Http\RedirectResponse;

/**
 * As atividades de um grupo.
 *
 * Atividade e o que a pessoa escolhe de fato: tem horario, capacidade e, as
 * vezes, faixa de idade. Reduzir a capacidade abaixo do que ja esta ocupado e
 * recusado no formulario, e o banco recusaria de novo — ninguem perde vaga que
 * ja tem.
 */
class AtividadeController extends Controller
{
    use CuidaDaEstruturaDoEvento;

    public function store(AtividadeRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $this->eventoDoGrupo($request->integer('grupo_atividade_id')));

        Atividade::create($request->dadosDaAtividade());

        return back()->with('sucesso', 'Atividade acrescentada.');
    }

    public function update(AtividadeRequest $request, Evento $evento, Atividade $atividade): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $this->eventoDaAtividade($atividade));

        $atividade->update($request->dadosDaAtividade());

        return back()->with('sucesso', 'Atividade atualizada.');
    }

    public function destroy(Evento $evento, Atividade $atividade): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $this->eventoDaAtividade($atividade));

        $motivo = $this->motivoParaNaoExcluir([(int) $atividade->id], 'esta atividade');

        if ($motivo !== null) {
            return back()->withErrors(['exclusao' => $motivo]);
        }

        $atividade->delete();

        return back()->with('sucesso', 'Atividade excluída.');
    }

    private function eventoDoGrupo(int $grupoId): ?int
    {
        return GrupoAtividade::query()->with('diaEvento')->find($grupoId)?->diaEvento?->evento_id;
    }

    private function eventoDaAtividade(Atividade $atividade): ?int
    {
        return $atividade->grupoAtividade()->with('diaEvento')->first()?->diaEvento?->evento_id;
    }
}

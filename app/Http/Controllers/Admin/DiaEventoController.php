<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\CuidaDaEstruturaDoEvento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiaEventoRequest;
use App\Models\DiaEvento;
use App\Models\Evento;
use Illuminate\Http\RedirectResponse;

/**
 * Os dias de um evento.
 *
 * Cada dia e uma data com programacao propria. A posicao ordena a leitura na
 * tela do participante e nao se repete dentro do evento — o banco cobra isso e
 * o formulario avisa antes.
 */
class DiaEventoController extends Controller
{
    use CuidaDaEstruturaDoEvento;

    public function store(DiaEventoRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        DiaEvento::create($request->dadosDoDia());

        return back()->with('sucesso', 'Dia acrescentado à programação.');
    }

    public function update(DiaEventoRequest $request, Evento $evento, DiaEvento $diaEvento): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $diaEvento->evento_id);

        $diaEvento->update($request->dadosDoDia());

        return back()->with('sucesso', 'Dia atualizado.');
    }

    public function destroy(Evento $evento, DiaEvento $diaEvento): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $diaEvento->evento_id);

        $atividadeIds = $diaEvento->gruposAtividades()
            ->with('atividades')
            ->get()
            ->flatMap(fn ($grupo) => $grupo->atividades->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $motivo = $this->motivoParaNaoExcluir($atividadeIds, 'este dia');

        if ($motivo !== null) {
            return back()->withErrors(['exclusao' => $motivo]);
        }

        $diaEvento->delete();

        return back()->with('sucesso', 'Dia excluído.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\CuidaDaEstruturaDoEvento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConflitoAtividadeRequest;
use App\Models\Atividade;
use App\Models\ConflitoAtividade;
use App\Models\Evento;
use Illuminate\Http\RedirectResponse;

/**
 * Os conflitos entre atividades.
 *
 * Conflito e um par que ninguem pode escolher junto — por horario, por espaco
 * ou por combinacao que a organizacao nao quer. O par e guardado normalizado,
 * do menor identificador para o maior, para que (3, 7) e (7, 3) sejam sempre a
 * mesma linha.
 *
 * Apagar um conflito nao apaga escolha de ninguem: ele so deixa de barrar
 * escolhas futuras. Por isso e a unica peca da estrutura que pode ser removida
 * sem cerimonia.
 */
class ConflitoAtividadeController extends Controller
{
    use CuidaDaEstruturaDoEvento;

    public function store(ConflitoAtividadeRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        [$a] = $request->parNormalizado();

        $this->confirmarQueEDoEvento($evento, $this->eventoDaAtividade($a));

        ConflitoAtividade::create($request->dadosDoConflito());

        return back()->with('sucesso', 'Conflito cadastrado.');
    }

    public function destroy(Evento $evento, ConflitoAtividade $conflitoAtividade): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $this->eventoDaAtividade((int) $conflitoAtividade->atividade_a_id));

        $conflitoAtividade->delete();

        return back()->with('sucesso', 'Conflito removido.');
    }

    private function eventoDaAtividade(int $atividadeId): ?int
    {
        return Atividade::query()
            ->with('grupoAtividade.diaEvento')
            ->find($atividadeId)?->grupoAtividade?->diaEvento?->evento_id;
    }
}

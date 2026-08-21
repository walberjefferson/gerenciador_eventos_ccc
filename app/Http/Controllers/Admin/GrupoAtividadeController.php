<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\CuidaDaEstruturaDoEvento;
use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrupoAtividadeRequest;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Illuminate\Http\RedirectResponse;

/**
 * Os grupos de atividades de um dia.
 *
 * O grupo e o que diz "escolha de 1 a 2 modalidades" ou "trilha, se quiser".
 * Sao esses numeros que o formulario do participante obedece, entao errar aqui
 * quebra a inscricao de todo mundo.
 */
class GrupoAtividadeController extends Controller
{
    use CuidaDaEstruturaDoEvento;
    use RegistraAuditoria;

    public function store(GrupoAtividadeRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $grupo = new GrupoAtividade($request->dadosDoGrupo());

        $this->confirmarQueEDoEvento($evento, $this->eventoDoDia($request->integer('dia_evento_id')));

        $grupo->save();

        $this->auditarCriacao($grupo, 'grupo-atividade');

        return back()->with('sucesso', 'Grupo acrescentado.');
    }

    public function update(GrupoAtividadeRequest $request, Evento $evento, GrupoAtividade $grupoAtividade): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $grupoAtividade->diaEvento?->evento_id);

        $antes = $grupoAtividade->getRawOriginal();

        $grupoAtividade->update($request->dadosDoGrupo());

        $this->auditarAlteracao($grupoAtividade, $antes, 'grupo-atividade');

        return back()->with('sucesso', 'Grupo atualizado.');
    }

    public function destroy(Evento $evento, GrupoAtividade $grupoAtividade): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $grupoAtividade->diaEvento?->evento_id);

        $atividadeIds = $grupoAtividade->atividades()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $motivo = $this->motivoParaNaoExcluir($atividadeIds, 'este grupo');

        if ($motivo !== null) {
            return back()->withErrors(['exclusao' => $motivo]);
        }

        $grupoAtividade->delete();

        $this->auditarRemocao($grupoAtividade, 'grupo-atividade');

        return back()->with('sucesso', 'Grupo excluído.');
    }

    private function eventoDoDia(int $diaEventoId): ?int
    {
        $dia = DiaEvento::query()->find($diaEventoId);

        return $dia?->evento_id;
    }
}

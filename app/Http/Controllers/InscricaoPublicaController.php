<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoEvento;
use App\Http\Resources\CidadeResource;
use App\Http\Resources\EventoPublicoResource;
use App\Http\Resources\GrupoParticipanteResource;
use App\Models\Cidade;
use App\Models\ConflitoAtividade;
use App\Models\Evento;
use App\Models\GrupoParticipante;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulario publico de inscricao.
 *
 * Só monta a tela: entrega o evento, as listas de escolha e os pares de
 * atividades que nao combinam. Nenhuma regra e decidida aqui — a tela usa
 * estes dados para explicar antes, e o POST /inscricoes continua sendo a
 * unica autoridade sobre quem entra.
 */
class InscricaoPublicaController extends Controller
{
    public function create(string $slug): Response|RedirectResponse
    {
        $evento = Evento::query()
            ->peloSlug($slug)
            ->whereNotIn('situacao', [
                SituacaoEvento::Rascunho->value,
                SituacaoEvento::Cancelado->value,
            ])
            ->with([
                'diasEvento' => fn ($dias) => $dias->ativos(),
                'diasEvento.gruposAtividades' => fn ($grupos) => $grupos->ativos(),
                'diasEvento.gruposAtividades.atividades' => fn ($atividades) => $atividades->ativos(),
            ])
            ->firstOrFail();

        // Inscricao fechada nao tem formulario: a pagina do evento e quem
        // explica o motivo, e e para la que o visitante volta.
        if (! $evento->inscricoesEstaoAbertas() || ! $evento->temVagaDisponivel()) {
            return redirect()->route('eventos.show', ['slug' => $evento->slug]);
        }

        $cidades = Cidade::query()->ativos()->orderBy('nome')->orderBy('uf')->get();

        $grupos = GrupoParticipante::query()
            ->ativos()
            ->whereIn('cidade_id', $cidades->modelKeys())
            ->orderBy('nome')
            ->get();

        return Inertia::render('Inscricoes/Criar', [
            'evento' => new EventoPublicoResource($evento),
            // O formulario precisa do identificador para o POST; o resto do
            // evento continua chegando pelo Resource publico.
            'evento_id' => $evento->id,
            'cidades' => CidadeResource::collection($cidades)->resolve(),
            'grupos_participantes' => GrupoParticipanteResource::collection($grupos)->resolve(),
            'conflitos' => $this->conflitosDoEvento($evento),
        ]);
    }

    /**
     * Pares de atividades declaradas como incompativeis. O banco guarda o par
     * normalizado (menor id primeiro); a tela cuida dos dois sentidos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conflitosDoEvento(Evento $evento): array
    {
        $atividadeIds = $evento->diasEvento
            ->flatMap(fn ($dia) => $dia->gruposAtividades)
            ->flatMap(fn ($grupo) => $grupo->atividades)
            ->pluck('id')
            ->all();

        if ($atividadeIds === []) {
            return [];
        }

        return ConflitoAtividade::query()
            ->entreAtividades($atividadeIds)
            ->get()
            ->map(fn (ConflitoAtividade $conflito): array => [
                'atividade_a_id' => $conflito->atividade_a_id,
                'atividade_b_id' => $conflito->atividade_b_id,
                'motivo' => $conflito->motivo,
            ])
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoEvento;
use App\Http\Resources\EventoPublicoResource;
use App\Models\Evento;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagina publica do evento: a vitrine que qualquer visitante ve, sem login.
 *
 * Nao decide nada de negocio; apenas le o evento e o entrega ja traduzido
 * para a tela.
 */
class EventoPublicoController extends Controller
{
    /**
     * Evento em rascunho ou cancelado nao existe para o publico: responde 404,
     * e nao uma pagina explicando que existe mas esta escondido.
     */
    public function show(string $slug): Response
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

        return Inertia::render('Eventos/Show', [
            'evento' => new EventoPublicoResource($evento),
        ]);
    }
}

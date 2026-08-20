<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiaEvento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um dia de programacao com seus blocos de atividades.
 *
 * @mixin DiaEvento
 */
class DiaEventoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'data' => $this->data->toDateString(),
            'data_rotulo' => $this->data->format('d/m/Y'),
            'posicao' => $this->posicao,
            'grupos' => GrupoAtividadeResource::collection($this->whenLoaded('gruposAtividades')),
        ];
    }
}

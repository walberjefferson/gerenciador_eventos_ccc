<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GrupoParticipante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Grupo de participantes. Vai com a cidade junto para a tela conseguir
 * mostrar apenas os grupos da cidade escolhida, sem uma segunda requisicao.
 *
 * @mixin GrupoParticipante
 */
class GrupoParticipanteResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cidade_id' => $this->cidade_id,
            'nome' => $this->nome,
        ];
    }
}

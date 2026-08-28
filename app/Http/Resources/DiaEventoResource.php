<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiaEvento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            // "Sábado · 17/10" — o dia da semana entra porque e por ele que
            // alguem sabe se consegue ir; a data sozinha obriga a abrir o
            // calendario para descobrir isso.
            'quando' => $this->diaEmPalavras(),
            'posicao' => $this->posicao,
            'grupos' => GrupoAtividadeResource::collection($this->whenLoaded('gruposAtividades')),
        ];
    }

    private function diaEmPalavras(): string
    {
        $semana = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

        return Str::ucfirst($semana[(int) $this->data->dayOfWeek]).' · '.$this->data->format('d/m');
    }
}

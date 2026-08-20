<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Cidade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cidade como ela aparece na lista do formulario, com o rotulo ja montado
 * para o participante nao precisar adivinhar a UF.
 *
 * @mixin Cidade
 */
class CidadeResource extends JsonResource
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
            'nome' => $this->nome,
            'uf' => $this->uf,
            'rotulo' => $this->nome.' ('.$this->uf.')',
        ];
    }
}

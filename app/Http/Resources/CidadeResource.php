<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Cidade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O setor como ele aparece na lista do formulario.
 *
 * O Model continua sendo `Cidade` e a tabela, `cidades`: o renome para "setor"
 * vale para o que a pessoa le, nao para o banco.
 *
 * O `rotulo` e SO O NOME. A UF vinha junto para desambiguar cidades homonimas
 * de estados diferentes — "Franca (SP)" e "Franca (MG)". Os cinco setores da
 * comunidade sao todos de Alagoas e nenhum repete nome, entao o "(AL)" no fim
 * de cada linha da lista so acrescentava ruido. A coluna `uf` continua sendo
 * enviada, para quem precisar dela.
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
            'rotulo' => $this->nome,
        ];
    }
}

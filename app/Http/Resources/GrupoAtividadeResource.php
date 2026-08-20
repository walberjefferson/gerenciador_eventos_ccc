<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GrupoAtividade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bloco de atividades de um dia, com a regra de escolha ja escrita em
 * portugues simples para a tela apenas exibir. A regra continua sendo do
 * dominio: aqui so a traduzimos.
 *
 * @mixin GrupoAtividade
 */
class GrupoAtividadeResource extends JsonResource
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
            'obrigatorio' => $this->obrigatorio,
            'min_selecoes' => $this->min_selecoes,
            'max_selecoes' => $this->max_selecoes,
            'regra_rotulo' => $this->regraEmPalavras(),
            'atividades' => AtividadeResource::collection($this->whenLoaded('atividades')),
        ];
    }

    /**
     * Traduz min_selecoes e max_selecoes para uma frase que qualquer pessoa
     * entenda na primeira leitura.
     */
    private function regraEmPalavras(): string
    {
        $minimo = $this->min_selecoes;
        $maximo = $this->max_selecoes;

        if ($maximo !== null && $maximo === $minimo && $minimo > 0) {
            return 'Escolha '.$this->quantidade($minimo).'.';
        }

        if ($minimo > 0 && $maximo !== null) {
            return 'Escolha de '.$minimo.' a '.$this->quantidade($maximo).'.';
        }

        if ($minimo > 0) {
            return 'Escolha ao menos '.$this->quantidade($minimo).'.';
        }

        if ($maximo !== null) {
            return 'Escolha até '.$this->quantidade($maximo).'. Este bloco é opcional.';
        }

        return 'Escolha quantas quiser. Este bloco é opcional.';
    }

    private function quantidade(int $numero): string
    {
        return $numero === 1 ? '1 atividade' : $numero.' atividades';
    }
}

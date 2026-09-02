<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Atividade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Atividade como o visitante a enxerga.
 *
 * Os contadores vagas_reservadas e vagas_confirmadas sao internos e nunca
 * saem daqui: o que a tela recebe e o numero de vagas ainda disponiveis, ja
 * calculado pelo modelo.
 *
 * @mixin Atividade
 */
class AtividadeResource extends JsonResource
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
            // Os três nascem nulos quando a atividade não tem hora marcada: a
            // tela não escreve "a definir" nem um travessão, ela simplesmente
            // não escreve horário nenhum.
            'comeca_em' => $this->comeca_em?->toIso8601String(),
            'termina_em' => $this->termina_em?->toIso8601String(),
            'horario_rotulo' => $this->rotuloDoHorario(),
            // O dia em que a atividade acontece, em AAAA-MM-DD. Vem pronto do
            // servidor porque a tela precisa dele para a idade e para o choque
            // de dia inteiro, e porque sem horário não há de onde tirá-lo.
            'data' => $this->resource->data()->toDateString(),
            'capacidade' => $this->capacidade,
            'vagas_disponiveis' => $this->vagasDisponiveis(),
            'esgotado' => ! $this->temVagaDisponivel(),
            'idade_minima' => $this->idade_minima,
            'idade_maxima' => $this->idade_maxima,
        ];
    }

    /** "09:00 às 11:00", ou nada quando a atividade não tem hora marcada. */
    private function rotuloDoHorario(): ?string
    {
        if (! $this->resource->temHorario()) {
            return null;
        }

        return $this->comeca_em->format('H:i').' às '.$this->termina_em->format('H:i');
    }
}

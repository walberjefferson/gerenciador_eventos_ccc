<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Atividade;
use App\Models\Inscricao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A inscricao como o proprio participante a enxerga na pagina de
 * acompanhamento.
 *
 * O documento e a impressao digital dele nunca saem daqui — nem por engano,
 * porque a lista de campos e explicita. O e-mail e o telefone tambem ficam de
 * fora: quem abre o link nao precisa deles para acompanhar, e link que
 * circula em caixa de entrada nao pode virar ficha cadastral.
 *
 * @mixin Inscricao
 */
class InscricaoAcompanhamentoResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'codigo_publico' => $this->codigo_publico,
            'nome_completo' => $this->nome_completo,
            'situacao' => $this->situacao->value,
            'situacao_rotulo' => $this->situacao->rotulo(),
            'valor_centavos' => $this->valor_centavos,
            'moeda' => $this->evento?->moeda ?? 'BRL',
            'criada_em' => $this->created_at?->toIso8601String(),
            'prazo_pagamento' => $this->prazo_pagamento?->toIso8601String(),
            'confirmada_em' => $this->confirmada_em?->toIso8601String(),
            'expirada_em' => $this->expirada_em?->toIso8601String(),
            'cancelada_em' => $this->cancelada_em?->toIso8601String(),
            'motivo_cancelamento' => $this->motivo_cancelamento,
            'evento' => [
                'nome' => $this->evento?->nome,
                'slug' => $this->evento?->slug,
                'data_inicio' => $this->evento?->data_inicio?->toIso8601String(),
                'data_fim' => $this->evento?->data_fim?->toIso8601String(),
                'contato_email' => $this->evento?->contato_email,
                'contato_telefone' => $this->evento?->contato_telefone,
            ],
            'grupo_participante' => $this->grupoParticipante === null ? null : [
                'nome' => $this->grupoParticipante->nome,
                'cidade' => $this->grupoParticipante->cidade?->nome,
                'uf' => $this->grupoParticipante->cidade?->uf,
            ],
            'atividades' => $this->atividades->map(fn (Atividade $atividade): array => [
                'nome' => $atividade->nome,
                'dia' => $atividade->grupoAtividade?->diaEvento?->nome,
                'grupo' => $atividade->grupoAtividade?->nome,
                'comeca_em' => $atividade->comeca_em->toIso8601String(),
                'termina_em' => $atividade->termina_em->toIso8601String(),
                'horario_rotulo' => $atividade->comeca_em->format('H:i').' às '.$atividade->termina_em->format('H:i'),
            ])->values()->all(),
        ];
    }
}

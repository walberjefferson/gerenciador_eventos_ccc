<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pagamento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma cobranca no historico que o participante ve.
 *
 * Fica de fora tudo o que e conversa entre o sistema e a instituicao
 * financeira: gateway, id_externo e metadados. O copia e cola tambem nao sai
 * daqui — ele e assunto da tela de pagamento, que so o mostra enquanto a
 * cobranca ainda aceita pagamento.
 *
 * @mixin Pagamento
 */
class PagamentoHistoricoResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'codigo_publico' => $this->codigo_publico,
            'situacao' => $this->situacao->value,
            'situacao_rotulo' => $this->situacao->rotulo(),
            'metodo' => $this->metodo->value,
            'metodo_rotulo' => $this->metodo->rotulo(),
            'valor_centavos' => $this->valor_centavos,
            'criado_em' => $this->created_at?->toIso8601String(),
            'expira_em' => $this->expira_em?->toIso8601String(),
            'pago_em' => $this->pago_em?->toIso8601String(),
            'cancelado_em' => $this->cancelado_em?->toIso8601String(),
            'estornado_em' => $this->estornado_em?->toIso8601String(),
            'valor_estornado_centavos' => $this->valor_estornado_centavos,
        ];
    }
}

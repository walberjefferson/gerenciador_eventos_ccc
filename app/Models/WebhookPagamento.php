<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SituacaoWebhook;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro cru de um aviso automatico enviado pelo provedor de pagamento.
 *
 * Guardar o aviso antes de interpretar tem dois motivos: permite responder ao
 * provedor em milissegundos (ele desiste rapido e reenvia) e deixa rastro para
 * investigar qualquer divergencia depois.
 *
 * Dois identificadores moram aqui, e eles NAO sao a mesma coisa:
 *
 * - `id_evento_externo` identifica o AVISO. Na Efi e o "fim a fim" da
 *   transferencia Pix; e ele que impede processar o mesmo aviso duas vezes.
 * - `id_externo` identifica a COBRANCA de que o aviso fala — o txid. E por ele
 *   que se liga o aviso ao registro em `pagamentos.id_externo` na hora de
 *   conferir se o dinheiro que entrou bate com a inscricao certa.
 */
class WebhookPagamento extends Model
{
    protected $table = 'webhooks_pagamento';

    protected $fillable = [
        'gateway',
        'id_evento_externo',
        'id_externo',
        'tipo_evento',
        'payload',
        'assinatura_valida',
        'recebido_em',
        'processado_em',
        'situacao',
        'erro',
    ];

    public function estaPendenteDeProcessamento(): bool
    {
        return $this->situacao === SituacaoWebhook::Recebido;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'assinatura_valida' => 'boolean',
            'situacao' => SituacaoWebhook::class,
            'recebido_em' => 'datetime',
            'processado_em' => 'datetime',
        ];
    }
}

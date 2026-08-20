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
 */
class WebhookPagamento extends Model
{
    protected $table = 'webhooks_pagamento';

    protected $fillable = [
        'gateway',
        'id_evento_externo',
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

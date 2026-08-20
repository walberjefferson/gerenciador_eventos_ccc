<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Support\Str;

/**
 * Emite a cobranca de uma inscricao.
 *
 * O prazo da cobranca e exatamente o prazo da inscricao: nao pode existir um
 * Pix que ainda aceita pagamento depois de a vaga ter voltado para a fila, nem
 * o contrario.
 *
 * E idempotente: se a inscricao ja tem cobranca aguardando pagamento, devolve
 * a mesma. Isso importa porque o participante pode reenviar o formulario e
 * porque a criacao da inscricao e repetivel pela chave de idempotencia.
 */
class CriarPagamentoDaInscricao
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function __invoke(Inscricao $inscricao): Pagamento
    {
        $jaEmitida = Pagamento::query()
            ->where('inscricao_id', $inscricao->getKey())
            ->pendentes()
            ->first();

        if ($jaEmitida !== null) {
            return $jaEmitida;
        }

        $evento = $inscricao->relationLoaded('evento')
            ? $inscricao->evento
            : $inscricao->evento()->first();

        $resultado = $this->gateway->createPayment(new CreatePaymentData(
            externalReference: (string) $inscricao->codigo_publico,
            amountCents: (int) $inscricao->valor_centavos,
            currency: (string) config('payments.currency', 'BRL'),
            method: MetodoPagamento::Pix->value,
            description: Str::limit('Inscricao no evento '.($evento?->nome ?? ''), 120),
            payerName: (string) $inscricao->nome_completo,
            payerEmail: (string) $inscricao->email,
            payerDocument: (string) $inscricao->documento,
            expiresAt: $inscricao->prazo_pagamento,
            metadata: ['inscricao' => (string) $inscricao->codigo_publico],
        ));

        return Pagamento::create([
            'inscricao_id' => $inscricao->getKey(),
            'gateway' => $this->gateway->name(),
            'id_externo' => $resultado->externalId,
            'metodo' => MetodoPagamento::Pix,
            'valor_centavos' => (int) $inscricao->valor_centavos,
            'situacao' => SituacaoPagamento::deStatusExterno($resultado->status) ?? SituacaoPagamento::Pendente,
            'pix_copia_e_cola' => $resultado->pixPayload,
            'expira_em' => $inscricao->prazo_pagamento,
            // Guardamos so o que ajuda a investigar depois. Nada de dado
            // pessoal do pagador aqui: ele ja esta na inscricao.
            'metadados' => [
                'referencia_externa' => (string) $inscricao->codigo_publico,
                'status_externo' => $resultado->status,
            ],
        ]);
    }
}

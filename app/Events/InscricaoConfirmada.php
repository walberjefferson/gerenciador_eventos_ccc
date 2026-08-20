<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Anuncio interno: o dinheiro foi reconhecido e a inscricao virou confirmada.
 *
 * E disparado uma unica vez por inscricao — no exato momento em que a
 * transicao "aguardando_pagamento -> confirmada" acontece de verdade. Aviso
 * repetido do provedor nao dispara de novo, porque a transicao ja aconteceu.
 *
 * Nao tem ouvintes nesta entrega. Existe para que a fase de comunicacao
 * (e-mail de confirmacao, comprovante) seja acrescentada sem tocar em uma
 * linha sequer das regras de pagamento.
 */
class InscricaoConfirmada
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Inscricao $inscricao,
        public readonly ?Pagamento $pagamento = null,
    ) {}
}

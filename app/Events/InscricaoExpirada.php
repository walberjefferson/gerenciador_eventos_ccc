<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Inscricao;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Anuncio interno: o prazo de pagamento passou, a inscricao foi marcada como
 * expirada e as vagas que ela prendia ja voltaram para a fila.
 *
 * E disparado uma unica vez por inscricao — quem expira e sempre a transicao
 * condicional "aguardando_pagamento -> expirada", que so acontece uma vez.
 *
 * Nao tem ouvintes nesta entrega. Existe para que a fase de comunicacao possa
 * avisar a pessoa de que a reserva caiu, sem alterar a rotina de expiracao.
 */
class InscricaoExpirada
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Inscricao $inscricao) {}
}

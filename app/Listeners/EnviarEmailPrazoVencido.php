<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Events\InscricaoExpirada;
use App\Mail\EmailDaInscricao;
use App\Mail\PrazoVencidoMail;

/**
 * Avisa quem perdeu o prazo — e convida a tentar de novo.
 *
 * A rotina de expiracao roda de minuto em minuto e pode expirar muitas
 * inscricoes de uma vez; cada anuncio vira um trabalho separado na fila, entao
 * um envio lento nao segura os outros nem a varredura.
 */
class EnviarEmailPrazoVencido extends OuvinteDeComunicacao
{
    public function handle(InscricaoExpirada $evento): void
    {
        $inscricao = $evento->inscricao->loadMissing('evento');

        $this->enviar($inscricao, TipoComunicacao::PrazoVencido, fn (): PrazoVencidoMail => new PrazoVencidoMail(
            nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
            evento: (string) $inscricao->evento->nome,
            prazo: EmailDaInscricao::momento($inscricao->prazo_pagamento),
            link: route('eventos.show', ['slug' => $inscricao->evento->slug]),
        ));
    }
}

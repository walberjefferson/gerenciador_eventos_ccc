<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Events\InscricaoCriada;
use App\Mail\EmailDaInscricao;
use App\Mail\InscricaoRecebidaMail;

/**
 * Avisa a pessoa de que a inscricao entrou e mostra como pagar.
 *
 * O anuncio InscricaoCriada e disparado depois que a transacao fecha, entao
 * quando este ouvinte roda a inscricao ja existe de verdade no banco.
 */
class EnviarEmailInscricaoRecebida extends OuvinteDeComunicacao
{
    public function handle(InscricaoCriada $evento): void
    {
        $inscricao = $evento->inscricao->loadMissing('evento');

        $this->enviar($inscricao, TipoComunicacao::InscricaoRecebida, fn (): InscricaoRecebidaMail => new InscricaoRecebidaMail(
            nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
            evento: (string) $inscricao->evento->nome,
            valor: EmailDaInscricao::moeda((int) $inscricao->valor_centavos),
            prazo: EmailDaInscricao::momento($inscricao->prazo_pagamento),
            link: $this->links->para($inscricao),
        ));
    }
}

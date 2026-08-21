<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Events\InscricaoCancelada;
use App\Mail\EmailDaInscricao;
use App\Mail\InscricaoCanceladaMail;

/**
 * Avisa a pessoa de que a organizacao cancelou a inscricao dela.
 *
 * O anuncio traz o motivo escrito pelo organizador, e ele para aqui: nao e
 * repassado para a mensagem. Anotacao administrativa e escrita para a
 * organizacao — quem explica o caso para a pessoa e um ser humano, no contato
 * do evento.
 *
 * Quando a inscricao ja estava confirmada (ou seja, havia dinheiro pago), a
 * mensagem acrescenta uma linha sobre a devolucao do valor. Ela nao promete
 * estorno automatico, porque nao existe estorno automatico: o combinado e
 * feito com a organizacao.
 */
class EnviarEmailInscricaoCancelada extends OuvinteDeComunicacao
{
    public function handle(InscricaoCancelada $evento): void
    {
        $inscricao = $evento->inscricao->loadMissing('evento');
        $contato = $inscricao->evento->contato_email;

        $this->enviar($inscricao, TipoComunicacao::InscricaoCancelada, fn (): InscricaoCanceladaMail => new InscricaoCanceladaMail(
            nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
            evento: (string) $inscricao->evento->nome,
            canceladaEm: EmailDaInscricao::momento($inscricao->cancelada_em),
            haviaPagamento: $evento->estavaConfirmada,
            contato: is_string($contato) && $contato !== '' ? $contato : null,
            link: $this->links->para($inscricao),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use Illuminate\Mail\Mailables\Content;

/**
 * "O prazo esta acabando."
 *
 * O unico dos cinco e-mails que nao nasce de um anuncio do dominio: nada
 * acontece quando o tempo passa, entao quem repara na passagem do tempo e uma
 * rotina agendada (inscricoes:lembrar-prazo).
 *
 * Sai uma unica vez por inscricao — nao porque o comando se lembre disso, mas
 * porque o registro de envio nao aceita a segunda.
 */
class LembretePrazoMail extends EmailDaInscricao
{
    public function __construct(
        public readonly string $nome,
        public readonly string $evento,
        public readonly string $tempoRestante,
        public readonly string $valor,
        public readonly string $prazo,
        public readonly string $link,
    ) {
        parent::__construct(TipoComunicacao::LembretePrazo);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lembrete-prazo',
            text: 'emails.lembrete-prazo-texto',
            with: [
                'nome' => $this->nome,
                'evento' => $this->evento,
                'tempoRestante' => $this->tempoRestante,
                'valor' => $this->valor,
                'prazo' => $this->prazo,
                'link' => $this->link,
            ],
        );
    }
}

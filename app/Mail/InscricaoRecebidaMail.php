<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use Illuminate\Mail\Mailables\Content;

/**
 * "Recebemos a sua inscricao — falta o pagamento."
 *
 * E a primeira mensagem que a pessoa recebe, e a mais importante das cinco:
 * ate aqui, quem fechasse a aba do navegador perdia a inscricao de vista.
 *
 * Leva apenas o que a pessoa precisa para decidir e agir — evento, valor,
 * prazo e o link. Nada de CPF, telefone ou codigo Pix: o codigo de pagamento
 * mora na pagina, que e revogavel; e-mail, uma vez enviado, nao volta atras.
 */
class InscricaoRecebidaMail extends EmailDaInscricao
{
    public function __construct(
        public readonly string $nome,
        public readonly string $evento,
        public readonly string $valor,
        public readonly string $prazo,
        public readonly string $link,
    ) {
        parent::__construct(TipoComunicacao::InscricaoRecebida);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inscricao-recebida',
            text: 'emails.inscricao-recebida-texto',
            with: [
                'nome' => $this->nome,
                'evento' => $this->evento,
                'valor' => $this->valor,
                'prazo' => $this->prazo,
                'link' => $this->link,
            ],
        );
    }
}

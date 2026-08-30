<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use Illuminate\Mail\Mailables\Content;

/**
 * "O prazo venceu e a vaga voltou para a fila."
 *
 * E uma noticia ruim, e por isso precisa ser especialmente clara: diz o que
 * aconteceu, por que aconteceu e o que ainda da para fazer. O link aqui nao e
 * o da inscricao encerrada — e o da pagina do evento, porque a unica acao util
 * agora e se inscrever de novo, se ainda houver vaga.
 */
class PrazoVencidoMail extends EmailDaInscricao
{
    public function __construct(
        public readonly string $nome,
        public readonly string $evento,
        public readonly string $prazo,
        public readonly string $link,
    ) {
        parent::__construct(TipoComunicacao::PrazoVencido);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.prazo-vencido',
            text: 'emails.prazo-vencido-texto',
            with: [
                'nome' => $this->nome,
                'evento' => $this->evento,
                'prazo' => $this->prazo,
                'link' => $this->link,
            ],
        );
    }
}

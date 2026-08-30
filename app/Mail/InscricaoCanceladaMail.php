<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use Illuminate\Mail\Mailables\Content;

/**
 * "Sua inscricao foi cancelada pela organizacao."
 *
 * O motivo que o organizador escreveu NAO entra aqui, e essa ausencia e
 * deliberada: aquele campo e anotacao interna, escrita para a organizacao, e
 * pode conter observacao sobre a pessoa que ninguem redigiu para ela ler. A
 * mensagem informa o fato e aponta para quem sabe explicar — a organizacao.
 *
 * O construtor nem sequer recebe o motivo. Assim nao existe o caminho pelo
 * qual ele poderia vazar por descuido.
 */
class InscricaoCanceladaMail extends EmailDaInscricao
{
    public function __construct(
        public readonly string $nome,
        public readonly string $evento,
        public readonly string $canceladaEm,
        public readonly bool $haviaPagamento,
        public readonly ?string $contato,
        public readonly string $link,
    ) {
        parent::__construct(TipoComunicacao::InscricaoCancelada);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inscricao-cancelada',
            text: 'emails.inscricao-cancelada-texto',
            with: [
                'nome' => $this->nome,
                'evento' => $this->evento,
                'canceladaEm' => $this->canceladaEm,
                'haviaPagamento' => $this->haviaPagamento,
                'contato' => $this->contato,
                'link' => $this->link,
            ],
        );
    }
}

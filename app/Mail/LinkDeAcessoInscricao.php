<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * O unico e-mail desta fase: o caminho de volta para a inscricao.
 *
 * Nao leva CPF, telefone, data de nascimento nem valor em aberto. Nome do
 * evento, situacao e link — so. Mensagem que circula em caixa de entrada, e as
 * vezes e encaminhada, nao pode virar ficha cadastral.
 *
 * O envio e sincrono. A Fase 7, que monta o envio em escala, troca isso
 * acrescentando "implements ShouldQueue".
 */
class LinkDeAcessoInscricao extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{evento: string, situacao: string, link: string}>  $inscricoes
     */
    public function __construct(
        public readonly array $inscricoes,
        public readonly int $validadeEmDias,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seu link de acesso à inscrição',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.link-de-acesso',
            with: [
                'inscricoes' => $this->inscricoes,
                'validadeEmDias' => $this->validadeEmDias,
            ],
        );
    }
}

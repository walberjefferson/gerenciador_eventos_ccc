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
 * O envio e sincrono, e continua sincrono depois da Fase 7 — que colocou os
 * outros cinco e-mails na fila. A decisao foi reavaliada la e mantida (D-49),
 * por tres motivos:
 *
 * 1. Este e-mail responde a um pedido humano imediato: a pessoa esta parada na
 *    tela esperando o link chegar. Os outros cinco sao consequencia de um fato
 *    que ja aconteceu, e ninguem esta esperando por eles.
 * 2. O custo ja esta limitado: o pedido de acesso tem piso de tempo de
 *    resposta (D-48), que existe para que nao se descubra quem esta inscrito
 *    cronometrando a resposta. Enfileirar nao economizaria tempo nenhum, so
 *    esconderia a falha de envio da pessoa que a provocou.
 * 3. Enquanto nenhum trabalhador de fila estiver de pe, enfileirar este e-mail
 *    seria o mesmo que deixar de envia-lo.
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

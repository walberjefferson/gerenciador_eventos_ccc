<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * O que os cinco e-mails do participante tem em comum.
 *
 * Tres coisas moram aqui para nao serem repetidas (nem esquecidas) em cada
 * mensagem:
 *
 * 1. Sair pela fila. Nenhum e-mail e enviado durante o pedido da pessoa: um
 *    servidor de e-mail lento nao pode atrasar uma inscricao.
 * 2. Tentar de novo quando falhar, com espera crescente. Servidor de e-mail
 *    fora do ar costuma ser problema de minutos.
 * 3. O assunto vem do tipo da mensagem, para que assunto e registro de envio
 *    nunca discordem.
 *
 * O remetente nao aparece em lugar nenhum do codigo: vale o configurado em
 * MAIL_FROM_ADDRESS / MAIL_FROM_NAME.
 */
abstract class EmailDaInscricao extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Quantas vezes a fila insiste antes de desistir. Desistir aqui significa
     * ir para "failed_jobs" — nunca desfazer nada da inscricao.
     */
    public ?int $tries = null;

    public function __construct(public readonly TipoComunicacao $tipo)
    {
        $this->onConnection(config('inscricoes.comunicacao.conexao'));
        $this->onQueue((string) config('inscricoes.comunicacao.fila', 'emails'));
        $this->tries = (int) config('inscricoes.comunicacao.tentativas', 3);
    }

    /**
     * Espera entre as tentativas, em segundos.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        /** @var array<int, int> $espera */
        $espera = config('inscricoes.comunicacao.espera_entre_tentativas', [60, 300, 900]);

        return $espera;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->tipo->assunto());
    }

    /**
     * Valor em reais, do jeito que se le em voz alta: R$ 1.234,56.
     */
    public static function moeda(int $centavos): string
    {
        return 'R$ '.number_format($centavos / 100, 2, ',', '.');
    }

    /**
     * Data e hora em portugues corrente: "3 de setembro de 2026, às 18h30".
     */
    public static function momento(?Carbon $momento): string
    {
        if ($momento === null) {
            return 'sem prazo definido';
        }

        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        $local = $momento->copy()->setTimezone(config('app.timezone'));

        return $local->day.' de '.$meses[$local->month - 1].' de '.$local->year
            .', às '.$local->format('H\hi');
    }

    /**
     * So o primeiro nome, para o cumprimento. Nome completo em cabecalho de
     * e-mail nao acrescenta nada e circula mais do que deveria.
     */
    public static function primeiroNome(string $nomeCompleto): string
    {
        $partes = preg_split('/\s+/', trim($nomeCompleto)) ?: [];

        return $partes[0] ?? '';
    }
}

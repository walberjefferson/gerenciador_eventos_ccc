<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Mail\EmailDaInscricao;
use App\Models\Inscricao;
use App\Services\Comunicacao\RegistrarEnvio;
use App\Services\Inscricoes\GeradorLinkDeAcesso;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * O que todo ouvinte de e-mail desta fase faz igual.
 *
 * Os ouvintes rodam na fila, nunca durante o pedido da pessoa: o dominio
 * anuncia o fato e segue a vida. Se o e-mail demorar, falhar ou for tentado
 * tres vezes sem sucesso, a inscricao, a vaga e o pagamento continuam
 * exatamente como estavam — o prejuizo maximo de uma falha aqui e uma
 * mensagem que nao chegou, registrada em "failed_jobs".
 *
 * O envio propriamente dito passa sempre por RegistrarEnvio, que e quem
 * impede a segunda copia usando a unicidade do banco.
 */
abstract class OuvinteDeComunicacao implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected readonly RegistrarEnvio $registrar,
        protected readonly GeradorLinkDeAcesso $links,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Por que fila, tentativas e espera sao metodos, e nao propriedades
    |--------------------------------------------------------------------------
    |
    | Para descobrir em qual fila enfileirar um ouvinte, o Laravel cria uma
    | copia dele SEM chamar o construtor (Dispatcher::createListenerAndJob).
    | Qualquer valor que o construtor atribuisse seria perdido justamente na
    | hora em que ele importa, e o trabalho cairia na fila "default" — o que
    | um trabalhador dedicado a fila "emails" nunca veria.
    |
    | Por isso tudo aqui e metodo: metodo funciona na copia sem construtor, e o
    | valor continua vindo da configuracao.
    |
    */

    public function viaConnection(): ?string
    {
        $conexao = config('inscricoes.comunicacao.conexao');

        return is_string($conexao) && $conexao !== '' ? $conexao : null;
    }

    public function viaQueue(): string
    {
        return (string) config('inscricoes.comunicacao.fila', 'emails');
    }

    /**
     * Quantas vezes a fila insiste antes de desistir. Desistir significa ir
     * para "failed_jobs" — nunca desfazer nada da inscricao.
     */
    public function tries(mixed ...$argumentos): int
    {
        return (int) config('inscricoes.comunicacao.tentativas', 3);
    }

    /**
     * Espera entre as tentativas, em segundos.
     *
     * @return array<int, int>
     */
    public function backoff(mixed ...$argumentos): array
    {
        /** @var array<int, int> $espera */
        $espera = config('inscricoes.comunicacao.espera_entre_tentativas', [60, 300, 900]);

        return $espera;
    }

    /**
     * Monta e envia a mensagem, no maximo uma vez por inscricao.
     *
     * @param  Closure(): EmailDaInscricao  $montar
     */
    protected function enviar(Inscricao $inscricao, TipoComunicacao $tipo, Closure $montar): void
    {
        $destino = (string) $inscricao->email;

        if ($destino === '') {
            return;
        }

        $this->registrar->umaVezPor(
            $inscricao,
            $tipo,
            $destino,
            fn () => Mail::to($destino)->send($montar()),
        );
    }
}

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

    public ?string $connection = null;

    public ?string $queue = null;

    public ?int $tries = null;

    public function __construct(
        protected readonly RegistrarEnvio $registrar,
        protected readonly GeradorLinkDeAcesso $links,
    ) {
        $conexao = config('inscricoes.comunicacao.conexao');
        $this->connection = is_string($conexao) && $conexao !== '' ? $conexao : null;
        $this->queue = (string) config('inscricoes.comunicacao.fila', 'emails');
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

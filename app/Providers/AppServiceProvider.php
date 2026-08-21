<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\InscricaoCancelada;
use App\Events\InscricaoConfirmada;
use App\Events\InscricaoCriada;
use App\Events\InscricaoExpirada;
use App\Listeners\EnviarEmailInscricaoCancelada;
use App\Listeners\EnviarEmailInscricaoRecebida;
use App\Listeners\EnviarEmailPagamentoConfirmado;
use App\Listeners\EnviarEmailPrazoVencido;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Quem escuta o que.
     *
     * A lista e escrita a mao de proposito. O Laravel sabe descobrir ouvintes
     * sozinho varrendo app/Listeners, mas descoberta automatica esconde a
     * ligacao mais importante do sistema: qual fato do dominio faz sair uma
     * mensagem para a pessoa. Aqui isso se le em cinco linhas, e uma classe
     * nova em app/Listeners nao comeca a receber anuncios sem ninguem decidir.
     * (A descoberta esta desligada em bootstrap/app.php.)
     *
     * Todos os ouvintes rodam na fila. O dominio anuncia e segue; se o e-mail
     * falhar, nada acontece com a inscricao, a vaga ou o pagamento.
     *
     * @var array<class-string, list<class-string>>
     */
    private const OUVINTES = [
        InscricaoCriada::class => [EnviarEmailInscricaoRecebida::class],
        InscricaoConfirmada::class => [EnviarEmailPagamentoConfirmado::class],
        InscricaoExpirada::class => [EnviarEmailPrazoVencido::class],
        InscricaoCancelada::class => [EnviarEmailInscricaoCancelada::class],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::OUVINTES as $anuncio => $ouvintes) {
            foreach ($ouvintes as $ouvinte) {
                Event::listen($anuncio, $ouvinte);
            }
        }
    }
}

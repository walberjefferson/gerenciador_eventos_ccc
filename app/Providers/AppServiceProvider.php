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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

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

        $this->configurarLimitesDeRequisicao();
    }

    /**
     * Os limites das portas abertas para a internet.
     *
     * Limite de requisicao nao existe para incomodar quem usa o sistema: ele
     * existe porque, sem ele, um programa consegue repetir o mesmo pedido
     * milhares de vezes por minuto — criando inscricao em serie, adivinhando
     * senha ou simplesmente derrubando o servidor no dia da abertura. A conta
     * de cada limite esta escrita em config/inscricoes.php, junto do numero.
     *
     * Cada limite e contado por endereco de internet (IP). Nao ha nada melhor
     * disponivel numa porta publica: pedir login para se inscrever seria
     * inverter o produto para resolver um problema de infraestrutura.
     */
    private function configurarLimitesDeRequisicao(): void
    {
        // Envio do formulario de inscricao. Dois limites ao mesmo tempo, e de
        // proposito: o do minuto e folgado, para nao punir a familia inteira
        // saindo pelo mesmo IP; o da hora e o que realmente segura um script,
        // porque nenhuma familia manda sessenta inscricoes numa hora e nenhum
        // programa consegue disfarcar isso esperando entre os pedidos.
        RateLimiter::for('inscricoes', function (Request $request): array {
            $endereco = (string) $request->ip();

            return [
                Limit::perMinute((int) config('inscricoes.limites.criar_por_minuto'))
                    ->by('inscricoes-minuto:'.$endereco)
                    ->response($this->recusaDaInscricao(...)),
                Limit::perHour((int) config('inscricoes.limites.criar_por_hora'))
                    ->by('inscricoes-hora:'.$endereco)
                    ->response($this->recusaDaInscricao(...)),
            ];
        });

        // Aviso do provedor de pagamento. O teto e alto porque quem chama e um
        // servidor, e varias confirmacoes chegando juntas e o comportamento
        // normal de um dia movimentado, nao um ataque.
        //
        // Este limite NAO muda a decisao D-18: aviso com assinatura invalida
        // continua recebendo 200. A recusa por excesso acontece antes de o
        // aviso ser lido, entao ela tambem nao conta nada sobre a assinatura.
        RateLimiter::for('webhooks-pagamento', fn (Request $request): Limit => Limit::perMinute(
            (int) config('inscricoes.limites.webhook_por_minuto')
        )->by('webhook:'.$request->ip()));

        // Login do painel. O Laravel ja limita cinco tentativas por e-mail
        // (dentro do LoginRequest); este teto por IP vem por cima, para pegar
        // quem varre uma lista de e-mails diferentes do mesmo lugar e por isso
        // nunca esbarraria no limite por e-mail.
        RateLimiter::for('login-administrativo', fn (Request $request): Limit => Limit::perMinute(
            (int) config('inscricoes.limites.login_por_minuto')
        )->by('login:'.$request->ip()));
    }

    /**
     * A recusa que o participante le quando o limite estoura.
     *
     * O padrao do framework aqui e uma pagina de erro em ingles, com "429 Too
     * Many Requests" e nada mais. Quem esta tentando se inscrever nao tem como
     * saber o que fazer com isso. A resposta abaixo diz, em portugues, o que
     * aconteceu e quanto tempo esperar.
     *
     * O formulario publico e Inertia: para ele, a recusa volta como erro do
     * campo "evento", que a tela ja mostra como aviso geral da revisao. Para
     * qualquer outro cliente, volta como JSON.
     *
     * @param  array<string, mixed>  $cabecalhos
     */
    private function recusaDaInscricao(Request $request, array $cabecalhos): Response
    {
        $segundos = (int) ($cabecalhos['Retry-After'] ?? 60);
        $minutos = max(1, (int) ceil($segundos / 60));

        $mensagem = sprintf(
            'Recebemos inscrições demais deste mesmo acesso em pouco tempo. Aguarde %s e tente de novo. Se não foi você, fale com a organização.',
            $minutos === 1 ? 'um minuto' : "cerca de {$minutos} minutos",
        );

        if ($request->header('X-Inertia')) {
            return back()->withErrors(['evento' => $mensagem])->withHeaders($cabecalhos);
        }

        return response()->json(['message' => $mensagem], 429, $cabecalhos);
    }
}

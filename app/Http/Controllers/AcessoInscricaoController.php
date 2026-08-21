<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoInscricao;
use App\Http\Requests\EnviarLinkAcessoRequest;
use App\Mail\LinkDeAcessoInscricao;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Services\Inscricoes\GeradorLinkDeAcesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * O caminho de volta de quem fechou o navegador e perdeu o link.
 *
 * A regra que manda aqui e uma so: a resposta e sempre a mesma. Com inscricao
 * ou sem inscricao, dentro ou fora do limite de tentativas, com o envio dando
 * certo ou falhando — o mesmo texto, o mesmo codigo HTTP e o mesmo tempo de
 * resposta. Qualquer diferenca transformaria este formulario em uma maquina de
 * descobrir quem esta inscrito.
 */
class AcessoInscricaoController extends Controller
{
    /**
     * A unica resposta possivel do pedido. Nao muda nunca — nem quando o
     * e-mail nao existe, nem quando o limite de tentativas estoura.
     */
    public const MENSAGEM_NEUTRA = 'Se houver inscrição com esse e-mail, enviamos o link de acesso para ele.';

    /**
     * O formulario: um campo so. O parametro ?evento={slug} serve apenas para
     * dar contexto a quem chegou pela pagina do evento.
     */
    public function create(Request $request): Response
    {
        $slug = trim((string) $request->query('evento'));
        $evento = $slug === '' ? null : Evento::query()->peloSlug($slug)->first();

        return Inertia::render('Inscricoes/RecuperarAcesso', [
            'evento' => $evento === null ? null : [
                'nome' => $evento->nome,
                'slug' => $evento->slug,
            ],
            'mensagem' => session('mensagem'),
        ]);
    }

    public function store(EnviarLinkAcessoRequest $request, GeradorLinkDeAcesso $gerador): RedirectResponse
    {
        $comecou = microtime(true);

        $email = Str::lower(trim((string) $request->validated('email')));

        if ($this->dentroDoLimite((string) $request->ip(), $email)) {
            $this->enviarLink($email, $gerador);
        }

        $this->igualarOTempoDeResposta($comecou);

        $evento = (string) ($request->validated('evento') ?? '');

        return redirect()
            ->route('inscricoes.acesso', $evento === '' ? [] : ['evento' => $evento])
            ->with('mensagem', self::MENSAGEM_NEUTRA);
    }

    /**
     * Uma mensagem so, listando todas as inscricoes daquele e-mail.
     *
     * Inscricao cancelada fica de fora: nao ha o que acompanhar, e listar so
     * geraria duvida. Falha de envio e registrada e engolida de proposito —
     * um erro 500 aqui contaria que o e-mail existe.
     */
    private function enviarLink(string $email, GeradorLinkDeAcesso $gerador): void
    {
        $inscricoes = Inscricao::query()
            ->with('evento')
            ->whereRaw('lower(email) = ?', [$email])
            ->where('situacao', '!=', SituacaoInscricao::Cancelada->value)
            ->orderByDesc('id')
            ->get();

        if ($inscricoes->isEmpty()) {
            return;
        }

        $itens = $inscricoes->map(fn (Inscricao $inscricao): array => [
            'evento' => (string) ($inscricao->evento?->nome ?? 'Evento'),
            'situacao' => $inscricao->situacao->rotulo(),
            'link' => $gerador->para($inscricao),
        ])->values()->all();

        try {
            Mail::to($email)->send(new LinkDeAcessoInscricao($itens, $gerador->validadeEmDias()));
        } catch (Throwable $falha) {
            Log::warning('Nao foi possivel enviar o link de acesso.', ['erro' => $falha->getMessage()]);
        }
    }

    /**
     * Limite por IP e por e-mail, contado aqui dentro e nao no middleware: um
     * 429 avisaria a quem esta varrendo enderecos que ele acertou o alvo.
     * Estourado o limite, nada e enviado e a resposta continua a mesma.
     *
     * O e-mail vira impressao digital antes de virar chave: nem o cache guarda
     * a lista de quem tentou.
     */
    private function dentroDoLimite(string $ip, string $email): bool
    {
        $chaves = [
            'acesso-ip-minuto:'.$ip => 'acesso_por_minuto',
            'acesso-ip-hora:'.$ip => 'acesso_por_hora',
            'acesso-email-minuto:'.hash('sha256', $email) => 'acesso_por_minuto',
            'acesso-email-hora:'.hash('sha256', $email) => 'acesso_por_hora',
        ];

        $permitido = true;

        foreach ($chaves as $chave => $limite) {
            [$tentativas, $minutos] = $this->limite($limite);

            if (RateLimiter::tooManyAttempts($chave, $tentativas)) {
                $permitido = false;
            }

            // Todas as chaves sao marcadas sempre, inclusive quando o pedido ja
            // esta barrado: o tempo gasto nao pode denunciar o resultado.
            RateLimiter::hit($chave, $minutos * 60);
        }

        return $permitido;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function limite(string $nome): array
    {
        $partes = explode(',', (string) config('inscricoes.limites.'.$nome, '5,1'));

        return [max(1, (int) ($partes[0] ?? 5)), max(1, (int) ($partes[1] ?? 1))];
    }

    /**
     * Enviar mensagem demora mais do que nao enviar. Este piso deixa as duas
     * respostas com a mesma duracao perceptivel.
     */
    private function igualarOTempoDeResposta(float $comecou): void
    {
        $piso = max(0, (int) config('inscricoes.tempo_minimo_resposta_ms', 400)) * 1000;
        $gasto = (int) round((microtime(true) - $comecou) * 1_000_000);

        if ($gasto < $piso) {
            usleep($piso - $gasto);
        }
    }
}

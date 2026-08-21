<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Os cabecalhos que o navegador obedece antes de qualquer codigo nosso rodar.
 *
 * Cabecalho de seguranca e instrucao dada ao navegador da pessoa: "nao adivinhe
 * o tipo deste arquivo", "nao deixe ninguem colocar esta pagina dentro de um
 * quadro", "so execute script que veio daqui". Sao baratos de ligar e caros de
 * esquecer — a maioria dos ataques que eles impedem so aparece depois que a
 * aplicacao ja esta na internet.
 *
 * ## A regra mais importante: a CSP
 *
 * A Content-Security-Policy diz de onde o navegador pode carregar cada coisa.
 * Se um dia alguem conseguir injetar `<script>` numa tela, e a CSP que impede o
 * script de rodar.
 *
 * O script desta aplicacao e liberado por **nonce**: a cada resposta e sorteado
 * um numero de uso unico, ele vai no cabecalho e vai nos poucos scripts que o
 * proprio servidor escreve na pagina (o Ziggy, com a tabela de rotas, e os
 * arquivos do Vite). Script injetado por terceiro nao tem como adivinhar o
 * numero da vez, entao nao roda. **Nao existe `unsafe-inline` em `script-src`**,
 * que e o ponto de toda a defesa.
 *
 * ## Uma concessao, escrita para ninguem confundir com descuido
 *
 * `style-src` **tem** `'unsafe-inline'`. A interface e Vue com Tailwind, e
 * componente Vue escreve `style="..."` direto no elemento (barra de progresso,
 * altura calculada, cor de estado). Sem essa permissao, as telas quebrariam
 * visualmente em producao — exatamente o tipo de estrago que so aparece depois
 * do deploy. CSS injetado permite disfarce visual, o que e ruim; JavaScript
 * injetado permite roubar sessao e enviar formulario no lugar da pessoa, o que
 * e muito pior. A defesa fica onde o estrago e maior.
 *
 * ## O que a CSP precisa deixar passar neste projeto
 *
 * - **fonts.bunny.net** — a folha de estilo das fontes e os arquivos de fonte.
 * - **`data:`** em imagem e fonte — icones e fontes embutidas do pacote.
 * - **SVG do Pix** — o QR Code chega pronto do servidor e e inserido no HTML.
 *   SVG embutido nao e script, entao a CSP nao o bloqueia; ele so seria
 *   bloqueado se viesse por `<img src="...">` de outro endereco, que nao e o
 *   caso.
 * - **dados do Inertia** — vem num atributo `data-page` do HTML, nao num
 *   `<script>`. Atributo nao e script; a CSP nao interfere.
 *
 * ## HSTS
 *
 * `Strict-Transport-Security` so sai quando a resposta esta em HTTPS. Mandar
 * esse cabecalho em ambiente de desenvolvimento (http) faria o navegador
 * guardar por um ano que o endereco e sempre seguro, e a pessoa passaria dias
 * sem conseguir abrir o proprio ambiente local.
 */
class CabecalhosDeSeguranca
{
    /**
     * Quanto tempo o navegador lembra que este endereco so fala HTTPS.
     * Um ano, que e o minimo aceito pelas listas de pre-carregamento.
     */
    private const HSTS_SEGUNDOS = 31_536_000;

    public function handle(Request $request, Closure $next): Response
    {
        // O numero de uso unico precisa existir ANTES de a pagina ser montada:
        // e o Vite e o Ziggy que o escrevem nas tags de script.
        $nonce = Str::random(24);
        Vite::useCspNonce($nonce);

        $resposta = $next($request);

        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('X-Frame-Options', 'DENY');
        $resposta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $resposta->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure()) {
            $resposta->headers->set(
                'Strict-Transport-Security',
                'max-age='.self::HSTS_SEGUNDOS.'; includeSubDomains',
            );
        }

        if ($this->ehHtml($resposta)) {
            $resposta->headers->set('Content-Security-Policy', $this->politica($nonce));
        }

        return $resposta;
    }

    /**
     * A CSP so vai em pagina. Num CSV baixado ou num JSON de webhook ela nao
     * protege nada e so aumenta o tamanho da resposta.
     */
    private function ehHtml(Response $resposta): bool
    {
        return str_contains((string) $resposta->headers->get('Content-Type', ''), 'text/html');
    }

    private function politica(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $estilo = ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'];
        $conexao = ["'self'"];

        // Durante o desenvolvimento com `npm run dev`, os arquivos da interface
        // sao servidos pelo Vite noutro endereco, e ele conversa com a pagina
        // por websocket. Sem esta excecao, a tela ficaria em branco na maquina
        // de quem desenvolve — e a reacao natural seria afrouxar a CSP inteira.
        // A excecao existe SO enquanto o servidor de desenvolvimento estiver no
        // ar, e some sozinha em qualquer outro caso.
        if (app()->environment('local') && Vite::isRunningHot()) {
            $vite = ['http://localhost:5173', 'http://127.0.0.1:5173'];
            $script = [...$script, ...$vite];
            $estilo = [...$estilo, ...$vite];
            $conexao = [...$conexao, ...$vite, 'ws://localhost:5173', 'ws://127.0.0.1:5173'];
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $estilo),
            'font-src \'self\' data: https://fonts.bunny.net',
            "img-src 'self' data:",
            'connect-src '.implode(' ', $conexao),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}

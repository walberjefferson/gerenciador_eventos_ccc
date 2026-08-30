<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Conta desativada nao continua dentro.
 *
 * Conferir a situacao so no login nao bastaria: desativar alguem que esta com
 * a tela aberta nao surtiria efeito nenhum ate essa pessoa sair sozinha — e
 * quem desativa uma conta as vezes esta justamente com pressa para que ela
 * pare de valer.
 *
 * **Por que ele SUBSTITUI o apelido "auth" em vez de entrar no grupo `web`.**
 * A pergunta que este middleware faz — "esta conta ainda vale?" — so existe
 * onde ha conta, ou seja, exatamente onde o `auth` ja e exigido. Pendura-lo no
 * grupo `web` inteiro o faria rodar tambem nas telas publicas do participante,
 * que nao tem sessao administrativa nenhuma. E amarra-lo a mao em cada grupo
 * de rotas autenticadas o transformaria em trava que um dia vai faltar
 * justamente na rota que alguem esqueceu (e o mesmo raciocinio da D-82).
 * Estendendo o `Authenticate` do framework, toda rota que ja dizia "auth"
 * passa a fazer as duas conferencias, e nenhuma rota nova precisa lembrar
 * disso.
 *
 * A sessao e destruida antes do redirecionamento: deixar a sessao viva
 * significaria que a pessoa continua "logada" para efeito de cookie e volta a
 * bater na porta a cada clique.
 *
 * A pessoa cai na tela de login **sem nenhuma explicacao escrita**, de
 * proposito — dizer "sua conta foi desativada" contaria a quem tenta adivinhar
 * senha que aquele e-mail existe e esta cadastrado aqui.
 */
class GarantirContaAtiva extends Authenticate
{
    /**
     * @param  string  ...$guards
     *
     * @throws AuthenticationException
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        $usuario = $request->user();

        if ($usuario instanceof User && ! $usuario->ativo) {
            $this->derrubarSessao($request);

            throw new AuthenticationException('Unauthenticated.', $guards, $this->redirectTo($request));
        }

        return $next($request);
    }

    private function derrubarSessao(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}

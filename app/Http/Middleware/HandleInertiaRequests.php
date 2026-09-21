<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                // As permissoes de quem esta logado, so os nomes. A barra
                // lateral precisa delas para nao oferecer um item que o
                // proprio servidor recusaria com 403 — mostrar um caminho
                // fechado e pior do que nao mostrar caminho nenhum.
                //
                // Isto nao substitui a checagem no servidor: e enfeite de
                // tela. A tranca de verdade continua no middleware da rota.
                'permissoes' => $request->user()?->getAllPermissions()->pluck('name')->all() ?? [],
            ],
            // O aviso da acao que acabou de acontecer, disponivel em qualquer
            // tela. Cada pagina continua recebendo o seu 'sucesso' proprio para
            // desenhar o paragrafo dentro do conteudo; isto aqui e o que
            // permite a moldura mostrar o mesmo recado como aviso rapido, sem
            // que cada tela precise saber disso.
            'flash' => $this->avisoDaAcao($request),
        ]);
    }

    /**
     * O aviso guardado pela acao anterior, com um identificador descartavel.
     *
     * O identificador existe por um motivo pratico: cancelar duas inscricoes
     * seguidas produz exatamente a mesma frase, e uma tela que ficasse de olho
     * no TEXTO nao veria mudanca nenhuma na segunda vez — o aviso rapido
     * simplesmente nao apareceria, justo no uso repetido, que e o uso real do
     * painel. Com um valor novo a cada resposta, a segunda acao e tao visivel
     * quanto a primeira.
     *
     * Ele nao e dado de negocio: nasce aqui, vive uma resposta e morre.
     *
     * @return array<string, string|null>
     */
    private function avisoDaAcao(Request $request): array
    {
        $sessao = $request->hasSession() ? $request->session() : null;

        return [
            'sucesso' => $sessao?->get('sucesso'),
            'erro' => $sessao?->get('erro'),
            'id' => (string) Str::uuid(),
        ];
    }
}

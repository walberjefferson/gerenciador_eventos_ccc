<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Usuarios\GovernarConta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UsuarioPapelRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * A tela que diz quem entra no painel, com que papel, e ate quando.
 *
 * **Ela governa contas; nao as cria.** Nao existe rota de cadastro aqui: conta
 * administrativa continua nascendo por `php artisan usuario:criar-administrador`
 * (D-51), rodado por quem ja tem acesso ao servidor. E nao existe rota de
 * exclusao: a auditoria guarda `usuario_id`, e apagar deixaria o historico
 * apontando para o vazio.
 *
 * Exige "usuarios.gerenciar", que so o administrador tem — a mesma permissao
 * que ja existia no `PapeisSeeder` desde a Fase 6a e que ate aqui nenhuma rota
 * cobrava.
 *
 * As tres travas (nao mexer em si mesmo, nunca chegar a zero administrador e
 * registrar tudo na auditoria) moram na Action, e nao aqui: elas precisam valer
 * tambem quando a chamada nao vem da tela.
 */
class UsuarioController extends Controller
{
    /** Quantas linhas por pagina, no mesmo tamanho da tela de auditoria. */
    private const POR_PAGINA = 25;

    public function __construct(private readonly GovernarConta $governarConta) {}

    public function index(Request $pedido): Response
    {
        $this->conferirPermissao($pedido);

        $filtros = $this->filtros($pedido);
        $pagina = $this->consulta($filtros)->paginate(self::POR_PAGINA)->withQueryString();

        $euSou = (int) ($pedido->user()?->getKey() ?? 0);

        return inertia('Admin/Usuarios/Index', [
            'usuarios' => [
                'dados' => collect($pagina->items())
                    ->map(fn (User $usuario): array => [
                        'id' => (int) $usuario->getKey(),
                        'nome' => $usuario->name,
                        'email' => $usuario->email,
                        'papel' => $usuario->roles->pluck('name')->first(),
                        'ativo' => (bool) $usuario->ativo,
                        'entrou_em' => $usuario->created_at?->format('d/m/Y'),
                        // A tela precisa saber qual linha e a de quem esta
                        // olhando: e a unica em que as acoes nao aparecem.
                        'sou_eu' => (int) $usuario->getKey() === $euSou,
                    ])
                    ->all(),
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'por_pagina' => self::POR_PAGINA,
                'links' => [
                    'anterior' => $pagina->previousPageUrl(),
                    'proxima' => $pagina->nextPageUrl(),
                ],
            ],
            'filtros' => $filtros,
            'opcoes' => ['papeis' => self::papeis()],
            'sucesso' => session('sucesso'),
        ]);
    }

    public function atualizarPapel(UsuarioPapelRequest $pedido, User $usuario): RedirectResponse
    {
        $this->conferirPermissao($pedido);

        $recusa = $this->governarConta->trocarPapel($usuario, $pedido->papel(), $this->responsavel($pedido));

        if ($recusa !== null) {
            return back()->withErrors(['papel' => $recusa]);
        }

        return back()->with('sucesso', sprintf('%s agora é %s.', $usuario->name, $pedido->papel()));
    }

    public function atualizarSituacao(Request $pedido, User $usuario): RedirectResponse
    {
        $this->conferirPermissao($pedido);

        $validado = $pedido->validate(['ativo' => ['required', 'boolean']]);
        $ativo = (bool) $validado['ativo'];

        $recusa = $this->governarConta->trocarSituacao($usuario, $ativo, $this->responsavel($pedido));

        if ($recusa !== null) {
            return back()->withErrors(['situacao' => $recusa]);
        }

        return back()->with(
            'sucesso',
            sprintf('%s foi %s.', $usuario->name, $ativo ? 'reativado e volta a entrar' : 'desativado e não entra mais'),
        );
    }

    /**
     * Os dois papeis que existem, com o rotulo que a pessoa le.
     *
     * Sao lidos da tabela, e nao de uma lista escrita a mao: o `PapeisSeeder` e
     * a fonte (D-50), e a tela e uma janela para ele.
     *
     * @return list<array{valor: string, rotulo: string}>
     */
    public static function papeis(): array
    {
        return Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $nome): array => ['valor' => $nome, 'rotulo' => mb_ucfirst($nome)])
            ->values()
            ->all();
    }

    /**
     * A segunda tranca.
     *
     * A rota ja cobra "permission:usuarios.gerenciar". A conferencia aqui repete
     * o que o `AuditoriaController` ja faz, pelo mesmo motivo escrito la: se um
     * dia alguem registrar estas telas em outro grupo de rotas e esquecer o
     * middleware, o 403 continua acontecendo.
     */
    private function conferirPermissao(Request $pedido): void
    {
        abort_unless($pedido->user()?->can('usuarios.gerenciar') === true, 403);
    }

    private function responsavel(Request $pedido): User
    {
        $usuario = $pedido->user();

        // Nao ha caminho por onde isto seja nulo: as rotas exigem "auth" antes
        // da permissao. A conferencia existe para o tipo, e para o dia em que
        // alguem registrar a rota fora do grupo.
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }

    /**
     * @return array{busca: string|null, papel: string|null, situacao: string|null}
     */
    private function filtros(Request $pedido): array
    {
        $texto = static function (mixed $valor): ?string {
            $valor = is_scalar($valor) ? trim((string) $valor) : '';

            return $valor === '' ? null : $valor;
        };

        return [
            'busca' => $texto($pedido->input('busca')),
            'papel' => $texto($pedido->input('papel')),
            'situacao' => $texto($pedido->input('situacao')),
        ];
    }

    /**
     * @param  array{busca: string|null, papel: string|null, situacao: string|null}  $filtros
     * @return Builder<User>
     */
    private function consulta(array $filtros): Builder
    {
        $consulta = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->orderBy('id');

        if ($filtros['busca'] !== null) {
            // Sem caixa alta nem baixa: quem procura "MARIA" precisa achar
            // "maria@". `lower()` de ambos os lados, como o comando de criar
            // conta ja faz com o e-mail.
            $termo = '%'.mb_strtolower($filtros['busca']).'%';

            $consulta->where(function (Builder $parte) use ($termo): void {
                $parte->whereRaw('lower(name) like ?', [$termo])
                    ->orWhereRaw('lower(email) like ?', [$termo]);
            });
        }

        if ($filtros['papel'] !== null) {
            $tabelaDePapeis = (string) config('permission.table_names.roles', 'roles');
            $papel = $filtros['papel'];

            $consulta->whereHas(
                'roles',
                fn (Builder $parte): Builder => $parte->where($tabelaDePapeis.'.name', $papel),
            );
        }

        if ($filtros['situacao'] === 'ativos') {
            $consulta->where('ativo', true);
        }

        if ($filtros['situacao'] === 'desativados') {
            $consulta->where('ativo', false);
        }

        return $consulta;
    }
}

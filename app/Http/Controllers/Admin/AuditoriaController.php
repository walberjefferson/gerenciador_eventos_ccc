<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AcaoAuditada;
use App\Http\Controllers\Controller;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;

/**
 * A tela que mostra o rastro das acoes administrativas.
 *
 * **Somente leitura, e nao por falta de tempo.** Nao existe rota para criar,
 * alterar nem apagar registro de auditoria — e o model recusaria de qualquer
 * forma. Uma tela que pudesse editar o proprio rastro nao seria auditoria,
 * seria um caderno de rascunho.
 *
 * Exige a permissao "auditoria.ver", que so o administrador tem. Quem organiza
 * o evento nao precisa ver o que os outros fizeram; quem responde pelo sistema,
 * sim.
 */
class AuditoriaController extends Controller
{
    /**
     * Quantas linhas por pagina. Numero pequeno de proposito: a tela e para
     * ler com atencao, nao para rolar.
     */
    private const POR_PAGINA = 25;

    public function index(Request $pedido): Response
    {
        // A rota ja cobra "permission:auditoria.ver". A conferencia aqui e a
        // segunda tranca: se um dia alguem registrar esta tela em outro grupo
        // de rotas e esquecer o middleware, o 403 continua acontecendo.
        abort_unless($pedido->user()?->can('auditoria.ver') === true, 403);

        $filtros = $this->filtros($pedido);
        $consulta = $this->consulta($filtros);

        $pagina = $consulta->paginate(self::POR_PAGINA)->withQueryString();

        return inertia('Admin/Auditoria/Index', [
            'registros' => [
                'dados' => collect($pagina->items())
                    ->map(fn (LogAuditoria $linha): array => [
                        'id' => (int) $linha->id,
                        'quando' => $linha->created_at?->format('d/m/Y H:i:s'),
                        'responsavel' => $linha->responsavel(),
                        'acao' => $linha->acao->value,
                        'acao_rotulo' => $linha->acao->rotulo(),
                        'entidade' => $linha->entidade,
                        'entidade_id' => $linha->entidade_id === null ? null : (int) $linha->entidade_id,
                        'motivo' => $linha->motivo,
                        'ip' => $linha->ip,
                        // O conteudo ja chega limpo do servico que gravou: o
                        // que era sensivel foi trocado por um aviso de omissao
                        // antes de virar linha no banco. A tela nao precisa
                        // esconder nada, porque nada sensivel esta la.
                        'dados' => $linha->dados,
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
            'opcoes' => [
                'acoes' => array_map(
                    fn (AcaoAuditada $acao): array => ['valor' => $acao->value, 'rotulo' => $acao->rotulo()],
                    AcaoAuditada::cases(),
                ),
                // So aparecem no seletor as pessoas que de fato ja fizeram
                // alguma coisa. Listar todo mundo encheria o campo de nomes
                // que nunca vao devolver resultado nenhum.
                'usuarios' => User::query()
                    ->whereIn('id', LogAuditoria::query()->select('usuario_id')->whereNotNull('usuario_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $usuario): array => ['id' => (int) $usuario->id, 'nome' => $usuario->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array{de: string|null, ate: string|null, usuario_id: string|null, acao: string|null}
     */
    private function filtros(Request $pedido): array
    {
        $texto = static function (mixed $valor): ?string {
            $valor = is_scalar($valor) ? trim((string) $valor) : '';

            return $valor === '' ? null : $valor;
        };

        return [
            'de' => $texto($pedido->input('de')),
            'ate' => $texto($pedido->input('ate')),
            'usuario_id' => $texto($pedido->input('usuario_id')),
            'acao' => $texto($pedido->input('acao')),
        ];
    }

    /**
     * @param  array{de: string|null, ate: string|null, usuario_id: string|null, acao: string|null}  $filtros
     * @return Builder<LogAuditoria>
     */
    private function consulta(array $filtros): Builder
    {
        $consulta = LogAuditoria::query()
            ->with('usuario:id,name')
            // Do mais recente para o mais antigo: quem abre esta tela quase
            // sempre esta atras do que acabou de acontecer.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filtros['de'] !== null) {
            $consulta->where('created_at', '>=', Carbon::parse($filtros['de'])->startOfDay());
        }

        if ($filtros['ate'] !== null) {
            $consulta->where('created_at', '<=', Carbon::parse($filtros['ate'])->endOfDay());
        }

        if ($filtros['usuario_id'] !== null) {
            $consulta->where('usuario_id', (int) $filtros['usuario_id']);
        }

        $acao = AcaoAuditada::tryFrom((string) $filtros['acao']);

        if ($acao instanceof AcaoAuditada) {
            $consulta->where('acao', $acao->value);
        }

        return $consulta;
    }
}

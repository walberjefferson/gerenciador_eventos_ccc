<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SituacaoWebhook;
use App\Http\Controllers\Controller;
use App\Models\WebhookPagamento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;

/**
 * A tela que mostra os avisos automaticos recebidos do provedor de pagamento.
 *
 * **Somente leitura, e nao por falta de tempo.** Aviso de provedor e o
 * registro de algo que aconteceu fora daqui: nao ha o que criar, alterar nem
 * apagar. Tambem nao ha botao de reprocessar — primeiro enxergar, depois
 * decidir se vale agir.
 *
 * Exige a permissao "pagamentos.avisos-ver", que so o administrador tem. O
 * aviso e conversa entre o sistema e a instituicao financeira; quem organiza o
 * evento ja ve o resultado dela na propria inscricao.
 *
 * O payload chega limpo do banco: o controller do webhook trocou por
 * "[removido]" tudo o que costuma carregar segredo antes de gravar. Esta tela
 * nao mascara nada de novo, porque nao ha nada a mascarar aqui.
 */
class AvisosPagamentoController extends Controller
{
    /**
     * Quantas linhas por pagina. O mesmo numero da auditoria, pelo mesmo
     * motivo: e tela para ler com atencao, nao para rolar.
     */
    private const POR_PAGINA = 25;

    public function index(Request $pedido): Response
    {
        // A rota ja cobra "permission:pagamentos.avisos-ver". A conferencia
        // aqui e a segunda tranca: se um dia alguem registrar esta tela em
        // outro grupo de rotas e esquecer o middleware, o 403 continua
        // acontecendo.
        abort_unless($pedido->user()?->can('pagamentos.avisos-ver') === true, 403);

        $filtros = $this->filtros($pedido);

        $pagina = $this->consulta($filtros)->paginate(self::POR_PAGINA)->withQueryString();

        return inertia('Admin/Pagamentos/Avisos/Index', [
            'avisos' => [
                'dados' => collect($pagina->items())
                    ->map(fn (WebhookPagamento $linha): array => [
                        'id' => (int) $linha->id,
                        'gateway' => $linha->gateway,
                        'id_evento_externo' => $linha->id_evento_externo,
                        // A cobranca de que o aviso fala (o txid). Ele e o que
                        // permite sair desta tela e achar a inscricao
                        // correspondente na busca das inscricoes — o
                        // identificador ao lado, apesar do nome parecido,
                        // identifica a transferencia, nao a cobranca.
                        'id_externo' => $linha->id_externo,
                        'tipo_evento' => $linha->tipo_evento,
                        'assinatura_valida' => (bool) $linha->assinatura_valida,
                        'recebido_em' => $linha->recebido_em?->format('d/m/Y H:i:s'),
                        'processado_em' => $linha->processado_em?->format('d/m/Y H:i:s'),
                        'situacao' => $linha->situacao->value,
                        'situacao_rotulo' => $linha->situacao->rotulo(),
                        'erro' => $linha->erro,
                        // Ja gravado sem dado sensivel. Vai junto da linha
                        // porque a tela so o desenha quando alguem expande o
                        // aviso: uma segunda ida ao servidor para reler o mesmo
                        // registro custaria mais do que trafegar o que ja foi
                        // lido.
                        'payload' => $linha->payload,
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
                'situacoes' => array_map(
                    fn (SituacaoWebhook $situacao): array => [
                        'valor' => $situacao->value,
                        'rotulo' => $situacao->rotulo(),
                    ],
                    SituacaoWebhook::cases(),
                ),
                // So os provedores que de fato ja mandaram alguma coisa. Listar
                // os que existem no codigo encheria o seletor de opcoes que
                // nunca devolvem linha nenhuma.
                'gateways' => WebhookPagamento::query()
                    ->distinct()
                    ->orderBy('gateway')
                    ->pluck('gateway')
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array{de: string|null, ate: string|null, situacao: string|null, gateway: string|null, assinatura_valida: string|null}
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
            'situacao' => $texto($pedido->input('situacao')),
            'gateway' => $texto($pedido->input('gateway')),
            'assinatura_valida' => $texto($pedido->input('assinatura_valida')),
        ];
    }

    /**
     * @param  array{de: string|null, ate: string|null, situacao: string|null, gateway: string|null, assinatura_valida: string|null}  $filtros
     * @return Builder<WebhookPagamento>
     */
    private function consulta(array $filtros): Builder
    {
        // Sem "with" nenhum, de proposito: o aviso nao tem relacao carregada, e
        // por isso a lista custa uma consulta so, com 1 ou com 25 linhas. A
        // ordenacao cai no indice ['situacao', 'recebido_em'] que ja existe.
        $consulta = WebhookPagamento::query()
            // Do mais recente para o mais antigo: quem abre esta tela quase
            // sempre esta atras do aviso que acabou de chegar — ou do que
            // deveria ter chegado.
            ->orderByDesc('recebido_em')
            ->orderByDesc('id');

        if ($filtros['de'] !== null) {
            $consulta->where('recebido_em', '>=', Carbon::parse($filtros['de'])->startOfDay());
        }

        if ($filtros['ate'] !== null) {
            $consulta->where('recebido_em', '<=', Carbon::parse($filtros['ate'])->endOfDay());
        }

        $situacao = SituacaoWebhook::tryFrom((string) $filtros['situacao']);

        if ($situacao instanceof SituacaoWebhook) {
            $consulta->where('situacao', $situacao->value);
        }

        if ($filtros['gateway'] !== null) {
            $consulta->where('gateway', $filtros['gateway']);
        }

        // "sim"/"nao" e nao um booleano cru: assim o filtro ausente ("") e
        // distinguivel de "assinatura invalida", que e o caso que interessa
        // ver.
        if ($filtros['assinatura_valida'] === 'sim' || $filtros['assinatura_valida'] === 'nao') {
            $consulta->where('assinatura_valida', $filtros['assinatura_valida'] === 'sim');
        }

        return $consulta;
    }
}

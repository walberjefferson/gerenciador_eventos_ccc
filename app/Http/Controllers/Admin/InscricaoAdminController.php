<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MetodoPagamento;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\LinhaDaInscricaoResource;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\Evento;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Admin\FiltroDeInscricoes;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * A lista de inscricoes: onde o organizador acha uma pessoa.
 *
 * Os filtros se combinam — evento, situacao, setor, grupo, atividade
 * escolhida, situacao da cobranca e periodo — e a busca por texto olha nome,
 * e-mail e codigo publico. **CPF nao entra**: esta cifrado e a impressao
 * digital so serve para comparar o numero inteiro, nunca um pedaco.
 *
 * A paginacao carrega os filtros junto, para que a segunda pagina seja mesmo a
 * continuacao do que a pessoa estava vendo.
 */
class InscricaoAdminController extends Controller
{
    public function index(Request $pedido): Response
    {
        $this->authorize('viewAny', Inscricao::class);

        $filtro = FiltroDeInscricoes::doPedido($pedido);

        $pagina = $filtro->consulta()
            ->paginate(25)
            ->withQueryString();

        return inertia('Admin/Inscricoes/Index', [
            'inscricoes' => [
                'dados' => collect($pagina->items())
                    ->map(fn (Inscricao $inscricao): array => LinhaDaInscricaoResource::paraTela($inscricao))
                    ->all(),
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'por_pagina' => $pagina->perPage(),
                'links' => [
                    'anterior' => $pagina->previousPageUrl(),
                    'proxima' => $pagina->nextPageUrl(),
                ],
            ],
            'filtros' => $filtro->valores(),
            'opcoes' => $this->opcoes($filtro),
            'pode_exportar' => $pedido->user()?->can('inscricoes.exportar') ?? false,
            'sucesso' => session('sucesso'),
        ]);
    }

    /**
     * A ficha de uma inscricao, com o historico da cobranca.
     *
     * O historico e a parte que importa: cada cobranca emitida, com a situacao
     * em que parou e — quando o pagamento foi reconhecido na mao — quem
     * declarou isso e o que escreveu. Sem esse registro, "esta pago" e so uma
     * afirmacao sem dono.
     *
     * O CPF nao aparece. Nem cifrado, nem em pedaco, nem a impressao digital.
     */
    public function show(Request $pedido, Inscricao $inscricao): Response
    {
        $this->authorize('view', $inscricao);

        $inscricao->load([
            'evento:id,nome,slug',
            'grupoParticipante:id,nome,cidade_id',
            'grupoParticipante.cidade:id,nome,uf',
            'atividades:id,nome,comeca_em,termina_em',
            'pagamentos',
        ]);

        $usuario = $pedido->user();

        return inertia('Admin/Inscricoes/Show', [
            'inscricao' => [
                'id' => $inscricao->id,
                'codigo_publico' => $inscricao->codigo_publico,
                'nome_completo' => $inscricao->nome_completo,
                'email' => $inscricao->email,
                'telefone' => $inscricao->telefone,
                'evento' => $inscricao->evento?->nome ?? '',
                'cidade' => LinhaDaInscricaoResource::cidade($inscricao),
                'grupo' => $inscricao->grupoParticipante?->nome ?? '',
                'situacao' => $inscricao->situacao->value,
                'situacao_rotulo' => $inscricao->situacao->rotulo(),
                'valor_centavos' => $inscricao->valor_centavos,
                'prazo_pagamento' => $inscricao->prazo_pagamento?->toIso8601String(),
                'criada_em' => $inscricao->created_at?->toIso8601String(),
                'confirmada_em' => $inscricao->confirmada_em?->toIso8601String(),
                'expirada_em' => $inscricao->expirada_em?->toIso8601String(),
                'cancelada_em' => $inscricao->cancelada_em?->toIso8601String(),
                'motivo_cancelamento' => $inscricao->motivo_cancelamento,
                'atividades' => $inscricao->atividades
                    ->map(fn ($atividade): array => [
                        'id' => (int) $atividade->id,
                        'nome' => $atividade->nome,
                        // Nulos quando a atividade não tem hora marcada.
                        'comeca_em' => $atividade->comeca_em?->toIso8601String(),
                        'termina_em' => $atividade->termina_em?->toIso8601String(),
                    ])
                    ->all(),
                'esta_ativa' => $inscricao->situacao->estaAtiva(),
                'foi_paga' => $inscricao->pagamentos->contains(
                    fn (Pagamento $pagamento): bool => $pagamento->situacao === SituacaoPagamento::Pago
                ),
            ],
            'cobrancas' => $this->historicoDeCobrancas($inscricao),
            'metodos_manuais' => array_map(
                fn (MetodoPagamento $metodo): array => ['valor' => $metodo->value, 'rotulo' => $metodo->rotulo()],
                MetodoPagamento::manuais(),
            ),
            'pode_cancelar' => $usuario?->can('inscricoes.cancelar') ?? false,
            'pode_confirmar_manualmente' => $usuario?->can('pagamentos.confirmar-manual') ?? false,
            'sucesso' => session('sucesso'),
        ]);
    }

    /**
     * O historico da cobranca, da mais recente para a mais antiga.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historicoDeCobrancas(Inscricao $inscricao): array
    {
        return $inscricao->pagamentos
            ->sortByDesc('id')
            ->values()
            ->map(function (Pagamento $pagamento): array {
                $metadados = is_array($pagamento->metadados) ? $pagamento->metadados : [];
                $responsavel = is_array($metadados['responsavel'] ?? null) ? $metadados['responsavel'] : null;

                return [
                    'id' => $pagamento->id,
                    'codigo_publico' => $pagamento->codigo_publico,
                    // O identificador da cobranca no provedor — o txid, na Efi.
                    // Vai para a tela porque e por ele que se procura a
                    // cobranca no painel da instituicao financeira; o
                    // `codigo_publico` acima nao existe do lado de la. Fica
                    // nulo quando o pagamento foi reconhecido na mao, e a tela
                    // desenha isso como vazio.
                    'id_externo' => $pagamento->id_externo,
                    'gateway' => $pagamento->gateway,
                    'metodo' => $pagamento->metodo->value,
                    'metodo_rotulo' => $pagamento->metodo->rotulo(),
                    'situacao' => $pagamento->situacao->value,
                    'situacao_rotulo' => $pagamento->situacao->rotulo(),
                    'valor_centavos' => $pagamento->valor_centavos,
                    'criada_em' => $pagamento->created_at?->toIso8601String(),
                    'expira_em' => $pagamento->expira_em?->toIso8601String(),
                    'pago_em' => $pagamento->pago_em?->toIso8601String(),
                    'cancelado_em' => $pagamento->cancelado_em?->toIso8601String(),
                    // Quem pagou, quando o aviso do provedor tiver dito. Vem
                    // do mesmo jsonb da observacao, e ja chega com o CPF
                    // mascarado de quando o aviso foi gravado.
                    'pagador' => is_array($metadados['pagador'] ?? null) ? $metadados['pagador'] : null,
                    'origem_manual' => ($metadados['origem'] ?? null) === 'manual',
                    'observacao' => is_string($metadados['observacao'] ?? null) ? $metadados['observacao'] : null,
                    'responsavel' => is_string($responsavel['nome'] ?? null) ? $responsavel['nome'] : null,
                ];
            })
            ->all();
    }

    /**
     * As listas que alimentam os seletores de filtro.
     *
     * As atividades so aparecem quando ha um evento escolhido: sem isso, a
     * lista misturaria a programacao de todos os eventos e nao ajudaria
     * ninguem.
     *
     * @return array<string, mixed>
     */
    private function opcoes(FiltroDeInscricoes $filtro): array
    {
        $eventoId = $filtro->valores()['evento_id'];

        return [
            'eventos' => Evento::query()
                ->orderByDesc('data_inicio')
                ->get(['id', 'nome'])
                ->map(fn (Evento $evento): array => ['id' => $evento->id, 'nome' => $evento->nome])
                ->all(),
            // Os setores do filtro. O rotulo e so o nome: a UF servia para
            // separar cidades homonimas de estados diferentes, e os setores da
            // comunidade sao todos da mesma regiao. A chave da prop continua
            // sendo `cidades`, como a coluna.
            'cidades' => Cidade::query()
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (Cidade $cidade): array => ['id' => $cidade->id, 'nome' => $cidade->nome])
                ->all(),
            'grupos' => GrupoParticipante::query()
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (GrupoParticipante $grupo): array => ['id' => $grupo->id, 'nome' => $grupo->nome])
                ->all(),
            'atividades' => $eventoId === null ? [] : $this->atividadesDoEvento((int) $eventoId),
            'situacoes' => array_map(
                fn (SituacaoInscricao $situacao): array => ['valor' => $situacao->value, 'rotulo' => $situacao->rotulo()],
                SituacaoInscricao::cases(),
            ),
            'situacoes_pagamento' => array_map(
                fn (SituacaoPagamento $situacao): array => ['valor' => $situacao->value, 'rotulo' => $situacao->rotulo()],
                SituacaoPagamento::cases(),
            ),
        ];
    }

    /**
     * @return array<int, array{id: int, nome: string}>
     */
    private function atividadesDoEvento(int $eventoId): array
    {
        return Atividade::query()
            ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
            ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
            ->where('dias_evento.evento_id', $eventoId)
            ->orderBy('dias_evento.posicao')
            ->orderBy('atividades.posicao')
            ->orderBy('atividades.id')
            ->get(['atividades.id', 'atividades.nome', 'dias_evento.nome as dia'])
            ->map(fn (Atividade $atividade): array => [
                'id' => (int) $atividade->id,
                'nome' => $atividade->getAttribute('dia').' · '.$atividade->nome,
            ])
            ->all();
    }
}

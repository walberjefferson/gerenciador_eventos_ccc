<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\LinhaDaInscricaoResource;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\Evento;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use App\Services\Admin\FiltroDeInscricoes;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * A lista de inscricoes: onde o organizador acha uma pessoa.
 *
 * Os filtros se combinam — evento, situacao, cidade, grupo, atividade
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
            'cidades' => Cidade::query()
                ->orderBy('nome')
                ->get(['id', 'nome', 'uf'])
                ->map(fn (Cidade $cidade): array => ['id' => $cidade->id, 'nome' => "{$cidade->nome}/{$cidade->uf}"])
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

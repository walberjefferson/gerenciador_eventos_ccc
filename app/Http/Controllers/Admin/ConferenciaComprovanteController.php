<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Comprovantes\ConferirComprovante;
use App\Exceptions\Pagamentos\ComprovanteRecusadoException;
use App\Exceptions\Pagamentos\ConfirmacaoManualRecusadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConferirComprovanteRequest;
use App\Models\Cidade;
use App\Models\ComprovantePagamento;
use App\Policies\ComprovantePagamentoPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A fila de conferencia dos comprovantes de pagamento.
 *
 * Quem entra aqui ve APENAS o proprio setor (RN-S9), e o recorte e do servidor:
 * ele nao e um filtro na tela, nao viaja na URL e nao tem como ser trocado. A
 * regra mora inteira em ComprovantePagamentoPolicy e e cobrada em cada uma das
 * quatro portas — inclusive na do download, que e a que alguem tentaria pela
 * URL direta.
 *
 * **A ordem da fila e uma mitigacao, nao um detalhe de tela.** O sistema nao
 * tem situacao "em conferencia": o prazo da inscricao corre enquanto o
 * comprovante espera, e a rotina de expiracao — que roda de minuto em minuto e
 * nao sabe o que e comprovante — pode devolver a vaga com o dinheiro ja na
 * conta do responsavel. Por isso a fila vem ordenada pelo prazo MAIS PROXIMO, e
 * nao pela chegada, e destaca o que vence em menos de 24 horas. Isso reduz a
 * chance; nao a elimina.
 */
class ConferenciaComprovanteController extends Controller
{
    /** A partir daqui a linha aparece em vermelho na fila. */
    private const HORAS_DE_ALERTA = 24;

    public function index(Request $pedido): Response
    {
        $this->authorize('viewAny', ComprovantePagamento::class);

        $usuario = $pedido->user();
        $agora = Carbon::now();

        $comprovantes = ComprovantePagamento::query()
            ->with([
                'inscricao:id,codigo_publico,nome_completo,email,evento_id,grupo_participante_id,situacao,valor_centavos,prazo_pagamento',
                'inscricao.evento:id,nome',
                'inscricao.grupoParticipante:id,nome,cidade_id',
                'inscricao.grupoParticipante.cidade:id,nome',
                'conferidoPor:id,name',
            ])
            ->when(
                ComprovantePagamentoPolicy::estaRecortadoPorSetor($usuario),
                fn (Builder $consulta) => $consulta->whereHas(
                    'inscricao.grupoParticipante',
                    fn (Builder $grupo) => $grupo->whereIn(
                        'cidade_id',
                        ComprovantePagamentoPolicy::setoresDe($usuario),
                    ),
                ),
            )
            ->emAberto()
            ->get()
            // A ordenacao e feita aqui, e nao no banco, porque a chave e o prazo
            // da INSCRICAO — que esta noutra tabela — e a fila de um setor cabe
            // com folga na memoria: sao os comprovantes em aberto, nao todos.
            ->sortBy(fn (ComprovantePagamento $comprovante): string => (string) (
                $comprovante->inscricao?->prazo_pagamento?->toIso8601String() ?? '9999'
            ))
            ->values();

        return inertia('Admin/Comprovantes/Index', [
            'comprovantes' => $comprovantes
                ->map(fn (ComprovantePagamento $comprovante): array => $this->linha($comprovante, $agora))
                ->all(),
            'escopo' => [
                'recortado_por_setor' => ComprovantePagamentoPolicy::estaRecortadoPorSetor($usuario),
                'setores' => Cidade::query()
                    ->whereIn('id', ComprovantePagamentoPolicy::setoresDe($usuario))
                    ->orderBy('nome')
                    ->pluck('nome')
                    ->all(),
            ],
            'horas_de_alerta' => self::HORAS_DE_ALERTA,
            'sucesso' => session('sucesso'),
        ]);
    }

    /**
     * O arquivo, entregue por download.
     *
     * O disco e privado e nao tem URL: nao existe endereco publico deste
     * arquivo para adivinhar. A unica porta e esta, e ela confere o escopo de
     * setor antes de abrir qualquer coisa (RN-S11).
     */
    public function show(ComprovantePagamento $comprovante): StreamedResponse
    {
        $this->authorize('view', $comprovante);

        $disco = Storage::disk('comprovantes');

        abort_unless($disco->exists($comprovante->caminho), 404);

        // O nome que volta e o que a PESSOA deu ao arquivo, e nao o nome gerado
        // em disco: e por ele que quem confere reconhece o que baixou.
        return $disco->download($comprovante->caminho, $comprovante->nome_original);
    }

    /**
     * Aceitar: o dinheiro entrou, a inscricao confirma.
     *
     * A regra nao esta aqui — ela esta em ConfirmarPagamentoManual, alcancada
     * por ConferirComprovante (RN-S10). Este metodo so traduz as recusas do
     * dominio para uma frase no campo certo do formulario.
     */
    public function aceitar(
        ConferirComprovanteRequest $pedido,
        ComprovantePagamento $comprovante,
        ConferirComprovante $conferir,
    ): RedirectResponse {
        $this->authorize('conferir', $comprovante);

        try {
            $conferir->aceitar($comprovante, $pedido->user(), $pedido->observacao());
        } catch (ComprovanteRecusadoException|ConfirmacaoManualRecusadaException|InvalidArgumentException $recusa) {
            return back()->withErrors(['observacao' => $recusa->getMessage()]);
        }

        $nome = $comprovante->inscricao?->nome_completo ?? 'participante';

        return back()->with('sucesso', "Comprovante aceito. A inscrição de {$nome} está confirmada.");
    }

    /**
     * Recusar: o comprovante nao serve, e o participante precisa saber por que.
     *
     * A inscricao NAO e tocada: ela segue aguardando pagamento ate o prazo, e a
     * pessoa pode mandar outro comprovante.
     */
    public function recusar(
        ConferirComprovanteRequest $pedido,
        ComprovantePagamento $comprovante,
        ConferirComprovante $conferir,
    ): RedirectResponse {
        $this->authorize('conferir', $comprovante);

        try {
            $conferir->recusar($comprovante, $pedido->user(), $pedido->motivo());
        } catch (ComprovanteRecusadoException|InvalidArgumentException $recusa) {
            return back()->withErrors(['motivo' => $recusa->getMessage()]);
        }

        return back()->with(
            'sucesso',
            'Comprovante recusado. O participante vê o motivo na tela dele e pode enviar outro.'
        );
    }

    /**
     * Uma linha da fila.
     *
     * Ela carrega o prazo e o quanto falta para ele — em horas — porque e disso
     * que a tela precisa para pintar de vermelho o que esta perto de vencer.
     * A conta e feita no servidor de proposito: o relogio do navegador de quem
     * confere pode estar em qualquer fuso, e o da aplicacao e um so.
     *
     * @return array<string, mixed>
     */
    private function linha(ComprovantePagamento $comprovante, Carbon $agora): array
    {
        $inscricao = $comprovante->inscricao;
        $prazo = $inscricao?->prazo_pagamento;

        $horasRestantes = $prazo === null ? null : $agora->diffInHours($prazo, false);

        return [
            'id' => (int) $comprovante->id,
            'nome_original' => $comprovante->nome_original,
            'mime' => $comprovante->mime,
            'tamanho_bytes' => (int) $comprovante->tamanho_bytes,
            'enviado_em' => $comprovante->enviado_em?->toIso8601String(),
            'situacao' => $comprovante->situacao->value,
            'situacao_rotulo' => $comprovante->situacao->rotulo(),
            'inscricao' => [
                'id' => (int) ($inscricao?->id ?? 0),
                'codigo_publico' => $inscricao?->codigo_publico,
                'nome_completo' => $inscricao?->nome_completo,
                'email' => $inscricao?->email,
                'evento' => $inscricao?->evento?->nome,
                'setor' => $inscricao?->grupoParticipante?->cidade?->nome,
                'grupo' => $inscricao?->grupoParticipante?->nome,
                'valor_centavos' => (int) ($inscricao?->valor_centavos ?? 0),
                'situacao' => $inscricao?->situacao->value,
                'situacao_rotulo' => $inscricao?->situacao->rotulo(),
                'prazo_pagamento' => $prazo?->toIso8601String(),
            ],
            'horas_ate_o_prazo' => $horasRestantes === null ? null : (int) $horasRestantes,
            // Vence em menos de 24 horas (ou ja venceu): a fila pinta isso de
            // vermelho, porque e o caso em que a demora custa a vaga de alguem.
            'urgente' => $horasRestantes !== null && $horasRestantes < self::HORAS_DE_ALERTA,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Inscricoes\CancelarInscricaoAdministrativa;
use App\Actions\Pagamentos\ConfirmarPagamentoManual;
use App\Exceptions\Pagamentos\ConfirmacaoManualRecusadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelarInscricaoRequest;
use App\Http\Requests\Admin\ConfirmarPagamentoManualRequest;
use App\Models\Inscricao;
use Illuminate\Http\RedirectResponse;

/**
 * As duas acoes que a organizacao pode tomar sobre uma inscricao concreta.
 *
 * Nenhuma das duas tem regra de dominio aqui dentro: quem sabe devolver vaga e
 * quem sabe reconhecer dinheiro sao as Actions. Este controller so faz o que um
 * controller deve fazer — confere quem pode, valida o que veio do formulario,
 * chama a Action e conta o resultado em portugues.
 */
class AcaoInscricaoController extends Controller
{
    /**
     * Cancela a inscricao e devolve a vaga na hora.
     *
     * Inscricao ja confirmada tambem pode ser cancelada, e **nao ha estorno**:
     * o dinheiro so volta por decisao de gente, porque politica de reembolso
     * ainda nao existe neste sistema (P-02). A tela avisa isso antes do clique.
     */
    public function cancelar(
        CancelarInscricaoRequest $pedido,
        Inscricao $inscricao,
        CancelarInscricaoAdministrativa $cancelar,
    ): RedirectResponse {
        $this->authorize('cancelar', $inscricao);

        $cancelou = $cancelar(
            $inscricao,
            (string) $pedido->string('motivo'),
            $pedido->user(),
        );

        if (! $cancelou) {
            return back()->with('sucesso', 'Esta inscrição já não estava mais ativa: nada foi alterado.');
        }

        return back()->with('sucesso', 'Inscrição cancelada e vaga devolvida.');
    }

    /**
     * Reconhece um pagamento que entrou por fora do sistema.
     *
     * E exclusiva do administrador (DA-13): e a unica acao que diz "entrou
     * dinheiro" sem que nenhuma fonte externa tenha reconhecido nada.
     */
    public function confirmarPagamento(
        ConfirmarPagamentoManualRequest $pedido,
        Inscricao $inscricao,
        ConfirmarPagamentoManual $confirmar,
    ): RedirectResponse {
        $this->authorize('confirmarManualmente', $inscricao);

        $responsavel = $pedido->user();

        abort_if($responsavel === null, 403);

        try {
            $confirmou = $confirmar(
                $inscricao,
                $responsavel,
                $pedido->metodo(),
                (string) $pedido->string('observacao'),
            );
        } catch (ConfirmacaoManualRecusadaException $recusa) {
            // A recusa e uma resposta de negocio, nao um defeito: volta para o
            // campo do formulario, em portugues, como qualquer outro erro.
            return back()->withErrors(['observacao' => $recusa->getMessage()]);
        }

        if (! $confirmou) {
            return back()->with('sucesso', 'Esta inscrição já estava confirmada: nada foi alterado.');
        }

        return back()->with('sucesso', 'Pagamento reconhecido e inscrição confirmada.');
    }
}

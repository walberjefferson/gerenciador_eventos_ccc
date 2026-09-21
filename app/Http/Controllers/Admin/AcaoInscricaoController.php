<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Comunicacao\ReenviarComunicacao;
use App\Actions\Inscricoes\CancelarInscricaoAdministrativa;
use App\Actions\Pagamentos\ConfirmarPagamentoManual;
use App\Enums\AcaoAuditada;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Pagamentos\ConfirmacaoManualRecusadaException;
use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelarInscricaoRequest;
use App\Http\Requests\Admin\ConfirmarPagamentoManualRequest;
use App\Http\Requests\Admin\ReenviarComunicacaoRequest;
use App\Models\Inscricao;
use App\Services\Ingressos\PdfDoIngresso;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * As acoes que a organizacao pode tomar sobre uma inscricao concreta.
 *
 * Nenhuma delas tem regra de dominio aqui dentro: quem sabe devolver vaga, quem
 * sabe reconhecer dinheiro e quem sabe se uma mensagem cabe naquela situacao sao
 * as Actions. Este controller so faz o que um controller deve fazer — confere
 * quem pode, valida o que veio do formulario, chama a Action e conta o resultado
 * em portugues.
 */
class AcaoInscricaoController extends Controller
{
    use RegistraAuditoria;

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

    /**
     * Manda de novo uma mensagem que a pessoa diz nao ter recebido.
     *
     * A recusa por situacao errada sobe da Action como erro de validacao e cai
     * sozinha no campo "tipo" do formulario — nao ha o que tratar aqui.
     *
     * O que fica registrado e a auditoria, e nao "comunicacoes_enviadas": aquela
     * tabela guarda o envio AUTOMATICO, e a unicidade dela existe justamente
     * para impedir a segunda copia que este botao acabou de produzir de
     * proposito (RN-A1).
     */
    public function reenviarComunicacao(
        ReenviarComunicacaoRequest $pedido,
        Inscricao $inscricao,
        ReenviarComunicacao $reenviar,
    ): RedirectResponse {
        $this->authorize('reenviarComunicacao', $inscricao);

        $tipo = $pedido->tipo();

        $destino = $reenviar($inscricao, $tipo);

        $rotulo = ReenviarComunicacao::rotulo($tipo);

        // O endereco vai junto de propósito: trocar o e-mail de uma inscricao e
        // reenviar o link de acesso sao duas acoes legitimas que, em sequencia,
        // entregam o acesso a outra caixa de entrada. Sem o destino no
        // registro, a segunda metade dessa historia nao apareceria.
        $this->auditar(
            AcaoAuditada::ReenviouComunicacao,
            'inscricao',
            (int) $inscricao->getKey(),
            ['mensagem' => $rotulo, 'destino' => $destino],
        );

        return back()->with('sucesso', "“{$rotulo}” está a caminho de {$destino}.");
    }

    /**
     * O ingresso em PDF, entregue pelo painel.
     *
     * Serve a quem esta no balcao com a pessoa na frente, sem o link assinado em
     * maos. As duas trancas sao as mesmas do controller do participante, e pela
     * mesma razao: **so inscricao confirmada tem ingresso**. Entregar um PDF a
     * quem ainda deve seria imprimir um codigo que a portaria recusaria depois,
     * na frente da fila.
     *
     * Quem pode abrir a ficha pode imprimir o ingresso dela: a permissao e
     * "inscricoes.ver" (cobrada na rota) mais o alcance de setor, que a policy
     * confere aqui.
     */
    public function ingresso(Inscricao $inscricao, PdfDoIngresso $pdfDoIngresso): Response
    {
        $this->authorize('view', $inscricao);

        $inscricao->loadMissing(['evento', 'ingresso']);

        abort_unless($inscricao->situacao === SituacaoInscricao::Confirmada, 403);
        abort_unless($inscricao->ingresso !== null, 403);

        $pdf = $pdfDoIngresso($inscricao->ingresso);

        // Nome de arquivo com o codigo publico, e nao com o codigo do ingresso:
        // quem baixa dois ingressos da mesma familia nao pode acabar com dois
        // arquivos de mesmo nome na pasta de downloads.
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ingresso-'.$inscricao->codigo_publico.'.pdf"',
            // O ingresso e de uma pessoa so: nenhum intermediario guarda copia.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}

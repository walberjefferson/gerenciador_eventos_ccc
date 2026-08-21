<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Actions\Pagamentos\CancelarPagamento;
use App\Enums\AcaoAuditada;
use App\Enums\SituacaoInscricao;
use App\Events\InscricaoCancelada;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancela, por decisao da organizacao, a inscricao de alguem — e devolve as
 * vagas que ela ocupava.
 *
 * E irma da rotina de expiracao e segue exatamente o mesmo molde: a mudanca de
 * situacao acontece em um comando que exige a situacao anterior, as vagas so
 * voltam na execucao que de fato mudou a linha, e o anuncio sai depois que a
 * transacao fecha.
 *
 * Duas diferencas em relacao a expiracao, e as duas importam:
 *
 * 1. Existe um responsavel e um motivo escrito. Acao administrativa sem
 *    justificativa e rastro que nao explica nada, entao o motivo e obrigatorio.
 * 2. Inscricao ja confirmada tambem pode ser cancelada — o organizador precisa
 *    disso quando alguem desiste depois de pagar. Nesse caso a vaga que volta e
 *    a vaga paga, e **nenhum estorno acontece**: devolver dinheiro depende de
 *    uma politica que ainda nao existe, e software nao toma decisao financeira
 *    sozinho. A tela avisa a pessoa que aperta o botao.
 *
 * E idempotente: cancelar duas vezes muda tudo na primeira e nada na segunda,
 * entao a vaga nunca volta em dobro — nem quando a rotina de expiracao chega
 * ao mesmo registro no mesmo instante.
 *
 * Nada e apagado. A inscricao continua no banco, agora com a situacao, o
 * momento e o motivo do cancelamento gravados.
 */
class CancelarInscricaoAdministrativa
{
    public function __construct(
        private readonly LiberarVagas $liberarVagas,
        private readonly CancelarPagamento $cancelarPagamento,
        private readonly RegistrarAcao $registrarAcao,
    ) {}

    /**
     * @param  string  $motivo  justificativa escrita por quem cancelou
     * @param  User|null  $responsavel  quem apertou o botao
     * @return bool true se esta chamada foi quem cancelou; false se a inscricao
     *              ja havia saido do ar antes (outra aba, outro usuario ou a
     *              propria rotina de expiracao chegou primeiro)
     *
     * @throws InvalidArgumentException quando o motivo vem vazio
     */
    public function __invoke(
        Inscricao $inscricao,
        string $motivo,
        ?User $responsavel = null,
        ?Carbon $momento = null,
    ): bool {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new InvalidArgumentException('Informe o motivo do cancelamento.');
        }

        // O banco guarda no maximo 255 caracteres; cortar aqui evita que o
        // PostgreSQL recuse a gravacao por um caractere a mais.
        $motivo = mb_substr($motivo, 0, 255);

        $momento ??= Carbon::now();
        $estavaConfirmada = false;

        $cancelou = DB::transaction(function () use ($inscricao, $motivo, $momento, &$estavaConfirmada): bool {
            // Duas tentativas condicionais, uma por situacao de origem, porque a
            // vaga que volta e diferente em cada caso: quem estava aguardando
            // pagamento prendia vaga reservada; quem estava confirmada ocupava
            // vaga paga. A condicao faz o papel de trava: se outro processo
            // mudou a situacao no meio do caminho, nenhuma linha e alterada e
            // nada volta em dobro.
            if ($this->marcarCancelada($inscricao, SituacaoInscricao::AguardandoPagamento, $motivo, $momento)) {
                $this->liberarVagas->liberarReserva($inscricao);
            } elseif ($this->marcarCancelada($inscricao, SituacaoInscricao::Confirmada, $motivo, $momento)) {
                $estavaConfirmada = true;

                $this->liberarVagas->liberarConfirmada($inscricao);
            } else {
                return false;
            }

            $this->encerrarCobrancas($inscricao);

            return true;
        });

        if (! $cancelou) {
            return false;
        }

        $inscricao->refresh();

        // O rastro e gravado depois do commit e so na chamada que de fato
        // cancelou. Fica fora da transacao de proposito: se a gravacao falhar,
        // a vaga ja voltou e a inscricao ja esta cancelada — auditoria e
        // testemunha, e testemunha nao desfaz o que viu.
        //
        // Nada de dado pessoal entra aqui: o registro diz de qual situacao
        // para qual situacao a inscricao foi, e o codigo publico serve para
        // encontrar a pessoa sem repetir nome, e-mail nem documento.
        ($this->registrarAcao)(
            AcaoAuditada::CancelouInscricao,
            'inscricao',
            (int) $inscricao->getKey(),
            [
                'codigo_publico' => $inscricao->codigo_publico,
                'situacao' => [
                    'antes' => $estavaConfirmada
                        ? SituacaoInscricao::Confirmada->value
                        : SituacaoInscricao::AguardandoPagamento->value,
                    'depois' => SituacaoInscricao::Cancelada->value,
                ],
                'estava_confirmada' => $estavaConfirmada,
            ],
            $motivo,
            $responsavel,
        );

        // O anuncio sai depois do commit e so na chamada que de fato mudou a
        // situacao: ninguem deve ser avisado de um cancelamento que o banco
        // ainda pode desfazer, nem avisado duas vezes do mesmo cancelamento.
        InscricaoCancelada::dispatch($inscricao, $motivo, $responsavel, $estavaConfirmada);

        return true;
    }

    /**
     * Muda a situacao apenas se ela ainda for a situacao de origem informada.
     */
    private function marcarCancelada(
        Inscricao $inscricao,
        SituacaoInscricao $origem,
        string $motivo,
        Carbon $momento,
    ): bool {
        $linhas = Inscricao::query()
            ->whereKey($inscricao->getKey())
            ->where('situacao', $origem->value)
            ->update([
                'situacao' => SituacaoInscricao::Cancelada->value,
                'cancelada_em' => $momento,
                'motivo_cancelamento' => $motivo,
                'updated_at' => Carbon::now(),
            ]);

        return $linhas === 1;
    }

    /**
     * Fecha a porta do dinheiro: nenhuma cobranca pode continuar aceitando Pix
     * de uma vaga que ja voltou para a fila.
     *
     * Cobranca ja paga nao e tocada — e justamente o caso do cancelamento sem
     * estorno, em que o pagamento continua registrado como recebido.
     */
    private function encerrarCobrancas(Inscricao $inscricao): void
    {
        Pagamento::query()
            ->where('inscricao_id', $inscricao->getKey())
            ->pendentes()
            ->get()
            ->each(function (Pagamento $pagamento): void {
                ($this->cancelarPagamento)($pagamento);
            });
    }
}

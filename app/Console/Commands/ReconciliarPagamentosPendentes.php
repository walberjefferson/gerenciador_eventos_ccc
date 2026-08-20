<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Pagamentos\CancelarPagamento;
use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Contracts\Payments\PaymentGateway;
use App\Enums\SituacaoPagamento;
use App\Models\Pagamento;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de seguranca do aviso automatico.
 *
 * O provedor avisa por webhook quando alguem paga. Se esse aviso se perder —
 * rede fora do ar, deploy no momento errado, fila parada —, o dinheiro entrou
 * e a inscricao continuaria aguardando pagamento ate o prazo vencer.
 *
 * Este comando pergunta diretamente ao provedor, servidor a servidor, qual e a
 * situacao das cobrancas que estao perto de vencer ou ja venceram, e aplica
 * exatamente o mesmo caminho de confirmacao que o aviso aplicaria.
 *
 * E idempotente: quem ja foi confirmado nao e confirmado de novo, porque a
 * Action de confirmacao exige a situacao anterior para agir. Rodar duas vezes
 * seguidas nao conta vaga em dobro.
 */
class ReconciliarPagamentosPendentes extends Command
{
    protected $signature = 'pagamentos:reconciliar
                            {--margem=15 : quantos minutos antes do vencimento a cobranca ja entra na consulta}
                            {--lote=100 : quantas cobrancas por lote}';

    protected $description = 'Consulta o provedor sobre cobrancas pendentes e aplica o que ele responder';

    public function handle(
        PaymentGateway $gateway,
        ConfirmarPagamento $confirmar,
        CancelarPagamento $cancelar,
    ): int {
        $limite = Carbon::now()->addMinutes((int) $this->option('margem'));
        $consultadas = 0;
        $confirmadas = 0;
        $encerradas = 0;
        $falhas = 0;

        Pagamento::query()
            ->pendentes()
            ->whereNotNull('id_externo')
            ->whereNotNull('expira_em')
            ->where('expira_em', '<=', $limite)
            ->chunkById((int) $this->option('lote'), function (Collection $pagamentos) use (
                $gateway, $confirmar, $cancelar, &$consultadas, &$confirmadas, &$encerradas, &$falhas
            ): void {
                foreach ($pagamentos as $pagamento) {
                    $consultadas++;

                    try {
                        $resposta = $gateway->getPayment((string) $pagamento->id_externo);
                    } catch (Throwable $erro) {
                        $falhas++;

                        // Provedor indisponivel nao pode derrubar o lote inteiro:
                        // a proxima execucao volta a olhar para esta cobranca.
                        Log::warning('Nao foi possivel consultar a cobranca no provedor.', [
                            'pagamento' => $pagamento->codigo_publico,
                            'motivo' => $erro->getMessage(),
                        ]);

                        continue;
                    }

                    $situacao = SituacaoPagamento::deStatusExterno($resposta->status);

                    if ($situacao === SituacaoPagamento::Pago) {
                        $confirmadas += $confirmar($pagamento, $resposta->paidAt) ? 1 : 0;

                        continue;
                    }

                    if ($situacao !== null && ! $situacao->estaAberta()) {
                        // O provedor ja fechou a cobranca. Fechamos aqui tambem,
                        // sem avisa-lo de volta e sem tocar em contador de vaga:
                        // quem devolve vaga e a expiracao da inscricao.
                        $encerradas += $cancelar($pagamento, $situacao, avisarProvedor: false) ? 1 : 0;
                    }
                }
            });

        $this->components->info(
            "Cobrancas consultadas: {$consultadas}. Confirmadas: {$confirmadas}. Encerradas: {$encerradas}. Sem resposta: {$falhas}."
        );

        return self::SUCCESS;
    }
}

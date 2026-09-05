<?php

declare(strict_types=1);

namespace App\Actions\Comprovantes;

use App\Actions\Pagamentos\ConfirmarPagamentoManual;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoComprovante;
use App\Exceptions\Pagamentos\ComprovanteRecusadoException;
use App\Models\ComprovantePagamento;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A conferencia do comprovante: uma pessoa olha o arquivo e resolve.
 *
 * **Aceitar nao e uma regra de dinheiro nova** (RN-S10). Ele delega para
 * ConfirmarPagamentoManual, que ja e o unico caminho do sistema para "entrou
 * dinheiro sem provedor nenhum ter reconhecido": a vaga presa vira vaga paga, o
 * anuncio que sai e o InscricaoConfirmada de sempre, e a auditoria e a mesma —
 * com quem declarou, quanto e a explicacao escrita. Repetir aqui qualquer parte
 * disso criaria uma segunda porta para o mesmo fato, e as duas divergiriam.
 *
 * O metodo declarado e Transferencia porque foi o que de fato aconteceu: um Pix
 * direto para a conta do responsavel do setor. "Outro" ficaria mais vago do que
 * o fato.
 *
 * **Recusar nao mexe na inscricao.** Ela segue aguardando pagamento, com o
 * mesmo prazo, e a pessoa pode mandar outro comprovante. O motivo e obrigatorio
 * — e o banco tambem cobra isso — porque recusa sem explicacao deixa o
 * participante sem saber o que corrigir, do outro lado de uma tela, sem ter a
 * quem perguntar.
 *
 * Quem pode conferir o que, este arquivo nao decide: o escopo de setor mora em
 * ComprovantePagamentoPolicy e e cobrado antes, pelo controller.
 */
class ConferirComprovante
{
    public function __construct(
        private readonly ConfirmarPagamentoManual $confirmarPagamentoManual,
    ) {}

    /**
     * Aceita o comprovante e confirma a inscricao.
     *
     * @param  string  $observacao  o que quem conferiu viu no arquivo
     * @return bool true se esta chamada foi quem confirmou a inscricao; false se
     *              ela ja estava confirmada antes
     *
     * @throws ComprovanteRecusadoException quando o comprovante ja foi conferido
     * @throws InvalidArgumentException quando a observacao vem vazia
     */
    public function aceitar(ComprovantePagamento $comprovante, User $responsavel, string $observacao): bool
    {
        $this->recusarSeJaConferido($comprovante);

        $observacao = trim($observacao);

        if ($observacao === '') {
            throw new InvalidArgumentException('Descreva o que você conferiu no comprovante.');
        }

        $inscricao = $this->inscricaoDe($comprovante);

        // A confirmacao vem PRIMEIRO. Se ela for recusada — prazo vencido,
        // inscricao cancelada —, a excecao sobe e o comprovante continua em
        // aberto, do jeito que estava: marcar como aceito um comprovante cuja
        // inscricao nao confirmou seria gravar uma mentira curta e definitiva.
        $confirmou = ($this->confirmarPagamentoManual)(
            $inscricao,
            $responsavel,
            MetodoPagamento::Transferencia,
            $observacao,
        );

        $comprovante->update([
            'situacao' => SituacaoComprovante::Aceito,
            'conferido_por_id' => $responsavel->getKey(),
            'conferido_em' => Carbon::now(),
            'motivo_recusa' => null,
        ]);

        return $confirmou;
    }

    /**
     * Recusa o comprovante, com o motivo escrito.
     *
     * A inscricao nao e tocada de proposito: ela continua aguardando pagamento
     * ate o prazo, e o participante pode enviar outro comprovante — que nascera
     * numa linha nova, sem apagar esta recusa nem o motivo dela (RN-S6).
     *
     * @throws ComprovanteRecusadoException quando o comprovante ja foi conferido
     * @throws InvalidArgumentException quando o motivo vem vazio
     */
    public function recusar(ComprovantePagamento $comprovante, User $responsavel, string $motivo): void
    {
        $this->recusarSeJaConferido($comprovante);

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new InvalidArgumentException(
                'Escreva por que o comprovante não foi aceito: é o que o participante vai ler para corrigir.'
            );
        }

        $comprovante->update([
            'situacao' => SituacaoComprovante::Recusado,
            'conferido_por_id' => $responsavel->getKey(),
            'conferido_em' => Carbon::now(),
            'motivo_recusa' => mb_substr($motivo, 0, 300),
        ]);
    }

    /**
     * Conferir duas vezes o mesmo comprovante nao acontece por acidente: e o
     * duplo clique, ou duas pessoas do mesmo setor com a fila aberta ao mesmo
     * tempo. A segunda tentativa para aqui.
     */
    private function recusarSeJaConferido(ComprovantePagamento $comprovante): void
    {
        if (! $comprovante->estaEmAberto()) {
            throw new ComprovanteRecusadoException(
                'Este comprovante já foi conferido: ele está como '
                .mb_strtolower($comprovante->situacao->rotulo()).'.'
            );
        }
    }

    private function inscricaoDe(ComprovantePagamento $comprovante): Inscricao
    {
        $inscricao = $comprovante->relationLoaded('inscricao')
            ? $comprovante->inscricao
            : $comprovante->inscricao()->first();

        if (! $inscricao instanceof Inscricao) {
            throw new ComprovanteRecusadoException('A inscrição deste comprovante não existe mais.');
        }

        return $inscricao;
    }
}

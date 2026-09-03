<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Models\Inscricao;
use Illuminate\Support\Facades\DB;

/**
 * Devolve ou converte os contadores de vaga quando uma inscricao muda de
 * situacao.
 *
 * Transicoes possiveis:
 * - expirou ou foi cancelada aguardando pagamento -> devolve a vaga presa;
 * - foi confirmada -> a vaga presa vira vaga paga;
 * - inscricao confirmada foi cancelada -> devolve a vaga paga.
 *
 * Todo comando so tem efeito se o contador estiver acima de zero, entao
 * repetir a operacao nao gera contador negativo nem vaga do nada.
 *
 * A ordem e a mesma da reserva: evento primeiro, atividades em ordem crescente
 * de id.
 *
 * O LOTE NAO ENTRA AQUI, E A AUSENCIA E A REGRA (RN-L6). Quando uma inscricao
 * expira ou e cancelada, a vaga volta para o evento e para as atividades, mas
 * NAO volta para o lote: lotes.vagas_ocupadas so cresce, e lote esgotado nao
 * reabre. E decisao do dono do produto, tomada com o efeito colateral a vista:
 * quem reserva e nao paga queima a vaga do lote barato, e ela sera vendida pelo
 * preco do lote seguinte. Devolver a vaga ao lote de origem e um caminho
 * possivel — e outro plano, com outra conversa.
 */
class LiberarVagas
{
    /**
     * Vaga presa aguardando pagamento volta a ficar livre.
     */
    public function liberarReserva(Inscricao $inscricao): void
    {
        $this->aplicar(
            $inscricao,
            'vagas_reservadas = vagas_reservadas - 1',
            'vagas_reservadas > 0',
        );
    }

    /**
     * Pagamento reconhecido: a vaga presa passa a ser vaga paga. O total
     * ocupado nao muda, entao a restricao de capacidade continua satisfeita.
     */
    public function confirmar(Inscricao $inscricao): void
    {
        $this->aplicar(
            $inscricao,
            'vagas_reservadas = vagas_reservadas - 1, vagas_confirmadas = vagas_confirmadas + 1',
            'vagas_reservadas > 0',
        );
    }

    /**
     * Inscricao ja confirmada foi cancelada: a vaga paga volta a ficar livre.
     */
    public function liberarConfirmada(Inscricao $inscricao): void
    {
        $this->aplicar(
            $inscricao,
            'vagas_confirmadas = vagas_confirmadas - 1',
            'vagas_confirmadas > 0',
        );
    }

    private function aplicar(Inscricao $inscricao, string $atribuicoes, string $condicao): void
    {
        DB::update(
            "UPDATE eventos SET {$atribuicoes}, updated_at = now() WHERE id = ? AND {$condicao}",
            [$inscricao->evento_id],
        );

        foreach ($inscricao->atividadeIdsOrdenados() as $atividadeId) {
            DB::update(
                "UPDATE atividades SET {$atribuicoes}, updated_at = now() WHERE id = ? AND {$condicao}",
                [$atividadeId],
            );
        }
    }
}

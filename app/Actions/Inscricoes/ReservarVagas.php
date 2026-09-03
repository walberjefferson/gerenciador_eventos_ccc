<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Exceptions\Inscricoes\LoteIndisponivelException;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Atividade;
use App\Models\Evento;
use App\Models\Lote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prende uma vaga no evento e uma em cada atividade escolhida.
 *
 * Nao existe "consultar e depois gravar": cada contador sobe com um unico
 * comando que so tem efeito se ainda houver vaga. Se o comando nao alterar
 * nenhuma linha, a vaga acabou entre a consulta e a gravacao — exatamente o
 * intervalo que causaria venda a mais.
 *
 * A ordem e sempre a mesma: evento primeiro, depois o lote, depois as
 * atividades em ordem crescente de id (RN-L11). Ordem fixa impede que duas
 * inscricoes simultaneas travem uma esperando a outra. O lote entra logo depois
 * do evento porque cada inscricao toca um unico lote — nao ha ordem a arbitrar
 * entre lotes.
 */
class ReservarVagas
{
    /**
     * @param  Collection<int, Atividade>|array<int, Atividade>  $atividades
     * @param  Lote|null  $lote  o lote vigente, quando o evento trabalha com lotes
     *
     * @throws VagasEsgotadasException
     * @throws LoteIndisponivelException
     */
    public function __invoke(Evento $evento, Collection|array $atividades, ?Lote $lote = null, ?Carbon $momento = null): void
    {
        $this->reservarNoEvento($evento);

        if ($lote !== null) {
            $this->reservarNoLote($lote, $momento);
        }

        foreach ($this->emOrdemCanonica($atividades) as $atividade) {
            $this->reservarNaAtividade($atividade);
        }
    }

    /**
     * @throws VagasEsgotadasException
     */
    public function reservarNoEvento(Evento $evento): void
    {
        $linhas = DB::update(
            'UPDATE eventos
                SET vagas_reservadas = vagas_reservadas + 1, updated_at = now()
              WHERE id = ?
                AND (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas < capacidade)',
            [$evento->id],
        );

        if ($linhas === 0) {
            throw VagasEsgotadasException::doEvento();
        }
    }

    /**
     * Prende a vaga no lote — pelo prazo E pela quantidade, no mesmo comando.
     *
     * As duas condicoes viajam junto de proposito: o lote pode ter vencido
     * entre a leitura e a gravacao tanto por relogio quanto por vaga, e sao os
     * mesmos milissegundos. O instante vai como parametro, e nao como o now()
     * do banco, para que o codigo seja o mesmo que os testes conseguem observar
     * ao viajar no tempo.
     *
     * @throws LoteIndisponivelException
     */
    public function reservarNoLote(Lote $lote, ?Carbon $momento = null): void
    {
        $linhas = DB::update(
            'UPDATE lotes
                SET vagas_ocupadas = vagas_ocupadas + 1, updated_at = now()
              WHERE id = ?
                AND (disponivel_ate IS NULL OR disponivel_ate > ?)
                AND (quantidade IS NULL OR vagas_ocupadas < quantidade)',
            [$lote->id, $momento ?? Carbon::now()],
        );

        if ($linhas === 0) {
            throw LoteIndisponivelException::esgotouAgora($lote);
        }
    }

    /**
     * @throws VagasEsgotadasException
     */
    public function reservarNaAtividade(Atividade $atividade): void
    {
        $linhas = DB::update(
            'UPDATE atividades
                SET vagas_reservadas = vagas_reservadas + 1, updated_at = now()
              WHERE id = ?
                AND (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas < capacidade)',
            [$atividade->id],
        );

        if ($linhas === 0) {
            throw VagasEsgotadasException::daAtividade($atividade);
        }
    }

    /**
     * @param  Collection<int, Atividade>|array<int, Atividade>  $atividades
     * @return array<int, Atividade>
     */
    private function emOrdemCanonica(Collection|array $atividades): array
    {
        $lista = $atividades instanceof Collection ? $atividades->all() : $atividades;

        usort($lista, fn (Atividade $uma, Atividade $outra): int => $uma->id <=> $outra->id);

        return array_values($lista);
    }
}

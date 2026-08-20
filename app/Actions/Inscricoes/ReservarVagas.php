<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Atividade;
use App\Models\Evento;
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
 * A ordem e sempre a mesma: evento primeiro, depois as atividades em ordem
 * crescente de id. Ordem fixa impede que duas inscricoes simultaneas travem
 * uma esperando a outra.
 */
class ReservarVagas
{
    /**
     * @param  Collection<int, Atividade>|array<int, Atividade>  $atividades
     *
     * @throws VagasEsgotadasException
     */
    public function __invoke(Evento $evento, Collection|array $atividades): void
    {
        $this->reservarNoEvento($evento);

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

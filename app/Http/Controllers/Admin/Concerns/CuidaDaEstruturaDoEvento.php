<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Atividade;
use App\Models\Evento;
use Illuminate\Support\Facades\DB;

/**
 * O cuidado que toda tela de estrutura precisa ter.
 *
 * Duas coisas se repetem em dias, grupos e atividades:
 *
 * 1. conferir que a peca sendo mexida e mesmo daquele evento — o identificador
 *    vem da URL e ninguem deve alcancar a programacao de outro evento trocando
 *    um numero;
 * 2. contar quantas pessoas ja escolheram aquelas atividades, porque as chaves
 *    do banco apagam em cascata: excluir um dia levaria junto os grupos, as
 *    atividades e as escolhas de quem se inscreveu, sem perguntar nada.
 *
 * Por isso a exclusao aqui e sempre recusada quando ha escolha ou vaga
 * ocupada, com a explicacao do caminho certo.
 */
trait CuidaDaEstruturaDoEvento
{
    /**
     * Quantas inscricoes escolheram alguma destas atividades.
     *
     * @param  array<int, int>  $atividadeIds
     */
    protected function escolhasDeAtividades(array $atividadeIds): int
    {
        if ($atividadeIds === []) {
            return 0;
        }

        return DB::table('inscricoes_atividades')
            ->whereIn('atividade_id', $atividadeIds)
            ->count();
    }

    /**
     * Quantas vagas estao presas ou pagas nestas atividades.
     *
     * @param  array<int, int>  $atividadeIds
     */
    protected function vagasOcupadasDeAtividades(array $atividadeIds): int
    {
        if ($atividadeIds === []) {
            return 0;
        }

        return (int) Atividade::query()
            ->whereIn('id', $atividadeIds)
            ->sum(DB::raw('vagas_reservadas + vagas_confirmadas'));
    }

    /**
     * Recusa a exclusao quando alguma daquelas atividades ja foi escolhida.
     *
     * @param  array<int, int>  $atividadeIds
     * @return string|null a explicacao, ou null quando pode excluir
     */
    protected function motivoParaNaoExcluir(array $atividadeIds, string $oQue): ?string
    {
        $escolhas = $this->escolhasDeAtividades($atividadeIds);
        $ocupadas = $this->vagasOcupadasDeAtividades($atividadeIds);

        if ($escolhas === 0 && $ocupadas === 0) {
            return null;
        }

        return "Não é possível excluir {$oQue}: {$escolhas} inscrição(ões) já escolheram essas atividades "
            ."e há {$ocupadas} vaga(s) ocupada(s). Excluir apagaria a escolha dessas pessoas. "
            .'Desative em vez de excluir — assim some do formulário e o histórico continua inteiro.';
    }

    /**
     * Garante que a peca pertence mesmo ao evento da URL.
     */
    protected function confirmarQueEDoEvento(Evento $evento, ?int $eventoIdDaPeca): void
    {
        abort_unless($eventoIdDaPeca === $evento->getKey(), 404);
    }
}

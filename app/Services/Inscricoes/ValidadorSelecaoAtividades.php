<?php

declare(strict_types=1);

namespace App\Services\Inscricoes;

use App\Exceptions\Inscricoes\SelecaoAtividadesInvalidaException;
use App\Models\Atividade;
use App\Models\ConflitoAtividade;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Confere se a combinacao de atividades escolhida pode existir (RN-03 a RN-08).
 *
 * Junta todas as recusas antes de desistir: o participante ve de uma vez tudo
 * o que precisa corrigir, em vez de descobrir um problema por tentativa.
 */
class ValidadorSelecaoAtividades
{
    /**
     * @param  array<int, int>  $atividadeIds
     * @return Collection<int, Atividade> atividades escolhidas, em ordem crescente de id
     *
     * @throws SelecaoAtividadesInvalidaException
     */
    public function __invoke(Evento $evento, array $atividadeIds, Carbon $dataNascimento): Collection
    {
        $pedidos = array_values(array_unique(array_map('intval', $atividadeIds)));
        sort($pedidos);

        $atividades = $this->atividadesDoEvento($evento, $pedidos);

        $erros = [];

        // RN-05 — toda atividade recebida precisa existir, estar ativa e
        // pertencer a este evento.
        if ($atividades->count() !== count($pedidos)) {
            $erros[] = 'Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção.';

            // Sem saber quais atividades sao validas, as demais conferencias
            // produziriam mensagens enganosas.
            throw SelecaoAtividadesInvalidaException::com($erros);
        }

        $erros = array_merge(
            $erros,
            $this->conferirGrupos($evento, $atividades),
            $this->conferirHorarios($atividades),
            $this->conferirConflitosDeclarados($atividades),
            $this->conferirIdades($atividades, $dataNascimento),
        );

        if ($erros !== []) {
            throw SelecaoAtividadesInvalidaException::com($erros);
        }

        return $atividades;
    }

    /**
     * @param  array<int, int>  $atividadeIds
     * @return Collection<int, Atividade>
     */
    private function atividadesDoEvento(Evento $evento, array $atividadeIds): Collection
    {
        if ($atividadeIds === []) {
            /** @var Collection<int, Atividade> $vazia */
            $vazia = new Collection;

            return $vazia;
        }

        return Atividade::query()
            ->whereIn('id', $atividadeIds)
            ->ativos()
            ->whereHas(
                'grupoAtividade',
                fn (Builder $grupo) => $grupo->where('ativo', true)->whereHas(
                    'diaEvento',
                    fn (Builder $dia) => $dia->where('ativo', true)->where('evento_id', $evento->id),
                ),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * RN-03 e RN-04 — minimo, maximo e grupos obrigatorios.
     *
     * @param  Collection<int, Atividade>  $atividades
     * @return array<int, string>
     */
    private function conferirGrupos(Evento $evento, Collection $atividades): array
    {
        $escolhasPorGrupo = $atividades->countBy(
            fn (Atividade $atividade): int => (int) $atividade->grupo_atividade_id,
        );

        $erros = [];

        foreach ($this->gruposDoEvento($evento) as $grupo) {
            $escolhidas = (int) $escolhasPorGrupo->get($grupo->id, 0);
            $nome = Str::lower($grupo->nome);

            if ($grupo->obrigatorio && $escolhidas < $grupo->min_selecoes) {
                $erros[] = "Você precisa escolher pelo menos {$grupo->min_selecoes} {$nome}.";

                continue;
            }

            // Grupo opcional com minimo maior que zero significa "ou nada, ou
            // pelo menos o minimo" (decisao D-16).
            if (! $grupo->obrigatorio && $escolhidas > 0 && $escolhidas < $grupo->min_selecoes) {
                $erros[] = "Você precisa escolher pelo menos {$grupo->min_selecoes} opções em {$nome}.";
            }

            if ($grupo->max_selecoes !== null && $escolhidas > $grupo->max_selecoes) {
                $erros[] = "Você pode escolher no máximo {$grupo->max_selecoes} opções em {$nome}.";
            }
        }

        return $erros;
    }

    /**
     * @return Collection<int, GrupoAtividade>
     */
    private function gruposDoEvento(Evento $evento): Collection
    {
        return GrupoAtividade::query()
            ->doEvento($evento->id)
            ->ativos()
            ->whereHas('diaEvento', fn (Builder $dia) => $dia->where('ativo', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * RN-06 — duas atividades nao podem se sobrepor no tempo. Limites que
     * apenas se encostam sao permitidos.
     *
     * @param  Collection<int, Atividade>  $atividades
     * @return array<int, string>
     */
    private function conferirHorarios(Collection $atividades): array
    {
        $erros = [];
        $lista = $atividades->values();

        foreach ($lista as $indice => $atividade) {
            foreach ($lista->slice($indice + 1) as $outra) {
                if ($atividade->sobrepoe($outra)) {
                    $erros[] = "{$atividade->nome} e {$outra->nome} acontecem no mesmo horário. Escolha apenas uma das duas.";
                }
            }
        }

        return $erros;
    }

    /**
     * RN-07 — pares que a organizacao declarou incompativeis, mesmo sem choque
     * de horario. O par no banco e normalizado, entao a recusa vale nos dois
     * sentidos da escolha.
     *
     * @param  Collection<int, Atividade>  $atividades
     * @return array<int, string>
     */
    private function conferirConflitosDeclarados(Collection $atividades): array
    {
        if ($atividades->count() < 2) {
            return [];
        }

        $porId = $atividades->keyBy('id');

        return ConflitoAtividade::query()
            ->entreAtividades($porId->keys()->map(fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->map(function (ConflitoAtividade $conflito) use ($porId): string {
                $primeira = $porId->get($conflito->atividade_a_id)?->nome ?? '';
                $segunda = $porId->get($conflito->atividade_b_id)?->nome ?? '';

                if ($conflito->motivo === null || $conflito->motivo === '') {
                    return "{$primeira} e {$segunda} não podem ser escolhidas juntas.";
                }

                return "{$primeira} e {$segunda} não podem ser escolhidas juntas: {$conflito->motivo}";
            })
            ->values()
            ->all();
    }

    /**
     * RN-08 — a idade vale na data em que a atividade acontece, nao hoje.
     *
     * @param  Collection<int, Atividade>  $atividades
     * @return array<int, string>
     */
    private function conferirIdades(Collection $atividades, Carbon $dataNascimento): array
    {
        $erros = [];

        foreach ($atividades as $atividade) {
            $idade = $atividade->idadeNaData($dataNascimento);

            if ($atividade->idade_minima !== null && $idade < $atividade->idade_minima) {
                $erros[] = "{$atividade->nome} é permitida a partir de {$atividade->idade_minima} anos.";

                continue;
            }

            if ($atividade->idade_maxima !== null && $idade > $atividade->idade_maxima) {
                $erros[] = "{$atividade->nome} é permitida até {$atividade->idade_maxima} anos.";
            }
        }

        return $erros;
    }
}

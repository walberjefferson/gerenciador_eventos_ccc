<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { contarVagas } from '@/lib/formato';
import type { AtividadePublica, DiaEventoPublico } from '@/types/evento';

/**
 * Um dia da programacao, desenhado como um trecho de trilha.
 *
 * O dia inteiro fica recuado 30px, com um ponto no inicio e uma linha
 * pontilhada descendo por baixo dele: e o elemento-assinatura da identidade, e
 * o que faz a programacao parecer um caminho em vez de uma tabela.
 *
 * Cada atividade e uma LINHA — nao um cartao —, com o horario numa coluna de
 * largura fixa a esquerda. Em coluna fixa e monoespacada os horarios se alinham
 * na vertical e da para compara-los de relance; empilhados em cartoes,
 * comparar vira trabalho de memoria, que e exatamente o que alguem faz aqui
 * antes de escolher.
 */
defineProps<{
    dia: DiaEventoPublico;
}>();

function faixaEtaria(atividade: AtividadePublica): string | null {
    const { idade_minima: minima, idade_maxima: maxima } = atividade;

    if (minima !== null && maxima !== null) {
        return `De ${minima} a ${maxima} anos`;
    }

    if (minima !== null) {
        return `A partir de ${minima} anos`;
    }

    if (maxima !== null) {
        return `Até ${maxima} anos`;
    }

    return null;
}
</script>

<template>
    <!-- .day — recuo de 30px e a linha pontilhada correndo por baixo do ponto -->
    <section :aria-labelledby="`dia-${dia.id}`" class="relative pl-[30px]">
        <span aria-hidden="true" class="border-acao bg-background absolute top-[7px] left-0 size-[11px] rounded-full border-2"></span>

        <span aria-hidden="true" class="border-input absolute top-3 bottom-[6px] left-[5px] w-px border-l border-dashed"></span>

        <!-- .day__k — 12px, 0.12em, verde-mata -->
        <p class="text-acao-texto text-xs font-semibold tracking-[0.12em] uppercase">{{ dia.quando }}</p>

        <!-- .day__t — 21px -->
        <h3 :id="`dia-${dia.id}`" class="mt-[6px] mb-[2px] text-[21px] leading-[1.15] font-semibold tracking-[-0.02em]">
            {{ dia.nome }}
        </h3>

        <!-- .day__n — 14.5px -->
        <p v-if="dia.descricao" class="text-muted-foreground mb-4 text-[14.5px] leading-[1.55]">{{ dia.descricao }}</p>

        <p v-if="dia.grupos.length === 0" class="text-muted-foreground text-[14.5px]">A programação deste dia ainda será divulgada.</p>

        <div v-for="grupo in dia.grupos" :key="grupo.id" class="mt-4 first:mt-0">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h4 class="text-[15px] font-semibold">{{ grupo.nome }}</h4>
                <p class="text-acao-texto text-[13px] font-medium">{{ grupo.regra_rotulo }}</p>
            </div>

            <p v-if="grupo.descricao" class="text-muted-foreground mb-2 text-[13.5px] leading-[1.55]">{{ grupo.descricao }}</p>

            <ul>
                <!-- .slot — 13px/16px de recheio, raio de 10px, 8px entre linhas -->
                <li
                    v-for="atividade in grupo.atividades"
                    :key="atividade.id"
                    class="border-border bg-card mt-2 flex flex-wrap items-center gap-x-[14px] gap-y-1 rounded-[10px] border px-4 py-[13px] first:mt-0"
                    :class="atividade.esgotado ? 'opacity-60' : ''"
                >
                    <!-- .slot__t — coluna de 104px, monoespacada: e ela que
                         alinha os horarios de linhas diferentes na vertical -->
                    <span class="text-muted-foreground w-[124px] shrink-0 font-mono text-[13.5px] whitespace-nowrap tabular-nums">
                        {{ atividade.horario_rotulo }}
                    </span>

                    <span class="min-w-0 flex-1 basis-32">
                        <span class="block text-[15px] font-medium">{{ atividade.nome }}</span>
                        <span v-if="faixaEtaria(atividade)" class="text-muted-foreground block text-[13px]">{{ faixaEtaria(atividade) }}</span>
                        <span v-if="atividade.descricao" class="text-muted-foreground block text-[13px]">{{ atividade.descricao }}</span>
                    </span>

                    <!-- .slot__v — empurrado para a direita -->
                    <Badge v-if="atividade.esgotado" variant="destructive" class="ml-auto shrink-0">Esgotado</Badge>
                    <span v-else-if="atividade.vagas_disponiveis !== null" class="text-muted-foreground ml-auto shrink-0 text-[13px]">
                        {{ contarVagas(atividade.vagas_disponiveis) }}
                    </span>
                </li>
            </ul>
        </div>
    </section>
</template>

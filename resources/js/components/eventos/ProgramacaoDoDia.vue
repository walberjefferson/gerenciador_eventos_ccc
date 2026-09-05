<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { contarVagas } from '@/lib/formato';
import type { AtividadePublica, DiaEventoPublico } from '@/types/evento';
import { computed } from 'vue';

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
const props = defineProps<{
    dia: DiaEventoPublico;
}>();

/**
 * O dia tem um grupo so?
 *
 * Quando tem, o titulo do bloco e o NOME DO GRUPO — "Modalidades esportivas" —
 * e o nome do dia nao aparece. Os dois empilhados diziam quase a mesma coisa
 * uma embaixo da outra, e a data ja esta na linha verde logo acima. Quando o
 * dia tem mais de um grupo, o nome do dia volta a fazer falta: e ele que
 * explica o que aqueles blocos tem em comum.
 */
const grupoUnico = computed(() => (props.dia.grupos.length === 1 ? props.dia.grupos[0] : null));

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

        <!-- .day__t — 21px. O titulo e o nome do grupo quando ha um so. -->
        <h3 :id="`dia-${dia.id}`" class="mt-[6px] mb-[2px] text-[21px] leading-[1.15] font-semibold tracking-[-0.02em]">
            {{ grupoUnico ? grupoUnico.nome : dia.nome }}
        </h3>

        <!-- .day__n — 14.5px. A regra de escolha entra AQUI, no corpo do texto,
             e nao como etiqueta flutuando a direita: ela e parte da explicacao,
             e lida junto com ela em vez de disputar o olhar com o titulo. -->
        <p v-if="grupoUnico" class="text-muted-foreground mb-4 max-w-[58ch] text-[14.5px] leading-[1.55]">
            {{ [grupoUnico.descricao, grupoUnico.regra_rotulo].filter(Boolean).join(' ') }}
        </p>
        <p v-else-if="dia.descricao" class="text-muted-foreground mb-4 max-w-[58ch] text-[14.5px] leading-[1.55]">{{ dia.descricao }}</p>

        <p v-if="dia.grupos.length === 0" class="text-muted-foreground text-[14.5px]">A programação deste dia ainda será divulgada.</p>

        <template v-for="grupo in dia.grupos" :key="grupo.id">
            <!-- Com mais de um grupo no dia, cada um volta a ter o seu titulo. -->
            <div v-if="!grupoUnico" class="mt-4 first:mt-0">
                <h4 class="text-[15px] font-semibold">{{ grupo.nome }}</h4>
                <p class="text-muted-foreground mt-1 mb-2 max-w-[58ch] text-[13.5px] leading-[1.55]">
                    {{ [grupo.descricao, grupo.regra_rotulo].filter(Boolean).join(' ') }}
                </p>
            </div>

            <ul>
                <!-- .slot — 13px/16px de recheio, raio de 10px, 8px entre linhas -->
                <li
                    v-for="atividade in grupo.atividades"
                    :key="atividade.id"
                    class="border-border bg-card mt-2 flex flex-wrap items-center gap-x-[14px] gap-y-1 rounded-[10px] border px-4 py-[13px] first:mt-0"
                    :class="atividade.esgotado ? 'opacity-60' : ''"
                >
                    <!-- .slot__t — coluna fixa e monoespacada: e ela que alinha
                         os horarios de linhas diferentes na vertical.

                         Atividade sem hora marcada NÃO ganha a coluna: nada de
                         "a definir" nem de travessão. Ela acontece no dia
                         inteiro, e o dia já está escrito no cabeçalho — a linha
                         só precisa do nome. -->
                    <span
                        v-if="atividade.horario_rotulo"
                        class="text-muted-foreground w-[124px] shrink-0 font-mono text-[13.5px] whitespace-nowrap tabular-nums"
                    >
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
        </template>
    </section>
</template>

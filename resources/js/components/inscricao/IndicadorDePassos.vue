<script setup lang="ts">
import type { PassoDaInscricao } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Onde a pessoa esta dentro do formulario — o `.steps` do prototipo.
 *
 * Circulos numerados ligados por uma linha PONTILHADA, que e a mesma linha da
 * trilha dos dias: e o elemento que costura a identidade de uma tela a outra.
 *
 * A etapa atual recebe um halo verde ao redor do circulo; a concluida troca o
 * numero por um "check". Sao duas marcas diferentes de proposito — distinguir
 * "ja fiz" de "estou aqui" so pela cor deixaria de fora quem nao as separa
 * (WCAG 1.4.1).
 */
const props = defineProps<{
    passoAtual: PassoDaInscricao;
}>();

const passos: Array<{ chave: PassoDaInscricao; titulo: string }> = [
    { chave: 'dados', titulo: 'Seus dados' },
    { chave: 'participacao', titulo: 'Participação' },
    { chave: 'revisao', titulo: 'Revisão' },
    { chave: 'pagamento', titulo: 'Pagamento' },
];

const posicao = computed<number>(() => {
    const indice = passos.findIndex((passo) => passo.chave === props.passoAtual);

    return indice === -1 ? 1 : indice + 1;
});

const tituloAtual = computed<string>(() => passos[posicao.value - 1]?.titulo ?? '');
</script>

<template>
    <!-- .steps — 26px acima, 38px abaixo -->
    <nav :aria-label="`Etapas da inscrição — etapa ${posicao} de ${passos.length}: ${tituloAtual}`" class="mt-[26px] mb-[38px]">
        <ol class="flex items-center">
            <li
                v-for="(passo, indice) in passos"
                :key="passo.chave"
                :aria-current="passo.chave === props.passoAtual ? 'step' : undefined"
                class="flex min-w-0 items-center gap-[10px]"
                :class="indice + 1 < passos.length ? 'flex-1' : 'flex-none'"
            >
                <!-- .step__n — 26px, borda de 1.5px, fundo papel -->
                <!--
                    UM ternario decide fundo, borda e cor de texto ao mesmo
                    tempo — e nao uma classe estatica mais um ajuste.

                    Havia aqui um `bg-background` fixo com um `bg-acao`
                    condicional por cima: as duas classes chegavam juntas ao
                    elemento e quem decidia era a ORDEM no arquivo de estilo, e
                    nao a intencao de quem escreveu. O papel venceu o verde, e o
                    circulo da etapa atual ficava cor de fundo com texto branco
                    por cima — invisivel.
                -->
                <span
                    aria-hidden="true"
                    class="grid size-[26px] flex-none place-items-center rounded-full border-[1.5px] text-[13px] font-semibold"
                    :class="
                        passo.chave === props.passoAtual
                            ? 'border-acao bg-acao text-acao-foreground ring-sucesso-suave ring-4'
                            : indice + 1 < posicao
                              ? 'border-acao bg-acao text-acao-foreground'
                              : 'border-input bg-background text-muted-foreground'
                    "
                >
                    <template v-if="indice + 1 < posicao">✓</template>
                    <template v-else>{{ indice + 1 }}</template>
                </span>

                <!-- .step__l — 14px; a etapa atual ganha peso e cor de tinta -->
                <span
                    class="truncate text-sm whitespace-nowrap"
                    :class="[
                        passo.chave === props.passoAtual ? 'text-foreground font-semibold' : '',
                        indice + 1 < posicao ? 'text-foreground' : '',
                        indice + 1 > posicao ? 'text-muted-foreground' : '',
                        passo.chave === props.passoAtual ? '' : 'hidden sm:inline',
                    ]"
                >
                    {{ passo.titulo }}
                    <span v-if="indice + 1 < posicao" class="sr-only">(concluída)</span>
                </span>

                <!-- .step__line — a mesma linha pontilhada da trilha dos dias -->
                <span
                    v-if="indice + 1 < passos.length"
                    aria-hidden="true"
                    class="border-input mx-3 hidden h-px min-w-4 flex-1 border-t border-dashed sm:block"
                ></span>
            </li>
        </ol>
    </nav>
</template>

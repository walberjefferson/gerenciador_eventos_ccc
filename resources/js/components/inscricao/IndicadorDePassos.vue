<script setup lang="ts">
import type { PassoDaInscricao } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Mostra onde a pessoa esta dentro do formulario. Azul e a cor de informacao
 * e de navegacao — por isso ela aparece aqui, e nao o vermelho de acao.
 *
 * Numeros em circulo, ligados por um traco, em vez de uma barra de progresso:
 * a barra dizia "voce esta em 50%" sem dizer de que; os circulos dizem quais
 * etapas existem, qual ja passou e qual vem depois. A etapa concluida troca o
 * numero por um "check", que e o unico jeito de distinguir "ja fiz" de "ainda
 * vou fazer" sem depender so da cor (WCAG 1.4.1).
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
    <nav :aria-label="`Etapas da inscrição — etapa ${posicao} de ${passos.length}: ${tituloAtual}`">
        <ol class="flex items-center gap-2">
            <li
                v-for="(passo, indice) in passos"
                :key="passo.chave"
                :aria-current="passo.chave === props.passoAtual ? 'step' : undefined"
                class="flex min-w-0 items-center gap-2"
                :class="indice + 1 < passos.length ? 'flex-1' : ''"
            >
                <span
                    aria-hidden="true"
                    class="flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-bold"
                    :class="[
                        indice + 1 < posicao ? 'bg-sucesso text-sucesso-foreground border-transparent' : '',
                        passo.chave === props.passoAtual ? 'bg-informacao text-informacao-foreground border-transparent' : '',
                        indice + 1 > posicao ? 'border-border text-muted-foreground' : '',
                    ]"
                >
                    <template v-if="indice + 1 < posicao">✓</template>
                    <template v-else>{{ indice + 1 }}</template>
                </span>

                <span
                    class="truncate text-xs sm:text-sm"
                    :class="[
                        passo.chave === props.passoAtual ? 'text-informacao-texto font-semibold' : 'text-muted-foreground',
                        passo.chave === props.passoAtual ? '' : 'hidden sm:inline',
                    ]"
                >
                    {{ passo.titulo }}
                    <span v-if="indice + 1 < posicao" class="sr-only">(concluída)</span>
                </span>

                <!-- O traco de ligacao e enfeite: some do leitor de tela, que ja
                     recebe a ordem pela propria lista numerada. -->
                <span
                    v-if="indice + 1 < passos.length"
                    aria-hidden="true"
                    class="hidden h-px min-w-4 flex-1 sm:block"
                    :class="indice + 1 < posicao ? 'bg-sucesso' : 'bg-border'"
                ></span>
            </li>
        </ol>
    </nav>
</template>

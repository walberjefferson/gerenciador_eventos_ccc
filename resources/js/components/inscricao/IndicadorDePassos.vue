<script setup lang="ts">
import { Progress } from '@/components/ui/progress';
import type { PassoDaInscricao } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Mostra onde a pessoa esta dentro do formulario. Azul e a cor de informacao
 * e de navegacao — por isso ela aparece aqui, e nao o vermelho de acao.
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

const percentual = computed<number>(() => (posicao.value / passos.length) * 100);

const tituloAtual = computed<string>(() => passos[posicao.value - 1]?.titulo ?? '');
</script>

<template>
    <nav aria-label="Etapas da inscrição" class="space-y-3">
        <Progress :model-value="percentual" :aria-label="`Etapa ${posicao} de ${passos.length}: ${tituloAtual}`" />

        <ol class="flex flex-wrap gap-x-3 gap-y-1 text-xs sm:gap-x-4 sm:text-sm">
            <li
                v-for="(passo, indice) in passos"
                :key="passo.chave"
                :aria-current="passo.chave === props.passoAtual ? 'step' : undefined"
                :class="[
                    'flex items-center gap-1.5',
                    passo.chave === props.passoAtual ? 'font-semibold text-informacao-texto' : 'text-muted-foreground',
                    indice + 1 < posicao ? 'text-sucesso-texto' : '',
                ]"
            >
                <span aria-hidden="true">{{ indice + 1 }}.</span>
                <span>{{ passo.titulo }}</span>
                <span v-if="indice + 1 < posicao" class="sr-only">(concluída)</span>
            </li>
        </ol>
    </nav>
</template>

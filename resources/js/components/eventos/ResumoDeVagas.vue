<script setup lang="ts">
import { contarVagas } from '@/lib/formato';
import { computed } from 'vue';

/**
 * Quantas vagas ainda existem. O numero vem pronto do servidor; aqui so
 * escolhemos a frase.
 */
const props = defineProps<{
    vagasDisponiveis: number | null;
    esgotado: boolean;
}>();

const texto = computed<string>(() => {
    if (props.esgotado) {
        return 'Vagas esgotadas';
    }

    if (props.vagasDisponiveis === null) {
        return 'Vagas sem limite definido';
    }

    return `${contarVagas(props.vagasDisponiveis)} disponíveis`;
});
</script>

<template>
    <p class="text-sm font-semibold" :class="esgotado ? 'text-destructive' : 'text-sucesso-texto'">
        {{ texto }}
    </p>
</template>

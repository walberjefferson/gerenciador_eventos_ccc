<script setup lang="ts">
import MarcoDaLinhaDoTempo from '@/components/participante/MarcoDaLinhaDoTempo.vue';
import type { MarcoDaInscricao } from '@/types/participante';

/**
 * A historia da inscricao, do primeiro passo ao ultimo.
 *
 * E uma lista ordenada de verdade (<ol>): quem usa leitor de tela ouve
 * "item 2 de 4" e sabe onde esta, sem depender do desenho.
 */
defineProps<{
    marcos: MarcoDaInscricao[];
}>();
</script>

<template>
    <ol class="space-y-0" data-testid="linha-do-tempo">
        <MarcoDaLinhaDoTempo
            v-for="(marco, indice) in marcos"
            :key="marco.chave"
            :marco="marco"
            :ultimo="indice === marcos.length - 1"
        />
    </ol>
</template>

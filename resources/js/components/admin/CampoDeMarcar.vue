<script setup lang="ts">
/**
 * Uma caixa de marcar com o texto ao lado, nas telas administrativas.
 *
 * POR QUE ELE EXISTE: o padrao anterior era um `<div class="flex items-end">`
 * com a caixa e um `<label class="pb-2">` soltos dentro de uma celula do grid.
 * Isso quebrava de duas formas ao mesmo tempo:
 *
 * 1. `items-end` alinha pelo FUNDO DA CELULA — e a celula estica junto com a
 *    vizinha mais alta. Bastava um campo ao lado ter uma linha de ajuda
 *    embaixo ("Em branco, nao ha limite.") para a caixa afundar sozinha,
 *    parecendo perdida no rodape do formulario.
 * 2. O `pb-2` no rotulo subia o TEXTO sem subir a CAIXA, entao os dois nem
 *    entre si ficavam alinhados.
 *
 * Aqui o proprio `<label>` envolve a caixa: eles nao tem como se desalinhar, e
 * a area clicavel passa a ser a linha inteira em vez dos 16px do quadradinho.
 * `min-h-11` garante os 44px de alvo de toque (DA-42).
 */
defineProps<{
    id: string;
}>();

const marcado = defineModel<boolean>({ required: true });
</script>

<template>
    <label :for="id" class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium">
        <input :id="id" v-model="marcado" type="checkbox" class="border-input size-4 shrink-0 rounded" />
        <slot />
    </label>
</template>

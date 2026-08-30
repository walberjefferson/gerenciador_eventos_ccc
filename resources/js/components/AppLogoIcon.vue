<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

/**
 * A marca do painel: uma prancheta com um visto.
 *
 * Substitui o logotipo do Laravel que veio no pacote inicial. Ele dizia a
 * ferramenta com que o sistema foi escrito, o que nao interessa a ninguem que
 * abre a tela para conferir inscricoes — e, pior, sugeria que a pessoa estava
 * dentro de outro produto.
 *
 * A prancheta com visto e o simbolo mais direto de "inscricao registrada", e e
 * desenhada aqui em vez de baixada de algum acervo: nao entra licenca de
 * terceiro, nao entra arquivo que ninguem sabe de onde veio, e o traco
 * acompanha a cor de quem o coloca em tela (`currentColor`).
 *
 * Desenhado em traco, e nao preenchido, porque ele aparece pequeno — 20px na
 * barra lateral. Silhueta cheia nesse tamanho vira um borrao.
 */
defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="className"
        v-bind="$attrs"
    >
        <!-- A aba de cima, onde a prancheta prende o papel. -->
        <path d="M9 3h6a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
        <!-- O corpo, aberto nos dois pontos em que a aba encosta. -->
        <path d="M16 4.5h1.5A1.5 1.5 0 0 1 19 6v13.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19.5V6a1.5 1.5 0 0 1 1.5-1.5H8" />
        <!-- O visto: a inscricao confirmada. -->
        <path d="m9 13.5 2 2 4-4.5" />
    </svg>
</template>

<script setup lang="ts">
import { PopoverRoot, useForwardPropsEmits, type PopoverRootEmits, type PopoverRootProps } from 'reka-ui';

/**
 * Popover — a caixa flutuante que nasce ancorada num botao.
 *
 * Entrou a mao, e nao pelo gerador do shadcn-vue (DA-44): os componentes de
 * interface deste projeto carregam comentario em portugues e correcao de
 * acessibilidade que o gerador apagaria ao passar por cima deles.
 */
const props = defineProps<PopoverRootProps>();
const emits = defineEmits<PopoverRootEmits>();

const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
    <PopoverRoot v-bind="forwarded">
        <slot />
    </PopoverRoot>
</template>

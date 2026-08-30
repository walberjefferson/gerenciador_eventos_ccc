<script setup lang="ts">
import { cn } from '@/lib/utils';
import { PopoverContent, PopoverPortal, useForwardPropsEmits, type PopoverContentEmits, type PopoverContentProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(defineProps<PopoverContentProps & { class?: HTMLAttributes['class'] }>(), {
    align: 'start',
    sideOffset: 6,
    // Sem folga nenhuma a caixa encosta na borda do aparelho; 8px e o que
    // sobra de margem numa tela de 320px.
    collisionPadding: 8,
});

const emits = defineEmits<PopoverContentEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <!-- O portal pendura a caixa no `document.body`. E por isso que o escopo do
         tema mora no `<html>` e nao no layout (DA-52): daqui de dentro, um
         escopo posto na pagina nao alcancaria nada. -->
    <PopoverPortal>
        <PopoverContent
            v-bind="{ ...forwarded, ...$attrs }"
            :class="
                cn(
                    'z-50 w-72 rounded-md border bg-popover p-4 text-popover-foreground shadow-md outline-none animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                    props.class,
                )
            "
        >
            <slot />
        </PopoverContent>
    </PopoverPortal>
</template>

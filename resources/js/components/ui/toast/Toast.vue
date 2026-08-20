<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ToastRoot, type ToastRootEmits, type ToastRootProps, useForwardPropsEmits } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = withDefaults(defineProps<ToastRootProps & { class?: HTMLAttributes['class'] }>(), {
    duration: 4000,
});
const emits = defineEmits<ToastRootEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <ToastRoot
        v-bind="forwarded"
        :class="
            cn(
                'pointer-events-auto flex w-full items-start justify-between gap-3 rounded-md border bg-card p-4 text-card-foreground shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-80 data-[state=open]:slide-in-from-bottom-full',
                props.class,
            )
        "
    >
        <slot />
    </ToastRoot>
</template>

<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ProgressIndicator, ProgressRoot, type ProgressRootProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<ProgressRootProps & { class?: HTMLAttributes['class'] }>(),
    {
        modelValue: 0,
    },
);

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const percentual = computed(() => props.modelValue ?? 0);
</script>

<template>
    <ProgressRoot v-bind="delegatedProps" :class="cn('relative h-2 w-full overflow-hidden rounded-full bg-secondary', props.class)">
        <ProgressIndicator
            class="h-full w-full flex-1 bg-informacao transition-all"
            :style="`transform: translateX(-${100 - percentual}%);`"
        />
    </ProgressRoot>
</template>

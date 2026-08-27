<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ToastDescription } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <ToastDescription :class="cn('text-sm text-muted-foreground', props.class)">
        <slot />
    </ToastDescription>
</template>

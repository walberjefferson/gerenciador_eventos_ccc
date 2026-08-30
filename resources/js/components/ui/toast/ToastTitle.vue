<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ToastTitle } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <ToastTitle :class="cn('text-sm font-semibold', props.class)">
        <slot />
    </ToastTitle>
</template>

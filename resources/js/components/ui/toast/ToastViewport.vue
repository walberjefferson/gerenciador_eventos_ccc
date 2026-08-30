<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ToastViewport } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <ToastViewport
        :class="
            cn(
                'fixed bottom-0 left-0 right-0 z-[100] flex max-h-screen w-full flex-col-reverse gap-2 p-4 sm:left-auto sm:top-0 sm:flex-col sm:max-w-sm',
                props.class,
            )
        "
    />
</template>

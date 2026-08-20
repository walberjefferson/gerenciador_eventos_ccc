<script setup lang="ts">
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';
import { ToastClose } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <ToastClose
        aria-label="Fechar aviso"
        :class="
            cn(
                'inline-flex size-11 shrink-0 items-center justify-center rounded-md text-muted-foreground ring-offset-background transition-colors hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                props.class,
            )
        "
    >
        <X class="size-4" />
    </ToastClose>
</template>

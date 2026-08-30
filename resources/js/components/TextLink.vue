<script setup lang="ts">
import type { Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

interface Props {
    href: string;
    tabindex?: number;
    // O Inertia so aceita os verbos que conhece; "string" deixava passar
    // qualquer palavra.
    method?: Method;
    as?: string;
}

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="hover:decoration-current! text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out dark:decoration-neutral-500"
    >
        <slot />
    </Link>
</template>

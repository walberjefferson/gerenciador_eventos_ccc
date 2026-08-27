<script setup lang="ts">
import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';

/**
 * Campo de data. Usa o seletor nativo do navegador de proposito: no celular ele
 * abre o calendario do proprio sistema, ja e acessivel por teclado e nao pesa
 * no bundle. O valor trafega no formato ISO (AAAA-MM-DD), que e o mesmo que o
 * backend espera.
 */
const props = defineProps<{
    defaultValue?: string;
    modelValue?: string;
    min?: string;
    max?: string;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});
</script>

<template>
    <input
        v-model="modelValue"
        type="date"
        :min="min"
        :max="max"
        :class="
            cn(
                'flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                props.class,
            )
        "
    />
</template>

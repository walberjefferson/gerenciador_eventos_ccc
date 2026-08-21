<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * Um número com rótulo e com significado.
 *
 * O significado não é enfeite: "12" sozinho não diz nada, "12 pessoas ainda
 * podem desistir sem pagar" diz. A cor é sempre reforço, nunca a única
 * informação — quem não distingue cores lê exatamente o mesmo conteúdo.
 */
interface Props {
    rotulo: string;
    valor: string;
    significado?: string;
    tom?: 'neutro' | 'sucesso' | 'informacao' | 'atencao';
}

const props = withDefaults(defineProps<Props>(), {
    significado: undefined,
    tom: 'neutro',
});

const classesDoValor: Record<NonNullable<Props['tom']>, string> = {
    neutro: 'text-foreground',
    sucesso: 'text-sucesso-texto',
    informacao: 'text-informacao-texto',
    atencao: 'text-atencao-texto',
};
</script>

<template>
    <Card class="h-full">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground">{{ props.rotulo }}</CardTitle>
        </CardHeader>
        <CardContent class="space-y-1">
            <p :class="['text-3xl font-semibold tabular-nums tracking-tight', classesDoValor[props.tom]]">
                {{ props.valor }}
            </p>
            <p v-if="props.significado" class="text-sm text-muted-foreground">{{ props.significado }}</p>
        </CardContent>
    </Card>
</template>

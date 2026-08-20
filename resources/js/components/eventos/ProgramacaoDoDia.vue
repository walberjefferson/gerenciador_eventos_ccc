<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { contarVagas } from '@/lib/formato';
import type { AtividadePublica, DiaEventoPublico } from '@/types/evento';

/**
 * Programacao de um dia: os blocos de atividades com horario, vagas e a
 * regra de escolha que o servidor ja escreveu por extenso.
 */
defineProps<{
    dia: DiaEventoPublico;
}>();

function faixaEtaria(atividade: AtividadePublica): string | null {
    const { idade_minima: minima, idade_maxima: maxima } = atividade;

    if (minima !== null && maxima !== null) {
        return `De ${minima} a ${maxima} anos`;
    }

    if (minima !== null) {
        return `A partir de ${minima} anos`;
    }

    if (maxima !== null) {
        return `Até ${maxima} anos`;
    }

    return null;
}
</script>

<template>
    <Card>
        <CardHeader class="pb-3">
            <CardTitle class="text-xl">{{ dia.nome }}</CardTitle>
            <p class="text-sm text-muted-foreground">{{ dia.data_rotulo }}</p>
            <p v-if="dia.descricao" class="text-sm">{{ dia.descricao }}</p>
        </CardHeader>

        <CardContent class="space-y-6">
            <p v-if="dia.grupos.length === 0" class="text-sm text-muted-foreground">A programação deste dia ainda será divulgada.</p>

            <section v-for="grupo in dia.grupos" :key="grupo.id" :aria-labelledby="`grupo-${grupo.id}`" class="space-y-3">
                <div class="space-y-1">
                    <h3 :id="`grupo-${grupo.id}`" class="font-semibold">{{ grupo.nome }}</h3>
                    <p class="text-sm text-informacao-texto">{{ grupo.regra_rotulo }}</p>
                    <p v-if="grupo.descricao" class="text-sm text-muted-foreground">{{ grupo.descricao }}</p>
                </div>

                <ul class="space-y-2">
                    <li v-for="atividade in grupo.atividades" :key="atividade.id" class="rounded-md border border-border p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 space-y-1">
                                <p class="font-medium">{{ atividade.nome }}</p>
                                <p class="text-sm text-muted-foreground">{{ atividade.horario_rotulo }}</p>
                                <p v-if="faixaEtaria(atividade)" class="text-sm text-muted-foreground">
                                    {{ faixaEtaria(atividade) }}
                                </p>
                                <p v-if="atividade.descricao" class="text-sm">{{ atividade.descricao }}</p>
                            </div>

                            <Badge v-if="atividade.esgotado" variant="destructive">Esgotado</Badge>
                            <Badge v-else-if="atividade.vagas_disponiveis !== null" variant="sucesso">
                                {{ contarVagas(atividade.vagas_disponiveis) }}
                            </Badge>
                        </div>
                    </li>
                </ul>
            </section>
        </CardContent>
    </Card>
</template>

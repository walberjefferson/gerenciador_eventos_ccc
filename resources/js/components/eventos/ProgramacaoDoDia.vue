<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { contarVagas } from '@/lib/formato';
import type { AtividadePublica, DiaEventoPublico } from '@/types/evento';

/**
 * Programacao de um dia: os blocos de atividades com horario, vagas e a
 * regra de escolha que o servidor ja escreveu por extenso.
 *
 * Cada atividade e UMA linha, e nao um cartao: o que a pessoa faz aqui e
 * comparar horarios entre si. Em linha, os horarios ficam alinhados na mesma
 * coluna e a comparacao e visual; empilhados em cartoes, ela vira trabalho de
 * memoria.
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
            <p class="text-muted-foreground text-sm">{{ dia.data_rotulo }}</p>
            <p v-if="dia.descricao" class="text-sm">{{ dia.descricao }}</p>
        </CardHeader>

        <CardContent class="space-y-6">
            <p v-if="dia.grupos.length === 0" class="text-muted-foreground text-sm">A programação deste dia ainda será divulgada.</p>

            <section v-for="grupo in dia.grupos" :key="grupo.id" :aria-labelledby="`grupo-${grupo.id}`" class="space-y-3">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <h3 :id="`grupo-${grupo.id}`" class="font-semibold">{{ grupo.nome }}</h3>
                    <p class="text-informacao-texto text-xs font-medium">{{ grupo.regra_rotulo }}</p>
                </div>

                <p v-if="grupo.descricao" class="text-muted-foreground text-sm">{{ grupo.descricao }}</p>

                <ul class="space-y-2">
                    <li
                        v-for="atividade in grupo.atividades"
                        :key="atividade.id"
                        class="border-border flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-md border px-3 py-2.5"
                        :class="atividade.esgotado ? 'opacity-60' : ''"
                    >
                        <!-- Monoespacada e com largura fixa: e o que faz "08:00–10:00"
                             e "14:00–16:00" ocuparem o mesmo espaco e os horarios
                             de linhas diferentes se alinharem na vertical. -->
                        <span class="text-muted-foreground shrink-0 font-mono text-xs font-semibold tabular-nums">
                            {{ atividade.horario_rotulo }}
                        </span>

                        <span class="min-w-0 flex-1 basis-32">
                            <span class="block text-sm font-medium">{{ atividade.nome }}</span>
                            <span v-if="faixaEtaria(atividade)" class="text-muted-foreground block text-xs">
                                {{ faixaEtaria(atividade) }}
                            </span>
                            <span v-if="atividade.descricao" class="text-muted-foreground block text-xs">{{ atividade.descricao }}</span>
                        </span>

                        <Badge v-if="atividade.esgotado" variant="destructive" class="shrink-0">Esgotado</Badge>
                        <span v-else-if="atividade.vagas_disponiveis !== null" class="text-muted-foreground shrink-0 text-xs">
                            {{ contarVagas(atividade.vagas_disponiveis) }}
                        </span>
                    </li>
                </ul>
            </section>
        </CardContent>
    </Card>
</template>

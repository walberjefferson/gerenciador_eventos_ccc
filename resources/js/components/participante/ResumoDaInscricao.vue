<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatarValor } from '@/lib/formato';
import type { InscricaoAcompanhada } from '@/types/participante';
import { computed } from 'vue';

/**
 * O retrato da inscricao: onde, quem, quanto e o que a pessoa escolheu fazer.
 *
 * A situacao aparece com o mesmo rotulo que o dominio usa, e a cor vem sempre
 * de token semantico — nunca de cor escrita no componente.
 */
const props = defineProps<{
    inscricao: InscricaoAcompanhada;
}>();

const valor = computed(() => formatarValor(props.inscricao.valor_centavos, props.inscricao.moeda));

const variante = computed(() => {
    switch (props.inscricao.situacao) {
        case 'confirmada':
            return 'sucesso' as const;
        case 'aguardando_pagamento':
            return 'informacao' as const;
        default:
            return 'secondary' as const;
    }
});

const grupo = computed(() => {
    const dados = props.inscricao.grupo_participante;

    if (dados === null) {
        return null;
    }

    return dados.cidade ? `${dados.nome} — ${dados.cidade}${dados.uf ? ` (${dados.uf})` : ''}` : dados.nome;
});
</script>

<template>
    <Card data-testid="resumo-da-inscricao">
        <CardHeader class="pb-3">
            <CardTitle class="text-base font-medium text-muted-foreground">Sua inscrição</CardTitle>

            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="variante" data-testid="situacao-da-inscricao">{{ inscricao.situacao_rotulo }}</Badge>
                <span class="text-sm text-muted-foreground">
                    código <span class="font-mono">{{ inscricao.codigo_publico }}</span>
                </span>
            </div>
        </CardHeader>

        <CardContent class="space-y-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Participante</dt>
                    <dd class="font-medium">{{ inscricao.nome_completo }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Valor da inscrição</dt>
                    <dd class="font-medium" data-testid="valor-da-inscricao">{{ valor }}</dd>
                </div>
                <div v-if="inscricao.evento.nome">
                    <dt class="text-muted-foreground">Evento</dt>
                    <dd class="font-medium">{{ inscricao.evento.nome }}</dd>
                </div>
                <div v-if="grupo">
                    <dt class="text-muted-foreground">Grupo</dt>
                    <dd class="font-medium">{{ grupo }}</dd>
                </div>
            </dl>

            <div v-if="inscricao.atividades.length > 0">
                <h3 class="text-sm font-semibold">O que você escolheu</h3>

                <ul class="mt-2 space-y-2" data-testid="atividades-escolhidas">
                    <li v-for="atividade in inscricao.atividades" :key="atividade.nome" class="rounded-lg border border-border p-3 text-sm">
                        <p class="font-medium">{{ atividade.nome }}</p>
                        <p class="text-muted-foreground">
                            <template v-if="atividade.dia">{{ atividade.dia }} · </template>{{ atividade.horario_rotulo }}
                        </p>
                    </li>
                </ul>
            </div>
        </CardContent>
    </Card>
</template>

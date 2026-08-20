<script setup lang="ts">
import ResumoDeVagas from '@/components/eventos/ResumoDeVagas.vue';
import { Badge } from '@/components/ui/badge';
import { formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';
import { computed } from 'vue';

/**
 * Rosto do evento: banner, nome, quando acontece, quanto custa e quantas
 * vagas restam.
 */
const props = defineProps<{
    evento: EventoPublico;
}>();

const valor = computed<string>(() => formatarValor(props.evento.valor_centavos, props.evento.moeda));
</script>

<template>
    <header class="space-y-4">
        <img
            v-if="evento.banner_url"
            :src="evento.banner_url"
            :alt="`Imagem de divulgação do evento ${evento.nome}`"
            class="w-full rounded-lg border border-border object-cover"
            loading="lazy"
            decoding="async"
        />

        <div class="space-y-2">
            <Badge :variant="evento.inscricoes_abertas ? 'sucesso' : 'secondary'">
                {{ evento.inscricoes_abertas ? 'Inscrições abertas' : evento.situacao_rotulo }}
            </Badge>

            <h1 class="text-2xl font-bold leading-tight sm:text-3xl">{{ evento.nome }}</h1>

            <p class="text-sm text-muted-foreground">{{ evento.periodo_rotulo }}</p>
        </div>

        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <p class="text-lg font-semibold">
                {{ valor }}
                <span class="text-sm font-normal text-muted-foreground">por pessoa</span>
            </p>

            <ResumoDeVagas :vagas-disponiveis="evento.vagas_disponiveis" :esgotado="evento.esgotado" />
        </div>

        <p v-if="evento.descricao" class="whitespace-pre-line text-base leading-relaxed">
            {{ evento.descricao }}
        </p>
    </header>
</template>

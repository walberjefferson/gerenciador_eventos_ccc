<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { contarVagas } from '@/lib/formato';
import type { AtividadePublica } from '@/types/evento';
import type { SituacaoDaAtividade } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Uma atividade para escolher. O cartao inteiro e um rotulo de caixa de
 * selecao de verdade: funciona no teclado, no leitor de tela e no toque, e o
 * alvo passa bem dos 44 px.
 */
const props = defineProps<{
    atividade: AtividadePublica;
    situacao: SituacaoDaAtividade;
}>();

const emit = defineEmits<{
    (evento: 'alternar', atividadeId: number): void;
}>();

const idMotivo = computed<string>(() => `atividade-${props.atividade.id}-motivo`);

const descrita = computed<string | undefined>(() => (props.situacao.motivo !== null ? idMotivo.value : undefined));

const desabilitada = computed<boolean>(() => !props.situacao.selecionavel);
</script>

<template>
    <label class="block">
        <input
            type="checkbox"
            class="peer sr-only"
            :checked="situacao.selecionada"
            :disabled="desabilitada"
            :aria-describedby="descrita"
            @change="emit('alternar', atividade.id)"
        />

        <span
            :class="[
                'block rounded-lg border p-4 transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2',
                situacao.selecionada ? 'border-acao bg-acao/5' : 'border-border bg-card',
                desabilitada ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-acao/60',
            ]"
        >
            <span class="flex items-start justify-between gap-3">
                <span class="min-w-0">
                    <span class="block font-medium">{{ atividade.nome }}</span>
                    <span class="block text-sm text-muted-foreground">{{ atividade.horario_rotulo }}</span>
                </span>

                <Badge v-if="atividade.esgotado" variant="secondary" class="shrink-0">Esgotado</Badge>
                <Badge v-else-if="atividade.vagas_disponiveis !== null" variant="sucesso" class="shrink-0">
                    {{ contarVagas(atividade.vagas_disponiveis) }}
                </Badge>
            </span>

            <span v-if="atividade.descricao" class="mt-2 block text-sm text-muted-foreground">{{ atividade.descricao }}</span>

            <span v-if="situacao.motivo" :id="idMotivo" class="mt-2 block text-sm font-medium text-atencao-texto">
                {{ situacao.motivo }}
            </span>
        </span>
    </label>
</template>

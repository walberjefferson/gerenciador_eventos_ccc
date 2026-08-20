<script setup lang="ts">
import CartaoDeAtividade from '@/components/inscricao/CartaoDeAtividade.vue';
import type { GrupoAtividadePublico } from '@/types/evento';
import type { SituacaoDaAtividade } from '@/types/inscricao';

/**
 * Bloco de atividades de um dia, com a regra de escolha visivel antes de a
 * pessoa tentar. A frase da regra vem pronta do servidor.
 */
defineProps<{
    grupo: GrupoAtividadePublico;
    situacaoDe: (atividadeId: number) => SituacaoDaAtividade;
    rotuloDeContagem: string;
    problema?: string | null;
}>();

const emit = defineEmits<{
    (evento: 'alternar', atividadeId: number): void;
}>();
</script>

<template>
    <fieldset class="rounded-lg border border-border p-4">
        <legend class="px-1 text-base font-semibold">
            {{ grupo.nome }}
            <span v-if="grupo.obrigatorio" class="text-acao-texto">*</span>
        </legend>

        <p class="text-sm text-muted-foreground">{{ grupo.regra_rotulo }}</p>
        <p v-if="grupo.descricao" class="mt-1 text-sm text-muted-foreground">{{ grupo.descricao }}</p>

        <p class="mt-2 text-sm font-medium" role="status" aria-live="polite">{{ rotuloDeContagem }}</p>

        <p v-if="problema" class="mt-2 text-sm font-medium text-destructive" role="alert">{{ problema }}</p>

        <div class="mt-4 space-y-3">
            <CartaoDeAtividade
                v-for="atividade in grupo.atividades"
                :key="atividade.id"
                :atividade="atividade"
                :situacao="situacaoDe(atividade.id)"
                @alternar="emit('alternar', $event)"
            />

            <p v-if="grupo.atividades.length === 0" class="text-sm text-muted-foreground">Nenhuma atividade disponível neste bloco.</p>
        </div>
    </fieldset>
</template>

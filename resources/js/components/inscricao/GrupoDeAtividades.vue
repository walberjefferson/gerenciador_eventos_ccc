<script setup lang="ts">
import CartaoDeAtividade from '@/components/inscricao/CartaoDeAtividade.vue';
import type { GrupoAtividadePublico } from '@/types/evento';
import type { SituacaoDaAtividade } from '@/types/inscricao';

/**
 * Bloco de atividades de um dia — o `.group` do prototipo —, com a regra de
 * escolha visivel ANTES de a pessoa tentar. A frase da regra vem pronta do
 * servidor e fica numa pilula verde ao lado do titulo: quem le o titulo le a
 * regra no mesmo movimento dos olhos, em vez de descobri-la ao ser recusado.
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
    <fieldset class="mt-[22px] first:mt-0">
        <!-- .group__h — titulo e regra na mesma linha de base -->
        <legend class="mb-[6px] flex flex-wrap items-baseline gap-x-[10px] gap-y-1">
            <span class="text-[17px] font-semibold">
                {{ grupo.nome }}
                <span v-if="grupo.obrigatorio" class="text-acao-texto">*</span>
            </span>

            <!-- .group__r — a pilula da regra -->
            <span class="bg-sucesso-suave text-sucesso-suave-foreground rounded-full px-[9px] py-[3px] text-[13px] font-semibold">
                {{ grupo.regra_rotulo }}
            </span>
        </legend>

        <!-- .group__n — 14.5px, 58ch -->
        <p v-if="grupo.descricao" class="text-muted-foreground mb-[14px] max-w-[58ch] text-[14.5px] leading-[1.55]">{{ grupo.descricao }}</p>

        <p class="mb-2 text-[13.5px] font-medium" role="status" aria-live="polite">{{ rotuloDeContagem }}</p>

        <p v-if="problema" class="text-destructive mb-2 text-[13.5px] font-medium" role="alert">{{ problema }}</p>

        <div>
            <CartaoDeAtividade
                v-for="atividade in grupo.atividades"
                :key="atividade.id"
                :atividade="atividade"
                :situacao="situacaoDe(atividade.id)"
                @alternar="emit('alternar', $event)"
            />

            <p v-if="grupo.atividades.length === 0" class="text-muted-foreground text-[14.5px]">Nenhuma atividade disponível neste bloco.</p>
        </div>
    </fieldset>
</template>

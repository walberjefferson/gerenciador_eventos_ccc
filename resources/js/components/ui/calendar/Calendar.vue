<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { DateValue } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';
import type { HTMLAttributes } from 'vue';

/**
 * Calendario de escolher UM dia, sobre as primitivas do Reka UI.
 *
 * Entrou a mao, e nao pelo gerador do shadcn-vue (DA-44). Duas diferencas
 * deliberadas em relacao ao que o gerador escreveria:
 *
 *   1. o dia mede 44px, e nao 32px — e um alvo de dedo, e o projeto nao aceita
 *      alvo abaixo de 44px (DA-42). Sete colunas de 44px cabem numa tela de
 *      320px porque a caixa em volta usa 4px de folga em vez de 16px;
 *   2. o mes e o ano ficam num `<div>` com texto de verdade, legivel pelo
 *      leitor de tela, e as setas carregam rotulo escrito.
 *
 * O comportamento de teclado e o da primitiva: setas andam dia a dia,
 * PageUp/PageDown trocam de mes e Escape fecha quem o hospeda.
 */
const props = defineProps<{
    modelValue?: DateValue;
    placeholder?: DateValue;
    minValue?: DateValue;
    maxValue?: DateValue;
    class?: HTMLAttributes['class'];
}>();

defineEmits<{
    (e: 'update:modelValue', valor: DateValue | undefined): void;
}>();
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        :model-value="props.modelValue"
        :placeholder="props.placeholder"
        :min-value="props.minValue"
        :max-value="props.maxValue"
        locale="pt-BR"
        calendar-label="Escolha a data"
        initial-focus
        fixed-weeks
        :class="cn('p-1', props.class)"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <CalendarHeader class="flex items-center justify-between">
            <CalendarPrev
                aria-label="Mês anterior"
                class="hover:bg-accent inline-flex size-11 items-center justify-center rounded-md disabled:opacity-40"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
            </CalendarPrev>

            <CalendarHeading class="text-sm font-medium" />

            <CalendarNext aria-label="Próximo mês" class="hover:bg-accent inline-flex size-11 items-center justify-center rounded-md disabled:opacity-40">
                <ChevronRight class="size-4" aria-hidden="true" />
            </CalendarNext>
        </CalendarHeader>

        <CalendarGrid v-for="mes in grid" :key="mes.value.toString()" class="mt-1 w-full border-collapse">
            <CalendarGridHead>
                <CalendarGridRow>
                    <CalendarHeadCell v-for="dia in weekDays" :key="dia" class="text-muted-foreground size-11 text-[13px] font-normal">
                        {{ dia }}
                    </CalendarHeadCell>
                </CalendarGridRow>
            </CalendarGridHead>

            <CalendarGridBody>
                <CalendarGridRow v-for="(semana, indice) in mes.rows" :key="`semana-${indice}`">
                    <CalendarCell v-for="data in semana" :key="data.toString()" :date="data" class="p-0 text-center">
                        <!--
                            UM ternario nao serve aqui: sao estados que o
                            navegador conhece por atributo (`data-selected`,
                            `data-today`...). Cada um pinta fundo e texto de uma
                            vez so, sem classe estatica concorrente (DA-68).
                        -->
                        <CalendarCellTrigger
                            :day="data"
                            :month="mes.value"
                            class="hover:bg-accent data-[disabled]:text-muted-foreground data-[outside-view]:text-muted-foreground data-[selected]:bg-acao data-[selected]:text-acao-foreground data-[today]:font-semibold inline-flex size-11 items-center justify-center rounded-md text-sm data-[disabled]:pointer-events-none data-[disabled]:opacity-40 data-[outside-view]:opacity-60"
                        />
                    </CalendarCell>
                </CalendarGridRow>
            </CalendarGridBody>
        </CalendarGrid>
    </CalendarRoot>
</template>

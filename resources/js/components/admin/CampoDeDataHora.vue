<script setup lang="ts">
import { DateField } from '@/components/ui/date-field';
import { computed } from 'vue';

/**
 * Data e hora, lado a lado: o calendario da data e um campo de hora.
 *
 * POR QUE NAO E UM `<input type="datetime-local">`: o nativo entrega a
 * aparencia ao navegador, e cada um desenha a sua — no computador ele ainda
 * sai no formato do sistema operacional, que nem sempre e o do Brasil. Era o
 * mesmo motivo que tirou o `<input type="date">` do formulario de inscricao, e
 * a saida aqui e a mesma: reaproveitar o `DateField`, que ja mostra
 * `dd/mm/aaaa` e ja tem calendario proprio.
 *
 * O QUE ELE TROCA COM O FORMULARIO nao muda: continua sendo o ISO
 * `AAAA-MM-DDTHH:MM` que o `AtividadeRequest` ja recebia do campo nativo.
 * Nenhuma regra do servidor precisa saber que a aparencia mudou.
 *
 * A hora fica num campo separado, e nao dentro do de data, porque sao duas
 * perguntas com teclados diferentes: a data vem do calendario ou de oito
 * digitos; a hora, de quatro. Juntas numa caixa so, corrigir a hora obrigaria
 * a passar por cima da data.
 */
const props = defineProps<{
    modelValue?: string;
    /** Prefixo dos `id` dos dois campos — o rotulo de fora aponta para o de data. */
    id: string;
    min?: string;
    max?: string;
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string): void;
}>();

/**
 * O valor chega como "AAAA-MM-DDTHH:MM" e e cortado em dois. Vazio de um lado
 * NAO apaga o outro: quem escolheu a data e ainda nao digitou a hora nao pode
 * perder a data ao fazer isso.
 */
const parteData = computed<string>(() => (props.modelValue ?? '').split('T')[0] ?? '');
const parteHora = computed<string>(() => (props.modelValue ?? '').split('T')[1]?.slice(0, 5) ?? '');

function emitir(data: string, hora: string): void {
    if (data === '' && hora === '') {
        emits('update:modelValue', '');

        return;
    }

    emits('update:modelValue', `${data}T${hora}`);
}

function aoTrocarData(valor: string): void {
    emitir(valor, parteHora.value);
}

function aoTrocarHora(evento: Event): void {
    emitir(parteData.value, (evento.target as HTMLInputElement).value);
}
</script>

<template>
    <div class="flex flex-wrap items-start gap-2">
        <div class="min-w-[10rem] flex-1">
            <DateField
                :id="props.id"
                :model-value="parteData"
                :min="props.min"
                :max="props.max"
                rotulo-do-calendario="Escolher a data no calendário"
                v-bind="$attrs"
                @update:model-value="aoTrocarData"
            />
        </div>

        <div class="flex flex-col">
            <input
                :id="`${props.id}-hora`"
                type="time"
                :value="parteHora"
                :aria-label="`Hora — ${props.id.includes('comeca') ? 'início' : 'término'}`"
                class="border-input bg-background focus-visible:ring-ring h-11 w-[7.5rem] rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:outline-hidden"
                @input="aoTrocarHora"
            />
        </div>
    </div>
</template>

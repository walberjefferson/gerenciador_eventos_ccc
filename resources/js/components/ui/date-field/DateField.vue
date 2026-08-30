<script setup lang="ts">
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { CalendarDate, getLocalTimeZone, today, type DateValue } from '@internationalized/date';
import { CalendarIcon } from 'lucide-vue-next';
import { computed, nextTick, ref, watch, type HTMLAttributes } from 'vue';

/**
 * Campo de data digitavel, com calendario proprio.
 *
 * O que a pessoa VE e `dd/mm/aaaa`, que e como uma data se escreve em
 * portugues. O que o campo TROCA com o formulario continua sendo ISO
 * (AAAA-MM-DD), que e o que o `StoreInscricaoRequest` espera: nada fora daqui
 * precisa saber que a aparencia mudou.
 *
 * Antes daqui morava um `<input type="date">`. Ele era acessivel e nao pesava
 * nada, mas entregava a aparencia do calendario ao navegador — cada um com a
 * sua —, e no computador o campo saia com o formato do sistema operacional, e
 * nao com o do desenho. A troca foi decisao do dono do produto, sabendo do
 * custo: uma dependencia nova (`@internationalized/date`) e a conferencia de
 * data inexistente, que o campo nativo fazia de graca.
 *
 * O que ele NAO faz de proposito: dizer se a data existe. Isso e conferido
 * por quem usa o campo, junto das outras regras do formulario, para a frase
 * de erro aparecer no mesmo lugar que a dos outros campos. Aqui basta que
 * oito digitos virem uma data em ISO.
 */
const props = defineProps<{
    modelValue?: string;
    /** Primeiro dia que o calendario aceita, em ISO. */
    min?: string;
    /** Ultimo dia que o calendario aceita, em ISO. Vale para os dois caminhos. */
    max?: string;
    /** O que a lupa do leitor de tela le no botao do calendario. */
    rotuloDoCalendario?: string;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string): void;
}>();

defineOptions({
    // Os atributos de fora (id, name, aria-*, @blur) sao do CAMPO, e nao da
    // caixa que o envolve: sem isto eles parariam no `<div>` e o rotulo
    // deixaria de apontar para lugar nenhum.
    inheritAttrs: false,
});

const campo = ref<HTMLInputElement | null>(null);
const aberto = ref(false);

function apenasDigitos(valor: string): string {
    return valor.replace(/\D/g, '').slice(0, 8);
}

/** Vai pondo as barras conforme os numeros chegam. */
function mascarar(digitos: string): string {
    if (digitos.length <= 2) {
        return digitos;
    }

    if (digitos.length <= 4) {
        return `${digitos.slice(0, 2)}/${digitos.slice(2)}`;
    }

    return `${digitos.slice(0, 2)}/${digitos.slice(2, 4)}/${digitos.slice(4)}`;
}

function deIsoParaTexto(iso: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return '';
    }

    const [ano, mes, dia] = iso.split('-');

    return `${dia}/${mes}/${ano}`;
}

function deTextoParaIso(texto: string): string {
    const digitos = apenasDigitos(texto);

    if (digitos.length < 8) {
        return '';
    }

    return `${digitos.slice(4)}-${digitos.slice(2, 4)}-${digitos.slice(0, 2)}`;
}

const escrito = ref(deIsoParaTexto(props.modelValue ?? ''));

// O valor pode mudar por fora (o servidor devolvendo o formulario, por
// exemplo). Quando isso acontece, o que esta escrito acompanha — mas nao
// enquanto a pessoa digita uma data ainda incompleta, senao o campo se
// apagaria sozinho no meio da digitacao.
watch(
    () => props.modelValue,
    (novo) => {
        if (deTextoParaIso(escrito.value) !== (novo ?? '')) {
            escrito.value = deIsoParaTexto(novo ?? '');
        }
    },
);

function aoDigitar(evento: Event): void {
    const alvo = evento.target as HTMLInputElement;
    let digitos = apenasDigitos(alvo.value);

    // Apagar em cima de uma barra apaga o numero que vem antes dela. Sem isto,
    // a barra volta no mesmo instante e o cursor parece nao andar.
    if ((evento as InputEvent).inputType === 'deleteContentBackward' && alvo.value.endsWith('/')) {
        digitos = digitos.slice(0, -1);
    }

    escrito.value = mascarar(digitos);
    alvo.value = escrito.value;

    emits('update:modelValue', deTextoParaIso(escrito.value));
}

/** Texto ISO para o tipo de data do calendario — nulo quando nao da. */
function paraDataDoCalendario(iso: string | undefined): DateValue | undefined {
    if (iso === undefined || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return undefined;
    }

    const [ano, mes, dia] = iso.split('-').map(Number);

    try {
        const data = new CalendarDate(ano, mes, dia);

        // 31/02 vira 03/03 em silencio numa data comum; o CalendarDate guarda o
        // que recebeu, entao a conferencia e feita aqui mesmo.
        return data.day === dia && data.month === mes ? data : undefined;
    } catch {
        return undefined;
    }
}

const escolhida = computed<DateValue | undefined>(() => paraDataDoCalendario(props.modelValue));
const primeiroDia = computed<DateValue | undefined>(() => paraDataDoCalendario(props.min));
const ultimoDia = computed<DateValue | undefined>(() => paraDataDoCalendario(props.max));

/** Em que mes o calendario abre quando ainda nao ha data escolhida. */
const mesDeAbertura = computed<DateValue>(() => escolhida.value ?? ultimoDia.value ?? today(getLocalTimeZone()));

function aoEscolherNoCalendario(data: DateValue | undefined): void {
    if (data === undefined) {
        return;
    }

    const iso = `${String(data.year).padStart(4, '0')}-${String(data.month).padStart(2, '0')}-${String(data.day).padStart(2, '0')}`;

    escrito.value = deIsoParaTexto(iso);
    emits('update:modelValue', iso);
    aberto.value = false;
}

/**
 * Fechou o calendario: o foco volta para o CAMPO, e nao para o botao.
 *
 * Quem escolheu um dia terminou com o calendario; deixar o cursor no botao que
 * o abre convida a abri-lo de novo. E quem usa teclado precisa voltar para
 * algum lugar — nunca para o vazio.
 */
function devolverOFoco(evento: Event): void {
    evento.preventDefault();
    void nextTick(() => campo.value?.focus());
}
</script>

<template>
    <div class="relative w-full">
        <input
            ref="campo"
            type="text"
            inputmode="numeric"
            autocomplete="bday"
            placeholder="dd/mm/aaaa"
            :value="escrito"
            v-bind="$attrs"
            :class="
                cn(
                    'flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                    props.class,
                    // Depois da classe de fora, de proposito: o espaco da
                    // direita e do botao do calendario, e nenhum padding
                    // herdado pode passar por cima dele.
                    'pr-12',
                )
            "
            @input="aoDigitar"
        />

        <Popover v-model:open="aberto">
            <PopoverTrigger as-child>
                <!-- 44px de alvo, dentro da altura do proprio campo. -->
                <button
                    type="button"
                    :aria-label="props.rotuloDoCalendario ?? 'Escolher no calendário'"
                    class="text-muted-foreground hover:text-foreground absolute top-1/2 right-[3px] inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-md"
                >
                    <CalendarIcon class="size-[18px]" aria-hidden="true" />
                </button>
            </PopoverTrigger>

            <PopoverContent class="w-auto p-1" @close-auto-focus="devolverOFoco">
                <Calendar
                    :model-value="escolhida"
                    :placeholder="mesDeAbertura"
                    :min-value="primeiroDia"
                    :max-value="ultimoDia"
                    @update:model-value="aoEscolherNoCalendario"
                />
            </PopoverContent>
        </Popover>
    </div>
</template>

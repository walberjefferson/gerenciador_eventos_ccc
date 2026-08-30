<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, ref, watch, type HTMLAttributes } from 'vue';

/**
 * Campo de texto que se pontua sozinho enquanto a pessoa digita.
 *
 * POR QUE ELE EXISTE, EM VEZ DE UM `Input` COM `v-model` NUM COMPUTED:
 *
 * O `Input` do projeto guarda o valor num ref proprio (`useVModel` em modo
 * passivo) e so o reescreve quando o valor que vem de fora MUDA. Numa mascara
 * isso abre um buraco silencioso: quem digita uma letra no meio do telefone
 * nao altera nenhum digito, entao o valor mascarado sai igual ao anterior, o
 * ref de fora nao muda, o watch nao dispara — e a letra fica na tela, aceita.
 * O mesmo vale para ponto, virgula e qualquer coisa que a mascara descarta.
 *
 * Aqui o valor do elemento e reescrito a CADA digitacao, tenha o modelo mudado
 * ou nao. E o unico jeito de "so numeros" ser verdade em vez de intencao.
 *
 * O CURSOR e devolvido ao lugar certo contando DIGITOS, e nao caracteres: a
 * pontuacao entra e sai sozinha, entao "estou depois do quinto digito" e a
 * unica referencia que sobrevive a mascara. Sem isso, corrigir um numero no
 * meio jogaria o cursor para o fim a cada tecla.
 */
const props = defineProps<{
    /** Como o texto APARECE. Recebe o que foi digitado, devolve o pontuado. */
    mascara: (valor: string) => string;
    /**
     * O que vai para o `v-model`, quando difere do que aparece.
     *
     * O telefone guarda o texto pontuado, que e como uma pessoa o le. O CPF
     * guarda so os digitos, porque e deles que sai o `documento_hash` — e e
     * por esse hash que o sistema reconhece inscricao repetida.
     */
    paraOModelo?: (mascarado: string) => string;
    class?: HTMLAttributes['class'];
}>();

const modelo = defineModel<string>({ required: true });

const campo = ref<HTMLInputElement | null>(null);

/** As mesmas classes do `Input`, para os dois campos nao destoarem do resto. */
const classes = computed(() =>
    cn(
        'flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
        props.class,
    ),
);

function contarDigitos(texto: string): number {
    return (texto.match(/\d/g) ?? []).length;
}

/** Onde fica o cursor depois de `quantidade` digitos do texto ja pontuado. */
function posicaoApos(texto: string, quantidade: number): number {
    if (quantidade === 0) {
        return 0;
    }

    let vistos = 0;

    for (let i = 0; i < texto.length; i += 1) {
        if (/\d/.test(texto[i])) {
            vistos += 1;

            if (vistos === quantidade) {
                return i + 1;
            }
        }
    }

    return texto.length;
}

function aoDigitar(evento: Event): void {
    const alvo = evento.target as HTMLInputElement;

    // Quantos digitos existiam ANTES do cursor. E por eles que ele volta ao
    // lugar depois de a pontuacao ser refeita.
    const cursor = alvo.selectionStart ?? alvo.value.length;
    const digitosAntesDoCursor = contarDigitos(alvo.value.slice(0, cursor));

    const mascarado = props.mascara(alvo.value);

    // A reescrita e incondicional de proposito — ver o comentario do topo.
    alvo.value = mascarado;

    const posicao = posicaoApos(mascarado, digitosAntesDoCursor);
    alvo.setSelectionRange(posicao, posicao);

    modelo.value = props.paraOModelo ? props.paraOModelo(mascarado) : mascarado;
}

/**
 * Quando o valor muda POR FORA — o servidor devolvendo o formulario, um teste
 * preenchendo o campo —, a caixa acompanha. A comparacao evita reescrever o
 * elemento no meio de uma digitacao, que e o que jogaria o cursor para o fim.
 */
watch(modelo, (novo) => {
    const esperado = props.mascara(novo);

    if (campo.value !== null && campo.value.value !== esperado) {
        campo.value.value = esperado;
    }
});
</script>

<template>
    <input ref="campo" :value="mascara(modelo)" :class="classes" @input="aoDigitar" />
</template>

<script setup lang="ts">
import { formatarValor } from '@/lib/formato';
import type { LotePublico } from '@/types/evento';
import { computed } from 'vue';

/**
 * A sucessão de lotes do evento, nas duas telas públicas.
 *
 * MOSTRA TODOS, e não só o que vale agora. É essa a razão de existir de um
 * lote: o encerrado conta de onde o preço veio, o futuro conta para onde ele
 * vai, e é a distância entre os dois que faz alguém se inscrever hoje em vez de
 * "semana que vem". Uma lista com um item só não diria nada.
 *
 * SÓ O VIGENTE É SELECIONÁVEL, e quem decidiu isso foi o servidor: cada lote
 * chega com `selecionavel` já resolvido. A tela não olha data nem contador —
 * fazer essa conta aqui criaria um segundo lugar onde a regra mora, e o relógio
 * do aparelho de quem lê não é o relógio que vende a vaga.
 *
 * ACESSIBILIDADE: a situação de cada lote está SEMPRE escrita, nunca só na cor
 * (WCAG 1.4.1). No modo de escolha, o que não pode ser marcado é um `radio`
 * desabilitado de verdade — anunciado como indisponível pelo leitor de tela, e
 * não apenas pintado de cinza.
 */
const props = withDefaults(
    defineProps<{
        lotes: LotePublico[];
        moeda?: string;
        /**
         * Com escolha, a lista vira um grupo de opções e o vigente aparece
         * marcado. Sem ela, é uma lista de leitura — o caso da vitrine.
         */
        comEscolha?: boolean;
        /** Aviso do servidor quando o lote virou entre a tela e o envio (RN-L5). */
        erro?: string | null;
    }>(),
    { moeda: 'BRL', comEscolha: false, erro: null },
);

/** O lote enviado com o formulário. Só existe no modo de escolha. */
const loteEscolhido = defineModel<number | null>({ default: null });

const temLotes = computed<boolean>(() => props.lotes.length > 0);
</script>

<template>
    <section v-if="temLotes" aria-labelledby="titulo-lotes" data-testid="lista-de-lotes">
        <h2
            id="titulo-lotes"
            class="text-muted-foreground border-border mb-[18px] border-b pb-[14px] text-[13px] font-semibold tracking-[0.13em] uppercase"
        >
            Lotes de inscrição
        </h2>

        <p
            v-if="erro"
            role="alert"
            data-testid="erro-do-lote"
            class="border-destructive/40 bg-destructive/10 text-destructive mb-4 rounded-[10px] border px-4 py-3 text-[14px]"
        >
            {{ erro }}
        </p>

        <!--
            Uma lista de verdade, e não uma pilha de `div`: o leitor de tela
            anuncia "lista de 3 itens" e a pessoa sabe, antes de percorrer, que
            está diante de uma sequência.

            No modo de escolha o papel vira `radiogroup`, porque é isso que ele
            é: uma escolha entre opções mutuamente exclusivas — mesmo que, hoje,
            só uma delas esteja habilitada.
        -->
        <ul
            class="border-border grid gap-0 overflow-hidden rounded-[10px] border"
            :role="comEscolha ? 'radiogroup' : undefined"
            :aria-labelledby="comEscolha ? 'titulo-lotes' : undefined"
        >
            <li
                v-for="lote in lotes"
                :key="lote.id"
                :data-testid="`lote-${lote.situacao}`"
                :aria-disabled="!lote.selecionavel ? 'true' : undefined"
                class="border-border flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b px-4 py-[13px] last:border-b-0"
                :class="lote.selecionavel ? 'bg-card' : 'bg-muted/30 text-muted-foreground'"
            >
                <component
                    :is="comEscolha ? 'label' : 'div'"
                    class="flex min-w-0 flex-1 items-baseline gap-3"
                    :class="lote.selecionavel && comEscolha ? 'cursor-pointer' : ''"
                >
                    <input
                        v-if="comEscolha"
                        v-model="loteEscolhido"
                        type="radio"
                        name="lote_id"
                        :value="lote.id"
                        :disabled="!lote.selecionavel"
                        class="border-input text-acao focus-visible:ring-ring size-5 shrink-0 self-center disabled:cursor-not-allowed"
                    />

                    <span class="min-w-0">
                        <span class="block text-[15.5px] font-medium" :class="lote.selecionavel ? '' : 'line-through decoration-1'">{{
                            lote.nome
                        }}</span>
                        <!-- O limite em palavras: "até tal dia", "restam tantas
                             vagas" — ou por que ele encerrou. -->
                        <span v-if="lote.limite_rotulo" class="text-muted-foreground block text-[13px]">{{ lote.limite_rotulo }}</span>
                    </span>
                </component>

                <span class="font-titulo ml-auto text-[17px] font-semibold tracking-[-0.02em] tabular-nums">
                    {{ formatarValor(lote.valor_centavos, moeda) }}
                </span>

                <!-- A palavra fica sempre escrita. A cor é reforço, nunca a
                     informação sozinha (WCAG 1.4.1). -->
                <span
                    class="w-full text-[12px] font-semibold tracking-[0.08em] uppercase sm:w-auto sm:basis-[92px] sm:text-right"
                    :class="lote.situacao === 'vigente' ? 'text-acao-texto' : 'text-muted-foreground'"
                >
                    {{ lote.situacao_rotulo }}
                </span>
            </li>
        </ul>

        <p v-if="comEscolha" class="text-muted-foreground mt-3 text-[13px]">
            A inscrição sai pelo lote em vigor no momento do envio. Se ele mudar enquanto você preenche, avisamos antes de confirmar.
        </p>
    </section>
</template>

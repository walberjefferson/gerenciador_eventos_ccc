<script setup lang="ts">
import { Toaster } from '@/components/ui/toast';
import { computed } from 'vue';

/**
 * Moldura das telas publicas: cabecalho com a logo, conteudo e rodape.
 * Sem barra lateral e sem menu de aplicacao — quem chega aqui e um visitante,
 * nao um usuario autenticado.
 *
 * Pensado primeiro para o celular: uma coluna so, sem rolagem horizontal a
 * partir de 320 px de largura.
 */
const props = withDefaults(
    defineProps<{
        contatoEmail?: string | null;
        contatoTelefone?: string | null;
        /**
         * Largura da coluna de conteudo.
         *
         * "padrao" e a coluna estreita de leitura, que serve a home, a cobranca
         * e o acompanhamento: uma coisa de cada vez, do tamanho de um celular
         * mesmo quando a tela e grande.
         *
         * "ampla" existe para as duas telas em que ha DUAS coisas lado a lado em
         * tela grande — a vitrine (convite + programacao) e o formulario
         * (campos + resumo). Nao e "mais espaco por gosto": abaixo de 1024px as
         * duas voltam a ser uma coluna so, e a largura extra deixa de existir.
         */
        largura?: 'padrao' | 'ampla';
    }>(),
    {
        contatoEmail: null,
        contatoTelefone: null,
        largura: 'padrao',
    },
);

const larguraDaColuna = computed<string>(() => (props.largura === 'ampla' ? 'max-w-5xl' : 'max-w-3xl'));

const anoAtual = new Date().getFullYear();
</script>

<template>
    <div class="bg-background text-foreground flex min-h-screen w-full flex-col overflow-x-hidden">
        <a
            href="#conteudo"
            class="focus:bg-acao focus:text-acao-foreground sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:px-4 focus:py-3"
        >
            Ir direto para o conteúdo
        </a>

        <header class="border-border bg-card border-b">
            <div :class="['mx-auto flex w-full items-center gap-3 px-4 py-3', larguraDaColuna]">
                <img
                    src="/img/logo-ccc.png"
                    width="1937"
                    height="2000"
                    alt="Caminhada Comunitária com Cristo"
                    class="h-12 w-auto shrink-0 object-contain"
                    decoding="async"
                />

                <div class="min-w-0">
                    <p class="truncate text-sm leading-tight font-semibold sm:text-base">Caminhada Comunitária com Cristo</p>
                    <p class="text-muted-foreground truncate text-xs">Inscrições da comunidade</p>
                </div>
            </div>
        </header>

        <!-- A faixa de topo, quando a pagina tem uma. Fica FORA do container
             de propria vontade: e o unico jeito de o fundo sangrar de borda a
             borda em tela grande. Quem usa o slot centraliza o conteudo por
             dentro, com a mesma largura do resto da pagina. -->
        <slot name="hero" />

        <main id="conteudo" :class="['mx-auto w-full flex-1 px-4 pt-6 pb-10', larguraDaColuna]">
            <slot />
        </main>

        <footer class="border-border bg-card border-t">
            <div :class="['text-muted-foreground mx-auto w-full space-y-2 px-4 py-6 text-sm', larguraDaColuna]">
                <p v-if="contatoEmail || contatoTelefone">
                    Precisa de ajuda? Fale com a organização
                    <template v-if="contatoEmail">
                        pelo e-mail
                        <a class="text-acao-texto font-medium underline underline-offset-4" :href="`mailto:${contatoEmail}`">
                            {{ contatoEmail }}
                        </a>
                    </template>
                    <template v-if="contatoEmail && contatoTelefone"> ou </template>
                    <template v-if="contatoTelefone">
                        pelo telefone
                        <a class="text-acao-texto font-medium underline underline-offset-4" :href="`tel:${contatoTelefone}`">
                            {{ contatoTelefone }}
                        </a>
                    </template>
                    .
                </p>

                <p>&copy; {{ anoAtual }} Caminhada Comunitária com Cristo</p>
            </div>
        </footer>

        <Toaster />
    </div>
</template>

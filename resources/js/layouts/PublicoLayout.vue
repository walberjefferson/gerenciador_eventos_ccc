<script setup lang="ts">
import { Toaster } from '@/components/ui/toast';
import { Link, usePage } from '@inertiajs/vue3';
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

/**
 * A navegacao do cabecalho — o `.top__nav` do prototipo.
 *
 * Sao os dois caminhos que alguem procura de fora de uma tela: a agenda, para
 * ver o que existe, e a propria inscricao, para quem ja se inscreveu e perdeu
 * o link. Ate aqui o segundo so aparecia na home, e quem chegasse por um link
 * direto do WhatsApp nao tinha como voltar a ele.
 *
 * O monograma do prototipo NAO entra: marca e assunto de quem conduz a
 * comunidade, e nao desta tela (DA-37).
 */
const caminhoAtual = computed<string>(() => {
    const endereco = usePage().url;

    // O `url` do Inertia ja vem sem dominio, mas pode trazer a consulta.
    return endereco.split('?')[0];
});

const links: Array<{ rotulo: string; destino: string; atual: (caminho: string) => boolean }> = [
    { rotulo: 'Agenda', destino: route('home'), atual: (caminho) => caminho === '/' },
    { rotulo: 'Minha inscrição', destino: route('inscricoes.acesso'), atual: (caminho) => caminho.startsWith('/acesso') },
];
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
            <div :class="['mx-auto flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3', larguraDaColuna]">
                <img
                    src="/img/logo-ccc.png"
                    width="1937"
                    height="2000"
                    alt="Caminhada Comunitária com Cristo"
                    class="h-12 w-auto shrink-0 object-contain"
                    decoding="async"
                />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm leading-tight font-semibold sm:text-base">Caminhada Comunitária com Cristo</p>
                    <p class="text-muted-foreground truncate text-xs">Inscrições da comunidade</p>
                </div>

                <!--
                    .top__nav — 8px entre os links, encostada a direita.

                    No celular ela desce para uma segunda linha inteira, em vez
                    de espremer o nome da comunidade ao lado dela: a 320px os
                    dois nao cabem na mesma faixa sem que um dos dois vire
                    reticencias.
                -->
                <nav aria-label="Navegação do site" class="order-last flex w-full items-center gap-2 sm:order-none sm:w-auto">
                    <!-- .top__link — 14px, muted, padding 8/12 e raio de pilula.
                         A altura minima e nossa: o desenho deixa o link com 33px
                         de altura, abaixo dos 44px de alvo de toque (DA-42). -->
                    <Link
                        v-for="link in links"
                        :key="link.rotulo"
                        :href="link.destino"
                        :aria-current="link.atual(caminhoAtual) ? 'page' : undefined"
                        class="text-muted-foreground hover:bg-secondary hover:text-foreground aria-[current=page]:text-foreground aria-[current=page]:bg-secondary inline-flex min-h-11 items-center rounded-full px-3 text-sm font-medium"
                    >
                        {{ link.rotulo }}
                    </Link>
                </nav>
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

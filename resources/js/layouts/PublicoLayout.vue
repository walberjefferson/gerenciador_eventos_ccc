<script setup lang="ts">
import { Toaster } from '@/components/ui/toast';

/**
 * Moldura das telas publicas: cabecalho com a logo, conteudo e rodape.
 * Sem barra lateral e sem menu de aplicacao — quem chega aqui e um visitante,
 * nao um usuario autenticado.
 *
 * Pensado primeiro para o celular: uma coluna so, sem rolagem horizontal a
 * partir de 320 px de largura.
 */
withDefaults(
    defineProps<{
        contatoEmail?: string | null;
        contatoTelefone?: string | null;
    }>(),
    {
        contatoEmail: null,
        contatoTelefone: null,
    },
);

const anoAtual = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-screen w-full flex-col overflow-x-hidden bg-background text-foreground">
        <a
            href="#conteudo"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-acao focus:px-4 focus:py-3 focus:text-acao-foreground"
        >
            Ir direto para o conteúdo
        </a>

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex w-full max-w-3xl items-center gap-3 px-4 py-3">
                <img
                    src="/img/logo-ccc.png"
                    width="1937"
                    height="2000"
                    alt="Caminhada Comunitária com Cristo"
                    class="h-12 w-auto shrink-0 object-contain"
                    decoding="async"
                />

                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-tight sm:text-base">Caminhada Comunitária com Cristo</p>
                    <p class="truncate text-xs text-muted-foreground">Inscrições da comunidade</p>
                </div>
            </div>
        </header>

        <!-- A faixa de topo, quando a pagina tem uma. Fica FORA do container
             de propria vontade: e o unico jeito de o fundo sangrar de borda a
             borda em tela grande. Quem usa o slot centraliza o conteudo por
             dentro, com o mesmo max-w-3xl do resto. -->
        <slot name="hero" />

        <main id="conteudo" class="mx-auto w-full max-w-3xl flex-1 px-4 pb-10 pt-6">
            <slot />
        </main>

        <footer class="border-t border-border bg-card">
            <div class="mx-auto w-full max-w-3xl space-y-2 px-4 py-6 text-sm text-muted-foreground">
                <p v-if="contatoEmail || contatoTelefone">
                    Precisa de ajuda? Fale com a organização
                    <template v-if="contatoEmail">
                        pelo e-mail
                        <a class="font-medium text-informacao-texto underline underline-offset-4" :href="`mailto:${contatoEmail}`">
                            {{ contatoEmail }}
                        </a>
                    </template>
                    <template v-if="contatoEmail && contatoTelefone"> ou </template>
                    <template v-if="contatoTelefone">
                        pelo telefone
                        <a class="font-medium text-informacao-texto underline underline-offset-4" :href="`tel:${contatoTelefone}`">
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

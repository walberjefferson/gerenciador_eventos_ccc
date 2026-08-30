<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head } from '@inertiajs/vue3';

/**
 * Moldura das telas administrativas.
 *
 * Reaproveita o esqueleto que já vinha no projeto (barra lateral, cabeçalho e
 * trilha de navegação) e acrescenta o que toda tela do lado de dentro precisa:
 * um título de verdade na aba do navegador, um cabeçalho com explicação curta
 * e um ponto de entrada para o teclado pular direto ao conteúdo.
 */
interface Props {
    titulo: string;
    descricao?: string;
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    descricao: undefined,
    breadcrumbs: () => [],
});
</script>

<template>
    <Head :title="titulo" />

    <AppSidebarLayout :breadcrumbs="breadcrumbs">
        <a
            href="#conteudo-administrativo"
            class="sr-only rounded-md bg-acao px-4 py-2 text-acao-foreground focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50"
        >
            Pular para o conteúdo
        </a>

        <div id="conteudo-administrativo" class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ titulo }}</h1>
                <p v-if="descricao" class="max-w-3xl text-sm text-muted-foreground">{{ descricao }}</p>
            </header>

            <slot />
        </div>
    </AppSidebarLayout>
</template>

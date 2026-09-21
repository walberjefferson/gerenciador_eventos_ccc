<script setup lang="ts">
import { Toaster } from '@/components/ui/toast';
import { useAvisosDoServidor } from '@/composables/useAvisosDoServidor';
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
 *
 * É também daqui que sai o aviso rápido de toda ação confirmada: a moldura é o
 * único lugar que está na tela o tempo inteiro, então nenhuma das telas de
 * dentro precisa saber que ele existe.
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

useAvisosDoServidor();
</script>

<template>
    <Head :title="titulo" />

    <AppSidebarLayout :breadcrumbs="breadcrumbs">
        <a
            href="#conteudo-administrativo"
            class="bg-acao text-acao-foreground sr-only rounded-md px-4 py-2 focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50"
        >
            Pular para o conteúdo
        </a>

        <div id="conteudo-administrativo" class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ titulo }}</h1>
                <p v-if="descricao" class="text-muted-foreground max-w-3xl text-sm">{{ descricao }}</p>
            </header>

            <slot />
        </div>

        <Toaster />
    </AppSidebarLayout>
</template>

<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <!--
            "min-w-0": sem isto, a coluna de conteúdo se recusa a ficar mais
            estreita do que a tabela mais larga que estiver dentro dela — e no
            celular o navegador reage afastando a página inteira, como se fosse
            uma folha grande vista de longe. Com isto, a tabela rola sozinha na
            caixa dela e o resto da tela continua do tamanho do aparelho.
        -->
        <AppContent variant="sidebar" class="min-w-0">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
    </AppShell>
</template>

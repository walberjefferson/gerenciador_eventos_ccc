<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * A marca no canto do painel.
 *
 * Dizia "Laravel Starter Kit", nome que veio do pacote inicial: quem organiza
 * o evento nao tem por que saber com que ferramenta o sistema foi escrito, e
 * o nome ainda dava a entender que a pessoa estava dentro de outro produto.
 *
 * Agora diz a mesma coisa que o cabecalho do lado publico, na mesma ordem: a
 * comunidade em cima, o que a tela faz embaixo. Quem administra e quem se
 * inscreve passam a ver um sistema so.
 */
interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
        <AppLogoIcon class="size-5 text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-none font-semibold">Caminhada com Cristo</span>
        <span class="text-muted-foreground truncate text-xs leading-none">Painel de inscrições</span>
    </div>
</template>

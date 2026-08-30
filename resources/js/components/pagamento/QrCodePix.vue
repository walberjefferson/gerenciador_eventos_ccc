<script setup lang="ts">
/**
 * O QR Code da cobranca.
 *
 * O desenho chega pronto do servidor, em SVG, e vai direto para dentro do
 * HTML: aparece mesmo se o JavaScript falhar, fica nitido em qualquer tela e
 * imprime bem. O conteudo e gerado pelo proprio sistema (bacon/bacon-qr-code),
 * nunca vem do participante — por isso pode ser embutido.
 */
defineProps<{
    svg: string | null;
}>();
</script>

<template>
    <figure v-if="svg" class="flex flex-col items-center gap-3">
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div
            class="border-border w-full max-w-[16rem] rounded-lg border bg-white p-3 [&>svg]:h-auto [&>svg]:w-full"
            data-testid="qr-code-pix"
            v-html="svg"
        />

        <figcaption class="text-muted-foreground text-center text-sm">Aponte a câmera do aplicativo do seu banco para este código.</figcaption>
    </figure>

    <p v-else class="text-muted-foreground text-sm">O QR Code não está disponível para esta cobrança. Use o código copia e cola abaixo.</p>
</template>

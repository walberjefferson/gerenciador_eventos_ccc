<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import type { IngressoDoParticipante } from '@/types/ingresso';
import { Download, Ticket } from 'lucide-vue-next';

/**
 * O ingresso na tela do participante: o QR, o codigo e o papel para imprimir.
 *
 * O desenho do QR chega PRONTO do servidor, em SVG, e vai direto para dentro
 * do HTML — como ja acontece com o QR do Pix. Aparece mesmo se o JavaScript
 * falhar, fica nitido em qualquer tela e imprime bem. O conteudo e gerado pelo
 * proprio sistema, nunca vem do participante, por isso pode ser embutido.
 *
 * O CODIGO ESCRITO POR EXTENSO NAO E ENFEITE. Camera suja, tela rachada, sol
 * batendo no celular na fila do portao: quando o desenho nao for lido, quem
 * esta na portaria digita esses doze caracteres. Por isso ele aparece grande,
 * em fonte de largura fixa e em grupos de quatro — e por isso ele nunca sai
 * desta tela, nem quando o QR estiver disponivel.
 */
defineProps<{
    ingresso: IngressoDoParticipante;
    /** O SVG do QR, pronto do servidor. Null quando o desenho nao pode ser gerado. */
    qrSvg: string | null;
    /** URL assinada do PDF. Null quando nao ha o que baixar. */
    urlPdf: string | null;
}>();
</script>

<template>
    <Card data-testid="ingresso-da-inscricao">
        <CardHeader class="pb-3">
            <div class="flex items-start gap-3">
                <span class="bg-sucesso text-sucesso-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
                    <Ticket class="size-6" aria-hidden="true" />
                </span>

                <div class="min-w-0 space-y-1">
                    <h2 class="text-lg font-semibold">Seu ingresso</h2>
                    <p class="text-muted-foreground text-sm leading-relaxed">
                        Apresente este código na entrada do evento. Ele vale para uma única entrada.
                    </p>
                </div>
            </div>
        </CardHeader>

        <CardContent class="space-y-4">
            <figure v-if="qrSvg" class="flex flex-col items-center gap-3">
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    class="border-border w-full max-w-[16rem] rounded-lg border bg-white p-3 [&>svg]:h-auto [&>svg]:w-full"
                    data-testid="qr-code-ingresso"
                    v-html="qrSvg"
                />

                <figcaption class="text-muted-foreground text-center text-sm">A organização aponta o leitor para este código na entrada.</figcaption>
            </figure>

            <div class="space-y-1 text-center">
                <p class="text-muted-foreground text-sm">Código do ingresso</p>
                <p class="font-mono text-xl font-semibold tracking-widest break-all" data-testid="codigo-do-ingresso">
                    {{ ingresso.codigo_formatado }}
                </p>
                <p class="text-muted-foreground text-sm">Se o código não for lido pelo leitor, informe estes caracteres.</p>
            </div>

            <!-- Link comum, e nao navegacao do Inertia: o destino e um arquivo
                 para baixar, nao uma tela. -->
            <Button
                v-if="urlPdf"
                as-child
                class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base"
                data-testid="botao-ingresso-pdf"
            >
                <a :href="urlPdf">
                    <Download aria-hidden="true" />
                    Baixar o ingresso em PDF
                </a>
            </Button>
        </CardContent>
    </Card>
</template>

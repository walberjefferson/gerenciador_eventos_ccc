<script setup lang="ts">
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { varianteDeAtivo, varianteDoDominio, type DominioDeSituacao } from '@/lib/situacoes';
import { computed } from 'vue';

/**
 * A situação de uma linha, escrita e colorida.
 *
 * Existe para que nenhuma tela volte a decidir cor de situação por conta
 * própria. A tela diz de QUE dominio é a situação e passa o valor bruto do
 * enum; a cor sai do `lib/situacoes.ts`, que é o único lugar onde esse mapa
 * mora.
 *
 * **A palavra fica sempre escrita.** A cor é reforço, nunca a informação
 * sozinha (WCAG 1.4.1): quem não distingue verde de vermelho lê "Confirmada" e
 * "Expirada" do mesmo jeito, e o leitor de tela anuncia o texto.
 *
 * Rótulo nulo não vira etiqueta vazia nem etiqueta cinza dizendo nada: vira o
 * travessão que a tabela já usava para "não há". Uma pílula colorida com um
 * traço dentro pareceria um estado do sistema, e não a ausência de um.
 */
const props = defineProps<{
    /** Qual mapa consultar. `ativo` é o par ligado/desligado dos cadastros. */
    dominio: DominioDeSituacao | 'ativo';
    /**
     * O valor BRUTO do enum (`confirmada`, `aguardando_pagamento`) — ou o
     * booleano, quando o domínio é `ativo`. Nunca o rótulo legível: rótulo muda
     * quando alguém melhora um texto, valor de enum não muda sem migração.
     */
    situacao: string | boolean | null;
    /** O que a pessoa lê. É ele que o leitor de tela anuncia. */
    rotulo: string | null;
}>();

const variante = computed<BadgeVariants['variant']>(() =>
    props.dominio === 'ativo' ? varianteDeAtivo(props.situacao === true) : varianteDoDominio(props.dominio, String(props.situacao ?? '')),
);
</script>

<template>
    <Badge v-if="props.rotulo" :variant="variante">{{ props.rotulo }}</Badge>
    <span v-else class="text-muted-foreground">—</span>
</template>

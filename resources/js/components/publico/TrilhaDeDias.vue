<script lang="ts">
/**
 * A trilha de dias — o elemento-assinatura da identidade publica.
 *
 * E a peca que faz a programacao do evento parecer um caminho, e nao uma
 * tabela: a data de cada dia fica numa coluna a esquerda, e uma linha
 * pontilhada com um ponto em cada dia liga um ao outro, de cima a baixo. O dia
 * que esta acontecendo — ou o proximo — recebe o ponto cheio, na cor da marca.
 *
 * **A linha e os pontos sao decoracao, e saem do leitor de tela.** Quem
 * navega por audio ja recebe a ordem pela propria lista (`<ol>`): repetir a
 * mesma informacao em desenho so faria a leitura mais longa sem dizer nada
 * novo. As datas, essas ficam — elas sao conteudo.
 *
 * Este componente nasce nesta etapa junto com o resto da identidade, mas quem
 * o coloca em tela e o plano seguinte, o da agenda e da pagina do evento. Ele
 * esta aqui, e nao la, porque e peca de base: a mesma trilha vai servir a
 * agenda e a programacao do evento, e nenhuma das duas deveria inventar a sua.
 */

/** Um dia da trilha, do ponto de vista de quem desenha — nao do dominio. */
export interface DiaDaTrilha {
    /** Identificador estavel do dia, usado so para a lista se reordenar bem. */
    chave: string | number;
    /** O numero do dia, ja escrito: "07", "12". */
    dia: string;
    /** O mes abreviado, ja escrito: "abr", "mai". */
    mes: string;
    /**
     * O dia em foco — o que esta acontecendo, ou o proximo a acontecer.
     * Recebe o ponto cheio. No maximo um da lista deveria te-lo.
     */
    destacado?: boolean;
    /** Dia que ja passou: a linha inteira desbota, como no prototipo. */
    passado?: boolean;
}
</script>

<script setup lang="ts">
withDefaults(
    defineProps<{
        dias?: DiaDaTrilha[];
        /** Rotulo da lista para quem navega por audio. */
        rotulo?: string;
    }>(),
    {
        dias: () => [],
        rotulo: 'Dias do evento',
    },
);
</script>

<template>
    <ol :aria-label="rotulo" class="relative">
        <li
            v-for="dia in dias"
            :key="dia.chave"
            class="group border-border relative grid grid-cols-[4.5rem_1fr] py-6 sm:grid-cols-[5.75rem_1fr] [&+li]:border-t"
            :class="dia.passado ? 'opacity-60' : ''"
        >
            <!-- A coluna da data. O `tabular-nums` nao e enfeite: e ele que
                 alinha os algarismos em coluna quando um dia tem um digito e o
                 seguinte tem dois — e, no tema publico, e tambem o gancho que
                 troca a familia do numero para a DM Mono. -->
            <div class="relative pr-6">
                <span class="block text-2xl leading-none font-medium tabular-nums">{{ dia.dia }}</span>
                <span class="text-muted-foreground mt-1.5 block text-xs tracking-[0.1em] uppercase">{{ dia.mes }}</span>

                <!-- A linha pontilhada que liga este dia ao proximo. Ela desce
                     alem do fim do item de proposito, para atravessar o espaco
                     entre um dia e outro; no ultimo, some. -->
                <span
                    aria-hidden="true"
                    class="absolute top-1.5 right-[0.6875rem] -bottom-8 w-px bg-[repeating-linear-gradient(to_bottom,var(--border)_0_4px,transparent_4px_9px)] group-last:hidden"
                />

                <!-- O ponto do dia. Vazado quando e um dia qualquer, cheio e na
                     cor da marca quando e o dia em foco. -->
                <span
                    aria-hidden="true"
                    class="bg-background absolute top-1.5 right-1.5 size-[0.6875rem] rounded-full border-2"
                    :class="dia.destacado ? 'border-acao bg-acao ring-sucesso-suave ring-4' : 'border-input'"
                />
            </div>

            <!-- O que acontece naquele dia. Quem usa a trilha decide o que vai
                 aqui: o titulo da etapa, a lista de atividades, o preco. -->
            <div class="min-w-0">
                <slot :dia="dia" />
            </div>
        </li>
    </ol>
</template>

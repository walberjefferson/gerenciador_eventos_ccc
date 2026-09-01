<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/vue3';
import type { Component, HTMLAttributes } from 'vue';
import { computed } from 'vue';

/**
 * O botão de ação de uma linha de listagem — um só, para o painel inteiro.
 *
 * Antes deste componente cada tabela escrevia a sua própria string de classe, e
 * o resultado era previsível: quinze cópias de
 * `rounded-md border border-border px-3 py-1`, todas iguais, todas cinzas, e
 * "Editar" indistinguível de "Excluir" até o clique acontecer. Numa linha de
 * tabela, onde as ações são pequenas e estão coladas umas nas outras, a cor é o
 * que separa o que corrige do que apaga.
 *
 * A COR DIZ A INTENÇÃO, E NUNCA SOZINHA
 * O rótulo continua escrito por extenso; a cor e o ícone só antecipam a
 * consequência. As razões de contraste do texto sobre o branco da tabela:
 * `ver` 5.86:1, `editar` 5.25:1, `excluir` 4.77:1, `neutra` 19.90:1. No estado
 * de passagem do ponteiro, quando o fundo deixa de ser branco, o texto muda
 * junto para o par medido do token suave: `ver` 7.59:1, `excluir` 7.04:1,
 * `editar` 4.77:1 sobre `secondary` e `neutra` 18.10:1 sobre `muted`.
 *
 * REGRA DE COMPOSIÇÃO
 * Uma linha tem no máximo UMA ação `ver`, UMA `editar` e UMA `excluir`. Todo o
 * resto é `neutra`. Três botões coloridos lado a lado não hierarquizam nada —
 * é por isso que "Programação", na lista de eventos, é neutro mesmo sendo
 * navegação: a ação colorida daquela linha já é "Editar".
 *
 * ALVO DE TOQUE, E POR QUE O COMPACTO É RESPONSIVO
 * `min-h-11` são os 44px que o projeto cobra, e é altura MÍNIMA e não fixa: o
 * rótulo que quebra em duas linhas no celular faz o botão crescer, em vez de o
 * texto vazar.
 *
 * O tamanho `compacto` NÃO é simplesmente um botão menor — ele é 44px no
 * celular e 36px a partir do `md`. A razão é que os dois contextos têm alvos
 * diferentes: no celular quem aciona é um dedo, e 44px continua sendo o mínimo
 * que a WCAG e o projeto cobram; no computador quem aciona é um ponteiro de
 * poucos pixels, e aí o custo se inverte — numa tabela densa, com três botões
 * por linha e vinte linhas na tela, o botão de 44px empurra a linha para baixo
 * e a lista deixa de caber.
 *
 * Os 36px não são número inventado: é a altura do `size: default` do `Button`
 * do projeto (`resources/js/components/ui/button/index.ts`, `h-9`), ou seja, o
 * botão de listagem passa a ter no computador exatamente a altura que o resto
 * do painel já tem.
 */
type Intencao = 'ver' | 'editar' | 'excluir' | 'neutra';

/** `padrao` para ação isolada; `compacto` para ação dentro de linha de tabela. */
type Tamanho = 'padrao' | 'compacto';

const props = withDefaults(
    defineProps<{
        /** O que a ação faz com a linha. Decide cor, não comportamento. */
        intencao?: Intencao;
        /** `compacto` encolhe só no computador — ver o comentário do topo. */
        tamanho?: Tamanho;
        /** Ícone lucide à esquerda do rótulo. Decorativo: o nome acessível vem do texto. */
        icone?: Component;
        /** Com endereço vira `<Link>` do Inertia; sem endereço, `<button>`. */
        href?: string;
        /** Só faz sentido no `<button>`. */
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { intencao: 'neutra', tamanho: 'padrao', icone: undefined, href: undefined, disabled: false, class: undefined },
);

/*
 * "relative" está na base, e não em uma tela só, porque o defeito que ele
 * corrige nasce em qualquer botão que carregue um texto `sr-only`.
 *
 * O texto que só o leitor de tela ouve fica posicionado de forma absoluta. Sem
 * uma âncora no próprio botão, ele se prende à moldura da página inteira,
 * escapa da caixa que rola e estica o documento para a largura da tabela — no
 * celular, a página inteira encolhe por causa disso.
 */
const BASE =
    'relative inline-flex items-center justify-center gap-2 rounded-md border transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-60 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0';

/*
 * Altura e respiro moram AQUI, e não na base, de propósito: com `min-h-11` na
 * base e `md:min-h-9` no tamanho, as duas ficariam disputando, e quem decidiria
 * o vencedor seria a ordem no arquivo de estilo — não a intenção de quem
 * escreveu. Uma fonte só de altura, e o `tailwind-merge` não tem o que resolver.
 */
const POR_TAMANHO: Record<Tamanho, string> = {
    padrao: 'min-h-11 px-3 py-1 text-sm',
    compacto: 'min-h-11 px-2.5 py-1 text-sm md:min-h-9 md:[&_svg]:size-3.5',
};

const POR_INTENCAO: Record<Intencao, string> = {
    ver: 'border-informacao text-informacao-texto hover:bg-informacao-suave hover:text-informacao-suave-foreground',
    editar: 'border-acao text-acao-texto hover:bg-secondary',
    excluir: 'border-destructive text-destructive hover:bg-erro-suave hover:text-erro-suave-foreground',
    neutra: 'border-border text-foreground hover:bg-muted',
};

const classes = computed(() => cn(BASE, POR_TAMANHO[props.tamanho], POR_INTENCAO[props.intencao], props.class));
</script>

<template>
    <Link v-if="props.href" :href="props.href" :class="classes">
        <!-- O ícone é enfeite do rótulo: quem ouve a tela já ouviu a palavra. -->
        <component :is="props.icone" v-if="props.icone" aria-hidden="true" />
        <slot />
    </Link>

    <button v-else type="button" :disabled="props.disabled" :class="classes">
        <component :is="props.icone" v-if="props.icone" aria-hidden="true" />
        <slot />
    </button>
</template>

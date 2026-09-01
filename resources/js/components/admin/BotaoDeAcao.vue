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
 * A ESCALA DE TAMANHO
 * Cinco degraus com os nomes que o Tailwind usa, para não haver um vocabulário
 * de tamanho só deste componente: `xs` 32px, `sm` 36px, `md` 44px, `lg` 48px,
 * `xl` 56px. Nenhum é número inventado — `sm` é o `h-9` do `size: default` do
 * `Button` do projeto, e `lg` é o `min-h-12` que o tema público usa.
 *
 * Toda altura é MÍNIMA e nunca fixa: o rótulo que quebra em duas linhas no
 * celular faz o botão crescer, em vez de o texto vazar.
 *
 * O PADRÃO É `md`, E O MOTIVO IMPORTA
 * 44px são o alvo de toque que o projeto cobra, então quem NÃO escolhe tamanho
 * recebe o botão seguro. Descer disso é decisão explícita de quem escreve a
 * tela, tomada uma vez, no lugar onde se vê o contexto.
 *
 * As listagens do painel usam `xs`, e SÓ elas. Ali quem aciona é um ponteiro de
 * poucos pixels num computador, e o custo se inverte: numa tabela densa, com
 * três botões por linha e vinte linhas na tela, o botão de 44px empurra a linha
 * para baixo e a lista deixa de caber.
 *
 * É um desvio consciente da regra dos 44px, e o limite dele é estreito: vale
 * para o botão que mora dentro de uma célula de tabela do painel, e não para
 * ação isolada em cabeçalho de tela, formulário ou ficha — essas continuam no
 * `md`. E não vale de forma alguma para as telas públicas de inscrição, que são
 * mobile-first de verdade e usam o `Button`, não este componente.
 *
 * Os 32px do `xs` seguem acima do mínimo de 24px que a WCAG 2.2 AA (2.5.8)
 * exige, mas é o degrau mais baixo que este componente deve ver numa tabela:
 * abaixo disso o alvo deixa de ser confortável até com mouse.
 */
type Intencao = 'ver' | 'editar' | 'excluir' | 'neutra';

/** A escala do Tailwind. `md` são os 44px do alvo de toque — ver o topo. */
type Tamanho = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

const props = withDefaults(
    defineProps<{
        /** O que a ação faz com a linha. Decide cor, não comportamento. */
        intencao?: Intencao;
        /** Escala do Tailwind: `xs` 32px … `xl` 56px. Padrão `md` (44px). */
        tamanho?: Tamanho;
        /** Ícone lucide à esquerda do rótulo. Decorativo: o nome acessível vem do texto. */
        icone?: Component;
        /** Com endereço vira `<Link>` do Inertia; sem endereço, `<button>`. */
        href?: string;
        /** Só faz sentido no `<button>`. */
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { intencao: 'neutra', tamanho: 'md', icone: undefined, href: undefined, disabled: false, class: undefined },
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
    'relative inline-flex items-center justify-center gap-2 rounded-md border transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-60 [&_svg]:pointer-events-none [&_svg]:shrink-0';

/*
 * Altura, respiro, corpo do texto e tamanho do ícone moram AQUI, e nenhum deles
 * na base, de propósito: uma altura na base e outra no tamanho ficariam
 * disputando, e quem decidiria o vencedor seria a ordem no arquivo de estilo —
 * não a intenção de quem escreveu. Uma fonte só por propriedade, e o
 * `tailwind-merge` não tem o que resolver.
 *
 * O ícone acompanha o degrau porque um `size-4` dentro de um botão de 32px
 * ocupa metade da altura e o rótulo perde o lugar.
 */
const POR_TAMANHO: Record<Tamanho, string> = {
    xs: 'min-h-8 px-2 py-0.5 text-xs [&_svg]:size-3.5',
    sm: 'min-h-9 px-2.5 py-1 text-sm [&_svg]:size-3.5',
    md: 'min-h-11 px-3 py-1 text-sm [&_svg]:size-4',
    lg: 'min-h-12 px-4 py-2 text-base [&_svg]:size-5',
    xl: 'min-h-14 px-5 py-2 text-base [&_svg]:size-5',
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

import { cva, type VariantProps } from 'class-variance-authority';

export { default as Badge } from './Badge.vue';

/**
 * A etiqueta, nos dois temas.
 *
 * O prototipo da identidade publica tem quatro etiquetas, e elas nao sao blocos
 * de cor cheia: sao pilulas de fundo lavado com o texto escuro por cima. O
 * mapeamento nas variantes que ja existiam e este, e ele vale so dentro de
 * `<html data-tema="publico">` (prefixo `publico:`):
 *
 * | Prototipo | Variante daqui | O que diz          |
 * |-----------|----------------|--------------------|
 * | `open`    | `sucesso`      | "inscricoes abertas" |
 * | `soon`    | `secondary`    | "em breve"           |
 * | `warn`    | `atencao`      | "o prazo esta correndo" |
 * | `done`    | `outline`      | "ja aconteceu"       |
 *
 * Nenhuma variante nova nasceu, e nenhuma tela precisou trocar de `variant`:
 * o que muda e como cada uma se pinta dentro do tema publico. No painel elas
 * continuam exatamente como estavam.
 *
 * O texto de `outline` no publico e o `muted-foreground` (#5B6C64) em vez do
 * cinza do prototipo (#8A968E): aquele rende 2.53:1 sobre o fundo da etiqueta e
 * reprova; este rende 4.58:1 (DA-42).
 *
 * CHEIA OU SUAVE — QUANDO USAR CADA UMA
 *
 * As sete variantes de cima sao de COR CHEIA: bloco de cor com texto claro por
 * cima. Elas servem ao destaque ISOLADO — uma etiqueta so, no topo de uma ficha
 * ou ao lado de um titulo, onde ela precisa ser a primeira coisa que se ve.
 *
 * As cinco de baixo (`sucessoSuave`, `informacaoSuave`, `atencaoSuave`,
 * `erroSuave`, `neutra`) sao de SUPERFICIE SUAVE: fundo lavado com texto
 * escuro. Elas servem a LISTA LONGA — a coluna "Situacao" de uma tabela com
 * trinta linhas. Trinta blocos de cor cheia empilhados nao hierarquizam nada:
 * viram um vitral, e a linha que realmente pede acao some no meio das outras.
 * O tom lavado deixa a tabela legivel e continua dizendo o que cada linha e.
 *
 * Nenhuma delas leva prefixo `publico:`: os tokens `-suave` existem nos dois
 * temas, com a razao de contraste calculada em cada um, entao a mesma variante
 * se veste sozinha do lado certo.
 *
 * Quem decide QUAL variante cada situacao recebe nao e a tela: e o
 * `resources/js/lib/situacoes.ts`, fonte unica do mapeamento.
 */
export const badgeVariants = cva(
    'inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 publico:rounded-full publico:px-3 publico:py-1',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground shadow-sm',
                secondary: 'border-transparent bg-secondary text-secondary-foreground publico:text-muted-foreground',
                destructive: 'border-transparent bg-destructive text-destructive-foreground shadow-sm',
                outline: 'text-foreground publico:border-transparent publico:bg-secondary publico:text-muted-foreground',
                sucesso: 'border-transparent bg-sucesso text-sucesso-foreground publico:bg-sucesso-suave publico:text-sucesso-suave-foreground',
                informacao: 'border-transparent bg-informacao text-informacao-foreground',
                atencao: 'border-transparent bg-atencao text-atencao-foreground publico:bg-atencao-suave publico:text-atencao-suave-foreground',

                // As de superficie suave. A razao de contraste de cada par
                // esta calculada no `app.css`, na linha de cima do token:
                // 7.41:1, 7.59:1, 6.36:1 e 7.04:1 no painel.
                sucessoSuave: 'border-transparent bg-sucesso-suave text-sucesso-suave-foreground',
                informacaoSuave: 'border-transparent bg-informacao-suave text-informacao-suave-foreground',
                atencaoSuave: 'border-transparent bg-atencao-suave text-atencao-suave-foreground',
                erroSuave: 'border-transparent bg-erro-suave text-erro-suave-foreground',

                // O texto e `foreground`, e NAO `muted-foreground`: o cinza
                // sobre o proprio `--muted` rende 4.39:1 e reprova — o
                // comentario do `--muted-foreground` no `app.css` registra a
                // medicao. Com `foreground` sao 18.10:1.
                neutra: 'border-transparent bg-muted text-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type BadgeVariants = VariantProps<typeof badgeVariants>;

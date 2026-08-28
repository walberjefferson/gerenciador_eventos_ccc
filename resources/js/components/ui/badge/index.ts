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
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type BadgeVariants = VariantProps<typeof badgeVariants>;

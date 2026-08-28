import { cva, type VariantProps } from 'class-variance-authority';

export { default as Button } from './Button.vue';

/**
 * O botao, nos dois temas.
 *
 * As classes com prefixo `publico:` so valem dentro de
 * `<html data-tema="publico">` — a variante esta declarada em
 * `resources/css/app.css`. E ela que permite o botao pilula de 48px do lado do
 * visitante SEM tocar no botao do painel, que continua com raio medio e 36px de
 * altura em quarenta telas que esta etapa jurou nao mexer.
 *
 * Um componente, dois temas. Duplicar o botao para ter "a versao verde" seria a
 * saida errada: dobraria a manutencao e faria as duas copias divergirem no
 * primeiro ajuste de acessibilidade.
 *
 * Por que `min-h-12` e nao `h-12`: altura minima deixa o botao crescer quando o
 * rotulo quebra em duas linhas no celular, em vez de o texto vazar para fora.
 * E 48px, e nao 44px, porque e o numero do prototipo — e ele ja e maior que o
 * minimo de alvo de toque que o projeto cobra.
 */
export const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 publico:rounded-full publico:font-semibold',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
                destructive: 'bg-destructive text-destructive-foreground shadow-xs hover:bg-destructive/90',
                outline: 'border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
                secondary: 'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                // O link nao vira pilula: ele nao tem fundo nem borda, e uma
                // altura de 48px so o afastaria do texto ao redor.
                link: 'text-primary underline-offset-4 hover:underline publico:rounded-sm publico:font-medium',
            },
            size: {
                default: 'h-9 px-4 py-2 publico:min-h-12 publico:px-5',
                sm: 'h-8 rounded-md px-3 text-xs publico:min-h-11 publico:rounded-full',
                lg: 'h-10 rounded-md px-8 publico:min-h-12 publico:rounded-full',
                // O botao so de icone continua redondo nos dois temas, mas no
                // publico ele cresce ate o alvo de dedo do prototipo.
                icon: 'h-9 w-9 publico:size-12',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export type ButtonVariants = VariantProps<typeof buttonVariants>;

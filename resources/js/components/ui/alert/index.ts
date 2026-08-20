import { cva, type VariantProps } from 'class-variance-authority';

export { default as Alert } from './Alert.vue';
export { default as AlertDescription } from './AlertDescription.vue';
export { default as AlertTitle } from './AlertTitle.vue';

export const alertVariants = cva(
    'relative w-full rounded-lg border p-4 [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg~*]:pl-7 [&>svg]:size-4',
    {
        variants: {
            variant: {
                default: 'bg-card text-card-foreground',
                destructive: 'border-destructive/50 text-destructive [&>svg]:text-destructive',
                informacao: 'border-informacao/40 bg-informacao/10 text-foreground [&>svg]:text-informacao-texto',
                sucesso: 'border-sucesso/40 bg-sucesso/10 text-foreground [&>svg]:text-sucesso-texto',
                atencao: 'border-atencao/60 bg-atencao/15 text-foreground [&>svg]:text-atencao-texto',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type AlertVariants = VariantProps<typeof alertVariants>;

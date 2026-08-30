import { ref } from 'vue';

/**
 * Avisos rapidos (toasts) para confirmar acoes curtas, como copiar o codigo
 * Pix. Nao substitui mensagem de erro dentro do formulario: erro de campo
 * continua ao lado do campo.
 *
 * Construido sobre as primitivas de Toast do reka-ui, sem dependencia nova.
 */
export type TomDoAviso = 'padrao' | 'sucesso' | 'erro';

export interface AvisoRapidoOpcoes {
    titulo: string;
    descricao?: string;
    tom?: TomDoAviso;
    /** Tempo em milissegundos ate sumir sozinho. */
    duracao?: number;
}

export interface AvisoRapido extends AvisoRapidoOpcoes {
    id: string;
}

const avisos = ref<AvisoRapido[]>([]);

let contador = 0;

function proximoId(): string {
    contador += 1;

    return `aviso-${contador}`;
}

export function dispensarAviso(id: string): void {
    avisos.value = avisos.value.filter((aviso) => aviso.id !== id);
}

export function toast(opcoes: AvisoRapidoOpcoes): string {
    const id = proximoId();

    avisos.value = [...avisos.value, { id, ...opcoes }];

    return id;
}

export function useToast() {
    return { avisos, toast, dispensarAviso };
}

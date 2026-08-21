/**
 * Tipos da tela de auditoria.
 *
 * Espelham exatamente o que o servidor manda. O registro de auditoria nunca
 * carrega CPF, senha, token nem o conteúdo de um pagamento — o que era
 * sensível já foi trocado por um aviso de omissão antes de virar linha no
 * banco, então não há nada aqui para esconder na tela.
 */

/** Uma linha do rastro: quem fez o quê, quando e de onde. */
export interface RegistroDeAuditoria {
    id: number;
    /** Data e hora já formatadas como "dd/mm/aaaa hh:mm:ss". */
    quando: string | null;
    /** O nome de quem fez, ou "Sistema" quando não houve gente por trás. */
    responsavel: string;
    acao: string;
    acao_rotulo: string;
    entidade: string;
    entidade_id: number | null;
    motivo: string | null;
    ip: string | null;
    /** O antes/depois do que mudou, sem nenhum dado sensível. */
    dados: Record<string, unknown> | null;
}

/** Uma página do rastro. */
export interface PaginaDeAuditoria {
    dados: RegistroDeAuditoria[];
    pagina_atual: number;
    ultima_pagina: number;
    total: number;
    por_pagina: number;
    links: {
        anterior: string | null;
        proxima: string | null;
    };
}

/** Os filtros que estão valendo, do jeito que vieram do endereço. */
export interface FiltrosDeAuditoria {
    de: string | null;
    ate: string | null;
    usuario_id: string | null;
    acao: string | null;
}

/** O que os seletores de filtro oferecem. */
export interface OpcoesDeAuditoria {
    acoes: { valor: string; rotulo: string }[];
    /** Só quem já apareceu no rastro pelo menos uma vez. */
    usuarios: { id: number; nome: string }[];
}

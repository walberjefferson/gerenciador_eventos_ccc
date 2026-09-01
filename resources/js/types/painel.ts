/**
 * O formato dos números que o painel administrativo recebe do servidor.
 *
 * Dinheiro chega sempre em centavos e inteiro; a vírgula e o "R$" só aparecem
 * na hora de escrever na tela.
 */

export interface ResumoDoEvento {
    id: number;
    nome: string;
    slug: string;
    situacao: string;
    situacao_rotulo: string;
    capacidade: number | null;
    vagas_reservadas: number;
    vagas_confirmadas: number;
    vagas_restantes: number | null;
    valor_centavos: number;
}

export interface InscricoesPorSituacao {
    situacao: string;
    rotulo: string;
    total: number;
}

export interface VagaDaAtividade {
    atividade_id: number;
    atividade: string;
    grupo: string;
    dia: string;
    /** Nulo quer dizer "sem limite de vagas", que é diferente de zero. */
    capacidade: number | null;
    reservadas: number;
    confirmadas: number;
    ocupadas: number;
    restantes: number | null;
}

export interface DinheiroDoEvento {
    recebido_centavos: number;
    pendente_centavos: number;
    estornado_centavos: number;
    pagamentos_pagos: number;
    pagamentos_pendentes: number;
}

/**
 * Quem entrou pelo portão e quem ainda falta.
 *
 * `confirmadas` vem junto de propósito: sem ela, "12 presentes" não diz se o
 * evento está cheio ou vazio. Os três números sempre fecham —
 * `presentes + faltantes === confirmadas`.
 */
export interface PresencaDoEvento {
    presentes: number;
    faltantes: number;
    confirmadas: number;
}

export interface NumerosDoEvento {
    inscricoes: {
        total: number;
        por_situacao: InscricoesPorSituacao[];
    };
    vagas: VagaDaAtividade[];
    dinheiro: DinheiroDoEvento;
    presenca: PresencaDoEvento;
}

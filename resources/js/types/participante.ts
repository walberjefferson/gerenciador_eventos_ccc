/**
 * Tipos dos props da area do participante.
 *
 * Espelham exatamente o que AcompanhamentoController@show envia. Nada
 * sensivel passa por aqui: nem documento, nem identificador da cobranca no
 * provedor, nem contador interno de vagas.
 */

/** Como cada marco da linha do tempo se apresenta. */
export type EstadoDoMarco = 'concluido' | 'atual' | 'futuro' | 'encerrado';

export interface MarcoDaInscricao {
    chave: string;
    titulo: string;
    descricao: string;
    /** ISO-8601, ou null quando o passo ainda nao tem data. */
    momento: string | null;
    estado: EstadoDoMarco;
}

export interface AtividadeEscolhida {
    nome: string;
    dia: string | null;
    grupo: string | null;
    comeca_em: string;
    termina_em: string;
    horario_rotulo: string;
}

export interface EventoDoAcompanhamento {
    nome: string | null;
    slug: string | null;
    data_inicio: string | null;
    data_fim: string | null;
    contato_email: string | null;
    contato_telefone: string | null;
}

export interface GrupoDoParticipante {
    nome: string;
    cidade: string | null;
    uf: string | null;
}

export interface InscricaoAcompanhada {
    codigo_publico: string;
    nome_completo: string;
    situacao: string;
    situacao_rotulo: string;
    valor_centavos: number;
    moeda: string;
    criada_em: string | null;
    prazo_pagamento: string | null;
    confirmada_em: string | null;
    expirada_em: string | null;
    cancelada_em: string | null;
    motivo_cancelamento: string | null;
    evento: EventoDoAcompanhamento;
    grupo_participante: GrupoDoParticipante | null;
    atividades: AtividadeEscolhida[];
}

/** Uma cobranca do historico. Sem gateway, sem id externo, sem copia e cola. */
export interface PagamentoDoHistorico {
    codigo_publico: string;
    situacao: string;
    situacao_rotulo: string;
    metodo: string;
    metodo_rotulo: string;
    valor_centavos: number;
    criado_em: string | null;
    expira_em: string | null;
    pago_em: string | null;
    cancelado_em: string | null;
    estornado_em: string | null;
    valor_estornado_centavos: number | null;
}

export interface PropsDoAcompanhamento {
    inscricao: InscricaoAcompanhada;
    linha_do_tempo: MarcoDaInscricao[];
    pagamentos: PagamentoDoHistorico[];
    /** Verdadeiro so enquanto a inscricao aguarda pagamento e o prazo nao venceu. */
    pode_pagar: boolean;
    /** URL assinada da tela de cobranca; null quando nao ha mais o que pagar. */
    url_pagamento: string | null;
    /** URL assinada que pede a segunda via do Pix; null quando nao ha o que pagar. */
    url_segunda_via: string | null;
    /** Explicacao deixada por quem redirecionou para esta tela. */
    aviso: string | null;
}

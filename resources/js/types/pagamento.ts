/**
 * Tipos dos props da tela da cobranca Pix.
 *
 * Espelham exatamente o que PagamentoController@show envia. Nenhum dado
 * sensivel passa por aqui: nada de documento, nada de contador interno.
 */

/** As tres telas possiveis. Quem decide qual e o servidor, nunca o navegador. */
export type EstadoDaCobranca = 'aguardando' | 'confirmada' | 'expirada';

export interface CobrancaPix {
    situacao: string;
    situacao_rotulo: string;
    /** So vem preenchido enquanto ainda da para pagar. */
    pix_copia_e_cola: string | null;
    /** SVG pronto para embutir no HTML; null quando nao ha mais o que pagar. */
    qr_code_svg: string | null;
    expira_em: string | null;
    pago_em: string | null;
}

/** As tres situacoes de um comprovante. Nenhuma delas e situacao de inscricao. */
export type SituacaoDoComprovante = 'enviado' | 'aceito' | 'recusado';

/**
 * O comprovante que a pessoa enviou, do jeito que a tela dela le.
 *
 * Ele conta o que aconteceu com o ARQUIVO — nunca com a inscricao. Enviar
 * comprovante nao confirma nada (RN-S7): a inscricao segue aguardando pagamento
 * ate alguem conferir, e e `PropsDaCobranca.situacao` que diz isso.
 */
export interface ComprovanteEnviado {
    id: number;
    nome_original: string;
    mime: string;
    tamanho_bytes: number;
    situacao: SituacaoDoComprovante;
    situacao_rotulo: string;
    enviado_em: string | null;
    conferido_em: string | null;
    /** Preenchido so quando a situacao e "recusado" — o banco cobra isso. */
    motivo_recusa: string | null;
}

/**
 * O setor de quem se inscreveu, quando o evento recebe pela chave Pix dele.
 *
 * A chave aparece aqui porque ela existe para ser mostrada (RN-S3). O que a
 * protege e esta tela ser assinada e pertencer a UMA inscricao — e nao a
 * criptografia.
 */
export interface SetorDaCobranca {
    nome: string;
    chave_pix: string;
    titular: string | null;
    responsavel: string | null;
}

export interface LimitesDoComprovante {
    tamanho_maximo_mb: number;
    tipos: string[];
}

export interface EventoDaCobranca {
    nome: string | null;
    slug: string | null;
}

export interface PropsDaCobranca {
    codigo_publico: string;
    nome_completo: string;
    evento: EventoDaCobranca;
    estado: EstadoDaCobranca;
    situacao: string;
    situacao_rotulo: string;
    valor_centavos: number;
    moeda: string;
    prazo_pagamento: string | null;
    confirmada_em: string | null;
    pagamento: CobrancaPix | null;
    /** Este evento recebe pela chave Pix do responsavel do setor? */
    recebe_pelo_setor: boolean;
    /** Null quando o evento recebe pelo provedor, ou quando o setor perdeu a chave. */
    setor: SetorDaCobranca | null;
    /** O comprovante mais recente, em qualquer situacao. */
    comprovante: ComprovanteEnviado | null;
    /** URL assinada para mandar (ou remandar) o comprovante. */
    url_comprovante: string | null;
    limites_do_comprovante: LimitesDoComprovante | null;
    /** Mensagem de sucesso vinda do envio anterior. */
    sucesso?: string | null;
    /** URL assinada que responde a situacao atual, para a tela nao pedir F5. */
    url_situacao: string;
    /** URL assinada da pagina de acompanhamento da inscricao. */
    url_acompanhamento: string;
}

/** A resposta curta de PagamentoController@situacao. */
export interface SituacaoDaCobranca {
    situacao: string;
    situacao_rotulo: string;
    estado: EstadoDaCobranca;
    pago_em: string | null;
}

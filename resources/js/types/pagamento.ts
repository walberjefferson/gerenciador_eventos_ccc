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
    /** URL assinada que responde a situacao atual, para a tela nao pedir F5. */
    url_situacao: string;
}

/** A resposta curta de PagamentoController@situacao. */
export interface SituacaoDaCobranca {
    situacao: string;
    situacao_rotulo: string;
    estado: EstadoDaCobranca;
    pago_em: string | null;
}

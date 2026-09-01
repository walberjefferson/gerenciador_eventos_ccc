/**
 * Tipos do ingresso.
 *
 * Espelham exatamente o que o servidor envia. O codigo CRU do ingresso nao
 * aparece aqui, e a ausencia e proposital: a tela mostra o codigo formatado e
 * o desenho do QR, que ja chega pronto do servidor. O navegador nunca precisa
 * do valor de verdade para nada.
 */

/** Como o ingresso se apresenta — o mesmo vocabulario do enum SituacaoIngresso. */
export type SituacaoDoIngresso = 'emitido' | 'usado' | 'invalido';

export interface IngressoDoParticipante {
    /** "ABCD-EFGH-JKMN": em grupos de quatro, para quem le e digita. */
    codigo_formatado: string;
    /** ISO-8601. */
    emitido_em: string | null;
    situacao: SituacaoDoIngresso;
    situacao_rotulo: string;
}

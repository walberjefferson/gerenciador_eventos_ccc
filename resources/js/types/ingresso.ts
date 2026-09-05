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

/**
 * O evento em que a portaria está trabalhando.
 *
 * Só o que quem está no portão precisa ler. Capacidade, dinheiro e contagem de
 * inscritos não vêm: a portaria não alcança nada disso, e mandar para a tela um
 * dado que ela não mostra é mandá-lo para o navegador de quem não deveria vê-lo.
 */
export interface EventoDaPortaria {
    id: number;
    nome: string;
    situacao: string;
    situacao_rotulo: string;
    /** "12/03/2026 a 14/03/2026", ou um dia só, ou "sem data". */
    periodo: string;
}

/** Quem acabou de entrar — o que a pessoa do portão lê em voz alta. */
export interface ParticipanteQueEntrou {
    nome: string;
    grupo: string | null;
    atividades: string[];
}

/** Passou pelas quatro conferências: pode entrar. */
export interface LeituraAceita {
    aceito: true;
    ingresso_id: number;
    codigo_formatado: string;
    /** "dd/mm/aaaa hh:mm" — a hora em que a entrada foi gravada. */
    usado_em: string;
    /** O nome de quem estava no portão. */
    usado_por: string;
    participante: ParticipanteQueEntrou;
}

/**
 * O portão disse não.
 *
 * `motivo` é o valor curto e estável (o mesmo das constantes de
 * `IngressoRecusado`); `mensagem` é a frase pronta para ler em voz alta. A tela
 * escolhe o desenho pelo motivo e mostra sempre a mensagem — quem está na fila
 * precisa da frase, não do código.
 */
export interface LeituraRecusada {
    aceito: false;
    motivo: string;
    mensagem: string;
    dados: {
        usado_em?: string | null;
        usado_por?: string | null;
        evento_do_ingresso?: string;
        situacao_da_inscricao?: string;
        situacao_rotulo?: string;
        cancelada_em?: string | null;
        ingresso_id?: number;
    };
}

export type ResultadoDaLeitura = LeituraAceita | LeituraRecusada;

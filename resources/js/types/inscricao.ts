/**
 * Tipos dos props e do estado do formulario de inscricao.
 *
 * Espelham os Resources de app/Http/Resources e o contrato de
 * StoreInscricaoRequest. Nenhuma regra vive aqui: apenas o formato dos dados.
 */

/**
 * Um setor, como o formulário público o recebe. O tipo e o campo guardam o
 * nome antigo, como a tabela: o renome para "setor" vale para o que a pessoa lê.
 */
export interface CidadePublica {
    id: number;
    nome: string;
    uf: string;
    /** "Setor Batalha" — só o nome, sem a UF. */
    rotulo: string;
}

export interface GrupoParticipantePublico {
    id: number;
    cidade_id: number;
    nome: string;
}

/**
 * Par de atividades que nao podem ser escolhidas juntas, mesmo sem choque de
 * horario. Vem normalizado do banco (o menor id primeiro), por isso o
 * composable precisa conferir os dois sentidos.
 */
export interface ConflitoDeAtividades {
    atividade_a_id: number;
    atividade_b_id: number;
    motivo: string | null;
}

/** Os quatro passos do formulario, na ordem em que o participante os percorre. */
export type PassoDaInscricao = 'dados' | 'participacao' | 'revisao' | 'pagamento';

/**
 * O que o participante preenche. Os nomes sao exatamente os campos que
 * POST /inscricoes espera.
 */
export interface FormularioInscricao {
    evento_id: number;
    cidade_id: number | null;
    grupo_participante_id: number | null;
    nome_completo: string;
    email: string;
    telefone: string;
    documento: string;
    data_nascimento: string;
    atividades: number[];
    aceite_termos: boolean;
    chave_idempotencia: string;
}

/** Por que uma atividade nao pode ser escolhida agora — ou que esta livre. */
export interface SituacaoDaAtividade {
    selecionada: boolean;
    selecionavel: boolean;
    /** Frase curta para o participante. `null` quando esta tudo certo. */
    motivo: string | null;
}

/**
 * Tipos das telas administrativas.
 *
 * Espelham exatamente o que o servidor manda. Uma regra atravessa o arquivo
 * inteiro: **CPF nunca aparece aqui**. O documento é guardado cifrado e a
 * impressão digital serve só para comparar — nenhuma tela precisa dele, então
 * nenhum tipo o declara.
 */

/** Uma cidade do catálogo global. */
export interface CidadeDoCatalogo {
    id: number;
    nome: string;
    uf: string;
    ativo: boolean;
    /** Quantos grupos de participantes dependem desta cidade. */
    grupos: number;
}

/** Um grupo de participantes do catálogo global. */
export interface GrupoDoCatalogo {
    id: number;
    nome: string;
    ativo: boolean;
    cidade_id: number;
    /** Nome da cidade já formatado como "Cidade/UF". */
    cidade: string;
    /** Quantas inscrições apontam para este grupo. */
    inscricoes: number;
}

/** Opção de cidade nos seletores. */
export interface OpcaoDeCidade {
    id: number;
    nome: string;
    ativo: boolean;
}

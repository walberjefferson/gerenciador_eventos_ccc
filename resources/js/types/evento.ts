/**
 * Tipos dos props que o backend envia para as telas publicas.
 *
 * Espelham exatamente os Resources de app/Http/Resources. Se um campo mudar
 * la, ele muda aqui — e o vue-tsc avisa quem esqueceu.
 */

export interface AtividadePublica {
    id: number;
    nome: string;
    descricao: string | null;
    comeca_em: string;
    termina_em: string;
    horario_rotulo: string;
    capacidade: number | null;
    /** null quando a atividade nao tem limite de vagas. */
    vagas_disponiveis: number | null;
    esgotado: boolean;
    idade_minima: number | null;
    idade_maxima: number | null;
}

export interface GrupoAtividadePublico {
    id: number;
    nome: string;
    descricao: string | null;
    obrigatorio: boolean;
    min_selecoes: number;
    max_selecoes: number | null;
    /** A regra de escolha ja escrita em portugues pelo servidor. */
    regra_rotulo: string;
    atividades: AtividadePublica[];
}

export interface DiaEventoPublico {
    id: number;
    nome: string;
    descricao: string | null;
    data: string;
    data_rotulo: string;
    /** "Sábado · 17/10", ja escrito pelo servidor. */
    quando: string;
    posicao: number;
    grupos: GrupoAtividadePublico[];
}

export interface EventoPublico {
    codigo_publico: string;
    nome: string;
    slug: string;
    descricao: string | null;
    banner_url: string | null;
    data_inicio: string;
    data_fim: string;
    periodo_rotulo: string;
    /** O nome curto do lugar, ou null enquanto ninguem o cadastrou. */
    local: string | null;
    /** Como chegar: distancia, referencia, estacionamento. */
    local_detalhe: string | null;
    /** O que a inscricao inclui. Lista vazia quando ninguem preencheu. */
    itens_incluidos: string[];
    /** As duvidas que a organizacao responde toda semana no WhatsApp. */
    perguntas_frequentes: Array<{ pergunta: string; resposta: string }>;
    inscricoes_abrem_em: string;
    inscricoes_fecham_em: string;
    /** "Encerram em 12 dias", ja escrito pelo servidor. null quando fechadas. */
    prazo_rotulo: string | null;
    valor_centavos: number;
    moeda: string;
    capacidade: number | null;
    /** null quando o evento nao tem limite de vagas. */
    vagas_disponiveis: number | null;
    esgotado: boolean;
    situacao: string;
    situacao_rotulo: string;
    inscricoes_abertas: boolean;
    /** Frase pronta explicando por que nao da para se inscrever agora. */
    motivo_inscricoes_fechadas: string | null;
    regulamento: string | null;
    versao_termos: string | null;
    contato_email: string | null;
    contato_telefone: string | null;
    dias: DiaEventoPublico[];
}

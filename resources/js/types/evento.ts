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
    /** Nulo quando a atividade não tem hora marcada: ela ocupa o dia inteiro. */
    comeca_em: string | null;
    termina_em: string | null;
    /** "09:00 às 11:00", ou nulo quando não há horário — a tela não escreve nada. */
    horario_rotulo: string | null;
    /** O dia em que a atividade acontece, em AAAA-MM-DD. Sempre existe. */
    data: string;
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

/**
 * Um lote de inscrição, como as telas públicas o recebem.
 *
 * A situação vem DECIDIDA pelo servidor, e a tela não a recalcula: dizer
 * "encerrado" a partir de uma data no navegador seria um segundo lugar onde a
 * regra mora — e o relógio do aparelho de quem lê não é o relógio que vende a
 * vaga.
 */
export interface LotePublico {
    id: number;
    nome: string;
    posicao: number;
    valor_centavos: number;
    /** Até quando o lote vale, em ISO. Nulo quando ele só encerra por vagas. */
    disponivel_ate: string | null;
    /** Quantas vagas o lote tem. Nulo quando ele só encerra por data. */
    quantidade: number | null;
    /** Quantas ainda cabem. Nulo quando não há limite de vagas. */
    vagas_restantes: number | null;
    situacao: 'encerrado' | 'vigente' | 'futuro';
    /** "Lote atual", "Em breve", "Encerrado" — a situação escrita. */
    situacao_rotulo: string;
    /** "Até 10/10/2026 · restam 12 vagas", já montado pelo servidor. */
    limite_rotulo: string;
    /** Só o vigente. O servidor decide de novo no envio (RN-L5). */
    selecionavel: boolean;
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
    /** "17 e 18 de outubro" — a data como alguem a fala. */
    quando_rotulo: string;
    /** "Sábado e domingo, 2026". */
    quando_nota: string;
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
    /**
     * O valor que vale AGORA: o do lote vigente quando há lotes, o do próprio
     * evento quando não há. A tela nunca escolhe entre os dois — o servidor já
     * escolheu.
     */
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
    /** Todos os lotes, na ordem — inclusive os encerrados. Vazio = sem lotes. */
    lotes: LotePublico[];
    /** O lote que vale agora. Nulo quando não há lotes ou todos se esgotaram. */
    lote_vigente_id: number | null;
}

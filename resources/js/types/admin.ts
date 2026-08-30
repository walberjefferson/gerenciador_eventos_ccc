/**
 * Tipos das telas administrativas.
 *
 * Espelham exatamente o que o servidor manda. Uma regra atravessa o arquivo
 * inteiro: **CPF nunca aparece aqui**. O documento é guardado cifrado e a
 * impressão digital serve só para comparar — nenhuma tela precisa dele, então
 * nenhum tipo o declara.
 */

/** Um setor do catálogo global. O tipo guarda o nome antigo, como a tabela. */
export interface CidadeDoCatalogo {
    id: number;
    nome: string;
    uf: string;
    ativo: boolean;
    /** Quantos grupos de participantes dependem deste setor. */
    grupos: number;
}

/** Um grupo de participantes do catálogo global. */
export interface GrupoDoCatalogo {
    id: number;
    nome: string;
    ativo: boolean;
    cidade_id: number;
    /** Nome do setor já formatado como "Setor/UF", na tela de catálogo. */
    cidade: string;
    /** Quantas inscrições apontam para este grupo. */
    inscricoes: number;
}

/** Opção de setor nos seletores. */
export interface OpcaoDeCidade {
    id: number;
    nome: string;
    ativo: boolean;
}

/** Uma situação do evento, com o rótulo que a pessoa lê na tela. */
export interface OpcaoDeSituacao {
    valor: string;
    rotulo: string;
}

/** Uma linha da lista de eventos. */
export interface EventoDaLista {
    id: number;
    nome: string;
    slug: string;
    situacao: string;
    situacao_rotulo: string;
    data_inicio: string;
    data_fim: string;
    capacidade: number | null;
    vagas_ocupadas: number;
    valor_centavos: number;
    /** Quantas inscrições existem neste evento, em qualquer situação. */
    inscricoes: number;
}

/** O evento aberto no formulário de cadastro. */
export interface EventoEmEdicao {
    id: number;
    nome: string;
    slug: string;
    descricao: string | null;
    /** O nome curto do lugar. */
    local: string | null;
    /** Como chegar: distancia, referencia, estacionamento. */
    local_detalhe: string | null;
    itens_incluidos: string[];
    perguntas_frequentes: Array<{ pergunta: string; resposta: string }>;
    data_inicio: string;
    data_fim: string;
    inscricoes_abrem_em: string;
    inscricoes_fecham_em: string;
    capacidade: number | null;
    valor_centavos: number;
    moeda: string;
    prazo_pagamento_minutos: number;
    situacao: string;
    regulamento: string;
    versao_termos: string;
    contato_email: string;
    contato_telefone: string | null;
    vagas_ocupadas: number;
    /** Quantas inscrições ativas seguram a estrutura deste evento. */
    inscricoes_ativas: number;
}

/** O cabeçalho do evento na tela de programação. */
export interface EventoDaEstrutura {
    id: number;
    nome: string;
    slug: string;
    situacao_rotulo: string;
    data_inicio: string;
    data_fim: string;
    inscricoes_ativas: number;
}

/** Uma atividade dentro de um grupo. */
export interface AtividadeDaEstrutura {
    id: number;
    grupo_atividade_id: number;
    nome: string;
    descricao: string | null;
    comeca_em: string;
    termina_em: string;
    capacidade: number | null;
    idade_minima: number | null;
    idade_maxima: number | null;
    posicao: number;
    ativo: boolean;
    vagas_ocupadas: number;
    /** Quantas pessoas já escolheram esta atividade. */
    escolhida_por: number;
}

/** Um grupo de atividades dentro de um dia. */
export interface GrupoDaEstrutura {
    id: number;
    dia_evento_id: number;
    nome: string;
    descricao: string | null;
    obrigatorio: boolean;
    min_selecoes: number;
    max_selecoes: number | null;
    posicao: number;
    ativo: boolean;
    atividades: AtividadeDaEstrutura[];
}

/** Um dia da programação. */
export interface DiaDaEstrutura {
    id: number;
    nome: string;
    descricao: string | null;
    data: string;
    posicao: number;
    ativo: boolean;
    grupos: GrupoDaEstrutura[];
}

/** Um par de atividades que ninguém pode escolher junto. */
export interface ConflitoDaEstrutura {
    id: number;
    atividade_a_id: number;
    atividade_b_id: number;
    atividade_a: string;
    atividade_b: string;
    motivo: string | null;
}

/** Uma atividade na lista plana usada pelos seletores de conflito. */
export interface OpcaoDeAtividade {
    id: number;
    nome: string;
    escolhida_por: number;
}

/** Uma linha da lista de inscrições. Sem CPF — nem cifrado, nem em pedaço. */
export interface InscricaoDaLista {
    id: number;
    codigo_publico: string;
    nome_completo: string;
    email: string;
    evento: string;
    cidade: string;
    grupo: string;
    situacao: string;
    situacao_rotulo: string;
    valor_centavos: number;
    prazo_pagamento: string | null;
    criada_em: string | null;
    situacao_pagamento: string | null;
    situacao_pagamento_rotulo: string | null;
}

/** O que o organizador pediu para filtrar. Cada campo vira um pedaço da URL. */
export interface FiltrosAplicados {
    evento_id: string | null;
    situacao: string | null;
    cidade_id: string | null;
    grupo_participante_id: string | null;
    atividade_id: string | null;
    situacao_pagamento: string | null;
    criada_de: string | null;
    criada_ate: string | null;
    busca: string | null;
}

/** Uma opção simples de seletor, identificada por número. */
export interface OpcaoComId {
    id: number;
    nome: string;
}

/** As listas que alimentam os seletores de filtro. */
export interface OpcoesDeFiltro {
    eventos: OpcaoComId[];
    cidades: OpcaoComId[];
    grupos: OpcaoComId[];
    atividades: OpcaoComId[];
    situacoes: OpcaoDeSituacao[];
    situacoes_pagamento: OpcaoDeSituacao[];
}

/** A página de resultados, com o suficiente para navegar sem perder o filtro. */
export interface PaginaDeInscricoes {
    dados: InscricaoDaLista[];
    pagina_atual: number;
    ultima_pagina: number;
    total: number;
    por_pagina: number;
    links: {
        anterior: string | null;
        proxima: string | null;
    };
}

/** Uma atividade escolhida por quem se inscreveu. */
export interface AtividadeEscolhida {
    id: number;
    nome: string;
    comeca_em: string;
    termina_em: string;
}

/** A ficha da inscrição. Sem CPF: ele fica cifrado e não é mostrado. */
export interface FichaDaInscricao {
    id: number;
    codigo_publico: string;
    nome_completo: string;
    email: string;
    telefone: string | null;
    evento: string;
    cidade: string;
    grupo: string;
    situacao: string;
    situacao_rotulo: string;
    valor_centavos: number;
    prazo_pagamento: string | null;
    criada_em: string | null;
    confirmada_em: string | null;
    expirada_em: string | null;
    cancelada_em: string | null;
    motivo_cancelamento: string | null;
    atividades: AtividadeEscolhida[];
    esta_ativa: boolean;
    /** Se alguma cobrança desta inscrição chegou a ser paga. */
    foi_paga: boolean;
}

/** Uma cobrança do histórico da inscrição. */
export interface CobrancaDaFicha {
    id: number;
    codigo_publico: string;
    gateway: string;
    metodo: string;
    metodo_rotulo: string;
    situacao: string;
    situacao_rotulo: string;
    valor_centavos: number;
    criada_em: string | null;
    expira_em: string | null;
    pago_em: string | null;
    cancelado_em: string | null;
    /** Verdadeiro quando o pagamento foi reconhecido na mão por alguém. */
    origem_manual: boolean;
    observacao: string | null;
    responsavel: string | null;
}

/**
 * Um aviso automático recebido do provedor de pagamento.
 *
 * O `payload` já vem limpo do banco: o que costuma carregar segredo foi
 * trocado por "[removido]" antes de virar linha, então não há nada aqui que a
 * tela precise esconder de novo.
 */
export interface AvisoDoProvedor {
    id: number;
    gateway: string;
    /** O identificador que o provedor deu ao aviso. Nulo quando ele não mandou. */
    id_evento_externo: string | null;
    tipo_evento: string | null;
    /** Falso é informação de segurança: alguém bateu na porta com a chave errada. */
    assinatura_valida: boolean;
    /** Data e hora já formatadas como "dd/mm/aaaa hh:mm:ss". */
    recebido_em: string | null;
    processado_em: string | null;
    situacao: string;
    situacao_rotulo: string;
    /** O motivo, quando o aviso foi ignorado ou falhou. */
    erro: string | null;
    payload: Record<string, unknown> | null;
}

/** Uma página de avisos, do mais recente para o mais antigo. */
export interface PaginaDeAvisos {
    dados: AvisoDoProvedor[];
    pagina_atual: number;
    ultima_pagina: number;
    total: number;
    por_pagina: number;
    links: {
        anterior: string | null;
        proxima: string | null;
    };
}

/** Os filtros que estão valendo, do jeito que vieram do endereço. */
export interface FiltrosDeAvisos {
    de: string | null;
    ate: string | null;
    situacao: string | null;
    gateway: string | null;
    /** "sim", "nao" ou nulo — nulo quer dizer "tanto faz". */
    assinatura_valida: string | null;
}

/** O que os seletores de filtro dos avisos oferecem. */
export interface OpcoesDeAvisos {
    situacoes: OpcaoDeSituacao[];
    /** Só os provedores que já mandaram algum aviso. */
    gateways: string[];
}

/** O aviso mais recente, como o cartão do painel o recebe. */
export interface UltimoAvisoDoProvedor {
    recebido_em: string | null;
    /** Quantos minutos atrás ele chegou. A frase é escrita na tela. */
    minutos_atras: number | null;
    situacao: string;
    situacao_rotulo: string;
    gateway: string;
}

/**
 * O bloco de avisos do painel.
 *
 * Nulo inteiro quer dizer "esta pessoa não pode ver os avisos"; `ultimo` nulo
 * quer dizer "nenhum aviso chegou ainda". São coisas diferentes, e a tela
 * responde diferente a cada uma.
 */
export interface AvisosDoProvedorNoPainel {
    ultimo: UltimoAvisoDoProvedor | null;
}

/**
 * Uma conta administrativa, como a lista de usuários a recebe.
 *
 * Nenhum campo de senha aparece aqui, e não é esquecimento: a tela mostra
 * quem entra e com que papel; hash de senha não é assunto de tela nenhuma.
 */
export interface UsuarioAdministrativo {
    id: number;
    nome: string;
    email: string;
    /** O nome do papel, como o `PapeisSeeder` o escreve. Nulo = conta sem papel. */
    papel: string | null;
    ativo: boolean;
    /** Quando a conta nasceu, já formatado como "dd/mm/aaaa". */
    entrou_em: string | null;
    /** Verdadeiro na linha de quem está olhando a tela. */
    sou_eu: boolean;
}

/** Uma página da lista de usuários. */
export interface PaginaDeUsuarios {
    dados: UsuarioAdministrativo[];
    pagina_atual: number;
    ultima_pagina: number;
    total: number;
    por_pagina: number;
    links: {
        anterior: string | null;
        proxima: string | null;
    };
}

/** Os filtros da lista de usuários, do jeito que vieram do endereço. */
export interface FiltrosDeUsuarios {
    busca: string | null;
    papel: string | null;
    /** "ativos", "desativados" ou nulo — nulo quer dizer "tanto faz". */
    situacao: string | null;
}

/** Um papel oferecido no seletor da lista. */
export interface OpcaoDePapel {
    valor: string;
    rotulo: string;
}

/** O que o seletor de papel oferece. */
export interface OpcoesDeUsuarios {
    papeis: OpcaoDePapel[];
}

/** Um papel na tela de consulta, com as permissões que ele alcança. */
export interface PapelDaMatriz {
    nome: string;
    rotulo: string;
    permissoes: string[];
    quantas: number;
}

/** Uma permissão, com a frase em português que diz o que ela alcança. */
export interface PermissaoDaMatriz {
    nome: string;
    explicacao: string;
}

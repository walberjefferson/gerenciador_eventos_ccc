import type { BadgeVariants } from '@/components/ui/badge';

/**
 * De que cor e cada situacao — decidido AQUI, uma vez, para o sistema inteiro.
 *
 * Antes deste arquivo o mesmo mapa existia em tres lugares (a lista de avisos
 * do provedor, o resumo da inscricao do participante e o historico da
 * cobranca), e os tres ja divergiam: "aguardando pagamento" era azul numa tela
 * e cinza na outra. Cor de situacao nao e enfeite de tela — e vocabulario do
 * sistema. Vocabulario duplicado vira sotaque.
 *
 * O tom nunca substitui a palavra: quem consome estas funcoes escreve o rotulo
 * dentro da etiqueta, sempre (WCAG 1.4.1). A cor so diz, de longe, o que a
 * pessoa deve FAZER com aquela linha.
 *
 * A entrada e o valor BRUTO do enum (`confirmada`, `aguardando_pagamento`), e
 * nao o rotulo legivel: rotulo muda quando alguem melhora um texto, valor de
 * enum nao muda sem migracao.
 *
 * Valor que nao esteja no mapa cai em `neutra`. Enum novo, ou valor vindo de um
 * banco mais adiantado que a tela, nao pode quebrar a listagem — e inventar
 * cor para o que nao se conhece seria pior do que nao pintar.
 */

/** As variantes de superficie suave que as listagens usam. */
type Variante = BadgeVariants['variant'];

const NEUTRA: Variante = 'neutra';

/**
 * Inscricao.
 *
 * `aguardando_pagamento` e ATENCAO, e nao informacao: o relogio esta correndo
 * e a vaga volta para a fila quando o prazo vencer. E a unica linha da lista
 * em que alguem ainda pode fazer alguma coisa a respeito.
 *
 * `cancelada` e NEUTRA — e aqui mora a aparente incoerencia com o evento
 * cancelado, que e vermelho. Cancelar uma inscricao e rotina administrativa:
 * acontece todo dia, alguem desistiu. Cancelar um EVENTO e excepcional e muda
 * a vida de todo mundo que se inscreveu. Pintar as duas de vermelho ensinaria
 * a organizacao a ignorar o vermelho, e o dia em que ele importasse de verdade
 * ele ja nao seria visto.
 */
const INSCRICAO: Record<string, Variante> = {
    aguardando_pagamento: 'atencaoSuave',
    confirmada: 'sucessoSuave',
    expirada: 'erroSuave',
    cancelada: 'neutra',
    lista_espera: 'informacaoSuave',
};

/**
 * Pagamento.
 *
 * `estornado` e INFORMACAO, e nao erro: o dinheiro voltou para quem pagou, e
 * isso costuma ser o desfecho correto de um acordo — nao uma falha do sistema.
 * `cancelado` e neutro pela mesma razao da inscricao cancelada.
 */
const PAGAMENTO: Record<string, Variante> = {
    pendente: 'atencaoSuave',
    pago: 'sucessoSuave',
    falhou: 'erroSuave',
    expirado: 'erroSuave',
    cancelado: 'neutra',
    estornado: 'informacaoSuave',
};

/**
 * Evento.
 *
 * `inscricoes_encerradas` e ATENCAO e nao erro: o evento esta vivo, so nao
 * recebe mais gente. Quem le a lista precisa distinguir isso de `cancelado`,
 * que e o unico vermelho daqui — e e vermelho porque e o estado que obriga
 * alguem a avisar todos os inscritos.
 */
const EVENTO: Record<string, Variante> = {
    rascunho: 'neutra',
    publicado: 'informacaoSuave',
    inscricoes_abertas: 'sucessoSuave',
    inscricoes_encerradas: 'atencaoSuave',
    finalizado: 'neutra',
    cancelado: 'erroSuave',
};

/**
 * Aviso do provedor de pagamento (webhook).
 *
 * "Ignorado" e NEUTRO por decisao, e a decisao vem da tela de avisos: nao e
 * erro, e o aviso que chegou sem assinatura valida, que falava de uma cobranca
 * que nao existe aqui, ou que repetia algo ja resolvido. Pintar de vermelho o
 * que e normal ensina a ignorar o vermelho. Quem exige atencao e `falhou`.
 *
 * `recebido` tambem e neutro: e o estado de passagem de quem acabou de chegar
 * e ainda vai ser lido.
 */
const WEBHOOK: Record<string, Variante> = {
    recebido: 'neutra',
    processado: 'sucessoSuave',
    ignorado: 'neutra',
    falhou: 'erroSuave',
};

export function varianteDaInscricao(situacao: string): Variante {
    return INSCRICAO[situacao] ?? NEUTRA;
}

export function varianteDoPagamento(situacao: string): Variante {
    return PAGAMENTO[situacao] ?? NEUTRA;
}

export function varianteDoEvento(situacao: string): Variante {
    return EVENTO[situacao] ?? NEUTRA;
}

export function varianteDoWebhook(situacao: string): Variante {
    return WEBHOOK[situacao] ?? NEUTRA;
}

/**
 * Ativo/inativo dos cadastros — setores, grupos, dias, atividades, contas.
 *
 * Desativado e NEUTRO, nunca vermelho: nada deu errado, alguem so tirou aquilo
 * do formulario. Vermelho ali faria a lista de catalogo parecer uma lista de
 * problemas.
 */
export function varianteDeAtivo(ativo: boolean): Variante {
    return ativo ? 'sucessoSuave' : 'neutra';
}

/** Os dominios que a `EtiquetaDeSituacao` sabe pintar. */
export type DominioDeSituacao = 'inscricao' | 'pagamento' | 'evento' | 'webhook';

/** O despachante do componente de etiqueta. Nao decide nada: so escolhe o mapa. */
export function varianteDoDominio(dominio: DominioDeSituacao, situacao: string): Variante {
    switch (dominio) {
        case 'inscricao':
            return varianteDaInscricao(situacao);
        case 'pagamento':
            return varianteDoPagamento(situacao);
        case 'evento':
            return varianteDoEvento(situacao);
        case 'webhook':
            return varianteDoWebhook(situacao);
    }
}

<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import CampoDeDataHora from '@/components/admin/CampoDeDataHora.vue';
import CampoDeMarcar from '@/components/admin/CampoDeMarcar.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import { DateField } from '@/components/ui/date-field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatarValor } from '@/lib/formato';
import type {
    AtividadeDaEstrutura,
    ConflitoDaEstrutura,
    DiaDaEstrutura,
    EventoDaEstrutura,
    GrupoDaEstrutura,
    LoteDaEstrutura,
    OpcaoDeAtividade,
    ResumoDosLotes,
} from '@/types/admin';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A programação do evento: dias, grupos de atividades, atividades e conflitos.
 *
 * Tudo numa tela só, porque só junto isso faz sentido de ler — um grupo sem o
 * dia dele não quer dizer nada, e uma atividade sem o grupo também não.
 *
 * Nada some em silêncio. Cada linha mostra quantas pessoas já escolheram
 * aquela atividade; quando alguém já escolheu, a tela não oferece o botão de
 * excluir e explica o caminho certo — desativar, que tira do formulário sem
 * apagar a escolha de ninguém.
 */
const props = defineProps<{
    evento: EventoDaEstrutura;
    dias: DiaDaEstrutura[];
    conflitos: ConflitoDaEstrutura[];
    atividades: OpcaoDeAtividade[];
    lotes: LoteDaEstrutura[];
    lotes_resumo: ResumoDosLotes;
    sucesso: string | null;
}>();

const erroDeExclusao = computed<string | undefined>(() => usePage().props.errors?.exclusao);

const grupos = computed<GrupoDaEstrutura[]>(() => props.dias.flatMap((dia) => dia.grupos));

/* ---------------------------------------------------------------- dias --- */

/**
 * OS QUATRO CADASTROS DESTA TELA MORAM EM MODAIS.
 *
 * Antes, cada cartao trazia o formulario em cima e a lista logo abaixo, com o
 * mesmo peso visual e sem nada entre os dois. O resultado e o que se ve numa
 * captura de tela: "Minimo de escolhas" e "Modalidades esportivas" parecem
 * pertencer ao mesmo bloco, e nao da para dizer, batendo o olho, o que e campo
 * a preencher e o que e registro ja salvo. Numa tela cujo unico proposito e
 * conferir a programacao montada, isso e o defeito principal.
 *
 * Agora cada cartao mostra SO a lista, com um botao que abre o formulario por
 * cima. O que esta cadastrado e o que se ve; o que se digita interrompe a tela
 * de proposito, e sai dela quando termina.
 */
/**
 * A SEÇÃO DE DIAS COMEÇA RECOLHIDA QUANDO O EVENTO TEM UM DIA SÓ.
 *
 * Todo evento novo já nasce com o "Dia 1" pronto, e a maioria deles tem
 * mesmo um dia só. Deixar a tabela de dias aberta no topo da tela faz a
 * primeira coisa que se lê ser justamente a que não precisa de trabalho, e
 * empurra as atividades — o motivo de ter entrado aqui — para baixo da dobra.
 *
 * Recolhida não é escondida: o botão diz quantos dias existem e abre a tabela
 * com um toque. Com dois dias ou mais, a seção começa aberta, como sempre foi:
 * aí a programação de fato tem estrutura para conferir.
 */
const diasExpandidos = ref<boolean>(props.evento.dias_total !== 1);

/** "Dia 1 · 17/10/2026" — o que a seção recolhida mostra no lugar da tabela. */
const resumoDosDias = computed<string>(() => props.dias.map((dia) => `${dia.nome} · ${dataEmPortugues(dia.data)}`).join(', '));

const modalDiaAberto = ref(false);

const diaEmEdicao = ref<DiaDaEstrutura | null>(null);

// O campo da data viaja com outro nome porque "data" é o nome do método que o
// formulário do Inertia já usa para devolver os próprios valores. Na hora de
// enviar, ele volta a se chamar "data", que é como o servidor o conhece.
const formularioDia = useForm({
    nome: '',
    descricao: '',
    data_do_dia: props.evento.data_inicio,
    posicao: 1,
    ativo: true as boolean,
}).transform((dados) => ({ ...dados, data: dados.data_do_dia }));

/** O erro da data chega do servidor no campo "data", que não existe no formulário. */
const erroDaDataDoDia = computed<string | undefined>(() => usePage().props.errors?.data);

function editarDia(dia: DiaDaEstrutura): void {
    modalDiaAberto.value = true;
    diaEmEdicao.value = dia;
    formularioDia.clearErrors();
    formularioDia.nome = dia.nome;
    formularioDia.descricao = dia.descricao ?? '';
    formularioDia.data_do_dia = dia.data;
    formularioDia.posicao = dia.posicao;
    formularioDia.ativo = dia.ativo;
}

function abrirCadastroDia(): void {
    diaEmEdicao.value = null;
    formularioDia.clearErrors();
    formularioDia.reset();
    modalDiaAberto.value = true;
}

/**
 * Fechar DESFAZ a edicao em curso: quem fechou desistiu. Sem isto, o proximo
 * "Novo" abriria com os dados de um registro que a pessoa achou que tinha
 * abandonado.
 */
function aoTrocarAberturaDia(aberto: boolean): void {
    modalDiaAberto.value = aberto;

    if (!aberto) {
        diaEmEdicao.value = null;
        formularioDia.clearErrors();
        formularioDia.reset();
    }
}

function cancelarDia(): void {
    diaEmEdicao.value = null;
    modalDiaAberto.value = false;
    formularioDia.clearErrors();
    formularioDia.reset();
}

function gravarDia(): void {
    if (diaEmEdicao.value === null) {
        formularioDia.post(route('admin.eventos.dias.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => {
                formularioDia.reset();
                modalDiaAberto.value = false;
            },
        });

        return;
    }

    formularioDia.put(route('admin.eventos.dias.update', { evento: props.evento.id, dia_evento: diaEmEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarDia(),
    });
}

/* -------------------------------------------------------------- grupos --- */

const modalGrupoAberto = ref(false);

const grupoEmEdicao = ref<GrupoDaEstrutura | null>(null);

const formularioGrupo = useForm({
    dia_evento_id: 0,
    nome: '',
    descricao: '',
    obrigatorio: false as boolean,
    min_selecoes: 0,
    max_selecoes: null as number | null,
    posicao: 1,
    ativo: true as boolean,
});

function editarGrupo(grupo: GrupoDaEstrutura): void {
    modalGrupoAberto.value = true;
    grupoEmEdicao.value = grupo;
    formularioGrupo.clearErrors();
    formularioGrupo.dia_evento_id = grupo.dia_evento_id;
    formularioGrupo.nome = grupo.nome;
    formularioGrupo.descricao = grupo.descricao ?? '';
    formularioGrupo.obrigatorio = grupo.obrigatorio;
    formularioGrupo.min_selecoes = grupo.min_selecoes;
    formularioGrupo.max_selecoes = grupo.max_selecoes;
    formularioGrupo.posicao = grupo.posicao;
    formularioGrupo.ativo = grupo.ativo;
}

function abrirCadastroGrupo(): void {
    grupoEmEdicao.value = null;
    formularioGrupo.clearErrors();
    formularioGrupo.reset();
    modalGrupoAberto.value = true;
}

/**
 * Fechar DESFAZ a edicao em curso: quem fechou desistiu. Sem isto, o proximo
 * "Novo" abriria com os dados de um registro que a pessoa achou que tinha
 * abandonado.
 */
function aoTrocarAberturaGrupo(aberto: boolean): void {
    modalGrupoAberto.value = aberto;

    if (!aberto) {
        grupoEmEdicao.value = null;
        formularioGrupo.clearErrors();
        formularioGrupo.reset();
    }
}

function cancelarGrupo(): void {
    grupoEmEdicao.value = null;
    modalGrupoAberto.value = false;
    formularioGrupo.clearErrors();
    formularioGrupo.reset();
}

function gravarGrupo(): void {
    if (grupoEmEdicao.value === null) {
        formularioGrupo.post(route('admin.eventos.grupos.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => {
                formularioGrupo.reset();
                modalGrupoAberto.value = false;
            },
        });

        return;
    }

    formularioGrupo.put(route('admin.eventos.grupos.update', { evento: props.evento.id, grupo_atividade: grupoEmEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarGrupo(),
    });
}

/* ---------------------------------------------------------- atividades --- */

const modalAtividadeAberto = ref(false);

const atividadeEmEdicao = ref<AtividadeDaEstrutura | null>(null);

const formularioAtividade = useForm({
    grupo_atividade_id: 0,
    nome: '',
    descricao: '',
    comeca_em: '',
    termina_em: '',
    capacidade: null as number | null,
    idade_minima: null as number | null,
    idade_maxima: null as number | null,
    posicao: 1,
    ativo: true as boolean,
});

function editarAtividade(atividade: AtividadeDaEstrutura): void {
    modalAtividadeAberto.value = true;
    atividadeEmEdicao.value = atividade;
    formularioAtividade.clearErrors();
    formularioAtividade.grupo_atividade_id = atividade.grupo_atividade_id;
    formularioAtividade.nome = atividade.nome;
    formularioAtividade.descricao = atividade.descricao ?? '';
    // O horário é opcional: quando não existe, o campo abre vazio — e vazio é
    // o que o servidor recebe de volta se ninguém preencher.
    formularioAtividade.comeca_em = atividade.comeca_em ?? '';
    formularioAtividade.termina_em = atividade.termina_em ?? '';
    formularioAtividade.capacidade = atividade.capacidade;
    formularioAtividade.idade_minima = atividade.idade_minima;
    formularioAtividade.idade_maxima = atividade.idade_maxima;
    formularioAtividade.posicao = atividade.posicao;
    formularioAtividade.ativo = atividade.ativo;
}

function abrirCadastroAtividade(): void {
    atividadeEmEdicao.value = null;
    formularioAtividade.clearErrors();
    formularioAtividade.reset();
    modalAtividadeAberto.value = true;
}

/**
 * Fechar DESFAZ a edicao em curso: quem fechou desistiu. Sem isto, o proximo
 * "Novo" abriria com os dados de um registro que a pessoa achou que tinha
 * abandonado.
 */
function aoTrocarAberturaAtividade(aberto: boolean): void {
    modalAtividadeAberto.value = aberto;

    if (!aberto) {
        atividadeEmEdicao.value = null;
        formularioAtividade.clearErrors();
        formularioAtividade.reset();
    }
}

function cancelarAtividade(): void {
    atividadeEmEdicao.value = null;
    modalAtividadeAberto.value = false;
    formularioAtividade.clearErrors();
    formularioAtividade.reset();
}

function gravarAtividade(): void {
    if (atividadeEmEdicao.value === null) {
        formularioAtividade.post(route('admin.eventos.atividades.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => {
                formularioAtividade.reset();
                modalAtividadeAberto.value = false;
            },
        });

        return;
    }

    formularioAtividade.put(route('admin.eventos.atividades.update', { evento: props.evento.id, atividade: atividadeEmEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarAtividade(),
    });
}

/* ----------------------------------------------------------- conflitos --- */

const modalConflitoAberto = ref(false);

const formularioConflito = useForm({
    atividade_a_id: 0,
    atividade_b_id: 0,
    motivo: '',
});

function aoTrocarAberturaConflito(aberto: boolean): void {
    modalConflitoAberto.value = aberto;

    if (!aberto) {
        formularioConflito.clearErrors();
        formularioConflito.reset();
    }
}

function gravarConflito(): void {
    formularioConflito.post(route('admin.eventos.conflitos.store', { evento: props.evento.id }), {
        preserveScroll: true,
        onSuccess: () => {
            formularioConflito.reset();
            modalConflitoAberto.value = false;
        },
    });
}

/* ------------------------------------------------------------- lotes --- */

/**
 * OS LOTES SÃO OS DEGRAUS DE PREÇO DO EVENTO.
 *
 * Cada um vale até uma data, até acabarem as vagas dele, ou até o que vier
 * primeiro — e pelo menos um dos dois limites é obrigatório: lote que não
 * encerra é o valor do próprio evento com outro nome.
 *
 * A situação de cada lote (encerrado, atual, em breve) vem DECIDIDA do servidor:
 * ela é derivada da data e do contador a cada leitura, e não existe coluna
 * guardando-a. Refazer essa conta aqui criaria um segundo lugar onde a regra
 * mora — e o relógio deste computador não é o que vende a vaga.
 */
const modalLoteAberto = ref(false);

const loteEmEdicao = ref<LoteDaEstrutura | null>(null);

const formularioLote = useForm({
    nome: '',
    posicao: props.lotes.length + 1,
    valor_centavos: props.lotes_resumo.valor_do_evento,
    disponivel_ate: '',
    quantidade: null as number | null,
}).transform((dados) => ({
    ...dados,
    // Campo vazio é "sem prazo", e não string vazia: é assim que o servidor
    // reconhece um lote que só encerra por vagas.
    disponivel_ate: dados.disponivel_ate === '' ? null : dados.disponivel_ate,
}));

function editarLote(lote: LoteDaEstrutura): void {
    modalLoteAberto.value = true;
    loteEmEdicao.value = lote;
    formularioLote.clearErrors();
    formularioLote.nome = lote.nome;
    formularioLote.posicao = lote.posicao;
    formularioLote.valor_centavos = lote.valor_centavos;
    formularioLote.disponivel_ate = lote.disponivel_ate ?? '';
    formularioLote.quantidade = lote.quantidade;
}

function abrirCadastroLote(): void {
    loteEmEdicao.value = null;
    formularioLote.clearErrors();
    formularioLote.reset();
    // A próxima posição livre, para que o caso comum não peça digitação.
    formularioLote.posicao = props.lotes.length + 1;
    modalLoteAberto.value = true;
}

/**
 * Fechar DESFAZ a edicao em curso: quem fechou desistiu. Sem isto, o proximo
 * "Novo" abriria com os dados de um registro que a pessoa achou que tinha
 * abandonado.
 */
function aoTrocarAberturaLote(aberto: boolean): void {
    modalLoteAberto.value = aberto;

    if (!aberto) {
        loteEmEdicao.value = null;
        formularioLote.clearErrors();
        formularioLote.reset();
    }
}

function cancelarLote(): void {
    loteEmEdicao.value = null;
    modalLoteAberto.value = false;
    formularioLote.clearErrors();
    formularioLote.reset();
}

function gravarLote(): void {
    if (loteEmEdicao.value === null) {
        formularioLote.post(route('admin.eventos.lotes.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => {
                formularioLote.reset();
                modalLoteAberto.value = false;
            },
        });

        return;
    }

    formularioLote.put(route('admin.eventos.lotes.update', { evento: props.evento.id, lote: loteEmEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarLote(),
    });
}

function excluirLote(lote: LoteDaEstrutura): void {
    excluir(route('admin.eventos.lotes.destroy', { evento: props.evento.id, lote: lote.id }));
}

/** "Lote atual", "Em breve", "Encerrado" — a situação sempre escrita. */
function situacaoDoLote(lote: LoteDaEstrutura): string {
    if (lote.situacao === 'vigente') {
        return 'Lote atual';
    }

    return lote.situacao === 'futuro' ? 'Em breve' : 'Encerrado';
}

/** "Até 10/10/2026 às 23:59 · 40 vagas", ou o que houver dos dois. */
function limiteDoLote(lote: LoteDaEstrutura): string {
    const partes: string[] = [];

    if (lote.disponivel_ate !== null) {
        partes.push(`Até ${horario(lote.disponivel_ate)}`);
    }

    if (lote.quantidade !== null) {
        partes.push(`${lote.quantidade} vaga(s)`);
    }

    return partes.join(' · ');
}

/**
 * A soma das quantidades ao lado da capacidade — INFORMAÇÃO, nunca bloqueio.
 *
 * Os dois tetos são independentes: a soma pode ficar abaixo da capacidade (e aí
 * sobra vaga sem lote, e a inscrição fecha quando o último lote acabar) ou acima
 * dela (e aí o evento lota antes do último lote). Nenhum dos dois é erro; os
 * dois são decisões — e quem cadastra precisa enxergar qual delas tomou.
 */
const comparacaoComACapacidade = computed<string | null>(() => {
    const { capacidade, soma_quantidades: soma } = props.lotes_resumo;

    if (soma === null) {
        return null;
    }

    if (capacidade === null) {
        return `Os lotes somam ${soma} vaga(s). O evento não tem capacidade máxima definida.`;
    }

    if (soma === capacidade) {
        return `Os lotes somam ${soma} vaga(s), exatamente a capacidade do evento.`;
    }

    return soma < capacidade
        ? `Os lotes somam ${soma} vaga(s) e a capacidade do evento é ${capacidade}. ` +
              `Sobram ${capacidade - soma} vaga(s) sem lote: quando o último lote acabar, as inscrições fecham mesmo com vaga livre.`
        : `Os lotes somam ${soma} vaga(s) e a capacidade do evento é ${capacidade}. ` + 'O evento lota antes do último lote acabar.';
});

/* ---------------------------------------------------------- exclusões --- */

const excluindo = ref(false);

function excluir(url: string): void {
    excluindo.value = true;

    router.delete(url, { preserveScroll: true, onFinish: () => (excluindo.value = false) });
}

function excluirDia(dia: DiaDaEstrutura): void {
    excluir(route('admin.eventos.dias.destroy', { evento: props.evento.id, dia_evento: dia.id }));
}

function excluirGrupo(grupo: GrupoDaEstrutura): void {
    excluir(route('admin.eventos.grupos.destroy', { evento: props.evento.id, grupo_atividade: grupo.id }));
}

function excluirAtividade(atividade: AtividadeDaEstrutura): void {
    excluir(route('admin.eventos.atividades.destroy', { evento: props.evento.id, atividade: atividade.id }));
}

function excluirConflito(conflito: ConflitoDaEstrutura): void {
    excluir(route('admin.eventos.conflitos.destroy', { evento: props.evento.id, conflito_atividade: conflito.id }));
}

/* ------------------------------------------------------------- apoio --- */

/**
 * "17/10/2026" — a data como se escreve em portugues.
 *
 * O que vem do servidor e ISO (`AAAA-MM-DD`), que e o formato de troca. Ele
 * nunca deveria ter chegado a tela assim: "2026-10-17" na coluna de uma tabela
 * e um dado de maquina exposto a quem organiza o evento.
 *
 * A quebra e feita a mao, sem `new Date()`, de proposito: `new Date('2026-10-17')`
 * e lido como meia-noite em UTC e, no fuso do Brasil, volta como dia 16.
 */
function dataEmPortugues(iso: string): string {
    const [ano, mes, dia] = iso.slice(0, 10).split('-');

    return ano === undefined || mes === undefined || dia === undefined ? iso : `${dia}/${mes}/${ano}`;
}

/** "17/10/2026 às 08:00" — a data por extenso mais a hora. */
function horario(iso: string): string {
    const [data, hora] = iso.split('T');

    return `${dataEmPortugues(data ?? '')} às ${(hora ?? '').slice(0, 5)}`;
}

/**
 * "17/10/2026 às 08:00 — 10:00", ou "—" quando a atividade não tem hora marcada.
 *
 * O travessão sozinho vale AQUI, e só aqui: nesta tela a ausência de horário é
 * informação de trabalho — quem organiza precisa ver, batendo o olho na
 * listagem, quais atividades ocupam o dia inteiro. Nas telas de quem se
 * inscreve, a linha do horário simplesmente não existe.
 */
function horarioDaAtividade(atividade: AtividadeDaEstrutura): string {
    if (atividade.comeca_em === null || atividade.termina_em === null) {
        return '—';
    }

    return `${horario(atividade.comeca_em)} — ${horario(atividade.termina_em).slice(-5)}`;
}

function escolhas(grupo: GrupoDaEstrutura): string {
    const maximo = grupo.max_selecoes === null ? 'sem limite' : String(grupo.max_selecoes);

    return `${grupo.obrigatorio ? 'Obrigatório' : 'Opcional'} · de ${grupo.min_selecoes} a ${maximo}`;
}
</script>

<template>
    <AdminLayout
        :titulo="`Programação de ${props.evento.nome}`"
        descricao="Os dias do evento, os grupos de escolha de cada dia, as atividades de cada grupo e os pares que ninguém pode escolher junto. Nada aqui é apagado quando alguém já escolheu: desative em vez de excluir."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">{{ props.sucesso }}</p>

        <p v-if="erroDeExclusao" role="alert" class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm">
            {{ erroDeExclusao }}
        </p>

        <p v-if="props.evento.inscricoes_ativas > 0" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            Este evento tem {{ props.evento.inscricoes_ativas }} inscrição(ões) ativa(s). Mexer na programação agora muda o que essas pessoas já
            escolheram — prefira desativar o que não vai mais acontecer.
        </p>

        <div>
            <Link
                :href="route('admin.eventos.index')"
                class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Voltar para a lista de eventos
            </Link>
        </div>

        <!-- Dias -->
        <section aria-labelledby="titulo-dias" class="border-border grid gap-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="titulo-dias" class="mr-auto text-lg font-semibold">Dias do evento</h2>

                <button
                    type="button"
                    :aria-expanded="diasExpandidos"
                    aria-controls="lista-de-dias"
                    data-testid="alternar-dias"
                    class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="diasExpandidos = !diasExpandidos"
                >
                    {{ diasExpandidos ? 'Ocultar os dias' : `Mostrar os dias (${props.dias.length})` }}
                </button>

                <button
                    type="button"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="abrirCadastroDia"
                >
                    Novo dia
                </button>
            </div>

            <p v-if="!diasExpandidos" class="text-muted-foreground text-sm">{{ resumoDosDias }}</p>

            <Dialog :open="modalDiaAberto" @update:open="aoTrocarAberturaDia">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{{ diaEmEdicao === null ? 'Novo dia' : `Editando ${diaEmEdicao.nome}` }}</DialogTitle>
                        <DialogDescription
                            >Cada dia do evento tem uma data própria. A posição decide a ordem em que eles aparecem para quem se
                            inscreve.</DialogDescription
                        >
                    </DialogHeader>

                    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="gravarDia">
                        <div class="flex flex-col gap-1">
                            <label for="dia-nome" class="text-sm font-medium">Nome do dia</label>
                            <input
                                id="dia-nome"
                                v-model="formularioDia.nome"
                                type="text"
                                required
                                maxlength="120"
                                :aria-invalid="formularioDia.errors.nome ? true : undefined"
                                class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            />
                            <p v-if="formularioDia.errors.nome" role="alert" class="text-destructive text-sm">{{ formularioDia.errors.nome }}</p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="dia-data" class="text-sm font-medium">Data</label>
                            <DateField
                                id="dia-data"
                                v-model="formularioDia.data_do_dia"
                                rotulo-do-calendario="Escolher a data do dia no calendário"
                                :aria-invalid="erroDaDataDoDia ? true : undefined"
                            />
                            <p v-if="erroDaDataDoDia" role="alert" class="text-destructive text-sm">{{ erroDaDataDoDia }}</p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="dia-posicao" class="text-sm font-medium">Posição</label>
                            <input
                                id="dia-posicao"
                                v-model.number="formularioDia.posicao"
                                type="number"
                                min="1"
                                required
                                :aria-invalid="formularioDia.errors.posicao ? true : undefined"
                                class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            />
                            <p v-if="formularioDia.errors.posicao" role="alert" class="text-destructive text-sm">
                                {{ formularioDia.errors.posicao }}
                            </p>
                        </div>

                        <!-- A caixa alinha pelo CENTRO da linha dos campos, e nao pelo
                     fundo da celula: celula de grid estica com a vizinha mais
                     alta, e era isso que fazia a caixa afundar. -->
                        <div class="flex items-center md:mt-6">
                            <CampoDeMarcar id="dia-ativo" v-model="formularioDia.ativo">Ativo</CampoDeMarcar>
                        </div>

                        <DialogFooter>
                            <button
                                type="button"
                                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                @click="cancelarDia"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="formularioDia.processing"
                                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                            >
                                {{ diaEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <div id="lista-de-dias" v-show="diasExpandidos">
                <table v-if="props.dias.length > 0" class="w-full text-sm">
                    <caption class="sr-only">
                        Dias da programação, com a data, a posição na leitura e quantos grupos cada um tem.
                    </caption>
                    <thead>
                        <tr class="border-border border-b text-left">
                            <th scope="col" class="px-2 py-2 font-medium">Dia</th>
                            <th scope="col" class="px-2 py-2 font-medium">Data</th>
                            <th scope="col" class="px-2 py-2 font-medium">Posição</th>
                            <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-2 py-2 font-medium">Grupos</th>
                            <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="dia in props.dias" :key="dia.id" class="border-border border-b last:border-0">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ dia.nome }}</th>
                            <td class="px-2 py-2">{{ dataEmPortugues(dia.data) }}</td>
                            <td class="px-2 py-2">{{ dia.posicao }}</td>
                            <td class="px-2 py-2">
                                <EtiquetaDeSituacao dominio="ativo" :situacao="dia.ativo" :rotulo="dia.ativo ? 'Ativo' : 'Desativado'" />
                            </td>
                            <td class="px-2 py-2">{{ dia.grupos.length }}</td>
                            <td class="px-2 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editarDia(dia)">Editar</BotaoDeAcao>
                                    <BotaoDeAcao tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluirDia(dia)">
                                        Excluir
                                    </BotaoDeAcao>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-muted-foreground text-sm">Nenhum dia cadastrado. Comece por aqui: sem dia não há programação.</p>
            </div>
        </section>

        <!-- Grupos -->
        <section v-if="props.dias.length > 0" aria-labelledby="titulo-grupos" class="border-border grid gap-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="titulo-grupos" class="mr-auto text-lg font-semibold">Grupos de atividades</h2>

                <button
                    type="button"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="abrirCadastroGrupo"
                >
                    Novo grupo
                </button>
            </div>

            <Dialog :open="modalGrupoAberto" @update:open="aoTrocarAberturaGrupo">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{{ grupoEmEdicao === null ? 'Novo grupo de atividades' : `Editando ${grupoEmEdicao.nome}` }}</DialogTitle>
                        <DialogDescription
                            >O grupo reúne as atividades entre as quais a pessoa escolhe, e é ele que diz quantas ela pode marcar.</DialogDescription
                        >
                    </DialogHeader>

                    <form class="grid gap-4" @submit.prevent="gravarGrupo">
                        <!--
                            O NOME VEM PRIMEIRO, E OCUPA A LINHA INTEIRA. Ele e o
                            campo principal do que se esta criando; "Dia" e
                            acessorio, e vinha antes so por acidente de escrita.
                            Assim a ordem de tabulacao tambem melhora: quem abre o
                            dialogo digita o nome antes de escolher onde encaixar.

                            O `col-span` esta na MESMA faixa da grade
                            (`sm:grid-cols-2` pede `sm:col-span-2`). Antes era
                            `md:col-span-2` dentro de grade `sm:`, e essa
                            discordancia de faixa e que abria dois buracos no
                            desktop: o Nome so esticava a partir de 768px, e de
                            640 a 767 sobrava celula vazia ao lado.
                        -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1 sm:col-span-2">
                                <label for="grupo-nome" class="text-sm font-medium">Nome do grupo</label>
                                <input
                                    id="grupo-nome"
                                    v-model="formularioGrupo.nome"
                                    type="text"
                                    required
                                    maxlength="120"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioGrupo.errors.nome" role="alert" class="text-destructive text-sm">
                                    {{ formularioGrupo.errors.nome }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="grupo-dia" class="text-sm font-medium">Dia</label>
                                <select
                                    id="grupo-dia"
                                    v-model.number="formularioGrupo.dia_evento_id"
                                    class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    <option :value="0" disabled>Escolha o dia</option>
                                    <option v-for="dia in props.dias" :key="dia.id" :value="dia.id">{{ dia.nome }}</option>
                                </select>
                                <p v-if="formularioGrupo.errors.dia_evento_id" role="alert" class="text-destructive text-sm">
                                    {{ formularioGrupo.errors.dia_evento_id }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="grupo-posicao" class="text-sm font-medium">Posição</label>
                                <input
                                    id="grupo-posicao"
                                    v-model.number="formularioGrupo.posicao"
                                    type="number"
                                    min="1"
                                    required
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1">
                                <label for="grupo-min" class="text-sm font-medium">Mínimo de escolhas</label>
                                <input
                                    id="grupo-min"
                                    v-model.number="formularioGrupo.min_selecoes"
                                    type="number"
                                    min="0"
                                    required
                                    :aria-invalid="formularioGrupo.errors.min_selecoes ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioGrupo.errors.min_selecoes" role="alert" class="text-destructive text-sm">
                                    {{ formularioGrupo.errors.min_selecoes }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="grupo-max" class="text-sm font-medium">Máximo de escolhas</label>
                                <input
                                    id="grupo-max"
                                    v-model.number="formularioGrupo.max_selecoes"
                                    type="number"
                                    min="0"
                                    aria-describedby="ajuda-grupo-max"
                                    :aria-invalid="formularioGrupo.errors.max_selecoes ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p id="ajuda-grupo-max" class="text-muted-foreground text-sm">Em branco, não há limite.</p>
                                <p v-if="formularioGrupo.errors.max_selecoes" role="alert" class="text-destructive text-sm">
                                    {{ formularioGrupo.errors.max_selecoes }}
                                </p>
                            </div>

                            <div class="flex items-center md:mt-2">
                                <CampoDeMarcar id="grupo-obrigatorio" v-model="formularioGrupo.obrigatorio">Obrigatório</CampoDeMarcar>
                            </div>

                            <div class="flex items-center md:mt-2">
                                <CampoDeMarcar id="grupo-ativo" v-model="formularioGrupo.ativo">Ativo</CampoDeMarcar>
                            </div>
                        </div>

                        <DialogFooter>
                            <button
                                type="button"
                                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                @click="cancelarGrupo"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="formularioGrupo.processing"
                                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                            >
                                {{ grupoEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <div v-for="dia in props.dias" :key="`grupos-${dia.id}`" class="grid gap-2">
                <h3 class="text-muted-foreground text-sm font-semibold">{{ dia.nome }} — {{ dataEmPortugues(dia.data) }}</h3>

                <p v-if="dia.grupos.length === 0" class="text-muted-foreground text-sm">Nenhum grupo neste dia.</p>

                <table v-else class="w-full text-sm">
                    <caption class="sr-only">
                        Grupos de atividades do dia
                        {{
                            dia.nome
                        }}, com as regras de escolha e as atividades de cada um.
                    </caption>
                    <thead>
                        <tr class="border-border border-b text-left">
                            <th scope="col" class="px-2 py-2 font-medium">Grupo</th>
                            <th scope="col" class="px-2 py-2 font-medium">Escolhas</th>
                            <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-2 py-2 font-medium">Atividades</th>
                            <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="grupo in dia.grupos" :key="grupo.id" class="border-border border-b last:border-0">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ grupo.nome }}</th>
                            <td class="px-2 py-2">{{ escolhas(grupo) }}</td>
                            <td class="px-2 py-2">
                                <EtiquetaDeSituacao dominio="ativo" :situacao="grupo.ativo" :rotulo="grupo.ativo ? 'Ativo' : 'Desativado'" />
                            </td>
                            <td class="px-2 py-2">{{ grupo.atividades.length }}</td>
                            <td class="px-2 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editarGrupo(grupo)">Editar</BotaoDeAcao>
                                    <BotaoDeAcao tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluirGrupo(grupo)">
                                        Excluir
                                    </BotaoDeAcao>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Atividades -->
        <section v-if="grupos.length > 0" aria-labelledby="titulo-atividades" class="border-border grid gap-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="titulo-atividades" class="mr-auto text-lg font-semibold">Atividades</h2>

                <button
                    type="button"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="abrirCadastroAtividade"
                >
                    Nova atividade
                </button>
            </div>

            <Dialog :open="modalAtividadeAberto" @update:open="aoTrocarAberturaAtividade">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{{ atividadeEmEdicao === null ? 'Nova atividade' : `Editando ${atividadeEmEdicao.nome}` }}</DialogTitle>
                        <DialogDescription
                            >A atividade é o que a pessoa marca no formulário. Horário e capacidade são conferidos na hora da
                            inscrição.</DialogDescription
                        >
                    </DialogHeader>

                    <form class="grid gap-4" @submit.prevent="gravarAtividade">
                        <!--
                            Mesma correcao do dialogo do grupo, e pela mesma
                            razao: o Nome e o campo principal e abre a grade
                            ocupando a linha inteira, com `sm:col-span-2` casando
                            com a faixa da grade (`sm:grid-cols-2`). Grupo e
                            Posicao dividem a linha seguinte, sem celula vazia.
                        -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1 sm:col-span-2">
                                <label for="atividade-nome" class="text-sm font-medium">Nome da atividade</label>
                                <input
                                    id="atividade-nome"
                                    v-model="formularioAtividade.nome"
                                    type="text"
                                    required
                                    maxlength="120"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioAtividade.errors.nome" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.nome }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-grupo" class="text-sm font-medium">Grupo</label>
                                <select
                                    id="atividade-grupo"
                                    v-model.number="formularioAtividade.grupo_atividade_id"
                                    class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    <option :value="0" disabled>Escolha o grupo</option>
                                    <option v-for="grupo in grupos" :key="grupo.id" :value="grupo.id">{{ grupo.nome }}</option>
                                </select>
                                <p v-if="formularioAtividade.errors.grupo_atividade_id" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.grupo_atividade_id }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-posicao" class="text-sm font-medium">Posição</label>
                                <input
                                    id="atividade-posicao"
                                    v-model.number="formularioAtividade.posicao"
                                    type="number"
                                    min="1"
                                    required
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <!-- O HORÁRIO É OPCIONAL, E EM PAR. Nem toda
                                 programação tem hora marcada: um mutirão, uma
                                 caminhada, um retiro acontecem "no sábado", e
                                 obrigar quem cadastra a inventar 08:00 às 17:00
                                 é pedir um dado que ninguém tem. Deixar os dois
                                 campos em branco faz a atividade ocupar o dia
                                 inteiro; preencher só um é recusado, porque
                                 metade de um horário não descreve nada. -->
                            <p id="ajuda-atividade-horario" class="text-muted-foreground text-sm sm:col-span-2">
                                O horário é opcional. Sem hora de início e de término, a atividade ocupa o dia inteiro do dia a que pertence — e não
                                pode ser escolhida junto com nenhuma outra desse mesmo dia.
                            </p>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-comeca" class="text-sm font-medium">Começa em (opcional)</label>
                                <CampoDeDataHora
                                    id="atividade-comeca"
                                    v-model="formularioAtividade.comeca_em"
                                    aria-describedby="ajuda-atividade-horario"
                                    :aria-invalid="formularioAtividade.errors.comeca_em ? true : undefined"
                                />
                                <p v-if="formularioAtividade.errors.comeca_em" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.comeca_em }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-termina" class="text-sm font-medium">Termina em (opcional)</label>
                                <CampoDeDataHora
                                    id="atividade-termina"
                                    v-model="formularioAtividade.termina_em"
                                    aria-describedby="ajuda-atividade-horario"
                                    :aria-invalid="formularioAtividade.errors.termina_em ? true : undefined"
                                />
                                <p v-if="formularioAtividade.errors.termina_em" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.termina_em }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-capacidade" class="text-sm font-medium">Capacidade</label>
                                <input
                                    id="atividade-capacidade"
                                    v-model.number="formularioAtividade.capacidade"
                                    type="number"
                                    min="0"
                                    aria-describedby="ajuda-atividade-capacidade"
                                    :aria-invalid="formularioAtividade.errors.capacidade ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p id="ajuda-atividade-capacidade" class="text-muted-foreground text-sm">Em branco, não há limite.</p>
                                <p v-if="formularioAtividade.errors.capacidade" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.capacidade }}
                                </p>
                            </div>

                            <div class="flex items-center md:mt-6">
                                <CampoDeMarcar id="atividade-ativo" v-model="formularioAtividade.ativo">Ativa</CampoDeMarcar>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1">
                                <label for="atividade-idade-min" class="text-sm font-medium">Idade mínima</label>
                                <input
                                    id="atividade-idade-min"
                                    v-model.number="formularioAtividade.idade_minima"
                                    type="number"
                                    min="0"
                                    max="120"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-idade-max" class="text-sm font-medium">Idade máxima</label>
                                <input
                                    id="atividade-idade-max"
                                    v-model.number="formularioAtividade.idade_maxima"
                                    type="number"
                                    min="0"
                                    max="120"
                                    :aria-invalid="formularioAtividade.errors.idade_maxima ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioAtividade.errors.idade_maxima" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.idade_maxima }}
                                </p>
                            </div>
                        </div>

                        <DialogFooter>
                            <button
                                type="button"
                                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                @click="cancelarAtividade"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="formularioAtividade.processing"
                                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                            >
                                {{ atividadeEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <div v-for="grupo in grupos" :key="`atividades-${grupo.id}`" class="grid gap-2">
                <h3 class="text-muted-foreground text-sm font-semibold">{{ grupo.nome }}</h3>

                <p v-if="grupo.atividades.length === 0" class="text-muted-foreground text-sm">Nenhuma atividade neste grupo.</p>

                <table v-else class="w-full text-sm">
                    <caption class="sr-only">
                        Atividades do grupo
                        {{
                            grupo.nome
                        }}, com o horário, a capacidade e quantas pessoas já escolheram cada uma.
                    </caption>
                    <thead>
                        <tr class="border-border border-b text-left">
                            <th scope="col" class="px-2 py-2 font-medium">Atividade</th>
                            <th scope="col" class="px-2 py-2 font-medium">Horário</th>
                            <th scope="col" class="px-2 py-2 font-medium">Vagas</th>
                            <th scope="col" class="px-2 py-2 font-medium">Escolhida por</th>
                            <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="atividade in grupo.atividades" :key="atividade.id" class="border-border border-b last:border-0">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ atividade.nome }}</th>
                            <td class="px-2 py-2 whitespace-nowrap">{{ horarioDaAtividade(atividade) }}</td>
                            <td class="px-2 py-2">
                                {{
                                    atividade.capacidade === null
                                        ? `${atividade.vagas_ocupadas} (sem limite)`
                                        : `${atividade.vagas_ocupadas} de ${atividade.capacidade}`
                                }}
                            </td>
                            <td class="px-2 py-2">{{ atividade.escolhida_por }}</td>
                            <td class="px-2 py-2">
                                <EtiquetaDeSituacao dominio="ativo" :situacao="atividade.ativo" :rotulo="atividade.ativo ? 'Ativa' : 'Desativada'" />
                            </td>
                            <td class="px-2 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editarAtividade(atividade)"
                                        >Editar</BotaoDeAcao
                                    >
                                    <span v-if="atividade.escolhida_por > 0" class="text-muted-foreground">
                                        Já escolhida: desative em vez de excluir.
                                    </span>
                                    <BotaoDeAcao
                                        v-else
                                        tamanho="xs"
                                        intencao="excluir"
                                        :icone="Trash2"
                                        :disabled="excluindo"
                                        @click="excluirAtividade(atividade)"
                                    >
                                        Excluir
                                    </BotaoDeAcao>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Conflitos -->
        <section v-if="props.atividades.length > 1" aria-labelledby="titulo-conflitos" class="border-border grid gap-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="titulo-conflitos" class="mr-auto text-lg font-semibold">Conflitos entre atividades</h2>

                <button
                    type="button"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="modalConflitoAberto = true"
                >
                    Novo conflito
                </button>
            </div>

            <p class="text-muted-foreground max-w-3xl text-sm">
                Um conflito é um par que ninguém pode escolher junto. A ordem das duas atividades não importa: o par é o mesmo. Remover um conflito
                não apaga escolha de ninguém — ele só deixa de barrar escolhas futuras.
            </p>

            <Dialog :open="modalConflitoAberto" @update:open="aoTrocarAberturaConflito">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Novo conflito</DialogTitle>
                        <DialogDescription>
                            As duas atividades escolhidas aqui deixam de poder ser marcadas juntas por quem se inscreve.
                        </DialogDescription>
                    </DialogHeader>

                    <form class="grid gap-4" @submit.prevent="gravarConflito">
                        <div class="flex flex-col gap-1">
                            <label for="conflito-a" class="text-sm font-medium">Primeira atividade</label>
                            <select
                                id="conflito-a"
                                v-model.number="formularioConflito.atividade_a_id"
                                :aria-invalid="formularioConflito.errors.atividade_a_id ? true : undefined"
                                class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                <option :value="0" disabled>Escolha</option>
                                <option v-for="atividade in props.atividades" :key="`a-${atividade.id}`" :value="atividade.id">
                                    {{ atividade.nome }}
                                </option>
                            </select>
                            <p v-if="formularioConflito.errors.atividade_a_id" role="alert" class="text-destructive text-sm">
                                {{ formularioConflito.errors.atividade_a_id }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="conflito-b" class="text-sm font-medium">Segunda atividade</label>
                            <select
                                id="conflito-b"
                                v-model.number="formularioConflito.atividade_b_id"
                                :aria-invalid="formularioConflito.errors.atividade_b_id ? true : undefined"
                                class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                <option :value="0" disabled>Escolha</option>
                                <option v-for="atividade in props.atividades" :key="`b-${atividade.id}`" :value="atividade.id">
                                    {{ atividade.nome }}
                                </option>
                            </select>
                            <p v-if="formularioConflito.errors.atividade_b_id" role="alert" class="text-destructive text-sm">
                                {{ formularioConflito.errors.atividade_b_id }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="conflito-motivo" class="text-sm font-medium">Motivo</label>
                            <input
                                id="conflito-motivo"
                                v-model="formularioConflito.motivo"
                                type="text"
                                maxlength="255"
                                class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            />
                        </div>

                        <DialogFooter>
                            <button
                                type="button"
                                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                @click="aoTrocarAberturaConflito(false)"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="formularioConflito.processing"
                                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                            >
                                Cadastrar
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <table v-if="props.conflitos.length > 0" class="w-full text-sm">
                <caption class="sr-only">
                    Pares de atividades que ninguém pode escolher junto.
                </caption>
                <thead>
                    <tr class="border-border border-b text-left">
                        <th scope="col" class="px-2 py-2 font-medium">Primeira atividade</th>
                        <th scope="col" class="px-2 py-2 font-medium">Segunda atividade</th>
                        <th scope="col" class="px-2 py-2 font-medium">Motivo</th>
                        <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="conflito in props.conflitos" :key="conflito.id" class="border-border border-b last:border-0">
                        <th scope="row" class="px-2 py-2 text-left font-normal">{{ conflito.atividade_a }}</th>
                        <td class="px-2 py-2">{{ conflito.atividade_b }}</td>
                        <td class="px-2 py-2">{{ conflito.motivo ?? '—' }}</td>
                        <td class="px-2 py-2">
                            <BotaoDeAcao tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluirConflito(conflito)">
                                Remover
                            </BotaoDeAcao>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted-foreground text-sm">Nenhum conflito cadastrado.</p>
        </section>

        <!-- Lotes de inscrição -->
        <section aria-labelledby="titulo-lotes" class="border-border grid gap-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="titulo-lotes" class="mr-auto text-lg font-semibold">Lotes de inscrição</h2>

                <button
                    type="button"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="abrirCadastroLote"
                >
                    Novo lote
                </button>
            </div>

            <p class="text-muted-foreground max-w-3xl text-sm">
                Cada lote é um degrau de preço: ele vale até uma data, até acabarem as vagas dele, ou até o que vier primeiro — e precisa de pelo
                menos um desses dois limites. Quem se inscreve entra sempre pelo lote em vigor, e o valor daquele lote fica gravado na inscrição:
                mudar o preço depois não altera o que ninguém já deve. Sem nenhum lote cadastrado, vale o valor do próprio evento.
            </p>

            <p v-if="comparacaoComACapacidade" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
                {{ comparacaoComACapacidade }}
            </p>

            <Dialog :open="modalLoteAberto" @update:open="aoTrocarAberturaLote">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{{ loteEmEdicao === null ? 'Novo lote' : `Editando ${loteEmEdicao.nome}` }}</DialogTitle>
                        <DialogDescription>
                            A posição decide a ordem da sucessão: vale sempre o primeiro lote da fila que ainda não encerrou.
                        </DialogDescription>
                    </DialogHeader>

                    <form class="grid gap-4" @submit.prevent="gravarLote">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1 sm:col-span-2">
                                <label for="lote-nome" class="text-sm font-medium">Nome do lote</label>
                                <input
                                    id="lote-nome"
                                    v-model="formularioLote.nome"
                                    type="text"
                                    required
                                    maxlength="80"
                                    :aria-invalid="formularioLote.errors.nome ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioLote.errors.nome" role="alert" class="text-destructive text-sm">
                                    {{ formularioLote.errors.nome }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="lote-posicao" class="text-sm font-medium">Posição</label>
                                <input
                                    id="lote-posicao"
                                    v-model.number="formularioLote.posicao"
                                    type="number"
                                    min="1"
                                    required
                                    :aria-invalid="formularioLote.errors.posicao ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioLote.errors.posicao" role="alert" class="text-destructive text-sm">
                                    {{ formularioLote.errors.posicao }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="lote-valor" class="text-sm font-medium">Valor em centavos</label>
                                <input
                                    id="lote-valor"
                                    v-model.number="formularioLote.valor_centavos"
                                    type="number"
                                    min="0"
                                    required
                                    aria-describedby="ajuda-lote-valor"
                                    :aria-invalid="formularioLote.errors.valor_centavos ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p id="ajuda-lote-valor" class="text-muted-foreground text-sm">R$ 120,00 se escreve 12000.</p>
                                <p v-if="formularioLote.errors.valor_centavos" role="alert" class="text-destructive text-sm">
                                    {{ formularioLote.errors.valor_centavos }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <p id="ajuda-lote-limite" class="text-muted-foreground text-sm sm:col-span-2">
                                Preencha pelo menos um dos dois. Com os dois, o lote encerra no que vier primeiro.
                            </p>

                            <div class="flex flex-col gap-1">
                                <label for="lote-disponivel-ate" class="text-sm font-medium">Disponível até (opcional)</label>
                                <CampoDeDataHora
                                    id="lote-disponivel-ate"
                                    v-model="formularioLote.disponivel_ate"
                                    aria-describedby="ajuda-lote-limite"
                                    :aria-invalid="formularioLote.errors.disponivel_ate ? true : undefined"
                                />
                                <p v-if="formularioLote.errors.disponivel_ate" role="alert" class="text-destructive text-sm">
                                    {{ formularioLote.errors.disponivel_ate }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="lote-quantidade" class="text-sm font-medium">Quantidade de vagas (opcional)</label>
                                <input
                                    id="lote-quantidade"
                                    v-model.number="formularioLote.quantidade"
                                    type="number"
                                    min="1"
                                    aria-describedby="ajuda-lote-limite"
                                    :aria-invalid="formularioLote.errors.quantidade ? true : undefined"
                                    class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p v-if="formularioLote.errors.quantidade" role="alert" class="text-destructive text-sm">
                                    {{ formularioLote.errors.quantidade }}
                                </p>
                            </div>
                        </div>

                        <DialogFooter>
                            <button
                                type="button"
                                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                @click="cancelarLote"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="formularioLote.processing"
                                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                            >
                                {{ loteEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <table v-if="props.lotes.length > 0" class="w-full text-sm" data-testid="tabela-de-lotes">
                <caption class="sr-only">
                    Lotes de inscrição, com o valor, o limite de cada um, quantas vagas já saíram e a situação.
                </caption>
                <thead>
                    <tr class="border-border border-b text-left">
                        <th scope="col" class="px-2 py-2 font-medium">Lote</th>
                        <th scope="col" class="px-2 py-2 font-medium">Valor</th>
                        <th scope="col" class="px-2 py-2 font-medium">Limite</th>
                        <th scope="col" class="px-2 py-2 font-medium">Vagas do lote</th>
                        <th scope="col" class="px-2 py-2 font-medium">Inscrições</th>
                        <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="lote in props.lotes" :key="lote.id" class="border-border border-b last:border-0">
                        <th scope="row" class="px-2 py-2 text-left font-normal">{{ lote.posicao }}. {{ lote.nome }}</th>
                        <td class="px-2 py-2 tabular-nums">{{ formatarValor(lote.valor_centavos) }}</td>
                        <td class="px-2 py-2">{{ limiteDoLote(lote) }}</td>
                        <td class="px-2 py-2">
                            {{ lote.quantidade === null ? `${lote.vagas_ocupadas} (sem limite)` : `${lote.vagas_ocupadas} de ${lote.quantidade}` }}
                        </td>
                        <td class="px-2 py-2">{{ lote.inscricoes }}</td>
                        <!-- A palavra fica sempre escrita: a situação não pode
                             depender só da cor (WCAG 1.4.1). -->
                        <td class="px-2 py-2" :class="lote.situacao === 'vigente' ? 'font-medium' : 'text-muted-foreground'">
                            {{ situacaoDoLote(lote) }}
                        </td>
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap gap-2">
                                <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editarLote(lote)">Editar</BotaoDeAcao>
                                <!--
                                    Sem botão de excluir quando alguém já entrou
                                    por este lote: apagá-lo apagaria de onde
                                    aquelas pessoas vieram e por qual valor. O
                                    caminho certo é encerrar o lote pela data ou
                                    pela quantidade, e a frase diz isso no lugar
                                    do botão que não existe.
                                -->
                                <span v-if="lote.inscricoes > 0" class="text-muted-foreground">
                                    Já tem inscrição: encerre pela data ou pela quantidade em vez de excluir.
                                </span>
                                <BotaoDeAcao v-else tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluirLote(lote)">
                                    Excluir
                                </BotaoDeAcao>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted-foreground text-sm">
                Nenhum lote cadastrado. Sem lotes, a inscrição custa o valor do evento — {{ formatarValor(props.lotes_resumo.valor_do_evento) }} — do
                começo ao fim.
            </p>
        </section>
    </AdminLayout>
</template>

<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import CampoDeDataHora from '@/components/admin/CampoDeDataHora.vue';
import CampoDeMarcar from '@/components/admin/CampoDeMarcar.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import { DateField } from '@/components/ui/date-field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AtividadeDaEstrutura, ConflitoDaEstrutura, DiaDaEstrutura, EventoDaEstrutura, GrupoDaEstrutura, OpcaoDeAtividade } from '@/types/admin';
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
    formularioAtividade.comeca_em = atividade.comeca_em;
    formularioAtividade.termina_em = atividade.termina_em;
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
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="abrirCadastroDia"
                >
                    Novo dia
                </button>
            </div>

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
                                <BotaoDeAcao tamanho="compacto" intencao="editar" :icone="Pencil" @click="editarDia(dia)">Editar</BotaoDeAcao>
                                <BotaoDeAcao tamanho="compacto" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluirDia(dia)">
                                    Excluir
                                </BotaoDeAcao>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted-foreground text-sm">Nenhum dia cadastrado. Comece por aqui: sem dia não há programação.</p>
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
                        <div class="grid gap-4 sm:grid-cols-2">
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

                            <div class="flex flex-col gap-1 md:col-span-2">
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
                                    <BotaoDeAcao tamanho="compacto" intencao="editar" :icone="Pencil" @click="editarGrupo(grupo)">Editar</BotaoDeAcao>
                                    <BotaoDeAcao
                                        tamanho="compacto"
                                        intencao="excluir"
                                        :icone="Trash2"
                                        :disabled="excluindo"
                                        @click="excluirGrupo(grupo)"
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
                        <div class="grid gap-4 sm:grid-cols-2">
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

                            <div class="flex flex-col gap-1 md:col-span-2">
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
                            <div class="flex flex-col gap-1">
                                <label for="atividade-comeca" class="text-sm font-medium">Começa em</label>
                                <CampoDeDataHora id="atividade-comeca" v-model="formularioAtividade.comeca_em" />
                                <p v-if="formularioAtividade.errors.comeca_em" role="alert" class="text-destructive text-sm">
                                    {{ formularioAtividade.errors.comeca_em }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="atividade-termina" class="text-sm font-medium">Termina em</label>
                                <CampoDeDataHora
                                    id="atividade-termina"
                                    v-model="formularioAtividade.termina_em"
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
                            <td class="px-2 py-2 whitespace-nowrap">
                                {{ horario(atividade.comeca_em) }} — {{ horario(atividade.termina_em).slice(-5) }}
                            </td>
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
                                    <BotaoDeAcao tamanho="compacto" intencao="editar" :icone="Pencil" @click="editarAtividade(atividade)"
                                        >Editar</BotaoDeAcao
                                    >
                                    <span v-if="atividade.escolhida_por > 0" class="text-muted-foreground">
                                        Já escolhida: desative em vez de excluir.
                                    </span>
                                    <BotaoDeAcao
                                        v-else
                                        tamanho="compacto"
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
                            <BotaoDeAcao
                                tamanho="compacto"
                                intencao="excluir"
                                :icone="Trash2"
                                :disabled="excluindo"
                                @click="excluirConflito(conflito)"
                            >
                                Remover
                            </BotaoDeAcao>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted-foreground text-sm">Nenhum conflito cadastrado.</p>
        </section>
    </AdminLayout>
</template>

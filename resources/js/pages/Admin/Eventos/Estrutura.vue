<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type {
    AtividadeDaEstrutura,
    ConflitoDaEstrutura,
    DiaDaEstrutura,
    EventoDaEstrutura,
    GrupoDaEstrutura,
    OpcaoDeAtividade,
} from '@/types/admin';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    diaEmEdicao.value = dia;
    formularioDia.clearErrors();
    formularioDia.nome = dia.nome;
    formularioDia.descricao = dia.descricao ?? '';
    formularioDia.data_do_dia = dia.data;
    formularioDia.posicao = dia.posicao;
    formularioDia.ativo = dia.ativo;
}

function cancelarDia(): void {
    diaEmEdicao.value = null;
    formularioDia.clearErrors();
    formularioDia.reset();
}

function gravarDia(): void {
    if (diaEmEdicao.value === null) {
        formularioDia.post(route('admin.eventos.dias.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => formularioDia.reset(),
        });

        return;
    }

    formularioDia.put(route('admin.eventos.dias.update', { evento: props.evento.id, dia_evento: diaEmEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarDia(),
    });
}

/* -------------------------------------------------------------- grupos --- */

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

function cancelarGrupo(): void {
    grupoEmEdicao.value = null;
    formularioGrupo.clearErrors();
    formularioGrupo.reset();
}

function gravarGrupo(): void {
    if (grupoEmEdicao.value === null) {
        formularioGrupo.post(route('admin.eventos.grupos.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => formularioGrupo.reset(),
        });

        return;
    }

    formularioGrupo.put(route('admin.eventos.grupos.update', { evento: props.evento.id, grupo_atividade: grupoEmEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarGrupo(),
    });
}

/* ---------------------------------------------------------- atividades --- */

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

function cancelarAtividade(): void {
    atividadeEmEdicao.value = null;
    formularioAtividade.clearErrors();
    formularioAtividade.reset();
}

function gravarAtividade(): void {
    if (atividadeEmEdicao.value === null) {
        formularioAtividade.post(route('admin.eventos.atividades.store', { evento: props.evento.id }), {
            preserveScroll: true,
            onSuccess: () => formularioAtividade.reset(),
        });

        return;
    }

    formularioAtividade.put(
        route('admin.eventos.atividades.update', { evento: props.evento.id, atividade: atividadeEmEdicao.value.id }),
        { preserveScroll: true, onSuccess: () => cancelarAtividade() },
    );
}

/* ----------------------------------------------------------- conflitos --- */

const formularioConflito = useForm({
    atividade_a_id: 0,
    atividade_b_id: 0,
    motivo: '',
});

function gravarConflito(): void {
    formularioConflito.post(route('admin.eventos.conflitos.store', { evento: props.evento.id }), {
        preserveScroll: true,
        onSuccess: () => formularioConflito.reset(),
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

function horario(iso: string): string {
    return iso.replace('T', ' às ').slice(0, 16);
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
        <p v-if="props.sucesso" role="status" class="rounded-md border border-border bg-muted/40 px-4 py-2 text-sm">{{ props.sucesso }}</p>

        <p
            v-if="erroDeExclusao"
            role="alert"
            class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-2 text-sm text-destructive"
        >
            {{ erroDeExclusao }}
        </p>

        <p v-if="props.evento.inscricoes_ativas > 0" class="rounded-md border border-border bg-muted/40 px-4 py-2 text-sm">
            Este evento tem {{ props.evento.inscricoes_ativas }} inscrição(ões) ativa(s). Mexer na programação agora muda o que essas pessoas
            já escolheram — prefira desativar o que não vai mais acontecer.
        </p>

        <div>
            <Link
                :href="route('admin.eventos.index')"
                class="inline-flex h-10 items-center rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
            >
                Voltar para a lista de eventos
            </Link>
        </div>

        <!-- Dias -->
        <section aria-labelledby="titulo-dias" class="grid gap-4 rounded-lg border border-border p-4">
            <h2 id="titulo-dias" class="text-lg font-semibold">{{ diaEmEdicao === null ? 'Novo dia' : `Editando ${diaEmEdicao.nome}` }}</h2>

            <form class="grid gap-4 md:grid-cols-[1fr_10rem_7rem_auto_auto]" @submit.prevent="gravarDia">
                <div class="flex flex-col gap-1">
                    <label for="dia-nome" class="text-sm font-medium">Nome do dia</label>
                    <input
                        id="dia-nome"
                        v-model="formularioDia.nome"
                        type="text"
                        required
                        maxlength="120"
                        :aria-invalid="formularioDia.errors.nome ? true : undefined"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p v-if="formularioDia.errors.nome" role="alert" class="text-sm text-destructive">{{ formularioDia.errors.nome }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="dia-data" class="text-sm font-medium">Data</label>
                    <input
                        id="dia-data"
                        v-model="formularioDia.data_do_dia"
                        type="date"
                        required
                        :aria-invalid="erroDaDataDoDia ? true : undefined"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p v-if="erroDaDataDoDia" role="alert" class="text-sm text-destructive">{{ erroDaDataDoDia }}</p>
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
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p v-if="formularioDia.errors.posicao" role="alert" class="text-sm text-destructive">
                        {{ formularioDia.errors.posicao }}
                    </p>
                </div>

                <div class="flex items-end gap-2">
                    <input id="dia-ativo" v-model="formularioDia.ativo" type="checkbox" class="size-4 rounded border-input" />
                    <label for="dia-ativo" class="pb-2 text-sm font-medium">Ativo</label>
                </div>

                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        :disabled="formularioDia.processing"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        {{ diaEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                    </button>
                    <button
                        v-if="diaEmEdicao !== null"
                        type="button"
                        class="h-10 rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        @click="cancelarDia"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <table v-if="props.dias.length > 0" class="w-full text-sm">
                <caption class="sr-only">
                    Dias da programação, com a data, a posição na leitura e quantos grupos cada um tem.
                </caption>
                <thead>
                    <tr class="border-b border-border text-left">
                        <th scope="col" class="px-2 py-2 font-medium">Dia</th>
                        <th scope="col" class="px-2 py-2 font-medium">Data</th>
                        <th scope="col" class="px-2 py-2 font-medium">Posição</th>
                        <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-2 py-2 font-medium">Grupos</th>
                        <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="dia in props.dias" :key="dia.id" class="border-b border-border last:border-0">
                        <th scope="row" class="px-2 py-2 text-left font-normal">{{ dia.nome }}</th>
                        <td class="px-2 py-2">{{ dia.data }}</td>
                        <td class="px-2 py-2">{{ dia.posicao }}</td>
                        <td class="px-2 py-2">{{ dia.ativo ? 'Ativo' : 'Desativado' }}</td>
                        <td class="px-2 py-2">{{ dia.grupos.length }}</td>
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                    @click="editarDia(dia)"
                                >
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    :disabled="excluindo"
                                    class="rounded-md border border-destructive px-3 py-1 text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                                    @click="excluirDia(dia)"
                                >
                                    Excluir
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-sm text-muted-foreground">Nenhum dia cadastrado. Comece por aqui: sem dia não há programação.</p>
        </section>

        <!-- Grupos -->
        <section v-if="props.dias.length > 0" aria-labelledby="titulo-grupos" class="grid gap-4 rounded-lg border border-border p-4">
            <h2 id="titulo-grupos" class="text-lg font-semibold">
                {{ grupoEmEdicao === null ? 'Novo grupo de atividades' : `Editando ${grupoEmEdicao.nome}` }}
            </h2>

            <form class="grid gap-4" @submit.prevent="gravarGrupo">
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="flex flex-col gap-1">
                        <label for="grupo-dia" class="text-sm font-medium">Dia</label>
                        <select
                            id="grupo-dia"
                            v-model.number="formularioGrupo.dia_evento_id"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option :value="0" disabled>Escolha o dia</option>
                            <option v-for="dia in props.dias" :key="dia.id" :value="dia.id">{{ dia.nome }}</option>
                        </select>
                        <p v-if="formularioGrupo.errors.dia_evento_id" role="alert" class="text-sm text-destructive">
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularioGrupo.errors.nome" role="alert" class="text-sm text-destructive">
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <div class="flex items-end gap-2">
                        <input id="grupo-obrigatorio" v-model="formularioGrupo.obrigatorio" type="checkbox" class="size-4 rounded border-input" />
                        <label for="grupo-obrigatorio" class="pb-2 text-sm font-medium">Obrigatório</label>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="grupo-min" class="text-sm font-medium">Mínimo de escolhas</label>
                        <input
                            id="grupo-min"
                            v-model.number="formularioGrupo.min_selecoes"
                            type="number"
                            min="0"
                            required
                            :aria-invalid="formularioGrupo.errors.min_selecoes ? true : undefined"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularioGrupo.errors.min_selecoes" role="alert" class="text-sm text-destructive">
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p id="ajuda-grupo-max" class="text-sm text-muted-foreground">Em branco, não há limite.</p>
                        <p v-if="formularioGrupo.errors.max_selecoes" role="alert" class="text-sm text-destructive">
                            {{ formularioGrupo.errors.max_selecoes }}
                        </p>
                    </div>

                    <div class="flex items-end gap-2">
                        <input id="grupo-ativo" v-model="formularioGrupo.ativo" type="checkbox" class="size-4 rounded border-input" />
                        <label for="grupo-ativo" class="pb-2 text-sm font-medium">Ativo</label>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="formularioGrupo.processing"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        {{ grupoEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                    </button>
                    <button
                        v-if="grupoEmEdicao !== null"
                        type="button"
                        class="h-10 rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        @click="cancelarGrupo"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <div v-for="dia in props.dias" :key="`grupos-${dia.id}`" class="grid gap-2">
                <h3 class="text-sm font-semibold text-muted-foreground">{{ dia.nome }} — {{ dia.data }}</h3>

                <p v-if="dia.grupos.length === 0" class="text-sm text-muted-foreground">Nenhum grupo neste dia.</p>

                <table v-else class="w-full text-sm">
                    <caption class="sr-only">
                        Grupos de atividades do dia {{ dia.nome }}, com as regras de escolha e as atividades de cada um.
                    </caption>
                    <thead>
                        <tr class="border-b border-border text-left">
                            <th scope="col" class="px-2 py-2 font-medium">Grupo</th>
                            <th scope="col" class="px-2 py-2 font-medium">Escolhas</th>
                            <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-2 py-2 font-medium">Atividades</th>
                            <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="grupo in dia.grupos" :key="grupo.id" class="border-b border-border last:border-0">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ grupo.nome }}</th>
                            <td class="px-2 py-2">{{ escolhas(grupo) }}</td>
                            <td class="px-2 py-2">{{ grupo.ativo ? 'Ativo' : 'Desativado' }}</td>
                            <td class="px-2 py-2">{{ grupo.atividades.length }}</td>
                            <td class="px-2 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                        @click="editarGrupo(grupo)"
                                    >
                                        Editar
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="excluindo"
                                        class="rounded-md border border-destructive px-3 py-1 text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                                        @click="excluirGrupo(grupo)"
                                    >
                                        Excluir
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Atividades -->
        <section v-if="grupos.length > 0" aria-labelledby="titulo-atividades" class="grid gap-4 rounded-lg border border-border p-4">
            <h2 id="titulo-atividades" class="text-lg font-semibold">
                {{ atividadeEmEdicao === null ? 'Nova atividade' : `Editando ${atividadeEmEdicao.nome}` }}
            </h2>

            <form class="grid gap-4" @submit.prevent="gravarAtividade">
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="flex flex-col gap-1">
                        <label for="atividade-grupo" class="text-sm font-medium">Grupo</label>
                        <select
                            id="atividade-grupo"
                            v-model.number="formularioAtividade.grupo_atividade_id"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option :value="0" disabled>Escolha o grupo</option>
                            <option v-for="grupo in grupos" :key="grupo.id" :value="grupo.id">{{ grupo.nome }}</option>
                        </select>
                        <p v-if="formularioAtividade.errors.grupo_atividade_id" role="alert" class="text-sm text-destructive">
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularioAtividade.errors.nome" role="alert" class="text-sm text-destructive">
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <div class="flex flex-col gap-1">
                        <label for="atividade-comeca" class="text-sm font-medium">Começa em</label>
                        <input
                            id="atividade-comeca"
                            v-model="formularioAtividade.comeca_em"
                            type="datetime-local"
                            required
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularioAtividade.errors.comeca_em" role="alert" class="text-sm text-destructive">
                            {{ formularioAtividade.errors.comeca_em }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="atividade-termina" class="text-sm font-medium">Termina em</label>
                        <input
                            id="atividade-termina"
                            v-model="formularioAtividade.termina_em"
                            type="datetime-local"
                            required
                            :aria-invalid="formularioAtividade.errors.termina_em ? true : undefined"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularioAtividade.errors.termina_em" role="alert" class="text-sm text-destructive">
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p id="ajuda-atividade-capacidade" class="text-sm text-muted-foreground">Em branco, não há limite.</p>
                        <p v-if="formularioAtividade.errors.capacidade" role="alert" class="text-sm text-destructive">
                            {{ formularioAtividade.errors.capacidade }}
                        </p>
                    </div>

                    <div class="flex items-end gap-2">
                        <input id="atividade-ativo" v-model="formularioAtividade.ativo" type="checkbox" class="size-4 rounded border-input" />
                        <label for="atividade-ativo" class="pb-2 text-sm font-medium">Ativa</label>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <div class="flex flex-col gap-1">
                        <label for="atividade-idade-min" class="text-sm font-medium">Idade mínima</label>
                        <input
                            id="atividade-idade-min"
                            v-model.number="formularioAtividade.idade_minima"
                            type="number"
                            min="0"
                            max="120"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
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
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularioAtividade.errors.idade_maxima" role="alert" class="text-sm text-destructive">
                            {{ formularioAtividade.errors.idade_maxima }}
                        </p>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="formularioAtividade.processing"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        {{ atividadeEmEdicao === null ? 'Acrescentar' : 'Salvar' }}
                    </button>
                    <button
                        v-if="atividadeEmEdicao !== null"
                        type="button"
                        class="h-10 rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        @click="cancelarAtividade"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <div v-for="grupo in grupos" :key="`atividades-${grupo.id}`" class="grid gap-2">
                <h3 class="text-sm font-semibold text-muted-foreground">{{ grupo.nome }}</h3>

                <p v-if="grupo.atividades.length === 0" class="text-sm text-muted-foreground">Nenhuma atividade neste grupo.</p>

                <table v-else class="w-full text-sm">
                    <caption class="sr-only">
                        Atividades do grupo {{ grupo.nome }}, com o horário, a capacidade e quantas pessoas já escolheram cada uma.
                    </caption>
                    <thead>
                        <tr class="border-b border-border text-left">
                            <th scope="col" class="px-2 py-2 font-medium">Atividade</th>
                            <th scope="col" class="px-2 py-2 font-medium">Horário</th>
                            <th scope="col" class="px-2 py-2 font-medium">Vagas</th>
                            <th scope="col" class="px-2 py-2 font-medium">Escolhida por</th>
                            <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="atividade in grupo.atividades" :key="atividade.id" class="border-b border-border last:border-0">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ atividade.nome }}</th>
                            <td class="px-2 py-2 whitespace-nowrap">
                                {{ horario(atividade.comeca_em) }} — {{ horario(atividade.termina_em).slice(-5) }}
                            </td>
                            <td class="px-2 py-2">
                                {{ atividade.capacidade === null ? `${atividade.vagas_ocupadas} (sem limite)` : `${atividade.vagas_ocupadas} de ${atividade.capacidade}` }}
                            </td>
                            <td class="px-2 py-2">{{ atividade.escolhida_por }}</td>
                            <td class="px-2 py-2">{{ atividade.ativo ? 'Ativa' : 'Desativada' }}</td>
                            <td class="px-2 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                        @click="editarAtividade(atividade)"
                                    >
                                        Editar
                                    </button>
                                    <span v-if="atividade.escolhida_por > 0" class="text-muted-foreground">
                                        Já escolhida: desative em vez de excluir.
                                    </span>
                                    <button
                                        v-else
                                        type="button"
                                        :disabled="excluindo"
                                        class="rounded-md border border-destructive px-3 py-1 text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                                        @click="excluirAtividade(atividade)"
                                    >
                                        Excluir
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Conflitos -->
        <section v-if="props.atividades.length > 1" aria-labelledby="titulo-conflitos" class="grid gap-4 rounded-lg border border-border p-4">
            <h2 id="titulo-conflitos" class="text-lg font-semibold">Conflitos entre atividades</h2>

            <p class="max-w-3xl text-sm text-muted-foreground">
                Um conflito é um par que ninguém pode escolher junto. A ordem das duas atividades não importa: o par é o mesmo. Remover um
                conflito não apaga escolha de ninguém — ele só deixa de barrar escolhas futuras.
            </p>

            <form class="grid gap-4 md:grid-cols-[1fr_1fr_1fr_auto]" @submit.prevent="gravarConflito">
                <div class="flex flex-col gap-1">
                    <label for="conflito-a" class="text-sm font-medium">Primeira atividade</label>
                    <select
                        id="conflito-a"
                        v-model.number="formularioConflito.atividade_a_id"
                        :aria-invalid="formularioConflito.errors.atividade_a_id ? true : undefined"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option :value="0" disabled>Escolha</option>
                        <option v-for="atividade in props.atividades" :key="`a-${atividade.id}`" :value="atividade.id">
                            {{ atividade.nome }}
                        </option>
                    </select>
                    <p v-if="formularioConflito.errors.atividade_a_id" role="alert" class="text-sm text-destructive">
                        {{ formularioConflito.errors.atividade_a_id }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="conflito-b" class="text-sm font-medium">Segunda atividade</label>
                    <select
                        id="conflito-b"
                        v-model.number="formularioConflito.atividade_b_id"
                        :aria-invalid="formularioConflito.errors.atividade_b_id ? true : undefined"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option :value="0" disabled>Escolha</option>
                        <option v-for="atividade in props.atividades" :key="`b-${atividade.id}`" :value="atividade.id">
                            {{ atividade.nome }}
                        </option>
                    </select>
                    <p v-if="formularioConflito.errors.atividade_b_id" role="alert" class="text-sm text-destructive">
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
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </div>

                <div class="flex items-end">
                    <button
                        type="submit"
                        :disabled="formularioConflito.processing"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        Cadastrar
                    </button>
                </div>
            </form>

            <table v-if="props.conflitos.length > 0" class="w-full text-sm">
                <caption class="sr-only">
                    Pares de atividades que ninguém pode escolher junto.
                </caption>
                <thead>
                    <tr class="border-b border-border text-left">
                        <th scope="col" class="px-2 py-2 font-medium">Primeira atividade</th>
                        <th scope="col" class="px-2 py-2 font-medium">Segunda atividade</th>
                        <th scope="col" class="px-2 py-2 font-medium">Motivo</th>
                        <th scope="col" class="px-2 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="conflito in props.conflitos" :key="conflito.id" class="border-b border-border last:border-0">
                        <th scope="row" class="px-2 py-2 text-left font-normal">{{ conflito.atividade_a }}</th>
                        <td class="px-2 py-2">{{ conflito.atividade_b }}</td>
                        <td class="px-2 py-2">{{ conflito.motivo ?? '—' }}</td>
                        <td class="px-2 py-2">
                            <button
                                type="button"
                                :disabled="excluindo"
                                class="rounded-md border border-destructive px-3 py-1 text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                                @click="excluirConflito(conflito)"
                            >
                                Remover
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-sm text-muted-foreground">Nenhum conflito cadastrado.</p>
        </section>
    </AdminLayout>
</template>

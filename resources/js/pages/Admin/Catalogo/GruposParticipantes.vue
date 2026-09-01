<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import PainelDeFiltros from '@/components/admin/PainelDeFiltros.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { GrupoDoCatalogo, OpcaoDeCidade } from '@/types/admin';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * O catálogo de grupos de participantes.
 *
 * Mesma forma da tela de setores: formulário em cima, lista embaixo. Grupo com
 * inscrição não é excluído — apagar o grupo apagaria a resposta que a pessoa
 * deu no formulário, e neste sistema histórico não se perde.
 *
 * A prop e o campo continuam se chamando `cidades` e `cidade_id`, como as
 * colunas: o renome para "setor" vale para o que a pessoa lê, não para o
 * contrato com o servidor.
 */
const props = defineProps<{
    grupos: GrupoDoCatalogo[];
    cidades: OpcaoDeCidade[];
    sucesso: string | null;
}>();

/**
 * O cadastro mora num MODAL — mesma razao da tela de setores: o formulario e
 * eventual, a lista e o que se olha sempre. Aqui pesa ainda mais, porque sao
 * 29 grupos e a lista e o trabalho.
 *
 * Fechar desfaz a edicao em curso: quem fecha desistiu.
 */
const modalAberto = ref(false);

const emEdicao = ref<GrupoDoCatalogo | null>(null);
const confirmandoExclusao = ref<number | null>(null);
const campoNome = ref<HTMLInputElement | null>(null);

const formulario = useForm({
    nome: '',
    cidade_id: props.cidades[0]?.id ?? 0,
    ativo: true as boolean,
});

/**
 * O erro de exclusão não vem de um formulário: ele volta do servidor como erro
 * da página. É de lá que a tela o lê.
 */
const erroDeExclusao = computed<string | undefined>(() => usePage().props.errors?.exclusao);
const excluindo = ref(false);

const titulo = computed(() => (emEdicao.value === null ? 'Novo grupo' : `Editando ${emEdicao.value.nome}`));

/**
 * O filtro da lista, feito AQUI e nao no servidor.
 *
 * A lista chega inteira do controller, sem paginacao: filtrar no navegador
 * responde a cada tecla sem ida de rede. Sao 29 grupos hoje — poucos para
 * paginar, muitos para achar um no olho.
 *
 * O filtro por SETOR e o que mais importa nesta tela: ela mistura os grupos de
 * todos os setores numa lista so, e quem administra quase sempre esta cuidando
 * de um setor por vez.
 *
 * A busca ignora acento, caixa e apostrofo — "olho dagua" precisa achar
 * "Olho d'água das Flores".
 */
const busca = ref('');
const setorId = ref<string>('todos');
const situacao = ref<'todos' | 'ativos' | 'desativados'>('todos');

function semAcento(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/['’]/g, '')
        .toLowerCase()
        .trim();
}

const gruposFiltrados = computed<GrupoDoCatalogo[]>(() => {
    const termo = semAcento(busca.value);

    return props.grupos.filter((grupo) => {
        const combinaTexto = termo === '' || semAcento(grupo.nome).includes(termo) || semAcento(grupo.cidade).includes(termo);

        const combinaSetor = setorId.value === 'todos' || String(grupo.cidade_id) === setorId.value;

        const combinaSituacao =
            situacao.value === 'todos' || (situacao.value === 'ativos' && grupo.ativo) || (situacao.value === 'desativados' && !grupo.ativo);

        return combinaTexto && combinaSetor && combinaSituacao;
    });
});

const filtroEstaAtivo = computed<boolean>(() => busca.value.trim() !== '' || setorId.value !== 'todos' || situacao.value !== 'todos');

/** Quantos filtros estao pegando — o cabecalho do painel mostra isso recolhido. */
const quantosFiltrosAtivos = computed<number>(
    () => (busca.value.trim() === '' ? 0 : 1) + (setorId.value === 'todos' ? 0 : 1) + (situacao.value === 'todos' ? 0 : 1),
);

function limparFiltro(): void {
    busca.value = '';
    setorId.value = 'todos';
    situacao.value = 'todos';
}

function abrirCadastro(): void {
    emEdicao.value = null;
    formulario.clearErrors();
    formulario.reset();
    modalAberto.value = true;
}

function editar(grupo: GrupoDoCatalogo): void {
    modalAberto.value = true;
    emEdicao.value = grupo;
    formulario.clearErrors();
    formulario.nome = grupo.nome;
    formulario.cidade_id = grupo.cidade_id;
    formulario.ativo = grupo.ativo;

    void nextTick(() => campoNome.value?.focus());
}

function cancelarEdicao(): void {
    emEdicao.value = null;
    formulario.clearErrors();
    formulario.reset();
    modalAberto.value = false;
}

/**
 * O modal so fecha sozinho quando o servidor ACEITOU. Recusada a validacao, ele
 * fica aberto com a mensagem ao lado do campo.
 */
function aoTrocarAbertura(aberto: boolean): void {
    modalAberto.value = aberto;

    if (!aberto) {
        emEdicao.value = null;
        formulario.clearErrors();
        formulario.reset();
    }
}

function gravar(): void {
    if (emEdicao.value === null) {
        formulario.post(route('admin.catalogo.grupos-participantes.store'), {
            preserveScroll: true,
            onSuccess: () => {
                formulario.reset();
                modalAberto.value = false;
            },
        });

        return;
    }

    formulario.put(route('admin.catalogo.grupos-participantes.update', { grupo_participante: emEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarEdicao(),
    });
}

function excluir(grupo: GrupoDoCatalogo): void {
    excluindo.value = true;

    router.delete(route('admin.catalogo.grupos-participantes.destroy', { grupo_participante: grupo.id }), {
        preserveScroll: true,
        onFinish: () => {
            excluindo.value = false;
            confirmandoExclusao.value = null;
        },
    });
}
</script>

<template>
    <AdminLayout
        titulo="Grupos de participantes"
        descricao="Cada grupo pertence a um setor. Grupo que já tem gente inscrita não pode ser excluído: desative-o para tirá-lo do formulário sem apagar nenhuma resposta."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p v-if="erroDeExclusao" role="alert" class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm">
            {{ erroDeExclusao }}
        </p>

        <p v-if="props.cidades.length === 0" class="border-border bg-muted/40 rounded-md border px-4 py-3 text-sm">
            Cadastre ao menos um setor antes de criar grupos de participantes.
        </p>

        <div v-else>
            <button
                type="button"
                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                @click="abrirCadastro"
            >
                Novo grupo
            </button>
        </div>

        <Dialog :open="modalAberto" @update:open="aoTrocarAbertura">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ titulo }}</DialogTitle>
                    <DialogDescription>
                        Cada grupo pertence a um setor. Desativado, ele sai do formulário de inscrição sem apagar nenhuma resposta.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="gravar">
                    <div class="flex flex-col gap-1">
                        <label for="grupo-nome" class="text-sm font-medium">Nome do grupo</label>
                        <input
                            id="grupo-nome"
                            ref="campoNome"
                            v-model="formulario.nome"
                            type="text"
                            maxlength="120"
                            required
                            :aria-describedby="formulario.errors.nome ? 'erro-grupo-nome' : undefined"
                            :aria-invalid="formulario.errors.nome ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.nome" id="erro-grupo-nome" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.nome }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="grupo-setor" class="text-sm font-medium">Setor</label>
                        <select
                            id="grupo-setor"
                            v-model="formulario.cidade_id"
                            :aria-describedby="formulario.errors.cidade_id ? 'erro-grupo-setor' : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option v-for="cidade in props.cidades" :key="cidade.id" :value="cidade.id">
                                {{ cidade.nome }}{{ cidade.ativo ? '' : ' (desativado)' }}
                            </option>
                        </select>
                        <p v-if="formulario.errors.cidade_id" id="erro-grupo-setor" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.cidade_id }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="grupo-ativo" v-model="formulario.ativo" type="checkbox" class="border-input size-4 rounded" />
                        <label for="grupo-ativo" class="text-sm font-medium">Ativo</label>
                    </div>

                    <DialogFooter>
                        <button
                            type="button"
                            class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            @click="cancelarEdicao"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formulario.processing"
                            class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                        >
                            {{ emEdicao === null ? 'Cadastrar' : 'Salvar' }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <PainelDeFiltros v-if="props.grupos.length > 1" id="filtros-grupo" :ativos="quantosFiltrosAtivos">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full space-y-1 sm:w-56">
                    <label for="filtro-busca-grupo" class="block text-sm font-medium">Buscar</label>
                    <input
                        id="filtro-busca-grupo"
                        v-model="busca"
                        type="search"
                        placeholder="Nome do grupo"
                        class="border-input bg-background h-11 w-full rounded-md border px-3 text-sm"
                    />
                </div>

                <div class="w-full space-y-1 sm:w-64">
                    <label for="filtro-setor-grupo" class="block text-sm font-medium">Setor</label>
                    <select id="filtro-setor-grupo" v-model="setorId" class="border-input bg-background h-11 w-full rounded-md border px-3 text-sm">
                        <option value="todos">Todos</option>
                        <option v-for="cidade in props.cidades" :key="cidade.id" :value="String(cidade.id)">
                            {{ cidade.nome }}
                        </option>
                    </select>
                </div>

                <div class="w-full space-y-1 sm:w-44">
                    <label for="filtro-situacao-grupo" class="block text-sm font-medium">Situação</label>
                    <select
                        id="filtro-situacao-grupo"
                        v-model="situacao"
                        class="border-input bg-background h-11 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="todos">Todas</option>
                        <option value="ativos">Ativos</option>
                        <option value="desativados">Desativados</option>
                    </select>
                </div>

                <button
                    v-if="filtroEstaAtivo"
                    type="button"
                    class="border-border h-11 rounded-md border px-4 text-sm font-medium"
                    @click="limparFiltro"
                >
                    Limpar filtros
                </button>
            </div>
        </PainelDeFiltros>

        <section aria-labelledby="titulo-lista-grupos" class="border-border rounded-lg border">
            <h2 id="titulo-lista-grupos" class="border-border border-b px-4 py-3 text-lg font-semibold">Grupos cadastrados</h2>

            <p v-if="props.grupos.length === 0" class="text-muted-foreground px-4 py-6 text-sm">Nenhum grupo cadastrado ainda.</p>

            <p v-else-if="gruposFiltrados.length === 0" class="text-muted-foreground px-4 py-6 text-sm" role="status">
                Nenhum grupo combina com o filtro. <button type="button" class="text-acao-texto font-medium" @click="limparFiltro">Limpar</button>
            </p>

            <table v-else class="w-full text-sm">
                <caption class="sr-only">
                    Grupos de participantes, com o setor, a situação e quantas inscrições apontam para cada um.
                </caption>
                <thead>
                    <tr class="border-border border-b text-left">
                        <th scope="col" class="px-4 py-2 font-medium">Grupo</th>
                        <th scope="col" class="px-4 py-2 font-medium">Setor</th>
                        <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-2 font-medium">Inscrições</th>
                        <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="grupo in gruposFiltrados" :key="grupo.id" class="border-border border-b last:border-0">
                        <th scope="row" class="px-4 py-2 text-left font-normal">{{ grupo.nome }}</th>
                        <td class="px-4 py-2">{{ grupo.cidade }}</td>
                        <td class="px-4 py-2">
                            <EtiquetaDeSituacao dominio="ativo" :situacao="grupo.ativo" :rotulo="grupo.ativo ? 'Ativo' : 'Desativado'" />
                        </td>
                        <td class="px-4 py-2">{{ grupo.inscricoes }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editar(grupo)">Editar</BotaoDeAcao>

                                <template v-if="confirmandoExclusao === grupo.id">
                                    <span class="text-muted-foreground">Excluir mesmo?</span>
                                    <BotaoDeAcao tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluir(grupo)">
                                        Sim, excluir
                                    </BotaoDeAcao>
                                    <BotaoDeAcao tamanho="xs" @click="confirmandoExclusao = null">Não</BotaoDeAcao>
                                </template>
                                <BotaoDeAcao v-else tamanho="xs" intencao="excluir" :icone="Trash2" @click="confirmandoExclusao = grupo.id"
                                    >Excluir</BotaoDeAcao
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AdminLayout>
</template>

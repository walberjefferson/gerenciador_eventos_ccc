<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import PainelDeFiltros from '@/components/admin/PainelDeFiltros.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CidadeDoCatalogo } from '@/types/admin';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * O catálogo de setores.
 *
 * Uma tela só: o formulário em cima, a lista embaixo. Editar traz a linha para
 * o formulário em vez de abrir outra página — são poucos campos e ninguém
 * precisa perder a lista de vista.
 *
 * Setor em uso não é excluído. A tela diz isso antes de qualquer clique, e o
 * servidor recusa de novo se alguém insistir.
 *
 * O tipo continua sendo `CidadeDoCatalogo` e as rotas apontam para o
 * `CidadeController`: o renome para "setor" vale para o que a pessoa lê e para
 * o endereço, não para o banco nem para os nomes do código.
 */
const props = defineProps<{
    cidades: CidadeDoCatalogo[];
    ufs: string[];
    sucesso: string | null;
}>();

/**
 * O cadastro mora num MODAL, e nao numa faixa fixa no topo da tela.
 *
 * O formulario e usado de vez em quando; a lista, sempre. Ocupando o topo em
 * permanencia, ele empurrava para baixo justamente o que a pessoa veio ver — e
 * ainda por cima ficava ali vazio na maior parte das visitas.
 *
 * Fechar o modal DESFAZ a edicao em curso: quem fecha desistiu. Deixar o
 * formulario preenchido por baixo faria o proximo "Novo setor" abrir com os
 * dados de um setor que a pessoa achou que tinha abandonado.
 */
const modalAberto = ref(false);

const emEdicao = ref<CidadeDoCatalogo | null>(null);
const confirmandoExclusao = ref<number | null>(null);
const campoNome = ref<HTMLInputElement | null>(null);

const formulario = useForm({
    nome: '',
    // Todos os setores da comunidade são de Alagoas; o campo continua editável
    // porque a coluna é obrigatória e entra na chave única (nome, uf).
    uf: 'AL',
    ativo: true as boolean,
});

/**
 * O erro de exclusão não vem de um formulário: ele volta do servidor como erro
 * da página. É de lá que a tela o lê.
 */
const erroDeExclusao = computed<string | undefined>(() => usePage().props.errors?.exclusao);
const excluindo = ref(false);

const titulo = computed(() => (emEdicao.value === null ? 'Novo setor' : `Editando ${emEdicao.value.nome}`));

/**
 * O filtro da lista, feito AQUI e nao no servidor.
 *
 * A lista chega inteira do controller — sao poucos setores e nao ha paginacao.
 * Filtrar no navegador responde a cada tecla sem ida ao servidor; mandar o
 * filtro para o backend acrescentaria uma viagem de rede para reordenar uma
 * lista que ja esta na memoria.
 *
 * Sem acento e sem caixa: quem procura "olho dagua" precisa achar
 * "Olho d'água das Flores". `normalize('NFD')` separa a letra do acento, e o
 * `replace` joga o acento fora; o apostrofo tambem sai, porque ninguem digita
 * apostrofo para procurar.
 */
const busca = ref('');
const situacao = ref<'todos' | 'ativos' | 'desativados'>('todos');

function semAcento(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/['’]/g, '')
        .toLowerCase()
        .trim();
}

const cidadesFiltradas = computed<CidadeDoCatalogo[]>(() => {
    const termo = semAcento(busca.value);

    return props.cidades.filter((cidade) => {
        const combinaTexto = termo === '' || semAcento(cidade.nome).includes(termo) || semAcento(cidade.uf).includes(termo);

        const combinaSituacao =
            situacao.value === 'todos' || (situacao.value === 'ativos' && cidade.ativo) || (situacao.value === 'desativados' && !cidade.ativo);

        return combinaTexto && combinaSituacao;
    });
});

const filtroEstaAtivo = computed<boolean>(() => busca.value.trim() !== '' || situacao.value !== 'todos');

/** Quantos filtros estao pegando — o cabecalho do painel mostra isso recolhido. */
const quantosFiltrosAtivos = computed<number>(() => (busca.value.trim() === '' ? 0 : 1) + (situacao.value === 'todos' ? 0 : 1));

function limparFiltro(): void {
    busca.value = '';
    situacao.value = 'todos';
}

function abrirCadastro(): void {
    emEdicao.value = null;
    formulario.clearErrors();
    formulario.reset();
    modalAberto.value = true;
}

function editar(cidade: CidadeDoCatalogo): void {
    modalAberto.value = true;
    emEdicao.value = cidade;
    formulario.clearErrors();
    formulario.nome = cidade.nome;
    formulario.uf = cidade.uf;
    formulario.ativo = cidade.ativo;

    void nextTick(() => campoNome.value?.focus());
}

function cancelarEdicao(): void {
    emEdicao.value = null;
    formulario.clearErrors();
    formulario.reset();
    modalAberto.value = false;
}

/**
 * O modal so fecha sozinho quando o servidor ACEITOU. Se a validacao recusar,
 * ele fica aberto com a mensagem ao lado do campo — fechar levaria embora o
 * erro que a pessoa precisa ler.
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
        formulario.post(route('admin.catalogo.setores.store'), {
            preserveScroll: true,
            onSuccess: () => {
                formulario.reset();
                modalAberto.value = false;
            },
        });

        return;
    }

    formulario.put(route('admin.catalogo.setores.update', { setor: emEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarEdicao(),
    });
}

function excluir(cidade: CidadeDoCatalogo): void {
    excluindo.value = true;

    router.delete(route('admin.catalogo.setores.destroy', { setor: cidade.id }), {
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
        titulo="Setores"
        descricao="O catálogo de setores vale para todos os eventos. Setor em uso não pode ser excluído: desative-o para que ele pare de aparecer no formulário sem apagar o histórico de quem já se inscreveu."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p v-if="erroDeExclusao" role="alert" class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm">
            {{ erroDeExclusao }}
        </p>

        <div>
            <button
                type="button"
                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                @click="abrirCadastro"
            >
                Novo setor
            </button>
        </div>

        <Dialog :open="modalAberto" @update:open="aoTrocarAbertura">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ titulo }}</DialogTitle>
                    <DialogDescription>
                        O setor vale para todos os eventos. Desativado, ele deixa de aparecer no formulário de inscrição sem apagar nada.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="gravar">
                    <div class="flex flex-col gap-1">
                        <label for="setor-nome" class="text-sm font-medium">Nome do setor</label>
                        <input
                            id="setor-nome"
                            ref="campoNome"
                            v-model="formulario.nome"
                            type="text"
                            maxlength="120"
                            required
                            :aria-describedby="formulario.errors.nome ? 'erro-setor-nome' : undefined"
                            :aria-invalid="formulario.errors.nome ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.nome" id="erro-setor-nome" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.nome }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="setor-uf" class="text-sm font-medium">Estado</label>
                        <select
                            id="setor-uf"
                            v-model="formulario.uf"
                            :aria-describedby="formulario.errors.uf ? 'erro-setor-uf' : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option v-for="uf in props.ufs" :key="uf" :value="uf">{{ uf }}</option>
                        </select>
                        <p v-if="formulario.errors.uf" id="erro-setor-uf" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.uf }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="setor-ativo" v-model="formulario.ativo" type="checkbox" class="border-input size-4 rounded" />
                        <label for="setor-ativo" class="text-sm font-medium">Ativo</label>
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

        <PainelDeFiltros v-if="props.cidades.length > 1" id="filtros-setor" :ativos="quantosFiltrosAtivos">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full space-y-1 sm:w-56">
                    <label for="filtro-busca-setor" class="block text-sm font-medium">Buscar</label>
                    <input
                        id="filtro-busca-setor"
                        v-model="busca"
                        type="search"
                        placeholder="Nome do setor"
                        class="border-input bg-background h-11 w-full rounded-md border px-3 text-sm"
                    />
                </div>

                <div class="w-full space-y-1 sm:w-44">
                    <label for="filtro-situacao-setor" class="block text-sm font-medium">Situação</label>
                    <select
                        id="filtro-situacao-setor"
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

        <section aria-labelledby="titulo-lista-setores" class="border-border rounded-lg border">
            <h2 id="titulo-lista-setores" class="border-border border-b px-4 py-3 text-lg font-semibold">Setores cadastrados</h2>

            <p v-if="props.cidades.length === 0" class="text-muted-foreground px-4 py-6 text-sm">Nenhum setor cadastrado ainda.</p>

            <!-- Lista vazia POR CAUSA do filtro é outra coisa que lista vazia
                 de verdade: aqui existem setores, só nenhum que combine. Dizer
                 "nenhum setor cadastrado" faria a pessoa achar que perdeu
                 dados. -->
            <p v-else-if="cidadesFiltradas.length === 0" class="text-muted-foreground px-4 py-6 text-sm" role="status">
                Nenhum setor combina com o filtro. <button type="button" class="text-acao-texto font-medium" @click="limparFiltro">Limpar</button>
            </p>

            <table v-else class="w-full text-sm">
                <caption class="sr-only">
                    Setores do catálogo, com o estado, a situação e quantos grupos de participantes dependem de cada um.
                </caption>
                <thead>
                    <tr class="border-border border-b text-left">
                        <th scope="col" class="px-4 py-2 font-medium">Setor</th>
                        <th scope="col" class="px-4 py-2 font-medium">Estado</th>
                        <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-2 font-medium">Grupos</th>
                        <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="cidade in cidadesFiltradas" :key="cidade.id" class="border-border border-b last:border-0">
                        <th scope="row" class="px-4 py-2 text-left font-normal">{{ cidade.nome }}</th>
                        <td class="px-4 py-2">{{ cidade.uf }}</td>
                        <td class="px-4 py-2">
                            <EtiquetaDeSituacao dominio="ativo" :situacao="cidade.ativo" :rotulo="cidade.ativo ? 'Ativo' : 'Desativado'" />
                        </td>
                        <td class="px-4 py-2">{{ cidade.grupos }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editar(cidade)">Editar</BotaoDeAcao>

                                <template v-if="confirmandoExclusao === cidade.id">
                                    <span class="text-muted-foreground">Excluir mesmo?</span>
                                    <BotaoDeAcao tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluir(cidade)">
                                        Sim, excluir
                                    </BotaoDeAcao>
                                    <BotaoDeAcao tamanho="xs" @click="confirmandoExclusao = null">Não</BotaoDeAcao>
                                </template>
                                <BotaoDeAcao v-else tamanho="xs" intencao="excluir" :icone="Trash2" @click="confirmandoExclusao = cidade.id"
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

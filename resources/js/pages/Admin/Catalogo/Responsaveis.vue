<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import PainelDeFiltros from '@/components/admin/PainelDeFiltros.vue';
import CampoMascarado from '@/components/inscricao/CampoMascarado.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { mascararTelefone } from '@/lib/formato';
import type { ContaDoPainel, ResponsavelDoCatalogo, SetorParaVinculo } from '@/types/admin';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * O cadastro de quem recebe o Pix dos setores.
 *
 * No mesmo molde do catálogo de setores: o botão em cima, o formulário num
 * modal, a lista embaixo. Editar traz a linha para o modal em vez de abrir
 * outra página — são poucos campos e ninguém precisa perder a lista de vista.
 *
 * Duas coisas são ditas na tela antes de qualquer clique, porque são as duas
 * que surpreendem quem chega (RN-R1 e RN-R9): o responsável **pode existir sem
 * conta no painel** — ele recebe, mas não confere —, e quem já recebeu alguma
 * cobrança **não é excluído**; o caminho é desativar.
 */
const props = defineProps<{
    responsaveis: ResponsavelDoCatalogo[];
    setores: SetorParaVinculo[];
    contas: ContaDoPainel[];
    sucesso: string | null;
}>();

const modalAberto = ref(false);
const emEdicao = ref<ResponsavelDoCatalogo | null>(null);
const confirmandoExclusao = ref<number | null>(null);
const campoNome = ref<HTMLInputElement | null>(null);

const formulario = useForm({
    nome: '',
    // A chave é obrigatória: o cadastro existe para dizer para onde o dinheiro
    // vai, e sem ela a pessoa nasceria fora do sorteio (RN-R9).
    chave_pix: '',
    telefone: '',
    // A conta do painel é OPCIONAL. Nula quer dizer "recebe, mas não confere"
    // — o caso real do tesoureiro que não usa o sistema (RN-R1).
    user_id: null as number | null,
    ativo: true as boolean,
    setores: [] as number[],
});

/**
 * O erro de exclusão não vem de um formulário: ele volta do servidor como erro
 * da página. É de lá que a tela o lê.
 */
const erroDeExclusao = computed<string | undefined>(() => usePage().props.errors?.exclusao);
const excluindo = ref(false);

const titulo = computed(() => (emEdicao.value === null ? 'Novo responsável' : `Editando ${emEdicao.value.nome}`));

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

const responsaveisFiltrados = computed<ResponsavelDoCatalogo[]>(() => {
    const termo = semAcento(busca.value);

    return props.responsaveis.filter((pessoa) => {
        const combinaTexto = termo === '' || semAcento(pessoa.nome).includes(termo) || semAcento(pessoa.chave_pix).includes(termo);

        const combinaSituacao =
            situacao.value === 'todos' || (situacao.value === 'ativos' && pessoa.ativo) || (situacao.value === 'desativados' && !pessoa.ativo);

        return combinaTexto && combinaSituacao;
    });
});

const filtroEstaAtivo = computed<boolean>(() => busca.value.trim() !== '' || situacao.value !== 'todos');

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

function editar(pessoa: ResponsavelDoCatalogo): void {
    modalAberto.value = true;
    emEdicao.value = pessoa;
    formulario.clearErrors();
    formulario.nome = pessoa.nome;
    formulario.chave_pix = pessoa.chave_pix;
    formulario.telefone = pessoa.telefone ?? '';
    formulario.user_id = pessoa.user_id;
    formulario.ativo = pessoa.ativo;
    formulario.setores = pessoa.setores.map((setor) => setor.id);

    void nextTick(() => campoNome.value?.focus());
}

function cancelarEdicao(): void {
    emEdicao.value = null;
    formulario.clearErrors();
    formulario.reset();
    modalAberto.value = false;
}

/**
 * O modal só fecha sozinho quando o servidor ACEITOU. Se a validação recusar,
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
        formulario.post(route('admin.catalogo.responsaveis.store'), {
            preserveScroll: true,
            onSuccess: () => {
                formulario.reset();
                modalAberto.value = false;
            },
        });

        return;
    }

    formulario.put(route('admin.catalogo.responsaveis.update', { responsavel: emEdicao.value.id }), {
        preserveScroll: true,
        onSuccess: () => cancelarEdicao(),
    });
}

function excluir(pessoa: ResponsavelDoCatalogo): void {
    excluindo.value = true;

    router.delete(route('admin.catalogo.responsaveis.destroy', { responsavel: pessoa.id }), {
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
        titulo="Responsáveis"
        descricao="Quem recebe o Pix dos setores. Um responsável pode atender vários setores, e um setor pode ter vários responsáveis — a cada cobrança o sistema sorteia um deles, entre os que estiverem com menos inscrições no evento."
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
                Novo responsável
            </button>
        </div>

        <Dialog :open="modalAberto" @update:open="aoTrocarAbertura">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ titulo }}</DialogTitle>
                    <DialogDescription>
                        A chave Pix é da pessoa, e vale em todos os setores que ela atende. Desativado, o responsável sai do sorteio na hora — sem
                        mexer em nenhuma cobrança já emitida.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="gravar">
                    <div class="flex flex-col gap-1">
                        <label for="responsavel-nome" class="text-sm font-medium">Nome de quem recebe</label>
                        <input
                            id="responsavel-nome"
                            ref="campoNome"
                            v-model="formulario.nome"
                            type="text"
                            maxlength="120"
                            required
                            aria-describedby="ajuda-responsavel-nome"
                            :aria-invalid="formulario.errors.nome ? true : undefined"
                            data-testid="campo-nome"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p id="ajuda-responsavel-nome" class="text-muted-foreground text-sm">
                            É o nome que aparece no aplicativo de quem paga. Sem ele, a pessoa transfere para um nome que não reconhece.
                        </p>
                        <p v-if="formulario.errors.nome" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.nome }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="responsavel-chave" class="text-sm font-medium">Chave Pix</label>
                        <input
                            id="responsavel-chave"
                            v-model="formulario.chave_pix"
                            type="text"
                            maxlength="140"
                            required
                            aria-describedby="ajuda-responsavel-chave"
                            :aria-invalid="formulario.errors.chave_pix ? true : undefined"
                            data-testid="campo-chave-pix"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p id="ajuda-responsavel-chave" class="text-muted-foreground text-sm">
                            CPF, CNPJ, e-mail, telefone ou chave aleatória — do jeito que está no aplicativo do banco.
                        </p>
                        <p v-if="formulario.errors.chave_pix" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.chave_pix }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="responsavel-telefone" class="text-sm font-medium">Telefone</label>
                        <CampoMascarado
                            id="responsavel-telefone"
                            v-model="formulario.telefone"
                            :mascara="mascararTelefone"
                            type="tel"
                            inputmode="tel"
                            maxlength="40"
                            autocomplete="tel"
                            aria-describedby="ajuda-responsavel-telefone"
                            :aria-invalid="formulario.errors.telefone ? true : undefined"
                            data-testid="campo-telefone"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p id="ajuda-responsavel-telefone" class="text-muted-foreground text-sm">
                            Aparece na tela de pagamento de quem recebeu por esta chave, para tirar dúvidas. Opcional.
                        </p>
                        <p v-if="formulario.errors.telefone" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.telefone }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="responsavel-conta" class="text-sm font-medium">Conta do painel</label>
                        <select
                            id="responsavel-conta"
                            v-model="formulario.user_id"
                            aria-describedby="ajuda-responsavel-conta"
                            data-testid="campo-conta"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option :value="null">Sem conta — recebe, mas não confere</option>
                            <option v-for="conta in props.contas" :key="conta.id" :value="conta.id">{{ conta.nome }} ({{ conta.email }})</option>
                        </select>
                        <p id="ajuda-responsavel-conta" class="text-muted-foreground text-sm">
                            Só quem tem conta consegue entrar para conferir comprovantes. Quem não usa o sistema continua recebendo normalmente.
                        </p>
                        <p v-if="formulario.errors.user_id" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.user_id }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="responsavel-ativo" v-model="formulario.ativo" type="checkbox" class="border-input size-4 rounded" />
                        <label for="responsavel-ativo" class="text-sm font-medium">Ativo</label>
                    </div>

                    <fieldset class="border-border grid gap-3 rounded-md border p-3" data-testid="setores-do-responsavel">
                        <legend class="px-1 text-sm font-medium">Setores atendidos</legend>

                        <p class="text-muted-foreground text-sm">
                            A mesma chave vale para todos os setores marcados. Sem nenhum marcado, a pessoa fica cadastrada e simplesmente nunca é
                            sorteada.
                        </p>

                        <p v-if="props.setores.length === 0" class="text-muted-foreground text-sm">Nenhum setor cadastrado ainda.</p>

                        <div v-else class="grid gap-2">
                            <label
                                v-for="setor in props.setores"
                                :key="setor.id"
                                class="flex items-center gap-2 text-sm"
                                :data-testid="`setor-opcao-${setor.id}`"
                            >
                                <input v-model="formulario.setores" type="checkbox" :value="setor.id" class="border-input size-4 rounded" />
                                <span>{{ setor.nome }}/{{ setor.uf }}</span>
                                <span v-if="!setor.ativo" class="text-muted-foreground text-xs">desativado</span>
                            </label>
                        </div>

                        <p v-if="formulario.errors.setores" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.setores }}
                        </p>
                    </fieldset>

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

        <PainelDeFiltros v-if="props.responsaveis.length > 1" id="filtros-responsavel" :ativos="quantosFiltrosAtivos">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full space-y-1 sm:w-56">
                    <label for="filtro-busca-responsavel" class="block text-sm font-medium">Buscar</label>
                    <input
                        id="filtro-busca-responsavel"
                        v-model="busca"
                        type="search"
                        placeholder="Nome ou chave Pix"
                        class="border-input bg-background h-11 w-full rounded-md border px-3 text-sm"
                    />
                </div>

                <div class="w-full space-y-1 sm:w-44">
                    <label for="filtro-situacao-responsavel" class="block text-sm font-medium">Situação</label>
                    <select
                        id="filtro-situacao-responsavel"
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

        <section aria-labelledby="titulo-lista-responsaveis" class="border-border rounded-lg border">
            <h2 id="titulo-lista-responsaveis" class="border-border border-b px-4 py-3 text-lg font-semibold">Responsáveis cadastrados</h2>

            <p v-if="props.responsaveis.length === 0" class="text-muted-foreground px-4 py-6 text-sm">
                Nenhum responsável cadastrado ainda. Enquanto não houver um ativo e com chave em cada setor, nenhum evento pode receber pelo setor.
            </p>

            <!-- Lista vazia POR CAUSA do filtro é outra coisa que lista vazia
                 de verdade: aqui existem responsáveis, só nenhum que combine. -->
            <p v-else-if="responsaveisFiltrados.length === 0" class="text-muted-foreground px-4 py-6 text-sm" role="status">
                Nenhum responsável combina com o filtro.
                <button type="button" class="text-acao-texto font-medium" @click="limparFiltro">Limpar</button>
            </p>

            <table v-else class="w-full text-sm">
                <caption class="sr-only">
                    Responsáveis do catálogo, com a chave Pix, a conta do painel, os setores atendidos, se entram no sorteio e quantas cobranças já
                    apontaram para cada um.
                </caption>
                <thead>
                    <tr class="border-border border-b text-left">
                        <th scope="col" class="px-4 py-2 font-medium">Nome</th>
                        <th scope="col" class="px-4 py-2 font-medium">Chave Pix</th>
                        <th scope="col" class="px-4 py-2 font-medium">Conta</th>
                        <th scope="col" class="px-4 py-2 font-medium">Setores</th>
                        <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-2 font-medium">Entra no sorteio</th>
                        <th scope="col" class="px-4 py-2 font-medium">Cobranças</th>
                        <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="pessoa in responsaveisFiltrados" :key="pessoa.id" class="border-border border-b last:border-0">
                        <th scope="row" class="px-4 py-2 text-left font-normal">{{ pessoa.nome }}</th>
                        <td class="px-4 py-2 font-mono break-all" :data-testid="`chave-${pessoa.id}`">{{ pessoa.chave_pix }}</td>
                        <td class="px-4 py-2">
                            <span v-if="pessoa.conta_nome">{{ pessoa.conta_nome }}</span>
                            <!-- "Recebe, mas não confere" é uma situação normal,
                                 e não uma pendência: por isso ela é escrita,
                                 e não marcada com um alerta (RN-R1). -->
                            <span v-else class="text-muted-foreground">Sem conta</span>
                        </td>
                        <td class="px-4 py-2">
                            <span v-if="pessoa.setores.length > 0" :data-testid="`setores-${pessoa.id}`">
                                {{ pessoa.setores.map((setor) => setor.nome).join(', ') }}
                            </span>
                            <span v-else class="text-muted-foreground" :data-testid="`setores-${pessoa.id}`">—</span>
                        </td>
                        <td class="px-4 py-2">
                            <EtiquetaDeSituacao dominio="ativo" :situacao="pessoa.ativo" :rotulo="pessoa.ativo ? 'Ativo' : 'Desativado'" />
                        </td>
                        <td class="px-4 py-2">
                            <span v-if="pessoa.apto" class="text-sucesso-texto font-medium" :data-testid="`sorteio-${pessoa.id}`">Sim</span>
                            <span v-else class="text-muted-foreground inline-flex items-center gap-1" :data-testid="`sorteio-${pessoa.id}`">
                                <CircleAlert class="size-4" aria-hidden="true" />
                                Não
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ pessoa.cobrancas }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <BotaoDeAcao tamanho="xs" intencao="editar" :icone="Pencil" @click="editar(pessoa)">Editar</BotaoDeAcao>

                                <!-- Quem já recebeu não tem botão de excluir: a
                                     tela diz o caminho antes do clique, e o
                                     servidor recusa de novo se alguém insistir
                                     (RN-R9). -->
                                <span v-if="pessoa.cobrancas > 0" class="text-muted-foreground text-xs" :data-testid="`sem-exclusao-${pessoa.id}`">
                                    Já recebeu — desative em vez de excluir
                                </span>
                                <template v-else-if="confirmandoExclusao === pessoa.id">
                                    <span class="text-muted-foreground">Excluir mesmo?</span>
                                    <BotaoDeAcao tamanho="xs" intencao="excluir" :icone="Trash2" :disabled="excluindo" @click="excluir(pessoa)">
                                        Sim, excluir
                                    </BotaoDeAcao>
                                    <BotaoDeAcao tamanho="xs" @click="confirmandoExclusao = null">Não</BotaoDeAcao>
                                </template>
                                <BotaoDeAcao v-else tamanho="xs" intencao="excluir" :icone="Trash2" @click="confirmandoExclusao = pessoa.id"
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

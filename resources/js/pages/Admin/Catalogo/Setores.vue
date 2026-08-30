<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CidadeDoCatalogo } from '@/types/admin';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
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

function editar(cidade: CidadeDoCatalogo): void {
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
}

function gravar(): void {
    if (emEdicao.value === null) {
        formulario.post(route('admin.catalogo.setores.store'), {
            preserveScroll: true,
            onSuccess: () => formulario.reset(),
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
        <p v-if="props.sucesso" role="status" class="rounded-md border border-border bg-muted/40 px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p
            v-if="erroDeExclusao"
            role="alert"
            class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-2 text-sm text-destructive"
        >
            {{ erroDeExclusao }}
        </p>

        <section aria-labelledby="titulo-formulario-setor" class="rounded-lg border border-border p-4">
            <h2 id="titulo-formulario-setor" class="text-lg font-semibold">{{ titulo }}</h2>

            <form class="mt-4 grid gap-4 md:grid-cols-[1fr_8rem_auto_auto]" @submit.prevent="gravar">
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
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p
                        v-if="formulario.errors.nome"
                        id="erro-setor-nome"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formulario.errors.nome }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="setor-uf" class="text-sm font-medium">Estado</label>
                    <select
                        id="setor-uf"
                        v-model="formulario.uf"
                        :aria-describedby="formulario.errors.uf ? 'erro-setor-uf' : undefined"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option v-for="uf in props.ufs" :key="uf" :value="uf">{{ uf }}</option>
                    </select>
                    <p v-if="formulario.errors.uf" id="erro-setor-uf" role="alert" class="text-sm text-destructive">
                        {{ formulario.errors.uf }}
                    </p>
                </div>

                <div class="flex items-end gap-2">
                    <input id="setor-ativo" v-model="formulario.ativo" type="checkbox" class="size-4 rounded border-input" />
                    <label for="setor-ativo" class="pb-2 text-sm font-medium">Ativo</label>
                </div>

                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        :disabled="formulario.processing"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        {{ emEdicao === null ? 'Cadastrar' : 'Salvar' }}
                    </button>
                    <button
                        v-if="emEdicao !== null"
                        type="button"
                        class="h-10 rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        @click="cancelarEdicao"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section aria-labelledby="titulo-lista-setores" class="rounded-lg border border-border">
            <h2 id="titulo-lista-setores" class="border-b border-border px-4 py-3 text-lg font-semibold">
                Setores cadastrados
            </h2>

            <p v-if="props.cidades.length === 0" class="px-4 py-6 text-sm text-muted-foreground">
                Nenhum setor cadastrado ainda.
            </p>

            <table v-else class="w-full text-sm">
                <caption class="sr-only">
                    Setores do catálogo, com o estado, a situação e quantos grupos de participantes dependem de cada um.
                </caption>
                <thead>
                    <tr class="border-b border-border text-left">
                        <th scope="col" class="px-4 py-2 font-medium">Setor</th>
                        <th scope="col" class="px-4 py-2 font-medium">Estado</th>
                        <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-2 font-medium">Grupos</th>
                        <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="cidade in props.cidades" :key="cidade.id" class="border-b border-border last:border-0">
                        <th scope="row" class="px-4 py-2 text-left font-normal">{{ cidade.nome }}</th>
                        <td class="px-4 py-2">{{ cidade.uf }}</td>
                        <td class="px-4 py-2">{{ cidade.ativo ? 'Ativo' : 'Desativado' }}</td>
                        <td class="px-4 py-2">{{ cidade.grupos }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                    @click="editar(cidade)"
                                >
                                    Editar
                                </button>

                                <template v-if="confirmandoExclusao === cidade.id">
                                    <span class="text-muted-foreground">Excluir mesmo?</span>
                                    <button
                                        type="button"
                                        :disabled="excluindo"
                                        class="rounded-md border border-destructive px-3 py-1 text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                                        @click="excluir(cidade)"
                                    >
                                        Sim, excluir
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                        @click="confirmandoExclusao = null"
                                    >
                                        Não
                                    </button>
                                </template>
                                <button
                                    v-else
                                    type="button"
                                    class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                    @click="confirmandoExclusao = cidade.id"
                                >
                                    Excluir
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AdminLayout>
</template>

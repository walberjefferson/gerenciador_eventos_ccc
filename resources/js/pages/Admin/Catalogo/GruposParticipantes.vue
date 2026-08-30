<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { GrupoDoCatalogo, OpcaoDeCidade } from '@/types/admin';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
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

function editar(grupo: GrupoDoCatalogo): void {
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
}

function gravar(): void {
    if (emEdicao.value === null) {
        formulario.post(route('admin.catalogo.grupos-participantes.store'), {
            preserveScroll: true,
            onSuccess: () => formulario.reset(),
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

        <p v-if="props.cidades.length === 0" class="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm">
            Cadastre ao menos um setor antes de criar grupos de participantes.
        </p>

        <section v-else aria-labelledby="titulo-formulario-grupo" class="rounded-lg border border-border p-4">
            <h2 id="titulo-formulario-grupo" class="text-lg font-semibold">{{ titulo }}</h2>

            <form class="mt-4 grid gap-4 md:grid-cols-[1fr_1fr_auto_auto]" @submit.prevent="gravar">
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
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p
                        v-if="formulario.errors.nome"
                        id="erro-grupo-nome"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formulario.errors.nome }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="grupo-setor" class="text-sm font-medium">Setor</label>
                    <select
                        id="grupo-setor"
                        v-model="formulario.cidade_id"
                        :aria-describedby="formulario.errors.cidade_id ? 'erro-grupo-setor' : undefined"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option v-for="cidade in props.cidades" :key="cidade.id" :value="cidade.id">
                            {{ cidade.nome }}{{ cidade.ativo ? '' : ' (desativado)' }}
                        </option>
                    </select>
                    <p v-if="formulario.errors.cidade_id" id="erro-grupo-setor" role="alert" class="text-sm text-destructive">
                        {{ formulario.errors.cidade_id }}
                    </p>
                </div>

                <div class="flex items-end gap-2">
                    <input id="grupo-ativo" v-model="formulario.ativo" type="checkbox" class="size-4 rounded border-input" />
                    <label for="grupo-ativo" class="pb-2 text-sm font-medium">Ativo</label>
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

        <section aria-labelledby="titulo-lista-grupos" class="rounded-lg border border-border">
            <h2 id="titulo-lista-grupos" class="border-b border-border px-4 py-3 text-lg font-semibold">
                Grupos cadastrados
            </h2>

            <p v-if="props.grupos.length === 0" class="px-4 py-6 text-sm text-muted-foreground">
                Nenhum grupo cadastrado ainda.
            </p>

            <table v-else class="w-full text-sm">
                <caption class="sr-only">
                    Grupos de participantes, com o setor, a situação e quantas inscrições apontam para cada um.
                </caption>
                <thead>
                    <tr class="border-b border-border text-left">
                        <th scope="col" class="px-4 py-2 font-medium">Grupo</th>
                        <th scope="col" class="px-4 py-2 font-medium">Setor</th>
                        <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-2 font-medium">Inscrições</th>
                        <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="grupo in props.grupos" :key="grupo.id" class="border-b border-border last:border-0">
                        <th scope="row" class="px-4 py-2 text-left font-normal">{{ grupo.nome }}</th>
                        <td class="px-4 py-2">{{ grupo.cidade }}</td>
                        <td class="px-4 py-2">{{ grupo.ativo ? 'Ativo' : 'Desativado' }}</td>
                        <td class="px-4 py-2">{{ grupo.inscricoes }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                    @click="editar(grupo)"
                                >
                                    Editar
                                </button>

                                <template v-if="confirmandoExclusao === grupo.id">
                                    <span class="text-muted-foreground">Excluir mesmo?</span>
                                    <button
                                        type="button"
                                        :disabled="excluindo"
                                        class="rounded-md border border-destructive px-3 py-1 text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                                        @click="excluir(grupo)"
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
                                    @click="confirmandoExclusao = grupo.id"
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

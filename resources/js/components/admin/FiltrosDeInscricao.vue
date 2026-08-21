<script setup lang="ts">
import type { FiltrosAplicados, OpcoesDeFiltro } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * Os filtros da lista de inscrições.
 *
 * Todos se combinam: escolher o evento e a situação e a cidade vai estreitando
 * o resultado, e o endereço da página guarda tudo — assim a segunda página é
 * mesmo a continuação do que se estava vendo, e o link pode ser mandado para
 * outra pessoa.
 *
 * A busca por texto olha nome, e-mail e código público. **CPF não entra**: ele
 * é guardado cifrado e procurar por um pedaço dele é impossível de propósito.
 */
const props = defineProps<{
    filtros: FiltrosAplicados;
    opcoes: OpcoesDeFiltro;
}>();

const campos = reactive<FiltrosAplicados>({ ...props.filtros });

function aplicar(): void {
    const parametros: Record<string, string> = {};

    for (const [chave, valor] of Object.entries(campos)) {
        if (valor !== null && valor !== '') {
            parametros[chave] = String(valor);
        }
    }

    router.get(route('admin.inscricoes.index'), parametros, { preserveState: true, preserveScroll: true });
}

function limpar(): void {
    for (const chave of Object.keys(campos) as (keyof FiltrosAplicados)[]) {
        campos[chave] = null;
    }

    router.get(route('admin.inscricoes.index'));
}
</script>

<template>
    <form aria-labelledby="titulo-filtros" class="grid gap-4 rounded-lg border border-border p-4" @submit.prevent="aplicar">
        <h2 id="titulo-filtros" class="text-lg font-semibold">Filtros</h2>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="flex flex-col gap-1">
                <label for="filtro-busca" class="text-sm font-medium">Buscar</label>
                <input
                    id="filtro-busca"
                    v-model="campos.busca"
                    type="search"
                    aria-describedby="ajuda-filtro-busca"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <p id="ajuda-filtro-busca" class="text-sm text-muted-foreground">Nome, e-mail ou código da inscrição. O CPF não é buscável.</p>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-evento" class="text-sm font-medium">Evento</label>
                <select
                    id="filtro-evento"
                    v-model="campos.evento_id"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <option :value="null">Todos</option>
                    <option v-for="evento in props.opcoes.eventos" :key="evento.id" :value="String(evento.id)">{{ evento.nome }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-situacao" class="text-sm font-medium">Situação da inscrição</label>
                <select
                    id="filtro-situacao"
                    v-model="campos.situacao"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <option :value="null">Todas</option>
                    <option v-for="situacao in props.opcoes.situacoes" :key="situacao.valor" :value="situacao.valor">
                        {{ situacao.rotulo }}
                    </option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-cidade" class="text-sm font-medium">Cidade</label>
                <select
                    id="filtro-cidade"
                    v-model="campos.cidade_id"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <option :value="null">Todas</option>
                    <option v-for="cidade in props.opcoes.cidades" :key="cidade.id" :value="String(cidade.id)">{{ cidade.nome }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-grupo" class="text-sm font-medium">Grupo de participantes</label>
                <select
                    id="filtro-grupo"
                    v-model="campos.grupo_participante_id"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <option :value="null">Todos</option>
                    <option v-for="grupo in props.opcoes.grupos" :key="grupo.id" :value="String(grupo.id)">{{ grupo.nome }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-atividade" class="text-sm font-medium">Atividade escolhida</label>
                <select
                    id="filtro-atividade"
                    v-model="campos.atividade_id"
                    :disabled="props.opcoes.atividades.length === 0"
                    aria-describedby="ajuda-filtro-atividade"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                >
                    <option :value="null">Todas</option>
                    <option v-for="atividade in props.opcoes.atividades" :key="atividade.id" :value="String(atividade.id)">
                        {{ atividade.nome }}
                    </option>
                </select>
                <p id="ajuda-filtro-atividade" class="text-sm text-muted-foreground">Escolha um evento primeiro para filtrar por atividade.</p>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-pagamento" class="text-sm font-medium">Situação da cobrança</label>
                <select
                    id="filtro-pagamento"
                    v-model="campos.situacao_pagamento"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <option :value="null">Todas</option>
                    <option v-for="situacao in props.opcoes.situacoes_pagamento" :key="situacao.valor" :value="situacao.valor">
                        {{ situacao.rotulo }}
                    </option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-de" class="text-sm font-medium">Inscrita a partir de</label>
                <input
                    id="filtro-de"
                    v-model="campos.criada_de"
                    type="date"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>

            <div class="flex flex-col gap-1">
                <label for="filtro-ate" class="text-sm font-medium">Inscrita até</label>
                <input
                    id="filtro-ate"
                    v-model="campos.criada_ate"
                    type="date"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                type="submit"
                class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                Filtrar
            </button>
            <button
                type="button"
                class="h-10 rounded-md border border-border px-4 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="limpar"
            >
                Limpar filtros
            </button>
        </div>
    </form>
</template>

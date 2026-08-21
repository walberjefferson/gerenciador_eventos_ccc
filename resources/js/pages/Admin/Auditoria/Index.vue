<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { FiltrosDeAuditoria, OpcoesDeAuditoria, PaginaDeAuditoria } from '@/types/auditoria';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * O rastro das ações administrativas.
 *
 * **Esta tela só lê.** Não há botão de editar nem de apagar, e isso não é
 * economia de trabalho: registro de auditoria que pode ser corrigido depois
 * não prova nada. O próprio sistema recusa qualquer alteração nessas linhas.
 *
 * Quem chega aqui normalmente está atrás de uma pergunta concreta — "quem
 * cancelou aquela inscrição?", "quem confirmou aquele pagamento na mão?" —,
 * então a lista vem do mais recente para o mais antigo e os filtros são os
 * três que respondem a essas perguntas: quando, quem e o quê.
 */
const props = defineProps<{
    registros: PaginaDeAuditoria;
    filtros: FiltrosDeAuditoria;
    opcoes: OpcoesDeAuditoria;
}>();

const campos = reactive<FiltrosDeAuditoria>({ ...props.filtros });

function aplicar(): void {
    const parametros: Record<string, string> = {};

    for (const [chave, valor] of Object.entries(campos)) {
        if (valor !== null && valor !== '') {
            parametros[chave] = String(valor);
        }
    }

    router.get(route('admin.auditoria'), parametros, { preserveState: true, preserveScroll: true });
}

function limpar(): void {
    for (const chave of Object.keys(campos) as (keyof FiltrosDeAuditoria)[]) {
        campos[chave] = null;
    }

    router.get(route('admin.auditoria'));
}

const resumo = computed(() => {
    if (props.registros.total === 0) {
        return 'Nenhum registro encontrado com esses filtros.';
    }

    const primeira = (props.registros.pagina_atual - 1) * props.registros.por_pagina + 1;
    const ultima = primeira + props.registros.dados.length - 1;

    return `Mostrando ${primeira} a ${ultima} de ${props.registros.total} registro(s).`;
});

/**
 * O conteúdo da coluna "o que mudou", em uma linha legível.
 *
 * O registro guarda o antes/depois em formato de dados; despejar isso cru na
 * tela obrigaria quem lê a decifrar chaves e colchetes. Aqui vira uma frase
 * curta por campo alterado.
 */
function descreverDados(dados: Record<string, unknown> | null): string {
    if (dados === null || Object.keys(dados).length === 0) {
        return '—';
    }

    return Object.entries(dados)
        .map(([campo, valor]) => `${campo}: ${formatarValor(valor)}`)
        .join(' · ');
}

function formatarValor(valor: unknown): string {
    if (valor === null || valor === undefined) {
        return 'vazio';
    }

    if (Array.isArray(valor)) {
        return valor.map((item) => formatarValor(item)).join(', ');
    }

    if (typeof valor === 'object') {
        const registro = valor as Record<string, unknown>;

        if ('de' in registro || 'para' in registro) {
            return `de ${formatarValor(registro.de)} para ${formatarValor(registro.para)}`;
        }

        return Object.entries(registro)
            .map(([chave, item]) => `${chave} ${formatarValor(item)}`)
            .join(', ');
    }

    if (typeof valor === 'boolean') {
        return valor ? 'sim' : 'não';
    }

    return String(valor);
}
</script>

<template>
    <AdminLayout
        titulo="Auditoria"
        descricao="Tudo o que foi feito no painel e mexe em vaga, em dinheiro ou em acesso fica registrado aqui. A lista é só de leitura: nem esta tela, nem qualquer outra, consegue alterar ou apagar um registro."
    >
        <form aria-labelledby="titulo-filtros-auditoria" class="grid gap-4 rounded-lg border border-border p-4" @submit.prevent="aplicar">
            <h2 id="titulo-filtros-auditoria" class="text-lg font-semibold">Filtros</h2>

            <div class="grid gap-4 md:grid-cols-4">
                <div class="flex flex-col gap-1">
                    <label for="auditoria-de" class="text-sm font-medium">A partir de</label>
                    <input
                        id="auditoria-de"
                        v-model="campos.de"
                        type="date"
                        data-testid="auditoria-filtro-de"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </div>

                <div class="flex flex-col gap-1">
                    <label for="auditoria-ate" class="text-sm font-medium">Até</label>
                    <input
                        id="auditoria-ate"
                        v-model="campos.ate"
                        type="date"
                        data-testid="auditoria-filtro-ate"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </div>

                <div class="flex flex-col gap-1">
                    <label for="auditoria-usuario" class="text-sm font-medium">Quem fez</label>
                    <select
                        id="auditoria-usuario"
                        v-model="campos.usuario_id"
                        data-testid="auditoria-filtro-usuario"
                        aria-describedby="ajuda-auditoria-usuario"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option :value="null">Todos</option>
                        <option v-for="usuario in props.opcoes.usuarios" :key="usuario.id" :value="String(usuario.id)">
                            {{ usuario.nome }}
                        </option>
                    </select>
                    <p id="ajuda-auditoria-usuario" class="text-sm text-muted-foreground">
                        Só aparecem aqui as pessoas que já fizeram alguma coisa registrada.
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="auditoria-acao" class="text-sm font-medium">O que foi feito</label>
                    <select
                        id="auditoria-acao"
                        v-model="campos.acao"
                        data-testid="auditoria-filtro-acao"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option :value="null">Tudo</option>
                        <option v-for="acao in props.opcoes.acoes" :key="acao.valor" :value="acao.valor">{{ acao.rotulo }}</option>
                    </select>
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

        <p role="status" class="text-sm text-muted-foreground">{{ resumo }}</p>

        <div v-if="props.registros.dados.length > 0" class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-sm" data-testid="tabela-auditoria">
                <caption class="sr-only">Registros de auditoria, do mais recente para o mais antigo</caption>
                <thead class="bg-muted/40">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Quando</th>
                        <th scope="col" class="px-4 py-3 font-medium">Quem fez</th>
                        <th scope="col" class="px-4 py-3 font-medium">O que foi feito</th>
                        <th scope="col" class="px-4 py-3 font-medium">Sobre o quê</th>
                        <th scope="col" class="px-4 py-3 font-medium">Motivo</th>
                        <th scope="col" class="px-4 py-3 font-medium">O que mudou</th>
                        <th scope="col" class="px-4 py-3 font-medium">Origem</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="registro in props.registros.dados" :key="registro.id" class="border-t border-border align-top">
                        <td class="whitespace-nowrap px-4 py-3">{{ registro.quando ?? '—' }}</td>
                        <td class="px-4 py-3">{{ registro.responsavel }}</td>
                        <td class="px-4 py-3">{{ registro.acao_rotulo }}</td>
                        <td class="px-4 py-3">
                            {{ registro.entidade }}<span v-if="registro.entidade_id !== null"> #{{ registro.entidade_id }}</span>
                        </td>
                        <td class="px-4 py-3">{{ registro.motivo ?? '—' }}</td>
                        <td class="px-4 py-3">{{ descreverDados(registro.dados) }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ registro.ip ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="props.registros.ultima_pagina > 1" aria-label="Paginação do registro de auditoria" class="flex items-center gap-3">
            <Link
                v-if="props.registros.links.anterior"
                :href="props.registros.links.anterior"
                preserve-scroll
                class="h-10 rounded-md border border-border px-4 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                Página anterior
            </Link>

            <span class="text-sm text-muted-foreground"> Página {{ props.registros.pagina_atual }} de {{ props.registros.ultima_pagina }} </span>

            <Link
                v-if="props.registros.links.proxima"
                :href="props.registros.links.proxima"
                preserve-scroll
                class="h-10 rounded-md border border-border px-4 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                Próxima página
            </Link>
        </nav>
    </AdminLayout>
</template>

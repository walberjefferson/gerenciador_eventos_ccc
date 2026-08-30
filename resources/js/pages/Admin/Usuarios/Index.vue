<script setup lang="ts">
import PainelDeFiltros from '@/components/admin/PainelDeFiltros.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { FiltrosDeUsuarios, OpcoesDeUsuarios, PaginaDeUsuarios, UsuarioAdministrativo } from '@/types/admin';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * Quem entra no painel, com que papel, e até quando.
 *
 * **Esta tela governa contas; ela não as cria.** Não há botão de "nova conta":
 * conta administrativa nasce por `php artisan usuario:criar-administrador`,
 * rodado por quem já tem acesso ao servidor (D-51). E não há botão de excluir:
 * a auditoria guarda `usuario_id`, e apagar deixaria o histórico apontando
 * para o vazio.
 *
 * Duas decisões de desenho valem ser lidas antes de mexer aqui:
 *
 * 1. **A linha de quem está olhando é visivelmente diferente** — marcada como
 *    "você", com as ações desabilitadas e o motivo escrito ao lado. Ação
 *    desabilitada sem explicação é pior do que ação ausente: quem vê o botão
 *    apagado fica achando que o sistema quebrou.
 * 2. **Trocar de papel é um clique; desativar alguém, dois.** Trocar de papel
 *    se desfaz trocando de volta. Desativar tira o acesso de uma pessoa na
 *    hora, e isso não pode acontecer por clique errado.
 */
const props = defineProps<{
    usuarios: PaginaDeUsuarios;
    filtros: FiltrosDeUsuarios;
    opcoes: OpcoesDeUsuarios;
    sucesso: string | null;
}>();

const campos = reactive<FiltrosDeUsuarios>({ ...props.filtros });

/** Qual linha está com a pergunta "desativar mesmo?" aberta. */
const confirmandoDesativacao = ref<number | null>(null);

/** Enquanto o servidor responde, os botões da linha ficam fora do alcance. */
const emAndamento = ref<number | null>(null);

/**
 * Quantos filtros vieram APLICADOS do servidor — e não quantos estão digitados.
 * Quem preencheu um campo e ainda não clicou em "Filtrar" não mudou a lista.
 */
const filtrosAtivos = computed<number>(
    () => Object.values(props.filtros).filter((valor) => valor !== null && valor !== '' && valor !== undefined).length,
);

/**
 * As recusas do servidor. Elas não vêm de um formulário: chegam como erro da
 * página, do mesmo jeito que a recusa de exclusão do catálogo.
 */
const erro = computed<string | undefined>(() => {
    const erros = usePage().props.errors;

    return erros?.papel ?? erros?.situacao;
});

const resumo = computed<string>(() => {
    if (props.usuarios.total === 0) {
        return 'Nenhuma conta encontrada com esses filtros.';
    }

    const primeira = (props.usuarios.pagina_atual - 1) * props.usuarios.por_pagina + 1;
    const ultima = primeira + props.usuarios.dados.length - 1;

    return `Mostrando ${primeira} a ${ultima} de ${props.usuarios.total} conta(s).`;
});

function aplicar(): void {
    const parametros: Record<string, string> = {};

    for (const [chave, valor] of Object.entries(campos)) {
        if (valor !== null && valor !== '') {
            parametros[chave] = String(valor);
        }
    }

    router.get(route('admin.usuarios.index'), parametros, { preserveState: true, preserveScroll: true });
}

function limpar(): void {
    for (const chave of Object.keys(campos) as (keyof FiltrosDeUsuarios)[]) {
        campos[chave] = null;
    }

    router.get(route('admin.usuarios.index'));
}

/**
 * O seletor não guarda estado próprio (`:value`, e não `v-model`): quando o
 * servidor recusa a troca, a lista volta com o papel de antes e o campo
 * acompanha. Um `v-model` continuaria mostrando o papel que não foi gravado.
 */
function trocarPapel(usuario: UsuarioAdministrativo, evento: Event): void {
    const papel = (evento.target as HTMLSelectElement).value;

    if (papel === usuario.papel) {
        return;
    }

    emAndamento.value = usuario.id;

    router.put(
        route('admin.usuarios.papel', { usuario: usuario.id }),
        { papel },
        {
            preserveScroll: true,
            onFinish: () => {
                emAndamento.value = null;
            },
        },
    );
}

function trocarSituacao(usuario: UsuarioAdministrativo, ativo: boolean): void {
    emAndamento.value = usuario.id;

    router.put(
        route('admin.usuarios.situacao', { usuario: usuario.id }),
        { ativo },
        {
            preserveScroll: true,
            onFinish: () => {
                emAndamento.value = null;
                confirmandoDesativacao.value = null;
            },
        },
    );
}

/** O rótulo do papel, com a inicial maiúscula, sem inventar nome nenhum. */
function rotuloDoPapel(papel: string | null): string {
    if (papel === null) {
        return 'Sem papel';
    }

    return props.opcoes.papeis.find((opcao) => opcao.valor === papel)?.rotulo ?? papel;
}
</script>

<template>
    <AdminLayout
        titulo="Usuários"
        descricao="Quem entra no painel, com que papel, e até quando. As contas nascem pela linha de comando e não são excluídas: quem sai da organização é desativado, e o histórico do que essa pessoa fez continua de pé."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p v-if="erro" role="alert" data-testid="usuarios-recusa" class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm">
            {{ erro }}
        </p>

        <PainelDeFiltros id="filtros-usuarios" :ativos="filtrosAtivos">
            <form aria-labelledby="titulo-filtros-usuarios" class="grid gap-4" @submit.prevent="aplicar">
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="flex flex-col gap-1">
                        <label for="usuarios-busca" class="text-sm font-medium">Nome ou e-mail</label>
                        <input
                            id="usuarios-busca"
                            v-model="campos.busca"
                            type="search"
                            data-testid="usuarios-filtro-busca"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="usuarios-papel" class="text-sm font-medium">Papel</label>
                        <select
                            id="usuarios-papel"
                            v-model="campos.papel"
                            data-testid="usuarios-filtro-papel"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option :value="null">Todos</option>
                            <option v-for="papel in props.opcoes.papeis" :key="papel.valor" :value="papel.valor">{{ papel.rotulo }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="usuarios-situacao" class="text-sm font-medium">Situação</label>
                        <select
                            id="usuarios-situacao"
                            v-model="campos.situacao"
                            data-testid="usuarios-filtro-situacao"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option :value="null">Todas</option>
                            <option value="ativos">Ativo</option>
                            <option value="desativados">Desativado</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        Filtrar
                    </button>
                    <button
                        type="button"
                        class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        @click="limpar"
                    >
                        Limpar filtros
                    </button>
                </div>
            </form>
        </PainelDeFiltros>

        <p role="status" class="text-muted-foreground text-sm">{{ resumo }}</p>

        <div v-if="props.usuarios.dados.length > 0" class="border-border overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm" data-testid="tabela-usuarios">
                <caption class="sr-only">
                    Contas administrativas, em ordem alfabética
                </caption>
                <thead class="bg-muted/40">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Nome</th>
                        <th scope="col" class="px-4 py-3 font-medium">E-mail</th>
                        <th scope="col" class="px-4 py-3 font-medium">Papel</th>
                        <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-3 font-medium">Entrou em</th>
                        <th scope="col" class="px-4 py-3 font-medium">Acesso</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="usuario in props.usuarios.dados"
                        :key="usuario.id"
                        :class="usuario.sou_eu ? 'border-border bg-muted/40 border-t align-top' : 'border-border border-t align-top'"
                        :data-testid="usuario.sou_eu ? 'linha-de-voce' : 'linha-de-usuario'"
                    >
                        <th scope="row" class="px-4 py-3 text-left font-medium">
                            {{ usuario.nome }}
                            <span
                                v-if="usuario.sou_eu"
                                class="bg-secondary text-secondary-foreground ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                            >
                                você
                            </span>
                        </th>
                        <td class="px-4 py-3">{{ usuario.email }}</td>
                        <td class="px-4 py-3">
                            <span v-if="usuario.sou_eu">{{ rotuloDoPapel(usuario.papel) }}</span>
                            <template v-else>
                                <label :for="`papel-${usuario.id}`" class="sr-only">Papel de {{ usuario.nome }}</label>
                                <select
                                    :id="`papel-${usuario.id}`"
                                    :value="usuario.papel ?? ''"
                                    :disabled="emAndamento === usuario.id"
                                    :data-testid="`papel-de-${usuario.id}`"
                                    class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                                    @change="trocarPapel(usuario, $event)"
                                >
                                    <option v-if="usuario.papel === null" value="">Sem papel</option>
                                    <option v-for="papel in props.opcoes.papeis" :key="papel.valor" :value="papel.valor">
                                        {{ papel.rotulo }}
                                    </option>
                                </select>
                            </template>
                        </td>
                        <td class="px-4 py-3">
                            <!-- A palavra escrita, e não só a cor: quem não
                                 distingue as duas cores precisa ler o estado
                                 (WCAG 1.4.1). -->
                            <span
                                :class="
                                    usuario.ativo
                                        ? 'bg-sucesso-suave text-sucesso-suave-foreground rounded-full px-2 py-0.5 text-xs font-medium'
                                        : 'bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs font-medium'
                                "
                            >
                                {{ usuario.ativo ? 'Ativo' : 'Desativado' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ usuario.entrou_em ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <!-- A própria linha não oferece ação nenhuma, e o
                                 motivo fica escrito: sem ele, o espaço vazio
                                 pareceria defeito. -->
                            <p v-if="usuario.sou_eu" class="text-muted-foreground max-w-xs text-sm">
                                Esta é a sua conta. Ninguém muda o próprio papel nem desativa a si mesmo — peça a outra pessoa com acesso de
                                administrador.
                            </p>

                            <div v-else class="flex flex-wrap items-center gap-2">
                                <template v-if="confirmandoDesativacao === usuario.id">
                                    <span class="text-muted-foreground">Desativar mesmo? Ela deixa de entrar na hora.</span>
                                    <button
                                        type="button"
                                        :disabled="emAndamento === usuario.id"
                                        :data-testid="`confirmar-desativacao-${usuario.id}`"
                                        class="border-destructive text-destructive focus-visible:ring-ring min-h-11 rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                                        @click="trocarSituacao(usuario, false)"
                                    >
                                        Sim, desativar
                                    </button>
                                    <button
                                        type="button"
                                        class="border-border focus-visible:ring-ring min-h-11 rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                        @click="confirmandoDesativacao = null"
                                    >
                                        Não
                                    </button>
                                </template>

                                <button
                                    v-else-if="usuario.ativo"
                                    type="button"
                                    :data-testid="`desativar-${usuario.id}`"
                                    class="border-border focus-visible:ring-ring min-h-11 rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                    @click="confirmandoDesativacao = usuario.id"
                                >
                                    Desativar
                                </button>

                                <button
                                    v-else
                                    type="button"
                                    :disabled="emAndamento === usuario.id"
                                    :data-testid="`reativar-${usuario.id}`"
                                    class="border-border focus-visible:ring-ring min-h-11 rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                                    @click="trocarSituacao(usuario, true)"
                                >
                                    Reativar
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="props.usuarios.ultima_pagina > 1" aria-label="Paginação da lista de usuários" class="flex items-center gap-3">
            <Link
                v-if="props.usuarios.links.anterior"
                :href="props.usuarios.links.anterior"
                preserve-scroll
                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Página anterior
            </Link>

            <span class="text-muted-foreground text-sm"> Página {{ props.usuarios.pagina_atual }} de {{ props.usuarios.ultima_pagina }} </span>

            <Link
                v-if="props.usuarios.links.proxima"
                :href="props.usuarios.links.proxima"
                preserve-scroll
                class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Próxima página
            </Link>
        </nav>

        <section class="border-border rounded-lg border p-4">
            <h2 class="text-lg font-semibold">O que cada papel alcança</h2>
            <p class="text-muted-foreground mt-1 max-w-3xl text-sm">
                A lista acima fala em "administrador" e "organizador". A tela de papéis mostra, permissão por permissão, o que cada um desses nomes
                quer dizer.
            </p>
            <Link
                :href="route('admin.papeis')"
                data-testid="ir-para-papeis"
                class="border-border focus-visible:ring-ring mt-3 inline-flex min-h-11 items-center rounded-md border px-4 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Ver a matriz de papéis
            </Link>
        </section>
    </AdminLayout>
</template>

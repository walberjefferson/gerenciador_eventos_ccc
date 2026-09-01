<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import PainelDeFiltros from '@/components/admin/PainelDeFiltros.vue';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { FiltrosDeUsuarios, OpcoesDeUsuarios, PaginaDeUsuarios, UsuarioAdministrativo } from '@/types/admin';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, Pencil, UserCheck, UserMinus } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

/**
 * Quem entra no painel, com que papel, e até quando.
 *
 * **Esta tela cadastra, edita e governa contas.** O cadastro pela tela entrou
 * depois, quando o dono do produto reverteu essa parte da D-51: quem responde
 * pelo sistema passou a montar a equipe sem depender de alguém com acesso ao
 * container. O comando `usuario:criar-administrador` continua existindo, e
 * continua sendo o único caminho para a PRIMEIRA conta — sem ninguém
 * cadastrado, não há quem abra esta tela.
 *
 * **Não há botão de excluir**, e a ausência é decisão: a auditoria guarda
 * `usuario_id`, e apagar deixaria o histórico apontando para o vazio. Quem sai
 * da equipe é desativado.
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

/**
 * O cadastro e a edição moram num MODAL, como no catálogo e na programação: o
 * formulário é eventual, a lista é o que se olha sempre.
 *
 * `emEdicao` guarda quem está sendo editado — nulo quer dizer conta nova. É ele
 * que decide o título, o texto do botão e se a senha é obrigatória.
 */
const modalAberto = ref(false);
const emEdicao = ref<UsuarioAdministrativo | null>(null);

const formulario = useForm({
    name: '',
    email: '',
    papel: props.opcoes.papeis[0]?.valor ?? '',
    password: '',
    password_confirmation: '',
});

function abrirCadastro(): void {
    emEdicao.value = null;
    formulario.clearErrors();
    formulario.reset();
    modalAberto.value = true;
}

function abrirEdicao(usuario: UsuarioAdministrativo): void {
    emEdicao.value = usuario;
    formulario.clearErrors();
    formulario.name = usuario.nome;
    formulario.email = usuario.email;
    formulario.papel = usuario.papel ?? props.opcoes.papeis[0]?.valor ?? '';
    // A senha nasce vazia na edição de propósito: em branco, ela não é tocada.
    // Preencher aqui obrigaria a inventar uma senha nova para corrigir um nome.
    formulario.password = '';
    formulario.password_confirmation = '';
    modalAberto.value = true;
}

/** Fechar desfaz o que estava sendo digitado: quem fechou desistiu. */
function aoTrocarAbertura(aberto: boolean): void {
    modalAberto.value = aberto;

    if (!aberto) {
        emEdicao.value = null;
        formulario.clearErrors();
        formulario.reset();
    }
}

function gravar(): void {
    const opcoes = {
        preserveScroll: true,
        // O modal só fecha quando o servidor ACEITA. Recusado — e-mail
        // repetido, senhas diferentes, papel que deixaria o sistema sem
        // administrador —, ele fica aberto com a mensagem ao lado do campo.
        onSuccess: () => aoTrocarAbertura(false),
    };

    if (emEdicao.value === null) {
        formulario.post(route('admin.usuarios.store'), opcoes);

        return;
    }

    formulario.put(route('admin.usuarios.update', { usuario: emEdicao.value.id }), opcoes);
}

/**
 * Manda o link de redefinição. É o caminho preferido para "não consigo entrar":
 * resolve sem que quem administra chegue a saber a senha de ninguém.
 */
function enviarRedefinicao(usuario: UsuarioAdministrativo): void {
    emAndamento.value = usuario.id;

    router.post(
        route('admin.usuarios.redefinir-senha', { usuario: usuario.id }),
        {},
        { preserveScroll: true, onFinish: () => (emAndamento.value = null) },
    );
}

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
        descricao="Quem entra no painel, com que papel, e até quando. Contas não são excluídas: quem sai da organização é desativado, e o histórico do que essa pessoa fez continua de pé."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p
            v-if="erro"
            role="alert"
            data-testid="usuarios-recusa"
            class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm"
        >
            {{ erro }}
        </p>

        <div>
            <button
                type="button"
                data-testid="nova-conta"
                class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                @click="abrirCadastro"
            >
                Nova conta
            </button>
        </div>

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
                            <Badge v-if="usuario.sou_eu" variant="secondary" class="ml-2">você</Badge>
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
                                 (WCAG 1.4.1). A cor vem do mapeamento central,
                                 e não mais de duas strings de classe escritas
                                 aqui — inclusive o cinza de "Desativado", que
                                 usava `muted-foreground` sobre `muted` e rendia
                                 4.39:1. -->
                            <EtiquetaDeSituacao dominio="ativo" :situacao="usuario.ativo" :rotulo="usuario.ativo ? 'Ativo' : 'Desativado'" />
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ usuario.entrou_em ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <!-- A própria linha não oferece ação nenhuma, e o
                                 motivo fica escrito: sem ele, o espaço vazio
                                 pareceria defeito. -->
                            <div v-if="usuario.sou_eu" class="flex flex-wrap items-center gap-2">
                                <!-- Editar a PRÓPRIA conta é permitido: corrigir
                                     o próprio nome ou e-mail não tranca ninguém
                                     para fora. O que continua barrado é mudar o
                                     próprio papel e desativar a si mesmo, e o
                                     motivo fica escrito — espaço vazio sem
                                     explicação pareceria defeito. -->
                                <BotaoDeAcao
                                    tamanho="compacto"
                                    intencao="editar"
                                    :icone="Pencil"
                                    :data-testid="`editar-${usuario.id}`"
                                    @click="abrirEdicao(usuario)"
                                >
                                    Editar
                                </BotaoDeAcao>

                                <p class="text-muted-foreground max-w-xs text-sm">
                                    Esta é a sua conta. Ninguém muda o próprio papel nem desativa a si mesmo — peça a outra pessoa com acesso de
                                    administrador.
                                </p>
                            </div>

                            <div v-else class="flex flex-wrap items-center gap-2">
                                <BotaoDeAcao
                                    tamanho="compacto"
                                    intencao="editar"
                                    :icone="Pencil"
                                    :data-testid="`editar-${usuario.id}`"
                                    @click="abrirEdicao(usuario)"
                                >
                                    Editar
                                </BotaoDeAcao>

                                <!-- O caminho preferido para "não consigo
                                     entrar": resolve sem que quem administra
                                     chegue a saber a senha de ninguém. -->
                                <BotaoDeAcao
                                    tamanho="compacto"
                                    :icone="KeyRound"
                                    :disabled="emAndamento === usuario.id"
                                    :data-testid="`redefinir-senha-${usuario.id}`"
                                    @click="enviarRedefinicao(usuario)"
                                >
                                    Enviar link de senha
                                </BotaoDeAcao>

                                <template v-if="confirmandoDesativacao === usuario.id">
                                    <span class="text-muted-foreground">Desativar mesmo? Ela deixa de entrar na hora.</span>
                                    <!-- O ícone é de "tirar do time", e não uma lixeira: nada
                                         aqui é apagado. Conta desativada continua no
                                         banco, e a auditoria continua apontando para
                                         ela. A cor é a de ação irreversível porque a
                                         pessoa deixa de entrar na hora. -->
                                    <BotaoDeAcao
                                        tamanho="compacto"
                                        intencao="excluir"
                                        :icone="UserMinus"
                                        :disabled="emAndamento === usuario.id"
                                        :data-testid="`confirmar-desativacao-${usuario.id}`"
                                        @click="trocarSituacao(usuario, false)"
                                    >
                                        Sim, desativar
                                    </BotaoDeAcao>
                                    <BotaoDeAcao tamanho="compacto" @click="confirmandoDesativacao = null">Não</BotaoDeAcao>
                                </template>

                                <BotaoDeAcao
                                    tamanho="compacto"
                                    v-else-if="usuario.ativo"
                                    intencao="excluir"
                                    :icone="UserMinus"
                                    :data-testid="`desativar-${usuario.id}`"
                                    @click="confirmandoDesativacao = usuario.id"
                                >
                                    Desativar
                                </BotaoDeAcao>

                                <BotaoDeAcao
                                    v-else
                                    tamanho="compacto"
                                    :icone="UserCheck"
                                    :disabled="emAndamento === usuario.id"
                                    :data-testid="`reativar-${usuario.id}`"
                                    @click="trocarSituacao(usuario, true)"
                                >
                                    Reativar
                                </BotaoDeAcao>
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

        <Dialog :open="modalAberto" @update:open="aoTrocarAbertura">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ emEdicao === null ? 'Nova conta' : `Editando ${emEdicao.nome}` }}</DialogTitle>
                    <DialogDescription>
                        {{
                            emEdicao === null
                                ? 'A pessoa entra com este e-mail e esta senha. Peça que ela troque a senha na primeira entrada.'
                                : 'Nome e e-mail podem ser corrigidos. A senha só muda se você preencher os dois campos.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="gravar">
                    <div class="grid gap-1">
                        <label for="conta-nome" class="text-sm font-medium">Nome</label>
                        <input
                            id="conta-nome"
                            v-model="formulario.name"
                            type="text"
                            required
                            data-testid="conta-nome"
                            :aria-invalid="formulario.errors.name ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.name" role="alert" class="text-destructive text-sm">{{ formulario.errors.name }}</p>
                    </div>

                    <div class="grid gap-1">
                        <label for="conta-email" class="text-sm font-medium">E-mail</label>
                        <input
                            id="conta-email"
                            v-model="formulario.email"
                            type="email"
                            required
                            autocomplete="off"
                            data-testid="conta-email"
                            :aria-describedby="formulario.errors.email ? undefined : 'ajuda-conta-email'"
                            :aria-invalid="formulario.errors.email ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.email" role="alert" class="text-destructive text-sm">{{ formulario.errors.email }}</p>
                        <p v-else id="ajuda-conta-email" class="text-muted-foreground text-sm">É por ele que a pessoa entra no painel.</p>
                    </div>

                    <div class="grid gap-1">
                        <label for="conta-papel" class="text-sm font-medium">Papel</label>
                        <select
                            id="conta-papel"
                            v-model="formulario.papel"
                            data-testid="conta-papel"
                            :aria-describedby="formulario.errors.papel ? undefined : 'ajuda-conta-papel'"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option v-for="papel in props.opcoes.papeis" :key="papel.valor" :value="papel.valor">{{ papel.rotulo }}</option>
                        </select>
                        <p v-if="formulario.errors.papel" role="alert" class="text-destructive text-sm">{{ formulario.errors.papel }}</p>
                        <p v-else id="ajuda-conta-papel" class="text-muted-foreground text-sm">
                            O que cada papel alcança está em
                            <Link :href="route('admin.papeis')" class="text-acao-texto font-medium">Papéis e permissões</Link>.
                        </p>
                    </div>

                    <!-- Na EDIÇÃO a senha é opcional, e em branco ela não é
                         tocada: corrigir um nome não pode obrigar a inventar uma
                         senha nova para a pessoa. -->
                    <div class="grid gap-1">
                        <label for="conta-senha" class="text-sm font-medium">
                            {{ emEdicao === null ? 'Senha' : 'Nova senha (deixe em branco para não mexer)' }}
                        </label>
                        <input
                            id="conta-senha"
                            v-model="formulario.password"
                            type="password"
                            autocomplete="new-password"
                            :required="emEdicao === null"
                            data-testid="conta-senha"
                            :aria-invalid="formulario.errors.password ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.password" role="alert" class="text-destructive text-sm">{{ formulario.errors.password }}</p>
                    </div>

                    <div class="grid gap-1">
                        <label for="conta-senha-confirmacao" class="text-sm font-medium">Repita a senha</label>
                        <input
                            id="conta-senha-confirmacao"
                            v-model="formulario.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :required="emEdicao === null"
                            data-testid="conta-senha-confirmacao"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>

                    <DialogFooter>
                        <button
                            type="button"
                            class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            @click="aoTrocarAbertura(false)"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formulario.processing"
                            data-testid="salvar-conta"
                            class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                        >
                            {{ emEdicao === null ? 'Cadastrar' : 'Salvar' }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>

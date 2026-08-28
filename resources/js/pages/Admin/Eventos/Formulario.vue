<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { EventoEmEdicao, OpcaoDeSituacao } from '@/types/admin';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick } from 'vue';

/**
 * A ficha do evento: os dados gerais que valem para todo mundo que se inscreve.
 *
 * A mesma tela cadastra e edita. Quando o evento já tem gente inscrita, dois
 * campos passam a ser delicados e a tela avisa antes de o servidor recusar:
 * a capacidade não pode encolher abaixo do que já está ocupado, e o valor não
 * muda com inscrição ativa em pé — seria cobrar preços diferentes pela mesma
 * coisa.
 */
const props = defineProps<{
    evento: EventoEmEdicao | null;
    situacoes: OpcaoDeSituacao[];
}>();

const editando = computed(() => props.evento !== null);

const formulario = useForm({
    nome: props.evento?.nome ?? '',
    slug: props.evento?.slug ?? '',
    descricao: props.evento?.descricao ?? '',
    local: props.evento?.local ?? '',
    local_detalhe: props.evento?.local_detalhe ?? '',
    itens_incluidos: [...(props.evento?.itens_incluidos ?? [])],
    perguntas_frequentes: (props.evento?.perguntas_frequentes ?? []).map((linha) => ({ ...linha })),
    data_inicio: props.evento?.data_inicio ?? '',
    data_fim: props.evento?.data_fim ?? '',
    inscricoes_abrem_em: props.evento?.inscricoes_abrem_em ?? '',
    inscricoes_fecham_em: props.evento?.inscricoes_fecham_em ?? '',
    capacidade: (props.evento?.capacidade ?? null) as number | null,
    valor_centavos: props.evento?.valor_centavos ?? 0,
    moeda: props.evento?.moeda ?? 'BRL',
    prazo_pagamento_minutos: props.evento?.prazo_pagamento_minutos ?? 60,
    situacao: props.evento?.situacao ?? 'rascunho',
    regulamento: props.evento?.regulamento ?? '',
    versao_termos: props.evento?.versao_termos ?? '1.0',
    contato_email: props.evento?.contato_email ?? '',
    contato_telefone: props.evento?.contato_telefone ?? '',
});

/**
 * As duas listas de conteudo da pagina do evento.
 *
 * Sao listas simples de texto, entao a edicao e simples de proposito:
 * acrescentar cria uma linha vazia e leva o cursor ate ela; remover tira a
 * linha. Quem limpa o que ficou em branco e o servidor, no envio — assim
 * ninguem precisa apagar linha por linha antes de gravar.
 */
async function acrescentarItem(): Promise<void> {
    formulario.itens_incluidos.push('');

    await nextTick();
    document.getElementById(`evento-incluido-${formulario.itens_incluidos.length - 1}`)?.focus();
}

function removerItem(indice: number): void {
    formulario.itens_incluidos.splice(indice, 1);
}

async function acrescentarPergunta(): Promise<void> {
    formulario.perguntas_frequentes.push({ pergunta: '', resposta: '' });

    await nextTick();
    document.getElementById(`evento-pergunta-${formulario.perguntas_frequentes.length - 1}`)?.focus();
}

function removerPergunta(indice: number): void {
    formulario.perguntas_frequentes.splice(indice, 1);
}

function gravar(): void {
    if (props.evento === null) {
        formulario.post(route('admin.eventos.store'));

        return;
    }

    formulario.put(route('admin.eventos.update', { evento: props.evento.id }), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        :titulo="editando ? `Editando ${props.evento?.nome}` : 'Novo evento'"
        descricao="Os dados gerais do evento. A programação — dias, grupos e atividades — fica em outra tela, porque é outro assunto e muda com outra frequência."
    >
        <p
            v-if="props.evento && props.evento.inscricoes_ativas > 0"
            role="status"
            class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm"
        >
            Este evento tem {{ props.evento.inscricoes_ativas }} inscrição(ões) ativa(s) e {{ props.evento.vagas_ocupadas }} vaga(s) ocupada(s). O
            valor não pode mais ser alterado e a capacidade não pode ficar abaixo do que já está ocupado.
        </p>

        <form class="grid gap-6" @submit.prevent="gravar">
            <section aria-labelledby="titulo-identificacao" class="border-border grid gap-4 rounded-lg border p-4">
                <h2 id="titulo-identificacao" class="text-lg font-semibold">Identificação</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label for="evento-nome" class="text-sm font-medium">Nome</label>
                        <input
                            id="evento-nome"
                            v-model="formulario.nome"
                            type="text"
                            maxlength="160"
                            required
                            :aria-describedby="formulario.errors.nome ? 'erro-evento-nome' : undefined"
                            :aria-invalid="formulario.errors.nome ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.nome" id="erro-evento-nome" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.nome }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-slug" class="text-sm font-medium">Endereço na internet</label>
                        <input
                            id="evento-slug"
                            v-model="formulario.slug"
                            type="text"
                            maxlength="160"
                            aria-describedby="ajuda-evento-slug"
                            :aria-invalid="formulario.errors.slug ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p id="ajuda-evento-slug" class="text-muted-foreground text-sm">
                            Em branco, é gerado a partir do nome. Exemplo: copa-ccc-2026.
                        </p>
                        <p v-if="formulario.errors.slug" role="alert" class="text-destructive text-sm">{{ formulario.errors.slug }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="evento-descricao" class="text-sm font-medium">Descrição</label>
                    <textarea
                        id="evento-descricao"
                        v-model="formulario.descricao"
                        rows="3"
                        class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    ></textarea>
                </div>

                <!-- Onde o evento acontece. Sao dois campos porque sao duas
                     coisas: o nome curto cabe numa linha ao lado da data, na
                     porta de entrada; o detalhe e o que a pessoa precisa para
                     chegar la, e so faz sentido na pagina do evento. -->
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label for="evento-local" class="text-sm font-medium">Local</label>
                        <input
                            id="evento-local"
                            v-model="formulario.local"
                            type="text"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            :aria-invalid="formulario.errors.local ? true : undefined"
                        />
                        <p class="text-muted-foreground text-sm">O nome curto, como as pessoas chamam o lugar.</p>
                        <p v-if="formulario.errors.local" role="alert" class="text-destructive text-sm">{{ formulario.errors.local }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-local-detalhe" class="text-sm font-medium">Como chegar</label>
                        <input
                            id="evento-local-detalhe"
                            v-model="formulario.local_detalhe"
                            type="text"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            :aria-invalid="formulario.errors.local_detalhe ? true : undefined"
                        />
                        <p class="text-muted-foreground text-sm">Distância, referência, estacionamento — o que evita telefonema no dia.</p>
                        <p v-if="formulario.errors.local_detalhe" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.local_detalhe }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1 md:max-w-xs">
                    <label for="evento-situacao" class="text-sm font-medium">Situação</label>
                    <select
                        id="evento-situacao"
                        v-model="formulario.situacao"
                        class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        <option v-for="situacao in props.situacoes" :key="situacao.valor" :value="situacao.valor">
                            {{ situacao.rotulo }}
                        </option>
                    </select>
                    <p v-if="formulario.errors.situacao" role="alert" class="text-destructive text-sm">{{ formulario.errors.situacao }}</p>
                </div>
            </section>

            <section aria-labelledby="titulo-datas" class="border-border grid gap-4 rounded-lg border p-4">
                <h2 id="titulo-datas" class="text-lg font-semibold">Datas</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label for="evento-data-inicio" class="text-sm font-medium">Data inicial</label>
                        <input
                            id="evento-data-inicio"
                            v-model="formulario.data_inicio"
                            type="date"
                            required
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.data_inicio" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.data_inicio }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-data-fim" class="text-sm font-medium">Data final</label>
                        <input
                            id="evento-data-fim"
                            v-model="formulario.data_fim"
                            type="date"
                            required
                            :aria-describedby="formulario.errors.data_fim ? 'erro-evento-data-fim' : undefined"
                            :aria-invalid="formulario.errors.data_fim ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.data_fim" id="erro-evento-data-fim" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.data_fim }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-abrem" class="text-sm font-medium">Inscrições abrem em</label>
                        <input
                            id="evento-abrem"
                            v-model="formulario.inscricoes_abrem_em"
                            type="datetime-local"
                            required
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.inscricoes_abrem_em" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.inscricoes_abrem_em }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-fecham" class="text-sm font-medium">Inscrições fecham em</label>
                        <input
                            id="evento-fecham"
                            v-model="formulario.inscricoes_fecham_em"
                            type="datetime-local"
                            required
                            :aria-describedby="formulario.errors.inscricoes_fecham_em ? 'erro-evento-fecham' : undefined"
                            :aria-invalid="formulario.errors.inscricoes_fecham_em ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.inscricoes_fecham_em" id="erro-evento-fecham" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.inscricoes_fecham_em }}
                        </p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="titulo-vagas-e-valor" class="border-border grid gap-4 rounded-lg border p-4">
                <h2 id="titulo-vagas-e-valor" class="text-lg font-semibold">Vagas e valor</h2>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="flex flex-col gap-1">
                        <label for="evento-capacidade" class="text-sm font-medium">Capacidade</label>
                        <input
                            id="evento-capacidade"
                            v-model.number="formulario.capacidade"
                            type="number"
                            min="0"
                            aria-describedby="ajuda-evento-capacidade"
                            :aria-invalid="formulario.errors.capacidade ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p id="ajuda-evento-capacidade" class="text-muted-foreground text-sm">Em branco, o evento não tem limite de vagas.</p>
                        <p v-if="formulario.errors.capacidade" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.capacidade }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-valor" class="text-sm font-medium">Valor em centavos</label>
                        <input
                            id="evento-valor"
                            v-model.number="formulario.valor_centavos"
                            type="number"
                            min="0"
                            required
                            aria-describedby="ajuda-evento-valor"
                            :aria-invalid="formulario.errors.valor_centavos ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p id="ajuda-evento-valor" class="text-muted-foreground text-sm">
                            R$ 120,00 se escreve 12000. Use zero para evento gratuito.
                        </p>
                        <p v-if="formulario.errors.valor_centavos" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.valor_centavos }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-prazo" class="text-sm font-medium">Prazo de pagamento (minutos)</label>
                        <input
                            id="evento-prazo"
                            v-model.number="formulario.prazo_pagamento_minutos"
                            type="number"
                            min="5"
                            required
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.prazo_pagamento_minutos" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.prazo_pagamento_minutos }}
                        </p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="titulo-termos" class="border-border grid gap-4 rounded-lg border p-4">
                <h2 id="titulo-termos" class="text-lg font-semibold">Regulamento e contato</h2>

                <div class="flex flex-col gap-1">
                    <label for="evento-regulamento" class="text-sm font-medium">Regulamento</label>
                    <textarea
                        id="evento-regulamento"
                        v-model="formulario.regulamento"
                        rows="6"
                        required
                        aria-describedby="ajuda-evento-regulamento"
                        :aria-invalid="formulario.errors.regulamento ? true : undefined"
                        class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    ></textarea>
                    <p id="ajuda-evento-regulamento" class="text-muted-foreground text-sm">É o texto que a pessoa aceita ao se inscrever.</p>
                    <p v-if="formulario.errors.regulamento" role="alert" class="text-destructive text-sm">
                        {{ formulario.errors.regulamento }}
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="flex flex-col gap-1">
                        <label for="evento-versao" class="text-sm font-medium">Versão dos termos</label>
                        <input
                            id="evento-versao"
                            v-model="formulario.versao_termos"
                            type="text"
                            maxlength="40"
                            required
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.versao_termos" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.versao_termos }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-email" class="text-sm font-medium">E-mail de contato</label>
                        <input
                            id="evento-email"
                            v-model="formulario.contato_email"
                            type="email"
                            maxlength="160"
                            required
                            :aria-invalid="formulario.errors.contato_email ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.contato_email" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.contato_email }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="evento-telefone" class="text-sm font-medium">Telefone de contato</label>
                        <input
                            id="evento-telefone"
                            v-model="formulario.contato_telefone"
                            type="text"
                            maxlength="40"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="formulario.processing"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-10 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                >
                    {{ editando ? 'Salvar' : 'Cadastrar' }}
                </button>

                <Link
                    v-if="props.evento"
                    :href="route('admin.eventos.estrutura', { evento: props.evento.id })"
                    class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Programação
                </Link>

                <Link
                    :href="route('admin.eventos.index')"
                    class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Voltar para a lista
                </Link>
            </div>

            <!-- O conteudo que a pagina do evento mostra alem da programacao.
                 Sao as duvidas que hoje a organizacao responde no WhatsApp toda
                 semana, e que fazem a pessoa adiar a inscricao. -->
            <section aria-labelledby="titulo-conteudo" class="grid gap-4">
                <h2 id="titulo-conteudo" class="text-lg font-semibold">Conteúdo da página do evento</h2>

                <div class="grid gap-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium">O que está incluído</p>
                        <p class="text-muted-foreground text-sm">Camiseta, alimentação, seguro — um item por linha.</p>
                    </div>

                    <div v-for="(item, indice) in formulario.itens_incluidos" :key="`incluido-${indice}`" class="flex items-center gap-2">
                        <label :for="`evento-incluido-${indice}`" class="sr-only">Item {{ indice + 1 }} do que está incluído</label>
                        <input
                            :id="`evento-incluido-${indice}`"
                            v-model="formulario.itens_incluidos[indice]"
                            type="text"
                            class="border-input bg-background focus-visible:ring-ring h-10 flex-1 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <Button type="button" variant="ghost" class="h-10" @click="removerItem(indice)">
                            Remover<span class="sr-only"> o item {{ indice + 1 }}</span>
                        </Button>
                    </div>

                    <p v-if="formulario.itens_incluidos.length === 0" class="text-muted-foreground text-sm">
                        Nenhum item. A seção não aparece na página do evento enquanto a lista estiver vazia.
                    </p>

                    <div>
                        <Button type="button" variant="outline" class="h-10" @click="acrescentarItem">Acrescentar item</Button>
                    </div>
                </div>

                <div class="border-border grid gap-3 border-t pt-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium">Perguntas frequentes</p>
                        <p class="text-muted-foreground text-sm">Pergunta sem resposta não é gravada.</p>
                    </div>

                    <div
                        v-for="(pergunta, indice) in formulario.perguntas_frequentes"
                        :key="`pergunta-${indice}`"
                        class="border-border grid gap-2 rounded-md border p-3"
                    >
                        <label :for="`evento-pergunta-${indice}`" class="text-sm font-medium">Pergunta {{ indice + 1 }}</label>
                        <input
                            :id="`evento-pergunta-${indice}`"
                            v-model="pergunta.pergunta"
                            type="text"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />

                        <label :for="`evento-resposta-${indice}`" class="text-sm font-medium">Resposta</label>
                        <textarea
                            :id="`evento-resposta-${indice}`"
                            v-model="pergunta.resposta"
                            rows="3"
                            class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        ></textarea>

                        <div>
                            <Button type="button" variant="ghost" class="h-10" @click="removerPergunta(indice)">
                                Remover<span class="sr-only"> a pergunta {{ indice + 1 }}</span>
                            </Button>
                        </div>
                    </div>

                    <p v-if="formulario.perguntas_frequentes.length === 0" class="text-muted-foreground text-sm">
                        Nenhuma pergunta. A seção não aparece na página do evento enquanto a lista estiver vazia.
                    </p>

                    <div>
                        <Button type="button" variant="outline" class="h-10" @click="acrescentarPergunta">Acrescentar pergunta</Button>
                    </div>
                </div>
            </section>
        </form>
    </AdminLayout>
</template>

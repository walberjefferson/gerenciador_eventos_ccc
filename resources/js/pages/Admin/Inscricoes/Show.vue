<script setup lang="ts">
import DialogoDeAcao from '@/components/admin/DialogoDeAcao.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CobrancaDaFicha, FichaDaInscricao, OpcaoDeSituacao } from '@/types/admin';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * A ficha de uma inscrição, com o histórico da cobrança e as duas ações que a
 * organização pode tomar.
 *
 * O histórico é a parte que importa: cada cobrança emitida, em que situação
 * parou e — quando o pagamento foi reconhecido na mão — quem declarou isso e o
 * que escreveu.
 *
 * Cancelar uma inscrição já paga é permitido, porque acontece de alguém
 * desistir depois de pagar. Mas **o valor pago não é devolvido automaticamente**
 * e a tela diz isso antes do clique: devolver dinheiro é decisão de gente, não
 * de programa.
 */
const props = defineProps<{
    inscricao: FichaDaInscricao;
    cobrancas: CobrancaDaFicha[];
    metodos_manuais: OpcaoDeSituacao[];
    pode_cancelar: boolean;
    pode_confirmar_manualmente: boolean;
    sucesso: string | null;
}>();

const cancelamentoAberto = ref(false);
const confirmacaoAberta = ref(false);

const formularioCancelamento = useForm({ motivo: '' });
const formularioConfirmacao = useForm({
    metodo: props.metodos_manuais[0]?.valor ?? 'dinheiro',
    observacao: '',
});

const podeCancelarAgora = computed(() => props.pode_cancelar && props.inscricao.esta_ativa);
const podeConfirmarAgora = computed(() => props.pode_confirmar_manualmente && props.inscricao.situacao === 'aguardando_pagamento');

const avisoDoCancelamento = computed(() =>
    props.inscricao.foi_paga
        ? 'Esta inscrição tem pagamento recebido. O valor pago não é devolvido automaticamente: a devolução, se houver, é combinada fora do sistema.'
        : undefined,
);

function moeda(centavos: number): string {
    return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function momento(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

function cancelar(): void {
    formularioCancelamento.post(route('admin.inscricoes.cancelar', { inscricao: props.inscricao.id }), {
        preserveScroll: true,
        onSuccess: () => {
            cancelamentoAberto.value = false;
            formularioCancelamento.reset();
        },
    });
}

function confirmarPagamento(): void {
    formularioConfirmacao.post(route('admin.inscricoes.confirmar-pagamento', { inscricao: props.inscricao.id }), {
        preserveScroll: true,
        onSuccess: () => {
            confirmacaoAberta.value = false;
            formularioConfirmacao.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        :titulo="props.inscricao.nome_completo"
        :descricao="`Inscrição ${props.inscricao.codigo_publico} no evento ${props.inscricao.evento}.`"
    >
        <p v-if="props.sucesso" role="status" class="rounded-md border border-border bg-muted/40 px-4 py-2 text-sm">{{ props.sucesso }}</p>

        <div>
            <Link
                :href="route('admin.inscricoes.index')"
                class="inline-flex h-10 items-center rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
            >
                Voltar para a lista
            </Link>
        </div>

        <section aria-labelledby="titulo-dados" class="grid gap-3 rounded-lg border border-border p-4">
            <h2 id="titulo-dados" class="text-lg font-semibold">Dados da inscrição</h2>

            <dl class="grid gap-3 md:grid-cols-3">
                <div>
                    <dt class="text-sm text-muted-foreground">Situação</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.situacao_rotulo }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">E-mail</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.email }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Telefone</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.telefone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Setor</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.cidade || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Grupo</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.grupo || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Valor</dt>
                    <dd class="text-sm font-medium">{{ moeda(props.inscricao.valor_centavos) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Inscrita em</dt>
                    <dd class="text-sm font-medium">{{ momento(props.inscricao.criada_em) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Prazo de pagamento</dt>
                    <dd class="text-sm font-medium">{{ momento(props.inscricao.prazo_pagamento) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Confirmada em</dt>
                    <dd class="text-sm font-medium">{{ momento(props.inscricao.confirmada_em) }}</dd>
                </div>
            </dl>

            <p v-if="props.inscricao.motivo_cancelamento" class="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                <strong>Cancelada em {{ momento(props.inscricao.cancelada_em) }}.</strong> Motivo registrado:
                {{ props.inscricao.motivo_cancelamento }}
            </p>
        </section>

        <section aria-labelledby="titulo-atividades" class="grid gap-3 rounded-lg border border-border p-4">
            <h2 id="titulo-atividades" class="text-lg font-semibold">Atividades escolhidas</h2>

            <p v-if="props.inscricao.atividades.length === 0" class="text-sm text-muted-foreground">Nenhuma atividade escolhida.</p>

            <ul v-else class="grid gap-1">
                <li v-for="atividade in props.inscricao.atividades" :key="atividade.id" class="text-sm">
                    {{ atividade.nome }} — {{ momento(atividade.comeca_em) }}
                </li>
            </ul>
        </section>

        <section aria-labelledby="titulo-cobrancas" class="grid gap-3 rounded-lg border border-border p-4">
            <h2 id="titulo-cobrancas" class="text-lg font-semibold">Histórico da cobrança</h2>

            <p v-if="props.cobrancas.length === 0" class="text-sm text-muted-foreground">Nenhuma cobrança emitida.</p>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Cobranças emitidas para esta inscrição, da mais recente para a mais antiga.
                    </caption>
                    <thead>
                        <tr class="border-b border-border text-left">
                            <!-- Dois códigos, e eles nunca coincidem: o da esquerda é o que este sistema deu à cobrança; o txid é o que a Efí usa e o único que serve para procurar no painel dela. Antes esta coluna se chamava só "Cobrança", e era exatamente essa ambiguidade que fazia procurar o código errado do lado de lá. -->
                            <th scope="col" class="px-2 py-2 font-medium">Código interno</th>
                            <th scope="col" class="px-2 py-2 font-medium">txid (Efí)</th>
                            <th scope="col" class="px-2 py-2 font-medium">Método</th>
                            <th scope="col" class="px-2 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-2 py-2 font-medium">Valor</th>
                            <th scope="col" class="px-2 py-2 font-medium">Emitida em</th>
                            <th scope="col" class="px-2 py-2 font-medium">Paga em</th>
                            <th scope="col" class="px-2 py-2 font-medium">Origem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cobranca in props.cobrancas" :key="cobranca.id" class="border-b border-border last:border-0 align-top">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ cobranca.codigo_publico }}</th>
                            <!-- Vazio quando o pagamento foi reconhecido na mão: não houve provedor, e portanto não há txid. -->
                            <td class="px-2 py-2 font-mono break-all" :data-testid="`cobranca-txid-${cobranca.id}`">{{ cobranca.id_externo ?? '—' }}</td>
                            <td class="px-2 py-2">{{ cobranca.metodo_rotulo }}</td>
                            <td class="px-2 py-2">{{ cobranca.situacao_rotulo }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">{{ moeda(cobranca.valor_centavos) }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">{{ momento(cobranca.criada_em) }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">{{ momento(cobranca.pago_em) }}</td>
                            <td class="px-2 py-2">
                                <template v-if="cobranca.origem_manual">
                                    <span class="block">Reconhecida na mão{{ cobranca.responsavel ? ` por ${cobranca.responsavel}` : '' }}</span>
                                    <span v-if="cobranca.observacao" class="block text-muted-foreground">{{ cobranca.observacao }}</span>
                                </template>
                                <span v-else>{{ cobranca.gateway }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="titulo-acoes" class="grid gap-3 rounded-lg border border-border p-4">
            <h2 id="titulo-acoes" class="text-lg font-semibold">Ações</h2>

            <p class="max-w-3xl text-sm text-muted-foreground">
                As duas ações ficam registradas com o motivo que você escrever. Cancelar devolve a vaga na hora — inclusive as vagas das
                atividades escolhidas.
            </p>

            <div class="flex flex-wrap gap-3">
                <button
                    v-if="podeCancelarAgora"
                    type="button"
                    data-testid="abrir-cancelamento"
                    class="h-10 rounded-md border border-destructive px-4 text-sm text-destructive focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    @click="cancelamentoAberto = true"
                >
                    Cancelar inscrição
                </button>

                <button
                    v-if="podeConfirmarAgora"
                    type="button"
                    data-testid="abrir-confirmacao-manual"
                    class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    @click="confirmacaoAberta = true"
                >
                    Confirmar pagamento recebido
                </button>

                <p v-if="!podeCancelarAgora && !podeConfirmarAgora" class="text-sm text-muted-foreground">
                    Nenhuma ação disponível para esta inscrição.
                </p>
            </div>
        </section>

        <DialogoDeAcao
            v-model:aberto="cancelamentoAberto"
            v-model:texto="formularioCancelamento.motivo"
            titulo="Cancelar inscrição"
            descricao="A vaga do evento e as vagas das atividades voltam na hora. A inscrição não é apagada: ela fica registrada como cancelada."
            :aviso="avisoDoCancelamento"
            rotulo-do-campo="Motivo do cancelamento"
            texto-do-botao="Cancelar inscrição"
            :erro="formularioCancelamento.errors.motivo"
            :processando="formularioCancelamento.processing"
            @confirmar="cancelar"
        />

        <DialogoDeAcao
            v-model:aberto="confirmacaoAberta"
            v-model:texto="formularioConfirmacao.observacao"
            titulo="Confirmar pagamento recebido"
            descricao="Use quando o dinheiro entrou por fora do sistema — em espécie na secretaria ou por transferência direta. Fica registrado quem declarou."
            rotulo-do-campo="Como o pagamento foi recebido"
            texto-de-ajuda="Descreva o que aconteceu: quem entregou, quando e onde. Fica guardado na cobrança."
            texto-do-botao="Confirmar pagamento"
            :erro="formularioConfirmacao.errors.observacao"
            :processando="formularioConfirmacao.processing"
            @confirmar="confirmarPagamento"
        >
            <template #campos>
                <div class="flex flex-col gap-1">
                    <label for="metodo-manual" class="text-sm font-medium">Como o dinheiro chegou</label>
                    <select
                        id="metodo-manual"
                        v-model="formularioConfirmacao.metodo"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring w-full"
                    >
                        <option v-for="metodo in props.metodos_manuais" :key="metodo.valor" :value="metodo.valor">{{ metodo.rotulo }}</option>
                    </select>
                    <p v-if="formularioConfirmacao.errors.metodo" role="alert" class="text-sm text-destructive">
                        {{ formularioConfirmacao.errors.metodo }}
                    </p>
                </div>
            </template>
        </DialogoDeAcao>
    </AdminLayout>
</template>

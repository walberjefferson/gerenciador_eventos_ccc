<script setup lang="ts">
import DialogoDeAcao from '@/components/admin/DialogoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AtividadeEscolhida, CobrancaDaFicha, FichaDaInscricao, IngressoDaFicha, OpcaoDeSituacao } from '@/types/admin';
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
    pode_editar: boolean;
    pode_reenviar: boolean;
    /** O que faz sentido reenviar nesta situação. Vem do servidor (RN-A2). */
    reenvios: OpcaoDeSituacao[];
    /** Só existe para inscrição confirmada que já teve ingresso emitido. */
    ingresso: IngressoDaFicha | null;
    sucesso: string | null;
}>();

const cancelamentoAberto = ref(false);
const confirmacaoAberta = ref(false);

const formularioCancelamento = useForm({ motivo: '' });
const formularioConfirmacao = useForm({
    metodo: props.metodos_manuais[0]?.valor ?? 'dinheiro',
    observacao: '',
});

const formularioReenvio = useForm({ tipo: props.reenvios[0]?.valor ?? '' });

const podeCancelarAgora = computed(() => props.pode_cancelar && props.inscricao.esta_ativa);

/**
 * Reenviar só aparece quando há permissão E há alguma mensagem que caiba na
 * situação de agora. Inscrição sem e-mail não oferece nenhuma: a lista chega
 * vazia do servidor, e não há o que escolher.
 */
const podeReenviarAgora = computed(() => props.pode_reenviar && props.reenvios.length > 0);
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

/**
 * "Futebol — 17/10/2026 08:00", ou só "Futebol" quando a atividade não tem
 * hora marcada.
 *
 * O horário passou a ser opcional: atividade sem hora ocupa o dia inteiro. Sem
 * este cuidado, a linha sairia como "Futebol — —", que é pior do que não dizer
 * nada — o travessão duplo parece defeito de tela, não ausência de dado.
 */
function atividadeComQuando(atividade: AtividadeEscolhida): string {
    return atividade.comeca_em === null ? atividade.nome : `${atividade.nome} — ${momento(atividade.comeca_em)}`;
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

/**
 * O reenvio é um ato deliberado de gente, e por isso não passa pela trava que
 * impede a automação de mandar duas vezes: quem clica aqui está pedindo
 * justamente a segunda cópia.
 */
function reenviar(): void {
    formularioReenvio.post(route('admin.inscricoes.reenviar', { inscricao: props.inscricao.id }), {
        preserveScroll: true,
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
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">{{ props.sucesso }}</p>

        <div class="flex flex-wrap gap-3">
            <Link
                :href="route('admin.inscricoes.index')"
                class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Voltar para a lista
            </Link>

            <!-- Corrigir o cadastro é a ação mais comum desta tela, e por isso
                 ela fica aqui em cima, junto da navegação: quem abriu a ficha
                 por causa de um nome errado não deveria precisar rolar até o
                 fim para consertá-lo. -->
            <Link
                v-if="props.pode_editar"
                :href="route('admin.inscricoes.edit', { inscricao: props.inscricao.id })"
                data-testid="editar-inscricao"
                class="border-acao text-acao-texto hover:bg-secondary focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Editar inscrição
            </Link>
        </div>

        <section aria-labelledby="titulo-dados" class="border-border grid gap-3 rounded-lg border p-4">
            <h2 id="titulo-dados" class="text-lg font-semibold">Dados da inscrição</h2>

            <dl class="grid gap-3 md:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm">Situação</dt>
                    <dd class="text-sm font-medium">
                        <EtiquetaDeSituacao dominio="inscricao" :situacao="props.inscricao.situacao" :rotulo="props.inscricao.situacao_rotulo" />
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">E-mail</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.email }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Telefone</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.telefone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Setor</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.cidade || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Grupo</dt>
                    <dd class="text-sm font-medium">{{ props.inscricao.grupo || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Sexo</dt>
                    <!-- Nulo na inscrição anterior ao campo, e isso não é defeito: ninguém chegou a perguntar. -->
                    <dd class="text-sm font-medium">{{ props.inscricao.sexo_rotulo ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Valor</dt>
                    <dd class="text-sm font-medium">{{ moeda(props.inscricao.valor_centavos) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Inscrita em</dt>
                    <dd class="text-sm font-medium">{{ momento(props.inscricao.criada_em) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Prazo de pagamento</dt>
                    <dd class="text-sm font-medium">{{ momento(props.inscricao.prazo_pagamento) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm">Confirmada em</dt>
                    <dd class="text-sm font-medium">{{ momento(props.inscricao.confirmada_em) }}</dd>
                </div>
            </dl>

            <p v-if="props.inscricao.motivo_cancelamento" class="border-border bg-muted/40 rounded-md border px-3 py-2 text-sm">
                <strong>Cancelada em {{ momento(props.inscricao.cancelada_em) }}.</strong> Motivo registrado:
                {{ props.inscricao.motivo_cancelamento }}
            </p>
        </section>

        <section aria-labelledby="titulo-atividades" class="border-border grid gap-3 rounded-lg border p-4">
            <h2 id="titulo-atividades" class="text-lg font-semibold">Atividades escolhidas</h2>

            <p v-if="props.inscricao.atividades.length === 0" class="text-muted-foreground text-sm">Nenhuma atividade escolhida.</p>

            <ul v-else class="grid gap-1">
                <li v-for="atividade in props.inscricao.atividades" :key="atividade.id" class="text-sm">
                    {{ atividadeComQuando(atividade) }}
                </li>
            </ul>
        </section>

        <!-- O ingresso só existe para quem está confirmado: a prop chega nula
             em qualquer outra situação, e a seção inteira some junto. -->
        <section v-if="props.ingresso" aria-labelledby="titulo-ingresso" class="border-border grid gap-3 rounded-lg border p-4">
            <h2 id="titulo-ingresso" class="text-lg font-semibold">Ingresso</h2>

            <div class="flex flex-wrap items-start gap-4">
                <!-- O desenho vem pronto do servidor, em SVG, como na tela do
                     participante: aparece mesmo com a rede ruim e não depende
                     de biblioteca nenhuma no navegador. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    class="border-border w-40 shrink-0 rounded-lg border bg-white p-2 [&>svg]:h-auto [&>svg]:w-full"
                    data-testid="qr-do-ingresso"
                    v-html="props.ingresso.qr"
                />

                <div class="grid gap-2">
                    <div>
                        <p class="text-muted-foreground text-sm">Código do ingresso</p>
                        <p class="font-mono text-lg font-semibold tracking-widest" data-testid="codigo-do-ingresso">
                            {{ props.ingresso.codigo_formatado }}
                        </p>
                    </div>

                    <!-- Link comum, e não navegação do Inertia: o destino é um
                         arquivo para baixar, não uma tela. -->
                    <a
                        :href="props.ingresso.url_pdf"
                        data-testid="baixar-ingresso"
                        class="border-border focus-visible:ring-ring inline-flex h-10 w-fit items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        Baixar o ingresso em PDF
                    </a>

                    <p class="text-muted-foreground max-w-prose text-sm">
                        Serve para quem está no balcão com a pessoa na frente. Na entrada, a portaria lê este mesmo código.
                    </p>
                </div>
            </div>
        </section>

        <section aria-labelledby="titulo-cobrancas" class="border-border grid gap-3 rounded-lg border p-4">
            <h2 id="titulo-cobrancas" class="text-lg font-semibold">Histórico da cobrança</h2>

            <p v-if="props.cobrancas.length === 0" class="text-muted-foreground text-sm">Nenhuma cobrança emitida.</p>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Cobranças emitidas para esta inscrição, da mais recente para a mais antiga.
                    </caption>
                    <thead>
                        <tr class="border-border border-b text-left">
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
                        <tr v-for="cobranca in props.cobrancas" :key="cobranca.id" class="border-border border-b align-top last:border-0">
                            <th scope="row" class="px-2 py-2 text-left font-normal">{{ cobranca.codigo_publico }}</th>
                            <!-- Vazio quando o pagamento foi reconhecido na mão: não houve provedor, e portanto não há txid. -->
                            <td class="px-2 py-2 font-mono break-all" :data-testid="`cobranca-txid-${cobranca.id}`">
                                {{ cobranca.id_externo ?? '—' }}
                            </td>
                            <td class="px-2 py-2">{{ cobranca.metodo_rotulo }}</td>
                            <td class="px-2 py-2">
                                <EtiquetaDeSituacao dominio="pagamento" :situacao="cobranca.situacao" :rotulo="cobranca.situacao_rotulo" />
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">{{ moeda(cobranca.valor_centavos) }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">{{ momento(cobranca.criada_em) }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">{{ momento(cobranca.pago_em) }}</td>
                            <td class="px-2 py-2">
                                <template v-if="cobranca.origem_manual">
                                    <span class="block">Reconhecida na mão{{ cobranca.responsavel ? ` por ${cobranca.responsavel}` : '' }}</span>
                                    <span v-if="cobranca.observacao" class="text-muted-foreground block">{{ cobranca.observacao }}</span>
                                </template>
                                <template v-else>
                                    <span class="block">{{ cobranca.gateway }}</span>
                                    <span v-if="cobranca.pagador" class="text-muted-foreground block">
                                        Pago por {{ cobranca.pagador.nome ?? 'quem não se identificou' }}
                                        <template v-if="cobranca.pagador.documento">
                                            ({{ cobranca.pagador.tipo_documento === 'cnpj' ? 'CNPJ' : 'CPF' }} {{ cobranca.pagador.documento }})
                                        </template>
                                    </span>
                                    <span v-if="cobranca.pagador?.mensagem" class="text-muted-foreground block">
                                        “{{ cobranca.pagador.mensagem }}”
                                    </span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="titulo-acoes" class="border-border grid gap-3 rounded-lg border p-4">
            <h2 id="titulo-acoes" class="text-lg font-semibold">Ações</h2>

            <p class="text-muted-foreground max-w-3xl text-sm">
                Cancelar e confirmar ficam registradas com o motivo que você escrever. Cancelar devolve a vaga na hora — inclusive as vagas das
                atividades escolhidas.
            </p>

            <div class="flex flex-wrap gap-3">
                <button
                    v-if="podeCancelarAgora"
                    type="button"
                    data-testid="abrir-cancelamento"
                    class="border-destructive text-destructive focus-visible:ring-ring h-10 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="cancelamentoAberto = true"
                >
                    Cancelar inscrição
                </button>

                <button
                    v-if="podeConfirmarAgora"
                    type="button"
                    data-testid="abrir-confirmacao-manual"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-10 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    @click="confirmacaoAberta = true"
                >
                    Confirmar pagamento recebido
                </button>

                <p v-if="!podeCancelarAgora && !podeConfirmarAgora && !podeReenviarAgora" class="text-muted-foreground text-sm">
                    Nenhuma ação disponível para esta inscrição.
                </p>
            </div>

            <!-- Reenviar é ato deliberado de gente: a automação já mandou uma
                 vez e a trava dela existe para não mandar duas. Aqui a segunda
                 cópia é exatamente o que se está pedindo. -->
            <form v-if="podeReenviarAgora" class="border-border grid gap-3 border-t pt-4 md:max-w-xl" @submit.prevent="reenviar">
                <h3 class="text-base font-medium">Reenviar uma mensagem</h3>

                <div class="flex flex-col gap-1">
                    <label for="tipo-reenvio" class="text-sm font-medium">Qual mensagem</label>
                    <select
                        id="tipo-reenvio"
                        v-model="formularioReenvio.tipo"
                        data-testid="tipo-de-reenvio"
                        :aria-describedby="formularioReenvio.errors.tipo ? 'erro-tipo-reenvio' : 'ajuda-tipo-reenvio'"
                        :aria-invalid="formularioReenvio.errors.tipo ? true : undefined"
                        class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        <option v-for="reenvio in props.reenvios" :key="reenvio.valor" :value="reenvio.valor">{{ reenvio.rotulo }}</option>
                    </select>
                    <p id="ajuda-tipo-reenvio" class="text-muted-foreground text-sm">
                        Vai para {{ props.inscricao.email }}, que é o e-mail cadastrado nesta inscrição agora.
                    </p>
                    <p v-if="formularioReenvio.errors.tipo" id="erro-tipo-reenvio" role="alert" class="text-destructive text-sm">
                        {{ formularioReenvio.errors.tipo }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="formularioReenvio.processing"
                    data-testid="reenviar-mensagem"
                    class="border-border focus-visible:ring-ring h-10 w-fit rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                >
                    Reenviar
                </button>
            </form>
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
                        class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        <option v-for="metodo in props.metodos_manuais" :key="metodo.valor" :value="metodo.valor">{{ metodo.rotulo }}</option>
                    </select>
                    <p v-if="formularioConfirmacao.errors.metodo" role="alert" class="text-destructive text-sm">
                        {{ formularioConfirmacao.errors.metodo }}
                    </p>
                </div>
            </template>
        </DialogoDeAcao>
    </AdminLayout>
</template>

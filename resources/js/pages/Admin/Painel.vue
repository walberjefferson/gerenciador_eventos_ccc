<script setup lang="ts">
import CartaoDeNumero from '@/components/admin/CartaoDeNumero.vue';
import TabelaDeVagas from '@/components/admin/TabelaDeVagas.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatarValor } from '@/lib/formato';
import type { AvisosDoProvedorNoPainel } from '@/types/admin';
import type { InscricoesPorSituacao, NumerosDoEvento, ResumoDoEvento } from '@/types/painel';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * O painel do organizador.
 *
 * Uma tela, um evento por vez. Ela só lê: nada aqui muda inscrição, vaga ou
 * pagamento.
 */
const props = defineProps<{
    eventos: ResumoDoEvento[];
    evento: ResumoDoEvento | null;
    numeros: NumerosDoEvento | null;
    /** Nulo para quem não pode abrir a tela dos avisos — o cartão simplesmente não existe para essa pessoa. */
    avisos_do_provedor: AvisosDoProvedorNoPainel | null;
}>();

const eventoSelecionado = ref<number | null>(props.evento?.id ?? null);
const trocandoDeEvento = ref(false);

watch(
    () => props.evento?.id ?? null,
    (novo) => {
        eventoSelecionado.value = novo;
        trocandoDeEvento.value = false;
    },
);

function trocarEvento(): void {
    if (eventoSelecionado.value === null || eventoSelecionado.value === props.evento?.id) {
        return;
    }

    trocandoDeEvento.value = true;

    router.get(
        route('admin.painel'),
        { evento: eventoSelecionado.value },
        { preserveScroll: true, preserveState: true, onFinish: () => (trocandoDeEvento.value = false) },
    );
}

type Tom = 'neutro' | 'sucesso' | 'informacao' | 'atencao';

/**
 * O que cada situação quer dizer, em português de gente.
 *
 * A cor é sempre reforço do texto, nunca a informação sozinha. Situação que
 * apareça no banco sem estar nesta lista continua sendo mostrada, só que sem
 * explicação — melhor um número sem legenda do que um número escondido.
 */
const leituraDaSituacao: Record<string, { tom: Tom; significado: string }> = {
    aguardando_pagamento: { tom: 'informacao', significado: 'Vaga reservada; o dinheiro ainda não entrou.' },
    confirmada: { tom: 'sucesso', significado: 'Pagamento reconhecido; a vaga é dessas pessoas.' },
    expirada: { tom: 'atencao', significado: 'Prazo de pagamento vencido; a vaga voltou para a fila.' },
    cancelada: { tom: 'neutro', significado: 'Desistências e cancelamentos.' },
    lista_espera: { tom: 'neutro', significado: 'Gente esperando uma vaga sobrar.' },
};

function tomDe(linha: InscricoesPorSituacao): Tom {
    return leituraDaSituacao[linha.situacao]?.tom ?? 'neutro';
}

function significadoDe(linha: InscricoesPorSituacao): string {
    return leituraDaSituacao[linha.situacao]?.significado ?? '';
}

/** Todas as situações que o servidor mandou, na ordem em que se lê o problema. */
const situacoes = computed<InscricoesPorSituacao[]>(() => props.numeros?.inscricoes.por_situacao ?? []);

const totalDeInscricoes = computed<number>(() => props.numeros?.inscricoes.total ?? 0);

/** Evento publicado que ainda não recebeu ninguém: zeros com explicação, nunca tela vazia. */
const eventoAindaSemInscricao = computed<boolean>(() => props.numeros !== null && totalDeInscricoes.value === 0);

const dinheiro = computed(() => props.numeros?.dinheiro ?? null);

/**
 * O último aviso do provedor de pagamento.
 *
 * Ele é global, e não do evento escolhido no seletor: aviso de provedor fala de
 * uma cobrança, não de um evento. A pergunta que este cartão responde — "o
 * provedor ainda está chamando?" — não muda quando se troca o evento.
 */
const ultimoAviso = computed(() => props.avisos_do_provedor?.ultimo ?? null);

/**
 * O intervalo em português, escrito aqui e não no servidor.
 *
 * O servidor manda o número de minutos justamente para que a frase não dependa
 * do idioma configurado no PHP.
 */
function haQuantoTempo(minutos: number | null): string {
    if (minutos === null) {
        return 'em data desconhecida';
    }

    if (minutos < 1) {
        return 'há menos de um minuto';
    }

    if (minutos < 60) {
        return `há ${minutos} minuto${minutos === 1 ? '' : 's'}`;
    }

    const horas = Math.floor(minutos / 60);

    if (horas < 48) {
        return `há ${horas} hora${horas === 1 ? '' : 's'}`;
    }

    const dias = Math.floor(horas / 24);

    return `há ${dias} dias`;
}
</script>

<template>
    <AdminLayout titulo="Painel" descricao="Como está o evento agora: quem se inscreveu, quantas vagas restam e quanto dinheiro entrou.">
        <!-- Sem nenhum evento publicado não há o que acompanhar; a tela diz isso em vez de mostrar zeros sem contexto. -->
        <div v-if="props.eventos.length === 0" class="rounded-lg border border-border bg-muted/40 p-6">
            <h2 class="text-base font-semibold">Nenhum evento publicado ainda</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Assim que um evento sair do rascunho, os números dele aparecem aqui: inscrições, vagas por atividade e dinheiro.
            </p>
        </div>

        <template v-else>
            <div class="flex flex-col gap-2 sm:max-w-md">
                <label for="seletor-de-evento" class="text-sm font-medium">Evento</label>
                <select
                    id="seletor-de-evento"
                    v-model.number="eventoSelecionado"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 w-full"
                    @change="trocarEvento"
                >
                    <option v-for="item in props.eventos" :key="item.id" :value="item.id">{{ item.nome }} — {{ item.situacao_rotulo }}</option>
                </select>
            </div>

            <!-- Enquanto os números do outro evento não chegam, a tela avisa em vez de piscar em silêncio. -->
            <p v-if="trocandoDeEvento" class="text-sm text-muted-foreground" role="status" data-testid="painel-carregando">
                Carregando os números do evento…
            </p>

            <div
                v-if="props.evento && props.numeros"
                class="flex flex-col gap-8 transition-opacity"
                :class="trocandoDeEvento ? 'opacity-60' : ''"
                :aria-busy="trocandoDeEvento"
            >
                <!-- Zero não é defeito: é a resposta certa para um evento que acabou de abrir. -->
                <p
                    v-if="eventoAindaSemInscricao"
                    class="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
                    data-testid="painel-sem-inscricao"
                >
                    Ninguém se inscreveu neste evento ainda. Os números abaixo estão zerados porque não há o que contar — assim que a primeira
                    inscrição chegar, eles mudam sozinhos.
                </p>

                <section aria-labelledby="titulo-inscricoes" class="flex flex-col gap-3" data-testid="painel-inscricoes">
                    <h2 id="titulo-inscricoes" class="text-lg font-semibold">Inscrições</h2>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <CartaoDeNumero
                            rotulo="Total de inscrições"
                            :valor="String(totalDeInscricoes)"
                            significado="Tudo o que já foi criado, em qualquer situação."
                        />
                        <CartaoDeNumero
                            v-for="linha in situacoes"
                            :key="linha.situacao"
                            :rotulo="linha.rotulo"
                            :valor="String(linha.total)"
                            :tom="tomDe(linha)"
                            :significado="significadoDe(linha)"
                        />
                    </div>
                </section>

                <section aria-labelledby="titulo-vagas" class="flex flex-col gap-3" data-testid="painel-vagas">
                    <h2 id="titulo-vagas" class="text-lg font-semibold">Vagas por atividade</h2>
                    <TabelaDeVagas :vagas="props.numeros.vagas" />
                </section>

                <section v-if="dinheiro" aria-labelledby="titulo-dinheiro" class="flex flex-col gap-3" data-testid="painel-dinheiro">
                    <h2 id="titulo-dinheiro" class="text-lg font-semibold">Dinheiro</h2>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <CartaoDeNumero
                            rotulo="Recebido"
                            :valor="formatarValor(dinheiro.recebido_centavos)"
                            tom="sucesso"
                            :significado="`${dinheiro.pagamentos_pagos} cobrança(s) paga(s).`"
                        />
                        <CartaoDeNumero
                            rotulo="A receber"
                            :valor="formatarValor(dinheiro.pendente_centavos)"
                            tom="informacao"
                            :significado="`${dinheiro.pagamentos_pendentes} cobrança(s) ainda aberta(s).`"
                        />
                        <CartaoDeNumero
                            v-if="dinheiro.estornado_centavos > 0"
                            rotulo="Estornado"
                            :valor="formatarValor(dinheiro.estornado_centavos)"
                            tom="atencao"
                            significado="Dinheiro que voltou para quem pagou."
                        />
                    </div>
                </section>
            </div>
        </template>

        <!--
            O provedor de pagamento. Fica fora do bloco do evento de propósito:
            aviso de provedor fala de uma cobrança, não de um evento, e trocar o
            evento no seletor não muda esta resposta.
        -->
        <section
            v-if="props.avisos_do_provedor"
            aria-labelledby="titulo-avisos-do-provedor"
            class="flex flex-col gap-3"
            data-testid="painel-avisos-do-provedor"
        >
            <h2 id="titulo-avisos-do-provedor" class="text-lg font-semibold">Provedor de pagamento</h2>

            <div class="rounded-lg border border-border p-4">
                <template v-if="ultimoAviso">
                    <p class="text-sm text-muted-foreground">Último aviso recebido</p>
                    <p class="text-2xl font-semibold tracking-tight" data-testid="painel-ultimo-aviso">
                        {{ haQuantoTempo(ultimoAviso.minutos_atras) }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ ultimoAviso.recebido_em ?? 'sem data' }} · {{ ultimoAviso.gateway }} · {{ ultimoAviso.situacao_rotulo }}
                    </p>
                </template>

                <!-- Sem nenhum aviso não se calcula intervalo nenhum: um "há — dias" assustaria à toa em sistema recém-publicado ou com o provedor simulado. -->
                <template v-else>
                    <p class="text-base font-semibold" data-testid="painel-sem-aviso">Nenhum aviso ainda</p>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        O provedor de pagamento nunca chamou este sistema. Com a cobrança simulada isso é o esperado; com a cobrança real ligada,
                        costuma significar que o endereço de aviso não foi registrado no painel da Efí.
                    </p>
                </template>

                <Link
                    :href="route('admin.pagamentos.avisos')"
                    class="mt-3 inline-flex h-11 items-center rounded-md border border-border px-4 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Ver os avisos do provedor
                </Link>
            </div>
        </section>
    </AdminLayout>
</template>

<script setup lang="ts">
import CodigoCopiaECola from '@/components/pagamento/CodigoCopiaECola.vue';
import ContadorRegressivo from '@/components/pagamento/ContadorRegressivo.vue';
import QrCodePix from '@/components/pagamento/QrCodePix.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarDataHora, formatarValor } from '@/lib/formato';
import type { EstadoDaCobranca, PropsDaCobranca, SituacaoDaCobranca } from '@/types/pagamento';
import { Head, Link } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { CalendarClock, CheckCircle2, CircleAlert, RefreshCw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * A cobranca Pix da inscricao.
 *
 * Tres telas, e quem escolhe qual e sempre o servidor:
 *   aguardando  — QR Code, copia e cola, prazo e o passo a passo do pagamento
 *   confirmada  — o dinheiro foi reconhecido pelo dominio
 *   expirada    — o prazo acabou e a cobranca nao vale mais
 *
 * Enquanto a pessoa espera, a tela pergunta ao servidor de tempos em tempos se
 * algo mudou, por uma URL assinada. Nenhum parametro daqui declara pagamento:
 * a confirmacao nasce do aviso do provedor, no backend. A consulta para assim
 * que a resposta deixa de ser "aguardando".
 */
const props = defineProps<PropsDaCobranca>();

/** De quanto em quanto tempo perguntamos se o pagamento chegou. */
const INTERVALO_DA_CONSULTA = 5000;

const estado = ref<EstadoDaCobranca>(props.estado);
const situacaoRotulo = ref<string>(props.situacao_rotulo);
const pagoEm = ref<string | null>(props.pagamento?.pago_em ?? null);

const consultando = ref(false);
const falhaNaConsulta = ref(false);
const anuncio = ref('');

const valor = computed(() => formatarValor(props.valor_centavos, props.moeda));
const copiaECola = computed(() => props.pagamento?.pix_copia_e_cola ?? null);
const prazo = computed(() => props.pagamento?.expira_em ?? props.prazo_pagamento);
const confirmadaEm = computed(() => pagoEm.value ?? props.confirmada_em);

/**
 * Pergunta ao servidor qual e a situacao agora. E a unica fonte: a tela nunca
 * decide sozinha que foi pago.
 */
async function consultarSituacao(): Promise<void> {
    if (consultando.value) {
        return;
    }

    consultando.value = true;

    try {
        const resposta = await fetch(props.url_situacao, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!resposta.ok) {
            // Link vencido ou fora do ar: nao adianta insistir.
            falhaNaConsulta.value = true;
            pararConsulta();

            return;
        }

        const dados = (await resposta.json()) as SituacaoDaCobranca;

        falhaNaConsulta.value = false;
        situacaoRotulo.value = dados.situacao_rotulo;
        pagoEm.value = dados.pago_em;
        estado.value = dados.estado;
    } catch {
        // Rede oscilando. Guardamos o aviso discreto e tentamos de novo no
        // proximo ciclo — sem assustar quem esta esperando.
        falhaNaConsulta.value = true;
    } finally {
        consultando.value = false;
    }
}

const { pause: pararConsulta } = useIntervalFn(consultarSituacao, INTERVALO_DA_CONSULTA, {
    immediate: props.estado === 'aguardando',
});

watch(estado, (agora, antes) => {
    if (agora === antes) {
        return;
    }

    if (agora !== 'aguardando') {
        pararConsulta();
    }

    anuncio.value =
        agora === 'confirmada'
            ? 'Pagamento confirmado. Sua inscrição está confirmada.'
            : agora === 'expirada'
              ? 'O prazo para pagar terminou e esta cobrança não vale mais.'
              : '';
});

onBeforeUnmount(() => pararConsulta());

/**
 * As instrucoes de pagamento, como texto.
 *
 * Ficam aqui e nao na marcacao porque a numeracao passou a ser desenhada: em
 * seis blocos repetidos, mudar uma palavra viraria mudar em seis lugares. O
 * `<strong>` sobrevive porque a frase inteira e escrita aqui, e nao montada com
 * dado de fora — nao ha o que um visitante consiga injetar.
 */
const passosDoPagamento = computed<string[]>(() => [
    'Abra o aplicativo do banco onde você tem conta.',
    'Procure a opção <strong>Pix</strong> e escolha <strong>Pagar com QR Code</strong> ou <strong>Pix copia e cola</strong>.',
    'Se escolher QR Code, aponte a câmera do celular para a imagem acima.',
    'Se preferir, toque em <strong>Copiar código Pix</strong> aqui e cole no campo do aplicativo.',
    `Confira o valor de ${valor.value} e conclua o pagamento.`,
    'Volte para esta página: assim que o pagamento for reconhecido, ela muda sozinha.',
]);
</script>

<template>
    <Head :title="estado === 'confirmada' ? 'Inscrição confirmada' : 'Pagamento da inscrição'" />

    <PublicoLayout>
        <!-- Uma unica regiao de anuncio para a troca de estado: o leitor de tela
             conta a novidade sem que a pessoa precise procurar na pagina. -->
        <p role="alert" aria-live="assertive" class="sr-only">{{ anuncio }}</p>

        <div class="space-y-6">
            <header class="space-y-1">
                <p class="text-muted-foreground text-sm">{{ evento.nome }}</p>
                <h1 class="text-2xl leading-tight font-semibold sm:text-3xl">
                    <template v-if="estado === 'confirmada'">Inscrição confirmada</template>
                    <template v-else-if="estado === 'expirada'">Prazo de pagamento vencido</template>
                    <template v-else>Pague com Pix para garantir sua vaga</template>
                </h1>
                <p class="text-muted-foreground text-sm">
                    Inscrição de {{ nome_completo }} · código
                    <span class="font-mono">{{ codigo_publico }}</span>
                </p>
            </header>

            <!-- ESTADO 1: aguardando pagamento -->
            <template v-if="estado === 'aguardando'">
                <Card data-testid="cobranca-aguardando">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-muted-foreground text-base font-medium">Valor a pagar</CardTitle>
                        <!-- .pix__val — a familia dos titulos, como todo preco desta identidade -->
                        <p class="font-titulo text-[34px] leading-none font-semibold tracking-[-0.03em] tabular-nums" data-testid="valor-da-cobranca">
                            {{ valor }}
                        </p>
                    </CardHeader>

                    <CardContent class="space-y-6">
                        <ContadorRegressivo :prazo="prazo" @expirou="consultarSituacao" />

                        <QrCodePix :svg="props.pagamento?.qr_code_svg ?? null" />

                        <CodigoCopiaECola v-if="copiaECola" :codigo="copiaECola" />

                        <!-- .steps-how — o numero em circulo verde lavado, e nao
                             o marcador do navegador: numa lista de instrucoes que
                             a pessoa le com o celular na mao e o aplicativo do
                             banco aberto, o numero precisa ser achado de relance. -->
                        <div>
                            <h2 class="text-base font-semibold">Como pagar, passo a passo</h2>
                            <ol class="mt-[22px] grid list-none gap-3 p-0">
                                <li
                                    v-for="(passo, indice) in passosDoPagamento"
                                    :key="indice"
                                    class="text-muted-foreground flex gap-3 text-[15px] leading-relaxed"
                                >
                                    <span
                                        aria-hidden="true"
                                        class="bg-sucesso-suave text-sucesso-suave-foreground mt-[2px] grid size-[22px] flex-none place-items-center rounded-full text-xs font-bold"
                                    >
                                        {{ indice + 1 }}
                                    </span>
                                    <span v-html="passo"></span>
                                </li>
                            </ol>
                        </div>

                        <div class="flex flex-col gap-2">
                            <p aria-live="polite" class="text-muted-foreground text-sm" data-testid="estado-da-consulta">
                                <span v-if="falhaNaConsulta">
                                    Não consegui conferir o pagamento agora. Confira sua conexão e toque em “Já paguei” abaixo.
                                </span>
                                <span v-else>Estamos conferindo o pagamento para você. Pode deixar esta página aberta.</span>
                            </p>

                            <Button
                                type="button"
                                variant="outline"
                                class="h-12 w-full"
                                :disabled="consultando"
                                data-testid="botao-conferir-pagamento"
                                @click="consultarSituacao"
                            >
                                <RefreshCw :class="consultando ? 'animate-spin' : ''" aria-hidden="true" />
                                {{ consultando ? 'Conferindo...' : 'Já paguei, conferir agora' }}
                            </Button>
                        </div>

                        <Alert variant="informacao">
                            <CalendarClock aria-hidden="true" />
                            <AlertTitle>Guarde o link desta página</AlertTitle>
                            <AlertDescription>
                                Se fechar o navegador, use o mesmo link para voltar a esta cobrança. Ele vale até o fim do prazo.
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>
            </template>

            <!-- ESTADO 2: pagamento reconhecido pelo domínio -->
            <template v-else-if="estado === 'confirmada'">
                <Card class="border-sucesso/40" data-testid="cobranca-confirmada">
                    <CardContent class="space-y-4 pt-6">
                        <div class="flex items-start gap-3">
                            <span class="bg-sucesso text-sucesso-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
                                <CheckCircle2 class="size-6" aria-hidden="true" />
                            </span>

                            <div class="min-w-0 space-y-1">
                                <h2 class="text-xl font-semibold">Recebemos seu pagamento</h2>
                                <p class="text-muted-foreground text-sm leading-relaxed">
                                    Sua inscrição em <strong>{{ evento.nome }}</strong> está confirmada. Você já pode fechar esta página.
                                </p>
                            </div>
                        </div>

                        <dl class="border-border grid gap-3 rounded-lg border p-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-muted-foreground">Situação</dt>
                                <dd class="text-sucesso-texto font-medium">{{ situacaoRotulo }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Valor pago</dt>
                                <dd class="font-medium">{{ valor }}</dd>
                            </div>
                            <div v-if="confirmadaEm">
                                <dt class="text-muted-foreground">Pagamento reconhecido em</dt>
                                <dd class="font-medium">{{ formatarDataHora(confirmadaEm) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Código da inscrição</dt>
                                <dd class="font-mono font-medium">{{ codigo_publico }}</dd>
                            </div>
                        </dl>

                        <p class="text-muted-foreground text-sm">
                            Guarde o código da inscrição: é por ele que a organização encontra você no dia do evento.
                        </p>

                        <Button v-if="evento.slug" as-child variant="outline" class="h-12 w-full">
                            <Link :href="`/eventos/${evento.slug}`">Voltar para a página do evento</Link>
                        </Button>
                    </CardContent>
                </Card>
            </template>

            <!-- ESTADO 3: prazo vencido -->
            <template v-else>
                <Card data-testid="cobranca-expirada">
                    <CardContent class="space-y-4 pt-6">
                        <div class="flex items-start gap-3">
                            <span class="bg-muted text-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
                                <CircleAlert class="size-6" aria-hidden="true" />
                            </span>

                            <div class="min-w-0 space-y-1">
                                <h2 class="text-xl font-semibold">Esta cobrança não vale mais</h2>
                                <p class="text-muted-foreground text-sm leading-relaxed">
                                    O prazo para pagar terminou<template v-if="prazo"> em {{ formatarDataHora(prazo) }}</template
                                    >, e a vaga voltou para quem ainda quer se inscrever. Situação atual: {{ situacaoRotulo }}.
                                </p>
                            </div>
                        </div>

                        <Alert variant="atencao">
                            <CircleAlert aria-hidden="true" />
                            <AlertTitle>Ainda quer participar?</AlertTitle>
                            <AlertDescription>
                                É só fazer uma nova inscrição na página do evento, enquanto houver vagas. Se você pagou e mesmo assim vê esta
                                mensagem, fale com a organização pelo contato no rodapé.
                            </AlertDescription>
                        </Alert>

                        <Button v-if="evento.slug" as-child class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base">
                            <Link :href="`/eventos/${evento.slug}`">Ver o evento e tentar de novo</Link>
                        </Button>
                    </CardContent>
                </Card>
            </template>

            <!-- O caminho para a pagina do participante. Ela vale em qualquer
                 estado: e la que ficam a linha do tempo, o historico da
                 cobranca e a explicacao do que ja aconteceu.

                 Com o prazo vencido, o link desta pagina tambem envelhece —
                 por isso oferecemos, so nesse caso, o pedido de um link novo
                 por e-mail. -->
            <nav aria-label="Outras páginas da sua inscrição" class="border-border flex flex-col gap-1 border-t pt-4">
                <Link
                    :href="url_acompanhamento"
                    class="text-informacao-texto inline-flex min-h-11 items-center text-sm font-medium underline underline-offset-4"
                    data-testid="link-acompanhamento"
                >
                    Acompanhar minha inscrição
                </Link>

                <Link
                    v-if="estado === 'expirada'"
                    :href="evento.slug ? `/acesso?evento=${evento.slug}` : '/acesso'"
                    class="text-informacao-texto inline-flex min-h-11 items-center text-sm font-medium underline underline-offset-4"
                    data-testid="link-recuperar-acesso"
                >
                    Perdi o link da minha inscrição. Receber outro por e-mail
                </Link>
            </nav>
        </div>
    </PublicoLayout>
</template>

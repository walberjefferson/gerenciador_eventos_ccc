<script setup lang="ts">
import HistoricoDaCobranca from '@/components/participante/HistoricoDaCobranca.vue';
import LinhaDoTempo from '@/components/participante/LinhaDoTempo.vue';
import ResumoDaInscricao from '@/components/participante/ResumoDaInscricao.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarDataHora } from '@/lib/formato';
import type { PropsDoAcompanhamento } from '@/types/participante';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, CheckCircle2, CircleAlert, Info, QrCode, RefreshCw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A pagina do participante: o que ja aconteceu com a inscricao, o que falta e
 * o historico da cobranca.
 *
 * E uma tela de leitura. Diferente da tela da cobranca, ela nao fica
 * perguntando ao servidor se o dinheiro chegou: quem esta esperando o Pix cair
 * fica na tela da cobranca, que ja faz isso. Aqui, recarregar basta.
 */
const props = defineProps<PropsDoAcompanhamento>();

const navegando = ref(false);
let pararDeOuvir: (() => void) | null = null;

onMounted(() => {
    pararDeOuvir = router.on('finish', () => {
        navegando.value = false;
    });
});

onBeforeUnmount(() => pararDeOuvir?.());

const temCobrancaAberta = computed(() => props.pagamentos.some((pagamento) => pagamento.situacao === 'pendente'));

/** A cobranca deveria existir e nao existe: e o unico erro possivel nesta tela. */
const faltaCobranca = computed(() => props.pode_pagar && !temCobrancaAberta.value);

const encerrada = computed(() => !props.pode_pagar && props.inscricao.situacao !== 'confirmada');

/**
 * O pedido de segunda via do Pix.
 *
 * Nao cria cobranca nova por conta propria: o servidor chama a Action
 * idempotente, que devolve a cobranca pendente quando ja existe uma. Enquanto
 * o pedido esta indo e voltando, o botao diz o que esta acontecendo.
 */
const pedido = useForm({});
const falhouOPedido = ref(false);

function pedirSegundaVia(): void {
    if (props.url_segunda_via === null) {
        return;
    }

    falhouOPedido.value = false;

    pedido.post(props.url_segunda_via, {
        preserveScroll: true,
        onError: () => {
            falhouOPedido.value = true;
        },
    });
}
</script>

<template>
    <Head :title="`Acompanhar inscrição — ${inscricao.evento.nome ?? 'evento'}`" />

    <PublicoLayout :contato-email="inscricao.evento.contato_email" :contato-telefone="inscricao.evento.contato_telefone">
        <div class="space-y-6">
            <header class="space-y-1">
                <p class="text-sm text-muted-foreground">{{ inscricao.evento.nome }}</p>
                <h1 class="text-2xl font-semibold leading-tight sm:text-3xl">Acompanhe sua inscrição</h1>
                <p class="text-sm text-muted-foreground">
                    Aqui você vê o que já aconteceu com a sua inscrição e o que ainda falta.
                </p>
            </header>

            <!-- Explicacao de quem mandou a pessoa para ca (por exemplo, um
                 pedido de segunda via fora do prazo). Anunciada ao leitor de
                 tela sem interromper o que ele estiver lendo. -->
            <Alert v-if="aviso" variant="atencao" role="status" data-testid="aviso-do-servidor">
                <Info aria-hidden="true" />
                <AlertTitle>Sobre o seu pedido</AlertTitle>
                <AlertDescription>{{ aviso }}</AlertDescription>
            </Alert>

            <ResumoDaInscricao :inscricao="inscricao" />

            <!-- O caminho de volta ao Pix, enquanto ainda da tempo. -->
            <Card v-if="pode_pagar" data-testid="acao-de-pagamento">
                <CardContent class="space-y-4 pt-6">
                    <div class="flex items-start gap-3">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-informacao text-informacao-foreground">
                            <QrCode class="size-6" aria-hidden="true" />
                        </span>

                        <div class="min-w-0 space-y-1">
                            <h2 class="text-lg font-semibold">Falta pagar para garantir sua vaga</h2>
                            <p class="text-sm leading-relaxed text-muted-foreground">
                                <template v-if="inscricao.prazo_pagamento">
                                    Você tem até {{ formatarDataHora(inscricao.prazo_pagamento) }} para pagar o Pix.
                                </template>
                                <template v-else>Pague o Pix para confirmar sua inscrição.</template>
                            </p>
                        </div>
                    </div>

                    <Alert v-if="faltaCobranca" variant="atencao" data-testid="cobranca-ausente">
                        <CircleAlert aria-hidden="true" />
                        <AlertTitle>O código Pix ainda não foi gerado</AlertTitle>
                        <AlertDescription>
                            Isso acontece de vez em quando quando a conexão falha na hora de emitir a cobrança. Toque no botão abaixo para
                            gerar o Pix desta inscrição.
                        </AlertDescription>
                    </Alert>

                    <p v-if="falhouOPedido" role="alert" class="text-sm font-medium text-destructive" data-testid="erro-da-segunda-via">
                        Não consegui gerar o Pix agora. Confira sua conexão e tente de novo em alguns instantes.
                    </p>

                    <Button
                        v-if="url_pagamento && temCobrancaAberta"
                        as-child
                        class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90"
                        data-testid="botao-ver-pix"
                    >
                        <Link :href="url_pagamento" @click="navegando = true">
                            {{ navegando ? 'Abrindo a cobrança...' : 'Ver o Pix para pagar' }}
                        </Link>
                    </Button>

                    <!-- Segunda via: serve tanto para quem ficou sem cobranca
                         quanto para quem perdeu o Pix de vista. -->
                    <Button
                        v-if="url_segunda_via"
                        type="button"
                        :variant="temCobrancaAberta ? 'outline' : 'default'"
                        :class="[
                            'h-12 w-full',
                            temCobrancaAberta ? '' : 'bg-acao text-base text-acao-foreground hover:bg-acao/90',
                        ]"
                        :disabled="pedido.processing"
                        data-testid="botao-segunda-via"
                        @click="pedirSegundaVia"
                    >
                        <RefreshCw :class="pedido.processing ? 'animate-spin' : ''" aria-hidden="true" />
                        <template v-if="pedido.processing">Gerando o Pix...</template>
                        <template v-else-if="temCobrancaAberta">Não achei o Pix. Gerar de novo</template>
                        <template v-else>Gerar o Pix da minha inscrição</template>
                    </Button>
                </CardContent>
            </Card>

            <!-- Inscricao confirmada: nada a fazer, so a boa noticia. -->
            <Alert v-else-if="inscricao.situacao === 'confirmada'" variant="sucesso" data-testid="aviso-confirmada">
                <CheckCircle2 aria-hidden="true" />
                <AlertTitle>Sua inscrição está confirmada</AlertTitle>
                <AlertDescription>
                    Guarde o código da inscrição: é por ele que a organização encontra você no dia do evento.
                </AlertDescription>
            </Alert>

            <!-- Prazo vencido, cancelamento: explicamos em vez de oferecer um botao que nao resolve. -->
            <Alert v-else variant="atencao" data-testid="aviso-encerrada">
                <CalendarClock aria-hidden="true" />
                <AlertTitle>Não é mais possível pagar esta inscrição</AlertTitle>
                <AlertDescription>
                    <template v-if="inscricao.situacao === 'cancelada'">
                        Esta inscrição foi cancelada<template v-if="inscricao.motivo_cancelamento">
                            . Motivo: {{ inscricao.motivo_cancelamento }} </template
                        >.
                    </template>
                    <template v-else>
                        O prazo para pagar terminou<template v-if="inscricao.prazo_pagamento">
                            em {{ formatarDataHora(inscricao.prazo_pagamento) }} </template
                        >, e a vaga voltou para quem ainda quer se inscrever.
                    </template>
                    Se você quiser participar, faça uma nova inscrição na página do evento enquanto houver vagas.
                </AlertDescription>
            </Alert>

            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="text-lg">O que já aconteceu</CardTitle>
                </CardHeader>

                <CardContent>
                    <LinhaDoTempo :marcos="linha_do_tempo" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="text-lg">Histórico da cobrança</CardTitle>
                </CardHeader>

                <CardContent>
                    <HistoricoDaCobranca :pagamentos="pagamentos" :moeda="inscricao.moeda" />
                </CardContent>
            </Card>

            <Button v-if="encerrada && inscricao.evento.slug" as-child variant="outline" class="h-12 w-full">
                <Link :href="`/eventos/${inscricao.evento.slug}`">Ver a página do evento</Link>
            </Button>
        </div>
    </PublicoLayout>
</template>

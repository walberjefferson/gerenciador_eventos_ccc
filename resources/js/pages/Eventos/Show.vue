<script setup lang="ts">
import CabecalhoEvento from '@/components/eventos/CabecalhoEvento.vue';
import ProgramacaoDoDia from '@/components/eventos/ProgramacaoDoDia.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Vitrine do evento. So mostra o que o servidor mandou: se da para se
 * inscrever, quem decidiu foi ele.
 */
const props = defineProps<{
    evento?: EventoPublico | null;
}>();

const evento = computed<EventoPublico | null>(() => props.evento ?? null);

/** Enquanto o Inertia troca de pagina, avisamos que algo esta acontecendo. */
const navegando = ref(false);
const cancelarEventos: Array<() => void> = [];

onMounted(() => {
    cancelarEventos.push(
        router.on('start', () => {
            navegando.value = true;
        }),
        router.on('finish', () => {
            navegando.value = false;
        }),
    );
});

onBeforeUnmount(() => {
    cancelarEventos.forEach((cancelar) => cancelar());
});

const enderecoDaInscricao = computed<string>(() => `/eventos/${evento.value?.slug ?? ''}/inscricao`);

const valor = computed<string>(() => (evento.value ? formatarValor(evento.value.valor_centavos, evento.value.moeda) : ''));
</script>

<template>
    <Head>
        <title>{{ evento ? evento.nome : 'Evento' }}</title>
        <meta v-if="evento?.descricao" name="description" :content="evento.descricao.slice(0, 160)" />
    </Head>

    <PublicoLayout largura="ampla" :contato-email="evento?.contato_email" :contato-telefone="evento?.contato_telefone">
        <div
            v-if="navegando"
            role="status"
            aria-live="polite"
            class="border-border bg-muted text-muted-foreground mb-4 rounded-md border px-4 py-3 text-sm"
        >
            Carregando…
        </div>

        <Alert v-if="!evento" variant="destructive">
            <AlertTitle>Não conseguimos carregar este evento</AlertTitle>
            <AlertDescription> Tente recarregar a página em alguns instantes. Se o problema continuar, fale com a organização. </AlertDescription>
        </Alert>

        <div v-else class="space-y-8">
            <CabecalhoEvento :evento="evento" />

            <!-- Inscricao aberta: o convite. Fechada: a explicacao do porque. -->
            <section aria-labelledby="titulo-inscricao" class="space-y-3">
                <h2 id="titulo-inscricao" class="sr-only">Inscrição</h2>

                <!--
                    Uma acao principal, e uma so.

                    Antes havia dois botoes do mesmo peso e do mesmo tamanho: o
                    de cima levava para fora da pagina e o de baixo, identico,
                    tambem. Dois botoes iguais nao sao duas oportunidades — sao
                    uma pergunta a mais para quem so queria ver a programacao.
                    Agora o segundo virou ancora, que fica na mesma pagina e por
                    isso nao precisa competir.
                -->
                <div v-if="evento.inscricoes_abertas" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <!-- "w-full" e nao "flex-1": num container em coluna, o flex-1 age na
                         vertical e engole a altura de 48px do alvo de toque. -->
                    <Button as-child class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base sm:w-auto sm:min-w-56">
                        <Link :href="enderecoDaInscricao">Quero me inscrever</Link>
                    </Button>

                    <a
                        href="#titulo-programacao"
                        class="text-informacao-texto inline-flex min-h-11 items-center justify-center px-2 text-sm font-semibold underline underline-offset-4"
                    >
                        Ver programação ↓
                    </a>
                </div>

                <Alert v-else variant="atencao">
                    <AlertTitle>As inscrições não estão abertas</AlertTitle>
                    <AlertDescription>
                        {{ evento.motivo_inscricoes_fechadas }}
                    </AlertDescription>
                </Alert>

                <p v-if="evento.inscricoes_abertas" class="text-muted-foreground text-xs">Leva poucos minutos · o pagamento é por Pix, ao final.</p>

                <!-- Quem ja se inscreveu e perdeu o link volta por aqui: informa
                     o e-mail e recebe o endereco da sua inscricao de novo. -->
                <p class="text-sm">
                    <Link
                        :href="`/acesso?evento=${evento.slug}`"
                        class="text-informacao-texto inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                        data-testid="link-ja-me-inscrevi"
                    >
                        Já me inscrevi — acessar minha inscrição
                    </Link>
                </p>
            </section>

            <section aria-labelledby="titulo-programacao" class="scroll-mt-4 space-y-4">
                <h2 id="titulo-programacao" class="text-xl font-semibold">Programação</h2>

                <p v-if="evento.dias.length === 0" class="text-muted-foreground text-sm">A programação ainda será divulgada.</p>

                <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
                    <ProgramacaoDoDia v-for="dia in evento.dias" :key="dia.id" :dia="dia" />
                </div>
            </section>

            <section v-if="evento.regulamento" aria-labelledby="titulo-regulamento">
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle id="titulo-regulamento" class="text-xl">Regulamento</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm leading-relaxed whitespace-pre-line">{{ evento.regulamento }}</p>
                    </CardContent>
                </Card>
            </section>

            <!-- Em tela grande a acao volta ao fim da pagina, porque quem leu a
                 programacao inteira esta longe do botao de cima. No celular
                 quem faz esse papel e a barra presa ao rodape. -->
            <section v-if="evento.inscricoes_abertas" aria-label="Inscrição" class="hidden md:block">
                <Button as-child class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base sm:w-auto">
                    <Link :href="enderecoDaInscricao">Quero me inscrever</Link>
                </Button>
            </section>
        </div>

        <!--
            A barra de acao do celular.

            A maior parte das inscricoes chega por link de WhatsApp, no telefone,
            e a programacao e longa: sem isto, quem leu tudo precisa rolar de
            volta ate o topo para achar o botao. Valor e acao andam juntos de
            proposito — ninguem deveria descobrir o preco duas telas adiante.

            "sticky" e nao "fixed", e a diferenca importa: a barra continua
            ocupando lugar no fluxo da pagina, entao ela nunca cobre o ultimo
            paragrafo nem rouba um clique de quem esta no fim do conteudo. Uma
            barra "fixed" resolveria o mesmo problema visual e criaria esse.
        -->
        <div
            v-if="evento?.inscricoes_abertas"
            class="border-border bg-card/95 sticky bottom-0 z-40 -mx-4 mt-8 border-t px-4 py-3 backdrop-blur md:hidden"
        >
            <div class="flex items-center gap-3">
                <div class="min-w-0">
                    <p class="text-muted-foreground text-xs">Investimento</p>
                    <p class="truncate text-sm font-semibold tabular-nums">{{ valor }}</p>
                </div>

                <Button as-child class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 flex-1 text-base">
                    <Link :href="enderecoDaInscricao">Quero me inscrever</Link>
                </Button>
            </div>
        </div>
    </PublicoLayout>
</template>

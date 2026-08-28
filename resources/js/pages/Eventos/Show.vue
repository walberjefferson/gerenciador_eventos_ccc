<script setup lang="ts">
import CabecalhoEvento from '@/components/eventos/CabecalhoEvento.vue';
import ProgramacaoDoDia from '@/components/eventos/ProgramacaoDoDia.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Vitrine do evento. So mostra o que o servidor mandou: se da para se
 * inscrever, quem decidiu foi ele.
 *
 * Duas colunas em tela grande, como no prototipo: o conteudo a esquerda e, a
 * direita, um painel que ACOMPANHA A ROLAGEM com o valor e a acao. E o painel
 * que resolve o problema desta tela — ela e longa, e sem ele quem termina de
 * ler a programacao esta a uma tela inteira de distancia do botao.
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

const vagasEmPalavras = computed<string | null>(() => {
    const dados = evento.value;

    if (dados === null || dados.vagas_disponiveis === null || dados.capacidade === null) {
        return null;
    }

    return `${dados.vagas_disponiveis} de ${dados.capacidade} vagas livres`;
});
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

        <template v-else>
            <!-- .crumb — 14px, 20px abaixo -->
            <Link href="/" class="text-muted-foreground mb-5 inline-flex min-h-11 items-center gap-[7px] text-sm">← Agenda</Link>

            <CabecalhoEvento :evento="evento" />

            <!-- .cols — 1fr / 320px, com 44px entre elas -->
            <div class="grid gap-11 pb-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <div class="min-w-0">
                    <section aria-labelledby="titulo-programacao">
                        <!-- .block__h — 13px, 0.13em, com a linha embaixo -->
                        <h2
                            id="titulo-programacao"
                            class="text-muted-foreground border-border mb-[22px] scroll-mt-4 border-b pb-[14px] text-[13px] font-semibold tracking-[0.13em] uppercase"
                        >
                            Como funciona o fim de semana
                        </h2>

                        <p v-if="evento.dias.length === 0" class="text-muted-foreground text-[14.5px]">A programação ainda será divulgada.</p>

                        <div v-else class="grid gap-[34px]">
                            <ProgramacaoDoDia v-for="dia in evento.dias" :key="dia.id" :dia="dia" />
                        </div>
                    </section>

                    <!-- O que a inscricao inclui. A secao inteira some quando a
                         lista esta vazia: titulo sem itens embaixo nao informa. -->
                    <section v-if="evento.itens_incluidos.length > 0" aria-labelledby="titulo-incluido" class="mt-11">
                        <h2
                            id="titulo-incluido"
                            class="text-muted-foreground border-border mb-[22px] border-b pb-[14px] text-[13px] font-semibold tracking-[0.13em] uppercase"
                        >
                            O que está incluído
                        </h2>

                        <!-- .list — 15.5px, 11px entre o sinal e o texto -->
                        <ul class="grid gap-[10px]">
                            <li v-for="item in evento.itens_incluidos" :key="item" class="flex gap-[11px] text-[15.5px]">
                                <svg aria-hidden="true" class="text-acao mt-1 size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor">
                                    <circle cx="8" cy="8" r="7" stroke-width="1.4" />
                                    <path d="M4.8 8.2L7 10.3L11.2 5.9" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                    </section>

                    <section v-if="evento.perguntas_frequentes.length > 0" aria-labelledby="titulo-perguntas" class="mt-11">
                        <h2
                            id="titulo-perguntas"
                            class="text-muted-foreground border-border mb-[22px] border-b pb-[14px] text-[13px] font-semibold tracking-[0.13em] uppercase"
                        >
                            Perguntas frequentes
                        </h2>

                        <!-- `details` do proprio navegador, e nao um acordeao
                             escrito a mao: ele ja abre e fecha por teclado, ja e
                             anunciado como "expansivel" pelo leitor de tela e
                             continua funcionando se o JavaScript falhar. -->
                        <div class="border-border border-t">
                            <details v-for="pergunta in evento.perguntas_frequentes" :key="pergunta.pergunta" class="border-border group border-b">
                                <summary class="flex cursor-pointer list-none items-center gap-[14px] py-4 text-base font-medium marker:content-['']">
                                    {{ pergunta.pergunta }}
                                    <span
                                        aria-hidden="true"
                                        class="text-muted-foreground ml-auto text-xl leading-none transition-transform group-open:rotate-45"
                                    >
                                        +
                                    </span>
                                </summary>

                                <p class="text-muted-foreground max-w-[60ch] pb-[18px] text-[15.5px] leading-[1.6]">{{ pergunta.resposta }}</p>
                            </details>
                        </div>
                    </section>

                    <section v-if="evento.regulamento" aria-labelledby="titulo-regulamento" class="mt-11">
                        <h2
                            id="titulo-regulamento"
                            class="text-muted-foreground border-border mb-[22px] border-b pb-[14px] text-[13px] font-semibold tracking-[0.13em] uppercase"
                        >
                            Regulamento
                        </h2>

                        <p class="max-w-[60ch] text-[15.5px] leading-[1.6] whitespace-pre-line">{{ evento.regulamento }}</p>
                    </section>
                </div>

                <!-- .aside / .buy — acompanha a rolagem a partir de 1024px -->
                <aside aria-labelledby="titulo-inscricao" class="lg:sticky lg:top-6">
                    <h2 id="titulo-inscricao" class="sr-only">Inscrição</h2>

                    <div class="border-border bg-card rounded-[14px] border p-[22px] shadow-sm">
                        <!-- .buy__p — Bricolage Grotesque, 32px, -0.03em -->
                        <p class="font-titulo text-[32px] leading-none font-semibold tracking-[-0.03em] tabular-nums">
                            {{ valor }}
                            <span class="text-muted-foreground align-middle text-sm font-normal tracking-normal">/ pessoa</span>
                        </p>

                        <!-- .buy__n — 13.5px, 12px acima -->
                        <p class="text-muted-foreground mt-3 text-[13.5px] leading-[1.5]">
                            Pagamento por Pix ao final. A vaga fica reservada enquanto o prazo corre.
                        </p>

                        <div class="mt-[18px]">
                            <Button
                                v-if="evento.inscricoes_abertas"
                                as-child
                                class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base"
                            >
                                <Link :href="enderecoDaInscricao">Quero me inscrever</Link>
                            </Button>

                            <Alert v-else variant="atencao">
                                <AlertTitle>As inscrições não estão abertas</AlertTitle>
                                <AlertDescription>{{ evento.motivo_inscricoes_fechadas }}</AlertDescription>
                            </Alert>
                        </div>

                        <!-- .buy__list — 14px, 9px entre linhas, linha acima -->
                        <div class="border-border text-muted-foreground mt-[18px] grid gap-[9px] border-t pt-[18px] text-sm">
                            <p v-if="vagasEmPalavras">{{ vagasEmPalavras }}</p>
                            <p v-if="evento.prazo_rotulo">Inscrições {{ evento.prazo_rotulo.toLowerCase() }}</p>
                        </div>

                        <!-- .help — 14px, linha acima, 16px de respiro -->
                        <div
                            v-if="evento.contato_telefone || evento.contato_email"
                            class="border-border text-muted-foreground mt-4 border-t pt-4 text-sm"
                        >
                            <p>Precisa de ajuda?</p>
                            <p v-if="evento.contato_telefone">
                                <a class="text-informacao-texto font-medium underline underline-offset-4" :href="`tel:${evento.contato_telefone}`">
                                    {{ evento.contato_telefone }}
                                </a>
                            </p>
                            <p v-if="evento.contato_email">
                                <a class="text-informacao-texto font-medium underline underline-offset-4" :href="`mailto:${evento.contato_email}`">
                                    {{ evento.contato_email }}
                                </a>
                            </p>
                        </div>
                    </div>

                    <p class="mt-4 text-sm">
                        <Link
                            :href="`/acesso?evento=${evento.slug}`"
                            class="text-informacao-texto inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                            data-testid="link-ja-me-inscrevi"
                        >
                            Já me inscrevi — acessar minha inscrição
                        </Link>
                    </p>
                </aside>
            </div>

            <!--
                A barra de acao do celular, onde o painel lateral nao cabe.

                "sticky" e nao "fixed": ela continua ocupando lugar no fluxo,
                entao nunca cobre o ultimo paragrafo nem rouba um clique de quem
                esta no fim da pagina.
            -->
            <div
                v-if="evento.inscricoes_abertas"
                class="border-border bg-card/95 sticky bottom-0 z-40 -mx-4 mt-8 border-t px-4 py-3 backdrop-blur lg:hidden"
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
        </template>
    </PublicoLayout>
</template>

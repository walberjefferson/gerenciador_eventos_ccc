<script setup lang="ts">
import CabecalhoEvento from '@/components/eventos/CabecalhoEvento.vue';
import ProgramacaoDoDia from '@/components/eventos/ProgramacaoDoDia.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
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
</script>

<template>
    <Head>
        <title>{{ evento ? evento.nome : 'Evento' }}</title>
        <meta v-if="evento?.descricao" name="description" :content="evento.descricao.slice(0, 160)" />
    </Head>

    <PublicoLayout :contato-email="evento?.contato_email" :contato-telefone="evento?.contato_telefone">
        <div
            v-if="navegando"
            role="status"
            aria-live="polite"
            class="mb-4 rounded-md border border-border bg-muted px-4 py-3 text-sm text-muted-foreground"
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

                <Button v-if="evento.inscricoes_abertas" as-child class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90">
                    <Link :href="enderecoDaInscricao">Quero me inscrever</Link>
                </Button>

                <Alert v-else variant="atencao">
                    <AlertTitle>As inscrições não estão abertas</AlertTitle>
                    <AlertDescription>
                        {{ evento.motivo_inscricoes_fechadas }}
                    </AlertDescription>
                </Alert>
            </section>

            <section aria-labelledby="titulo-programacao" class="space-y-4">
                <h2 id="titulo-programacao" class="text-xl font-semibold">Programação</h2>

                <p v-if="evento.dias.length === 0" class="text-sm text-muted-foreground">A programação ainda será divulgada.</p>

                <ProgramacaoDoDia v-for="dia in evento.dias" :key="dia.id" :dia="dia" />
            </section>

            <section v-if="evento.regulamento" aria-labelledby="titulo-regulamento">
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle id="titulo-regulamento" class="text-xl">Regulamento</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="whitespace-pre-line text-sm leading-relaxed">{{ evento.regulamento }}</p>
                    </CardContent>
                </Card>
            </section>

            <section v-if="evento.inscricoes_abertas" aria-label="Inscrição">
                <Button as-child class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90">
                    <Link :href="enderecoDaInscricao">Quero me inscrever</Link>
                </Button>
            </section>
        </div>
    </PublicoLayout>
</template>

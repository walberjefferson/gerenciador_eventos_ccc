<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * A porta da rua.
 *
 * Quem chega aqui veio de um link no WhatsApp, esta no celular e decide em
 * segundos se aquilo e o lugar certo. Entao a tela responde tres perguntas —
 * qual e o evento, quando ele acontece e como se inscrever — e encaminha para
 * a vitrine, que e quem tem programacao, regulamento e formulario. Nada disso
 * e repetido aqui.
 *
 * Quem decide se da para se inscrever e o servidor: sem `inscricoes_abertas`
 * verdadeiro no que ele mandou, nao existe convite para se inscrever.
 */

/** O evento como a porta da rua o conhece: o minimo para apresentar e encaminhar. */
interface EventoEmDestaque {
    nome: string;
    slug: string;
    /** A descricao encurtada, ou null quando o evento nao tem uma. */
    resumo: string | null;
    data_inicio: string;
    data_fim: string;
    /** O periodo ja escrito em portugues pelo servidor. */
    periodo_rotulo: string;
    situacao: string;
    situacao_rotulo: string;
    inscricoes_abertas: boolean;
    /** Frase pronta dizendo a partir de quando da para se inscrever. */
    abre_em_rotulo: string | null;
}

const props = withDefaults(
    defineProps<{
        destaque?: EventoEmDestaque | null;
        outros_abertos?: EventoEmDestaque[];
        proximo?: EventoEmDestaque | null;
        aviso_sem_inscricoes?: string | null;
    }>(),
    {
        destaque: null,
        outros_abertos: () => [],
        proximo: null,
        aviso_sem_inscricoes: null,
    },
);

const destaque = computed<EventoEmDestaque | null>(() => props.destaque ?? null);

const titulo = computed<string>(() => (destaque.value ? `${destaque.value.nome} — inscrições abertas` : 'Inscrições da Caminhada Comunitária com Cristo'));

const descricao = computed<string>(() =>
    destaque.value
        ? `${destaque.value.nome}. ${destaque.value.periodo_rotulo}. Inscrições abertas.`
        : 'No momento não há inscrições abertas para a Caminhada Comunitária com Cristo.',
);

/**
 * O caminho de volta de quem ja se inscreveu (DA-36). Quando ha um evento em
 * destaque, o pedido de acesso ja chega sabendo de qual evento se trata.
 */
const enderecoDoAcesso = computed<string>(() => (destaque.value ? `/acesso?evento=${destaque.value.slug}` : '/acesso'));
</script>

<template>
    <Head :title="titulo">
        <meta name="description" :content="descricao" />
    </Head>

    <PublicoLayout>
        <div class="space-y-8">
            <!-- Um evento com inscricoes abertas: o convite direto. -->
            <template v-if="destaque">
                <header class="space-y-3">
                    <Badge variant="sucesso">Inscrições abertas</Badge>

                    <h1 class="text-2xl font-bold leading-tight sm:text-3xl">{{ destaque.nome }}</h1>

                    <p class="text-base font-medium">{{ destaque.periodo_rotulo }}</p>

                    <p v-if="destaque.resumo" class="text-base leading-relaxed text-muted-foreground">
                        {{ destaque.resumo }}
                    </p>
                </header>

                <section aria-labelledby="titulo-inscricao" class="space-y-3">
                    <h2 id="titulo-inscricao" class="sr-only">Inscrição</h2>

                    <Button as-child class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90">
                        <Link :href="`/eventos/${destaque.slug}`" data-testid="botao-fazer-inscricao">Fazer inscrição</Link>
                    </Button>

                    <Button as-child variant="outline" class="h-12 w-full text-base">
                        <Link :href="`/eventos/${destaque.slug}#titulo-programacao`" data-testid="link-programacao">Ver a programação</Link>
                    </Button>
                </section>
            </template>

            <!-- Nenhum evento aberto: o aviso, sem botao nenhum de inscricao. -->
            <template v-else>
                <header class="space-y-3">
                    <h1 class="text-2xl font-bold leading-tight sm:text-3xl">Inscrições</h1>
                </header>

                <Alert variant="atencao" data-testid="aviso-sem-inscricoes">
                    <AlertTitle>{{ aviso_sem_inscricoes ?? 'No momento não há inscrições abertas.' }}</AlertTitle>
                    <AlertDescription> Assim que um novo evento abrir inscrições, ele aparece aqui. </AlertDescription>
                </Alert>
            </template>

            <!-- Quem ja se inscreveu e perdeu o link volta por aqui (DA-36). -->
            <p class="text-sm">
                <Link
                    :href="enderecoDoAcesso"
                    class="inline-flex min-h-11 items-center font-medium text-informacao-texto underline underline-offset-4"
                    data-testid="link-ja-fiz-minha-inscricao"
                >
                    Já fiz minha inscrição
                </Link>
            </p>
        </div>
    </PublicoLayout>
</template>

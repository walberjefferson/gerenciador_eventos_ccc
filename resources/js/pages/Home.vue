<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarValor } from '@/lib/formato';
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
    /** Em centavos inteiros, como o dominio guarda (D-06). */
    valor_centavos: number;
    situacao: string;
    situacao_rotulo: string;
    inscricoes_abertas: boolean;
    /** Frase pronta dizendo a partir de quando da para se inscrever. */
    abre_em_rotulo: string | null;
    /** "Encerram em 12 dias", ja escrito pelo servidor. null quando fechadas. */
    prazo_rotulo: string | null;
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

const titulo = computed<string>(() =>
    destaque.value ? `${destaque.value.nome} — inscrições abertas` : 'Inscrições da Caminhada Comunitária com Cristo',
);

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
        <!-- O hero e a faixa de informacao sangram de borda a borda; o conteudo
             dentro deles respeita o mesmo max-w-3xl das demais secoes. Sem foto
             de proposito: imagem de banco numa comunidade soa falsa, e a forca
             aqui vem do tamanho do nome sobre fundo cheio. No dia em que houver
             foto de uma edicao anterior, ela entra atras disto. -->
        <template v-if="destaque" #hero>
            <div class="bg-informacao text-informacao-foreground">
                <div class="mx-auto w-full max-w-3xl px-4 pt-8 pb-8 sm:pt-12 sm:pb-12">
                    <div class="flex flex-wrap gap-2">
                        <Badge variant="sucesso">Inscrições abertas</Badge>

                        <!-- O prazo e o unico fato desta tela que muda com o
                             relogio, e e o que faz alguem agir hoje em vez de
                             "depois eu vejo". Vaga restante continua de fora,
                             por decisao que nao mudou: na porta de entrada vira
                             pressao sem contexto. -->
                        <Badge v-if="destaque.prazo_rotulo" variant="atencao">{{ destaque.prazo_rotulo }}</Badge>
                    </div>

                    <h1 class="mt-3 text-3xl leading-none font-extrabold sm:text-5xl">{{ destaque.nome }}</h1>

                    <!-- A data saiu daqui de proposito: ela esta na grade de
                         fatos, logo abaixo. Repetida nos dois lugares, uma
                         delas vira ruido — e a de cima era a que nao podia ser
                         comparada com nada. -->
                    <p v-if="destaque.resumo" class="mt-3 max-w-prose text-sm leading-relaxed opacity-90 sm:text-base">
                        {{ destaque.resumo }}
                    </p>
                </div>
            </div>

            <!-- Os fatos decisivos, em caixas do mesmo peso.
                 Antes, esta faixa dizia so o valor, e o resto da porta de
                 entrada era espaco em branco: quem chegava pelo WhatsApp
                 precisava abrir a vitrine so para saber quando e o evento.
                 Vaga restante NAO entra aqui, e a decisao nao mudou — na porta
                 de entrada ela vira pressao sem contexto e fica errada no
                 segundo seguinte. Quem precisa dela ve na vitrine, por
                 atividade, que e onde o numero significa alguma coisa. -->
            <div class="border-border bg-card border-b">
                <dl class="mx-auto grid w-full max-w-3xl grid-cols-2 gap-3 px-4 py-4">
                    <div class="border-border space-y-1 rounded-lg border p-4">
                        <dt class="text-muted-foreground text-xs font-bold tracking-wider uppercase">Quando</dt>
                        <dd class="text-sm font-semibold">{{ destaque.periodo_rotulo }}</dd>
                    </div>

                    <div class="border-border space-y-1 rounded-lg border p-4">
                        <dt class="text-muted-foreground text-xs font-bold tracking-wider uppercase">Investimento</dt>
                        <dd class="text-sm font-semibold tabular-nums">{{ formatarValor(destaque.valor_centavos) }}</dd>
                        <dd class="text-muted-foreground text-xs">por pessoa</dd>
                    </div>
                </dl>
            </div>
        </template>

        <div class="space-y-8">
            <!-- Um evento com inscricoes abertas: o convite direto. -->
            <template v-if="destaque">
                <section aria-labelledby="titulo-inscricao" class="space-y-3">
                    <h2 id="titulo-inscricao" class="sr-only">Inscrição</h2>

                    <!--
                        Uma acao principal, e uma so.

                        "Fazer inscricao" e "Ver a programacao" tinham o mesmo
                        peso visual e o mesmo tamanho, e por isso disputavam a
                        mesma decisao. Ver a programacao e um pedido de quem
                        ainda esta decidindo — nao precisa da mesma forca de um
                        botao cheio, e agora e link.
                    -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Button as-child class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base sm:w-auto sm:min-w-64">
                            <Link :href="`/eventos/${destaque.slug}`" data-testid="botao-fazer-inscricao">
                                Fazer inscrição — {{ formatarValor(destaque.valor_centavos) }}
                            </Link>
                        </Button>

                        <Link
                            :href="`/eventos/${destaque.slug}#titulo-programacao`"
                            class="text-informacao-texto inline-flex min-h-11 items-center justify-center px-2 text-sm font-semibold underline underline-offset-4"
                            data-testid="link-programacao"
                        >
                            Ver a programação
                        </Link>
                    </div>

                    <p class="text-muted-foreground text-xs">Leva poucos minutos · o pagamento é por Pix, ao final.</p>
                </section>

                <!-- Mais de um evento aberto (DA-38): o de inicio mais proximo
                     ficou em destaque; os demais vem aqui, de forma enxuta,
                     cada um levando a sua propria vitrine. -->
                <section v-if="outros_abertos.length > 0" aria-labelledby="titulo-outros-eventos" class="space-y-3">
                    <h2 id="titulo-outros-eventos" class="text-xl font-semibold">Outros eventos com inscrições abertas</h2>

                    <ul class="space-y-2" data-testid="lista-outros-eventos">
                        <li v-for="evento in outros_abertos" :key="evento.slug">
                            <Link
                                :href="`/eventos/${evento.slug}`"
                                class="border-border bg-card flex min-h-11 flex-col justify-center rounded-lg border px-4 py-3"
                            >
                                <span class="font-medium">{{ evento.nome }}</span>
                                <span class="text-muted-foreground text-sm">{{ evento.periodo_rotulo }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>

            <!-- Nenhum evento aberto: o aviso, sem botao nenhum de inscricao. -->
            <template v-else>
                <header class="space-y-3">
                    <h1 class="text-2xl leading-tight font-bold sm:text-3xl">Inscrições</h1>
                </header>

                <Alert variant="atencao" data-testid="aviso-sem-inscricoes">
                    <AlertTitle>{{ aviso_sem_inscricoes ?? 'No momento não há inscrições abertas.' }}</AlertTitle>
                    <AlertDescription> Assim que um novo evento abrir inscrições, ele aparece aqui. </AlertDescription>
                </Alert>

                <!-- Quem chegou cedo demais precisa saber quando voltar
                     (DA-35). O evento ja esta publicado, mas a janela de
                     inscricao ainda nao comecou: aqui ele so se apresenta, e
                     nunca ganha botao de inscricao. -->
                <section v-if="proximo" aria-labelledby="titulo-proximo-evento" class="space-y-3" data-testid="proximo-evento">
                    <h2 id="titulo-proximo-evento" class="text-xl font-semibold">Próximo evento</h2>

                    <div class="border-border bg-card space-y-2 rounded-lg border px-4 py-4">
                        <p class="text-lg leading-tight font-semibold">{{ proximo.nome }}</p>

                        <p class="text-base">{{ proximo.periodo_rotulo }}</p>

                        <p v-if="proximo.abre_em_rotulo" class="text-sm font-medium" data-testid="abertura-do-proximo">
                            {{ proximo.abre_em_rotulo }}
                        </p>

                        <p v-if="proximo.resumo" class="text-muted-foreground text-sm leading-relaxed">{{ proximo.resumo }}</p>
                    </div>
                </section>
            </template>

            <!-- Quem ja se inscreveu e perdeu o link volta por aqui (DA-36). -->
            <p class="text-sm">
                <Link
                    :href="enderecoDoAcesso"
                    class="text-informacao-texto inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                    data-testid="link-ja-fiz-minha-inscricao"
                >
                    Já fiz minha inscrição
                </Link>
            </p>
        </div>
    </PublicoLayout>
</template>

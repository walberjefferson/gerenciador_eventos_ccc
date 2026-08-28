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
 * segundos se aquilo e o lugar certo. Entao a tela responde as perguntas que
 * vem antes da decisao — qual e o evento, quando, onde, quanto custa e o que
 * acontece em cada dia — e encaminha para a vitrine, que e quem tem a
 * programacao completa, o regulamento e o formulario.
 *
 * O convite e um CARTAO de duas colunas: o que o evento e, a esquerda; o que
 * custa e como entrar, a direita. Em tela estreita as duas viram uma so, nessa
 * ordem — porque no celular a pessoa le antes de decidir.
 *
 * Quem decide se da para se inscrever e o servidor: sem `inscricoes_abertas`
 * verdadeiro no que ele mandou, nao existe convite para se inscrever.
 */

/** Um dia do evento, resumido pelo servidor. */
interface DiaEmResumo {
    id: number;
    nome: string;
    /** "Sábado · 17/10", ja escrito. */
    quando: string;
    /** O que acontece nesse dia, numa frase. */
    resumo: string;
}

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
    /** O nome curto do lugar, ou null enquanto ninguem o cadastrou. */
    local: string | null;
    /** Em centavos inteiros, como o dominio guarda (D-06). */
    valor_centavos: number;
    /** null quando o evento nao tem teto de vagas. */
    vagas_disponiveis: number | null;
    capacidade: number | null;
    situacao: string;
    situacao_rotulo: string;
    inscricoes_abertas: boolean;
    /** Frase pronta dizendo a partir de quando da para se inscrever. */
    abre_em_rotulo: string | null;
    /** "Encerram em 12 dias", ja escrito pelo servidor. null quando fechadas. */
    prazo_rotulo: string | null;
    /** So o evento em destaque carrega os dias; nos outros a chave nem vem. */
    dias?: DiaEmResumo[];
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

const dias = computed<DiaEmResumo[]>(() => destaque.value?.dias ?? []);

/** A linha acima do titulo: quando e, e onde — quando o lugar ja foi cadastrado. */
const quandoEOnde = computed<string>(() => {
    const evento = destaque.value;

    if (evento === null) {
        return '';
    }

    return evento.local === null ? evento.periodo_rotulo : `${evento.periodo_rotulo} · ${evento.local}`;
});

/**
 * Quanto da capacidade ja foi tomada, de 0 a 100.
 *
 * A barra mede o que JA FOI OCUPADO — e é assim que se lê uma barra que enche.
 * O protótipo desenha o contrário, com a barra cheia representando vaga livre;
 * seguir isso deixaria as duas barras do sistema dizendo coisas opostas, e a
 * da vitrine já estava escrita. Sem capacidade declarada não há fração
 * possível, e a barra simplesmente não aparece.
 */
const percentualOcupado = computed<number | null>(() => {
    const evento = destaque.value;

    if (evento === null || evento.capacidade === null || evento.capacidade <= 0 || evento.vagas_disponiveis === null) {
        return null;
    }

    const ocupadas = Math.min(Math.max(evento.capacidade - evento.vagas_disponiveis, 0), evento.capacidade);

    return Math.round((ocupadas / evento.capacidade) * 100);
});

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

    <PublicoLayout largura="ampla">
        <div class="space-y-12">
            <!-- Um evento com inscricoes abertas: o convite direto. -->
            <template v-if="destaque">
                <section aria-labelledby="titulo-inscricao">
                    <p class="text-muted-foreground text-xs font-bold tracking-[0.14em] uppercase">Próximo encontro da comunidade</p>

                    <div class="border-border bg-card mt-4 grid overflow-hidden rounded-2xl border shadow-sm lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <!-- Coluna da esquerda: o que o evento e. -->
                        <div class="p-6 sm:p-8">
                            <div class="flex flex-wrap gap-2">
                                <Badge variant="sucesso">Inscrições abertas</Badge>

                                <!-- O prazo e o unico fato desta tela que muda com
                                     o relogio, e e o que faz alguem agir hoje em
                                     vez de "depois eu vejo". -->
                                <Badge v-if="destaque.prazo_rotulo" variant="atencao">{{ destaque.prazo_rotulo }}</Badge>
                            </div>

                            <p class="text-acao-texto mt-5 text-xs font-bold tracking-wider uppercase">{{ quandoEOnde }}</p>

                            <h1 id="titulo-inscricao" class="mt-2 text-3xl leading-none font-bold tracking-tight sm:text-5xl">
                                {{ destaque.nome }}
                            </h1>

                            <p v-if="destaque.resumo" class="text-muted-foreground mt-4 max-w-prose leading-relaxed">
                                {{ destaque.resumo }}
                            </p>

                            <!--
                                A trilha dos dias.

                                Ela responde "o que eu vou fazer la?" sem obrigar a
                                abrir a vitrine — que era o passo que a porta da rua
                                cobrava de todo mundo. A linha pontilhada e os
                                pontos sao DECORACAO e saem do leitor de tela: a
                                ordem ja vem da propria lista numerada.
                            -->
                            <ol v-if="dias.length > 0" class="border-border mt-7 border-t pt-6">
                                <li v-for="(dia, indice) in dias" :key="dia.id" class="relative pb-6 pl-8 last:pb-0">
                                    <span aria-hidden="true" class="border-acao bg-card absolute top-1 left-0 size-3 rounded-full border-2"></span>

                                    <span
                                        v-if="indice < dias.length - 1"
                                        aria-hidden="true"
                                        class="border-border absolute top-4 bottom-0 left-[5px] w-px border-l border-dashed"
                                    ></span>

                                    <p class="text-muted-foreground text-xs font-bold tracking-wider uppercase">{{ dia.quando }}</p>
                                    <p class="mt-1 font-semibold">{{ dia.nome }}</p>
                                    <p class="text-muted-foreground mt-1 max-w-prose text-sm leading-relaxed">{{ dia.resumo }}</p>
                                </li>
                            </ol>
                        </div>

                        <!-- Coluna da direita: o que custa e como entrar. -->
                        <div class="border-border bg-muted/40 border-t p-6 sm:p-8 lg:border-t-0 lg:border-l">
                            <p class="text-muted-foreground text-xs font-bold tracking-wider uppercase">Investimento</p>

                            <!-- O preco e a unidade em LINHAS separadas.
                                 Na mesma linha, a fonte monoespacada dos numeros
                                 nao cabe nos 20rem da coluna e o "/ pessoa"
                                 quebrava sozinho, orfao, embaixo do valor. -->
                            <p class="mt-2 text-3xl font-bold tracking-tight tabular-nums">
                                {{ formatarValor(destaque.valor_centavos) }}
                            </p>
                            <p class="text-muted-foreground text-sm">por pessoa</p>

                            <!-- Vaga restante na porta de entrada entrou na Etapa
                                 26, por decisao do dono do produto. Antes era
                                 proibida, e a ressalva continua verdadeira: o
                                 numero desatualiza no segundo seguinte. Por isso
                                 ele vem do servidor a cada carga, e nunca de
                                 cache. -->
                            <div v-if="percentualOcupado !== null" class="mt-5">
                                <p class="text-muted-foreground text-sm">
                                    {{ destaque.vagas_disponiveis }} de {{ destaque.capacidade }} vagas livres
                                </p>

                                <div aria-hidden="true" class="bg-muted mt-2 h-1.5 overflow-hidden rounded-full">
                                    <div class="bg-acao h-full rounded-full" :style="{ width: `${percentualOcupado}%` }"></div>
                                </div>
                            </div>

                            <div class="mt-6 grid gap-3">
                                <Button as-child class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base">
                                    <Link :href="`/eventos/${destaque.slug}`" data-testid="botao-fazer-inscricao">
                                        Fazer inscrição — {{ formatarValor(destaque.valor_centavos) }}
                                    </Link>
                                </Button>

                                <Button as-child variant="outline" class="h-12 w-full text-base">
                                    <Link :href="`/eventos/${destaque.slug}#titulo-programacao`" data-testid="link-programacao">
                                        Ver a programação
                                    </Link>
                                </Button>
                            </div>

                            <p class="text-muted-foreground mt-6 text-sm leading-relaxed">Leva poucos minutos. O pagamento é por Pix, ao final.</p>

                            <p class="mt-1 text-sm">
                                <Link
                                    :href="enderecoDoAcesso"
                                    class="text-informacao-texto inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                                    data-testid="link-ja-fiz-minha-inscricao"
                                >
                                    Já fiz minha inscrição
                                </Link>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Depois dessa: o que ainda vem.
                     Reune os outros eventos com inscricao aberta (DA-38) e o que
                     ainda vai abrir (DA-35). Sao coisas diferentes e por isso o
                     cartao de cada um diz o que da para fazer com ele: um leva a
                     vitrine, o outro so avisa a data de abertura. -->
                <section v-if="outros_abertos.length > 0 || proximo" aria-labelledby="titulo-depois-dessa" class="space-y-4">
                    <div class="border-border flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b pb-4">
                        <h2 id="titulo-depois-dessa" class="text-xl font-semibold">Depois dessa</h2>
                        <span class="text-muted-foreground text-sm">Os próximos encontros da comunidade</span>
                    </div>

                    <ul v-if="outros_abertos.length > 0" class="grid gap-4 sm:grid-cols-2" data-testid="lista-outros-eventos">
                        <li v-for="evento in outros_abertos" :key="evento.slug">
                            <Link
                                :href="`/eventos/${evento.slug}`"
                                class="border-border bg-card hover:border-acao flex h-full flex-col rounded-2xl border p-6 transition-colors"
                            >
                                <Badge variant="sucesso" class="self-start">Inscrições abertas</Badge>

                                <span class="mt-3 text-lg font-semibold tracking-tight">{{ evento.nome }}</span>
                                <span class="text-muted-foreground mt-1 text-sm">{{ evento.periodo_rotulo }}</span>

                                <span v-if="evento.resumo" class="text-muted-foreground mt-3 text-sm leading-relaxed">
                                    {{ evento.resumo }}
                                </span>

                                <span class="mt-auto pt-4 text-sm font-semibold tabular-nums">
                                    {{ formatarValor(evento.valor_centavos) }}
                                    <span class="text-muted-foreground text-xs font-normal">por pessoa</span>
                                </span>
                            </Link>
                        </li>
                    </ul>

                    <!-- Quem chegou cedo demais precisa saber quando voltar
                         (DA-35). O evento ja esta publicado, mas a janela de
                         inscricao ainda nao comecou: aqui ele so se apresenta, e
                         nunca ganha botao de inscricao. -->
                    <div v-if="proximo" class="grid gap-4 sm:grid-cols-2" data-testid="proximo-evento">
                        <div class="border-border bg-card flex flex-col rounded-2xl border p-6">
                            <Badge variant="secondary" class="self-start">Inscrições ainda não abertas</Badge>

                            <p class="mt-3 text-lg font-semibold tracking-tight">{{ proximo.nome }}</p>
                            <p class="text-muted-foreground mt-1 text-sm">{{ proximo.periodo_rotulo }}</p>

                            <p v-if="proximo.abre_em_rotulo" class="mt-3 text-sm font-medium" data-testid="abertura-do-proximo">
                                {{ proximo.abre_em_rotulo }}
                            </p>

                            <p v-if="proximo.resumo" class="text-muted-foreground mt-2 text-sm leading-relaxed">{{ proximo.resumo }}</p>
                        </div>
                    </div>
                </section>
            </template>

            <!-- Nenhum evento aberto: o aviso, sem botao nenhum de inscricao. -->
            <template v-else>
                <header>
                    <h1 class="text-2xl leading-tight font-bold sm:text-3xl">Inscrições</h1>
                </header>

                <Alert variant="atencao" data-testid="aviso-sem-inscricoes">
                    <AlertTitle>{{ aviso_sem_inscricoes ?? 'No momento não há inscrições abertas.' }}</AlertTitle>
                    <AlertDescription> Assim que um novo evento abrir inscrições, ele aparece aqui. </AlertDescription>
                </Alert>

                <section v-if="proximo" aria-labelledby="titulo-proximo-evento" class="space-y-3" data-testid="proximo-evento">
                    <h2 id="titulo-proximo-evento" class="text-xl font-semibold">Próximo evento</h2>

                    <div class="border-border bg-card space-y-2 rounded-2xl border p-6">
                        <p class="text-lg leading-tight font-semibold">{{ proximo.nome }}</p>

                        <p class="text-base">{{ proximo.periodo_rotulo }}</p>

                        <p v-if="proximo.abre_em_rotulo" class="text-sm font-medium" data-testid="abertura-do-proximo">
                            {{ proximo.abre_em_rotulo }}
                        </p>

                        <p v-if="proximo.resumo" class="text-muted-foreground text-sm leading-relaxed">{{ proximo.resumo }}</p>
                    </div>
                </section>

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
            </template>
        </div>
    </PublicoLayout>
</template>

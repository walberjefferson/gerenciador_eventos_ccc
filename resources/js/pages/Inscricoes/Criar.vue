<script setup lang="ts">
import IndicadorDePassos from '@/components/inscricao/IndicadorDePassos.vue';
import PassoDadosPessoais from '@/components/inscricao/PassoDadosPessoais.vue';
import PassoParticipacao from '@/components/inscricao/PassoParticipacao.vue';
import PassoRevisao from '@/components/inscricao/PassoRevisao.vue';
import ResumoDaInscricao from '@/components/inscricao/ResumoDaInscricao.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useGruposDaCidade } from '@/composables/useGruposDaCidade';
import { useSelecaoAtividades } from '@/composables/useSelecaoAtividades';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarValor } from '@/lib/formato';
import type { DiaEventoPublico, EventoPublico } from '@/types/evento';
import type { CidadePublica, ConflitoDeAtividades, FormularioInscricao, GrupoParticipantePublico, PassoDaInscricao } from '@/types/inscricao';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

/**
 * Formulario de inscricao. As etapas sao navegacao na propria tela: nada e
 * gravado ate o envio final, porque o backend cria a inscricao inteira de uma
 * vez so.
 */
const props = defineProps<{
    evento?: EventoPublico | null;
    evento_id?: number;
    cidades?: CidadePublica[];
    grupos_participantes?: GrupoParticipantePublico[];
    conflitos?: ConflitoDeAtividades[];
}>();

const evento = computed<EventoPublico | null>(() => props.evento ?? null);
const cidades = computed<CidadePublica[]>(() => props.cidades ?? []);
const gruposParticipantes = computed<GrupoParticipantePublico[]>(() => props.grupos_participantes ?? []);
const conflitos = computed<ConflitoDeAtividades[]>(() => props.conflitos ?? []);
const dias = computed<DiaEventoPublico[]>(() => evento.value?.dias ?? []);

/**
 * A chave de idempotencia nasce quando o formulario abre e acompanha todas as
 * tentativas de envio: duplo clique ou reenvio nao viram duas inscricoes.
 */
function novaChave(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return '00000000-0000-4000-8000-000000000000';
}

const formulario = ref<FormularioInscricao>({
    evento_id: props.evento_id ?? 0,
    cidade_id: null,
    grupo_participante_id: null,
    nome_completo: '',
    email: '',
    telefone: '',
    documento: '',
    data_nascimento: '',
    atividades: [],
    aceite_termos: false,
    chave_idempotencia: novaChave(),
});

const cidadeId = computed<number | null>({
    get: () => formulario.value.cidade_id,
    set: (valor) => {
        formulario.value.cidade_id = valor;
    },
});

const grupoId = computed<number | null>({
    get: () => formulario.value.grupo_participante_id,
    set: (valor) => {
        formulario.value.grupo_participante_id = valor;
    },
});

const { gruposDaCidade, avisoSemGrupos } = useGruposDaCidade(cidades, gruposParticipantes, cidadeId, grupoId);

const dataNascimento = computed<string>(() => formulario.value.data_nascimento);

const selecao = useSelecaoAtividades({ dias, conflitos, dataNascimento });

const passo = ref<PassoDaInscricao>('dados');
const mostrarProblemasDaParticipacao = ref(false);
const errosLocais = ref<Record<string, string>>({});
const errosDoServidor = ref<Record<string, string>>({});
const enviando = ref(false);

/** O que a tela mostra: o erro do servidor sempre por cima do nosso palpite. */
const erros = computed<Record<string, string>>(() => ({ ...errosLocais.value, ...errosDoServidor.value }));
const anuncio = ref('');
const tituloDoPasso = ref<HTMLElement | null>(null);

const titulos: Record<PassoDaInscricao, string> = {
    dados: 'Seus dados',
    participacao: 'Sua participação',
    revisao: 'Revisão',
    pagamento: 'Pagamento',
};

/** Troca de etapa: anuncia para o leitor de tela e leva o foco para o titulo. */
async function irPara(destino: PassoDaInscricao): Promise<void> {
    passo.value = destino;
    anuncio.value = `Etapa ${titulos[destino]}.`;

    await nextTick();
    tituloDoPasso.value?.focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function apenasDigitos(valor: string): string {
    return valor.replace(/\D/g, '');
}

/**
 * A data existe mesmo?
 *
 * Enquanto o campo de nascimento era um `<input type="date">`, quem impedia
 * 31/02/2000 era o proprio navegador — nao havia como digitar um dia que nao
 * existe. Com o campo digitavel do desenho isso deixou de ser verdade, e a
 * conferencia precisa ser escrita: o mes de fevereiro nao tem 31 dias, e quem
 * digitou merece ouvir isso aqui em vez de esperar a viagem ate o servidor.
 */
function dataExiste(iso: string): boolean {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return false;
    }

    const [ano, mes, dia] = iso.split('-').map(Number);
    const candidata = new Date(Date.UTC(ano, mes - 1, dia));

    // O Date "corrige" 31/02 para 03/03 em silencio. Se o que voltou nao e o
    // que entrou, a data digitada nao existe no calendario.
    return candidata.getUTCFullYear() === ano && candidata.getUTCMonth() === mes - 1 && candidata.getUTCDate() === dia;
}

/** Hoje em ISO — o mesmo teto que o calendario do campo respeita. */
const hojeEmIso = new Date().toLocaleDateString('sv-SE');

/**
 * Conferencia de formato — a mesma que o StoreInscricaoRequest faz. Nao e
 * regra de negocio: so evita a viagem ate o servidor por um campo vazio.
 *
 * Um conferidor por campo, e nao um bloco unico, porque agora o mesmo criterio
 * e usado em dois momentos: quando a pessoa sai do campo e quando ela pede
 * para continuar. Duas copias da mesma regra acabariam discordando.
 *
 * A ORDEM desta lista e a ordem dos campos na tela, e isso importa: e dela que
 * sai qual campo recebe o cursor quando ha mais de um problema.
 */
const conferidores: Record<string, (dados: FormularioInscricao) => string | null> = {
    nome_completo: (dados) => (dados.nome_completo.trim().length < 3 ? 'Informe o seu nome completo.' : null),
    email: (dados) => (/^\S+@\S+\.\S+$/.test(dados.email.trim()) ? null : 'Este e-mail parece incompleto. Confira e tente de novo.'),
    telefone: (dados) => (apenasDigitos(dados.telefone).length < 8 ? 'Informe um telefone com DDD para contato.' : null),
    documento: (dados) => (apenasDigitos(dados.documento).length !== 11 ? 'Este CPF não parece válido. Confira os números digitados.' : null),
    data_nascimento: (dados) => {
        if (dados.data_nascimento === '') {
            return 'Informe a sua data de nascimento.';
        }

        if (!dataExiste(dados.data_nascimento)) {
            return 'Esta data não existe. Confira o dia e o mês.';
        }

        // O mesmo teto que o campo ja aplica no calendario, agora tambem para
        // quem digita: ninguem nasceu depois de hoje.
        return dados.data_nascimento > hojeEmIso ? 'A data de nascimento não pode estar no futuro.' : null;
    },
    cidade_id: (dados) => (dados.cidade_id === null ? 'Escolha a sua cidade.' : null),
    grupo_participante_id: (dados) => (dados.grupo_participante_id === null ? 'Escolha o seu grupo.' : null),
};

/** Um campo esta vazio quando a pessoa ainda nao escreveu nada nele. */
function estaVazio(campo: string): boolean {
    const valor = (formulario.value as unknown as Record<string, unknown>)[campo];

    return valor === null || valor === '' || (typeof valor === 'string' && valor.trim() === '');
}

/**
 * Confere UM campo, quando a pessoa sai dele.
 *
 * Campo vazio nao vira aviso: quem passou o dedo pelo formulario sem escrever
 * nada ainda nao errou — so nao chegou la. Marcar tudo de vermelho antes da
 * primeira letra transforma o formulario numa lista de repreensoes, e ensina a
 * pessoa a ignorar o vermelho justamente onde ele precisaria ser levado a
 * serio. Quem cobra campo vazio e o "Continuar".
 *
 * Quando o campo passa a estar certo, o aviso do servidor sobre ele tambem
 * sai: manter na tela uma recusa que ja foi atendida e mentir para quem
 * corrigiu.
 */
function conferirCampo(campo: string): void {
    const conferidor = conferidores[campo];

    if (conferidor === undefined || estaVazio(campo)) {
        return;
    }

    const problema = conferidor(formulario.value);
    const locais = { ...errosLocais.value };
    const doServidor = { ...errosDoServidor.value };

    if (problema === null) {
        delete locais[campo];
        delete doServidor[campo];
    } else {
        locais[campo] = problema;
    }

    errosLocais.value = locais;
    errosDoServidor.value = doServidor;
}

function conferirDados(): boolean {
    const encontrados: Record<string, string> = {};

    for (const [campo, conferidor] of Object.entries(conferidores)) {
        const problema = conferidor(formulario.value);

        if (problema !== null) {
            encontrados[campo] = problema;
        }
    }

    errosLocais.value = encontrados;

    const primeiro = Object.keys(encontrados)[0];

    if (primeiro !== undefined) {
        nextTick(() => document.getElementById(primeiro)?.focus());

        return false;
    }

    return true;
}

async function avancar(): Promise<void> {
    if (passo.value === 'dados') {
        if (!conferirDados()) {
            return;
        }

        await irPara('participacao');

        return;
    }

    if (passo.value === 'participacao') {
        mostrarProblemasDaParticipacao.value = true;

        if (!selecao.podeAvancar.value) {
            return;
        }

        formulario.value.atividades = [...selecao.selecionadas.value];
        await irPara('revisao');
    }
}

/** Em qual etapa mora cada campo que o servidor pode recusar. */
const passoDoCampo: Record<string, PassoDaInscricao> = {
    nome_completo: 'dados',
    email: 'dados',
    telefone: 'dados',
    documento: 'dados',
    data_nascimento: 'dados',
    cidade_id: 'dados',
    grupo_participante_id: 'dados',
    atividades: 'participacao',
    aceite_termos: 'revisao',
    chave_idempotencia: 'revisao',
    evento_id: 'revisao',
    evento: 'revisao',
};

function formatarDataCurta(iso: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return iso;
    }

    const [ano, mes, dia] = iso.split('-');

    return `${dia}/${mes}/${ano}`;
}

const resumoPessoal = computed<Array<{ rotulo: string; valor: string }>>(() => {
    const dados = formulario.value;
    const cidade = cidades.value.find((candidata) => candidata.id === dados.cidade_id);
    const grupo = gruposParticipantes.value.find((candidato) => candidato.id === dados.grupo_participante_id);

    return [
        { rotulo: 'Nome', valor: dados.nome_completo },
        { rotulo: 'E-mail', valor: dados.email },
        { rotulo: 'Telefone', valor: dados.telefone },
        { rotulo: 'CPF', valor: dados.documento },
        { rotulo: 'Data de nascimento', valor: formatarDataCurta(dados.data_nascimento) },
        { rotulo: 'Cidade', valor: cidade?.rotulo ?? '—' },
        { rotulo: 'Grupo', valor: grupo?.nome ?? '—' },
    ];
});

const atividadesPorDia = computed(() =>
    dias.value.map((dia) => ({
        id: dia.id,
        nome: dia.nome,
        data_rotulo: dia.data_rotulo,
        atividades: dia.grupos.flatMap((grupo) => grupo.atividades.filter((atividade) => selecao.estaSelecionada(atividade.id))),
    })),
);

/**
 * O 422 do servidor manda. Ele diz qual campo tem problema; a tela volta para
 * a etapa desse campo e coloca o foco nele, para ninguem ficar preso sem
 * entender o que aconteceu.
 */
async function tratarRecusa(recebidos: Record<string, string>): Promise<void> {
    const traduzidos: Record<string, string> = { ...recebidos };

    // "evento" nao e um campo da tela: vira o aviso geral da revisao.
    if (recebidos.evento !== undefined) {
        traduzidos.geral = recebidos.evento;
    }

    errosDoServidor.value = traduzidos;

    const primeiro = Object.keys(recebidos)[0];

    if (primeiro === undefined) {
        return;
    }

    const raiz = primeiro.split('.')[0];
    const destino = passoDoCampo[raiz] ?? 'revisao';

    if (destino === 'participacao') {
        mostrarProblemasDaParticipacao.value = true;
    }

    if (destino !== passo.value) {
        await irPara(destino);
    }

    await nextTick();
    document.getElementById(raiz)?.focus();
}

function enviar(): void {
    if (enviando.value) {
        return;
    }

    errosDoServidor.value = {};
    formulario.value.atividades = [...selecao.selecionadas.value];
    enviando.value = true;

    // A chave de idempotencia e sempre a mesma desta sessao do formulario:
    // reenviar nunca cria uma segunda inscricao.
    router.post(
        '/inscricoes',
        { ...formulario.value },
        {
            onError: (recebidos: Record<string, string>) => {
                void tratarRecusa(recebidos);
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

async function voltar(): Promise<void> {
    if (passo.value === 'participacao') {
        await irPara('dados');

        return;
    }

    if (passo.value === 'revisao') {
        await irPara('participacao');
    }
}
</script>

<template>
    <Head>
        <title>{{ evento ? `Inscrição — ${evento.nome}` : 'Inscrição' }}</title>
    </Head>

    <PublicoLayout largura="ampla" :contato-email="evento?.contato_email" :contato-telefone="evento?.contato_telefone">
        <Alert v-if="!evento" variant="destructive">
            <AlertTitle>Não conseguimos carregar o formulário</AlertTitle>
            <AlertDescription>Tente recarregar a página em alguns instantes. Se o problema continuar, fale com a organização.</AlertDescription>
        </Alert>

        <div v-else>
            <!--
                .crumb — 14px muted, 20px abaixo, com a seta a 7px do nome.

                No lugar do antigo "Inscrição — {nome do evento}" em duas
                linhas de titulo: o caminho de volta e o nome do evento sao a
                mesma informacao, e assim ela tambem vira o caminho de volta.

                A linha "Valor da inscrição" SAIU daqui: o valor vive no resumo
                ao lado (e, no celular, na barra do rodape). Repeti-lo acima do
                indicador de etapas era o mesmo numero duas vezes na mesma dobra.
            -->
            <Link
                :href="`/eventos/${evento.slug}`"
                class="text-muted-foreground mb-5 inline-flex min-h-11 items-center gap-[7px] text-sm"
                data-testid="voltar-ao-evento"
            >
                <span aria-hidden="true">&larr;</span>
                {{ evento.nome }}
            </Link>

            <!-- 30px, como o titulo da tela de inscricao do prototipo -->
            <h1 class="text-[30px] leading-tight font-semibold">Inscrição</h1>

            <IndicadorDePassos :passo-atual="passo" />

            <!-- Anuncio da troca de etapa para quem usa leitor de tela. -->
            <p aria-live="polite" role="status" class="sr-only">{{ anuncio }}</p>

            <!--
                Duas colunas a partir de 1024px: o formulario e, ao lado dele, o
                resumo do que ja foi escolhido. Abaixo disso volta a ser uma
                coluna so — nao por simplificacao, mas porque num celular o
                resumo roubaria a tela inteira do campo que esta sendo digitado.
                Quem faz o papel dele ali e o total na barra do rodape.
            -->
            <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
                <!--
                    .panel — cartao branco de raio 14px, borda de 1px e sombra
                    baixa, com 28px de padding (20px abaixo de 640px, como o
                    prototipo). O formulario deixou de ser campo solto sobre o
                    papel e passou a viver dentro dele.
                -->
                <section class="border-border bg-card min-w-0 rounded-[14px] border p-5 shadow-sm sm:p-[28px]" data-testid="painel-da-etapa">
                    <!-- .panel h2 — 23px -->
                    <h2 ref="tituloDoPasso" tabindex="-1" class="text-[23px] font-semibold outline-hidden">{{ titulos[passo] }}</h2>

                    <!-- .panel__n — 15px muted, 8px abaixo do titulo -->
                    <p v-if="passo === 'dados'" class="text-muted-foreground mt-2 text-[15px]">
                        Usamos só para organizar o encontro e enviar sua confirmação.
                    </p>

                    <PassoDadosPessoais
                        v-show="passo === 'dados'"
                        v-model="formulario"
                        :cidades="cidades"
                        :grupos-da-cidade="gruposDaCidade"
                        :aviso-sem-grupos="avisoSemGrupos"
                        :erros="erros"
                        :ao-sair-do-campo="conferirCampo"
                    />

                    <PassoParticipacao
                        v-if="passo === 'participacao'"
                        class="mt-[26px]"
                        :dias="dias"
                        :selecao="selecao"
                        :mostrar-problemas="mostrarProblemasDaParticipacao"
                    />

                    <PassoRevisao
                        v-if="passo === 'revisao'"
                        class="mt-[26px]"
                        v-model="formulario"
                        :evento="evento"
                        :resumo-pessoal="resumoPessoal"
                        :atividades-por-dia="atividadesPorDia"
                        :erros="erros"
                        :enviando="enviando"
                        @editar="irPara"
                        @enviar="enviar"
                    />

                    <!--
                        A barra de navegacao do formulario — o `.actions` do
                        prototipo: linha de 1px em cima, 30px acima e 24px de
                        respiro, com a acao principal empurrada para a direita.

                        "sticky" no celular: ela acompanha a rolagem sem sair do
                        fluxo, entao o botao esta sempre a um toque de distancia
                        e nunca cobre o campo que esta sendo preenchido. Em tela
                        grande ela volta a ser uma linha comum no fim do
                        formulario, porque ali o botao ja esta visivel.

                        DESVIO CONSCIENTE do desenho: o prototipo poe as acoes
                        so dentro do painel, e no celular isso esconderia o
                        Total e o "Continuar" atras de uma rolagem — ali nao ha
                        resumo lateral para compensar. As margens negativas
                        levam a barra ate a borda do painel, para ela ser lida
                        como rodape dele e nao como mais uma caixa.
                    -->
                    <div
                        class="border-border bg-card/95 sticky bottom-0 z-30 -mx-5 -mb-5 rounded-b-[14px] border-t px-5 py-3 backdrop-blur sm:-mx-[28px] sm:-mb-[28px] sm:px-[28px] lg:static lg:mx-0 lg:mt-[30px] lg:mb-0 lg:rounded-none lg:bg-transparent lg:px-0 lg:pt-6 lg:pb-0 lg:backdrop-blur-none"
                        data-testid="barra-de-acoes"
                    >
                        <!--
                            No celular: total e acao principal na MESMA linha, e o
                            botao secundario embaixo. A barra fica com duas linhas
                            em vez de tres — cada linha que ela ganha e uma linha
                            que o formulario perde, e e o formulario que a pessoa
                            veio preencher.
                        -->
                        <div class="flex flex-wrap items-center gap-3 sm:flex-row-reverse">
                            <Button
                                v-if="passo !== 'revisao'"
                                type="button"
                                class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 flex-1 text-base sm:w-auto sm:flex-none"
                                @click="avancar"
                            >
                                Continuar
                            </Button>

                            <!-- O total so aparece onde o resumo lateral nao cabe:
                                 em tela grande ele ja esta ao lado, e repeti-lo
                                 seria dizer o mesmo numero duas vezes na mesma
                                 tela. -->
                            <p class="text-muted-foreground order-first text-sm sm:order-none sm:mr-auto lg:hidden">
                                Total
                                <strong class="text-foreground font-semibold tabular-nums">
                                    {{ formatarValor(evento.valor_centavos, evento.moeda) }}
                                </strong>
                            </p>

                            <Button v-if="passo !== 'dados'" type="button" variant="outline" class="h-12 w-full text-base sm:w-auto" @click="voltar">
                                Voltar
                            </Button>

                            <!-- .btn--quiet — discreto, a esquerda, com o texto
                                 do prototipo -->
                            <Button v-else as-child variant="ghost" class="text-muted-foreground h-12 w-full text-base sm:w-auto">
                                <Link :href="`/eventos/${evento.slug}`">Voltar ao evento</Link>
                            </Button>
                        </div>
                    </div>
                </section>

                <!--
                    O `.summary` do prototipo e `sticky` em `top: 84px` porque
                    la o cabecalho e fixo e mede 64px. O NOSSO cabecalho rola
                    junto com a pagina: copiar os 84px abriria um vao de 84
                    pixels acima do resumo assim que a pessoa rolasse. Os 24px
                    aqui sao so o respiro do topo da janela.
                -->
                <ResumoDaInscricao
                    class="hidden lg:sticky lg:top-6 lg:block"
                    :evento="evento"
                    :atividades-por-dia="atividadesPorDia"
                    @editar="irPara('participacao')"
                />
            </div>
        </div>
    </PublicoLayout>
</template>

<script setup lang="ts">
import IndicadorDePassos from '@/components/inscricao/IndicadorDePassos.vue';
import PassoDadosPessoais from '@/components/inscricao/PassoDadosPessoais.vue';
import PassoParticipacao from '@/components/inscricao/PassoParticipacao.vue';
import PassoRevisao from '@/components/inscricao/PassoRevisao.vue';
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
 * Conferencia de formato — a mesma que o StoreInscricaoRequest faz. Nao e
 * regra de negocio: so evita a viagem ate o servidor por um campo vazio.
 */
function conferirDados(): boolean {
    const encontrados: Record<string, string> = {};
    const dados = formulario.value;

    if (dados.nome_completo.trim().length < 3) {
        encontrados.nome_completo = 'Informe o seu nome completo.';
    }

    if (!/^\S+@\S+\.\S+$/.test(dados.email.trim())) {
        encontrados.email = 'Este e-mail parece incompleto. Confira e tente de novo.';
    }

    if (apenasDigitos(dados.telefone).length < 8) {
        encontrados.telefone = 'Informe um telefone com DDD para contato.';
    }

    if (apenasDigitos(dados.documento).length !== 11) {
        encontrados.documento = 'Este CPF não parece válido. Confira os números digitados.';
    }

    if (dados.data_nascimento === '') {
        encontrados.data_nascimento = 'Informe a sua data de nascimento.';
    }

    if (dados.cidade_id === null) {
        encontrados.cidade_id = 'Escolha a sua cidade.';
    }

    if (dados.grupo_participante_id === null) {
        encontrados.grupo_participante_id = 'Escolha o seu grupo.';
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

    <PublicoLayout :contato-email="evento?.contato_email" :contato-telefone="evento?.contato_telefone">
        <Alert v-if="!evento" variant="destructive">
            <AlertTitle>Não conseguimos carregar o formulário</AlertTitle>
            <AlertDescription>Tente recarregar a página em alguns instantes. Se o problema continuar, fale com a organização.</AlertDescription>
        </Alert>

        <div v-else class="space-y-6">
            <div>
                <p class="text-sm text-muted-foreground">{{ evento.periodo_rotulo }}</p>
                <h1 class="text-2xl font-semibold">Inscrição — {{ evento.nome }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Valor da inscrição: <strong>{{ formatarValor(evento.valor_centavos, evento.moeda) }}</strong>
                </p>
            </div>

            <IndicadorDePassos :passo-atual="passo" />

            <!-- Anuncio da troca de etapa para quem usa leitor de tela. -->
            <p aria-live="polite" role="status" class="sr-only">{{ anuncio }}</p>

            <h2 ref="tituloDoPasso" tabindex="-1" class="text-xl font-semibold outline-none">{{ titulos[passo] }}</h2>

            <PassoDadosPessoais
                v-show="passo === 'dados'"
                v-model="formulario"
                :cidades="cidades"
                :grupos-da-cidade="gruposDaCidade"
                :aviso-sem-grupos="avisoSemGrupos"
                :erros="erros"
            />

            <PassoParticipacao v-if="passo === 'participacao'" :dias="dias" :selecao="selecao" :mostrar-problemas="mostrarProblemasDaParticipacao" />

            <PassoRevisao
                v-if="passo === 'revisao'"
                v-model="formulario"
                :evento="evento"
                :resumo-pessoal="resumoPessoal"
                :atividades-por-dia="atividadesPorDia"
                :erros="erros"
                :enviando="enviando"
                @editar="irPara"
                @enviar="enviar"
            />

            <div class="flex flex-col gap-3 sm:flex-row-reverse">
                <Button
                    v-if="passo !== 'revisao'"
                    type="button"
                    class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90 sm:w-auto"
                    @click="avancar"
                >
                    Continuar
                </Button>

                <Button v-if="passo !== 'dados'" type="button" variant="outline" class="h-12 w-full text-base sm:w-auto" @click="voltar">
                    Voltar
                </Button>

                <Button v-else as-child variant="ghost" class="h-12 w-full text-base sm:w-auto">
                    <Link :href="`/eventos/${evento.slug}`">Voltar para o evento</Link>
                </Button>
            </div>
        </div>
    </PublicoLayout>
</template>

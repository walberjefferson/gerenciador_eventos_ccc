<script setup lang="ts">
import IndicadorDePassos from '@/components/inscricao/IndicadorDePassos.vue';
import PassoDadosPessoais from '@/components/inscricao/PassoDadosPessoais.vue';
import PassoParticipacao from '@/components/inscricao/PassoParticipacao.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useGruposDaCidade } from '@/composables/useGruposDaCidade';
import { useSelecaoAtividades } from '@/composables/useSelecaoAtividades';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { formatarValor } from '@/lib/formato';
import type { DiaEventoPublico, EventoPublico } from '@/types/evento';
import type { CidadePublica, ConflitoDeAtividades, FormularioInscricao, GrupoParticipantePublico, PassoDaInscricao } from '@/types/inscricao';
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

/**
 * Formulario de inscricao. As etapas sao navegacao na propria tela: nada e
 * gravado ate o envio final, porque o backend cria a inscricao inteira de uma
 * vez so.
 */
const props = defineProps<{
    evento?: EventoPublico | null;
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
    evento_id: 0,
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
    const erros: Record<string, string> = {};
    const dados = formulario.value;

    if (dados.nome_completo.trim().length < 3) {
        erros.nome_completo = 'Informe o seu nome completo.';
    }

    if (!/^\S+@\S+\.\S+$/.test(dados.email.trim())) {
        erros.email = 'Este e-mail parece incompleto. Confira e tente de novo.';
    }

    if (apenasDigitos(dados.telefone).length < 8) {
        erros.telefone = 'Informe um telefone com DDD para contato.';
    }

    if (apenasDigitos(dados.documento).length !== 11) {
        erros.documento = 'Este CPF não parece válido. Confira os números digitados.';
    }

    if (dados.data_nascimento === '') {
        erros.data_nascimento = 'Informe a sua data de nascimento.';
    }

    if (dados.cidade_id === null) {
        erros.cidade_id = 'Escolha a sua cidade.';
    }

    if (dados.grupo_participante_id === null) {
        erros.grupo_participante_id = 'Escolha o seu grupo.';
    }

    errosLocais.value = erros;

    const primeiro = Object.keys(erros)[0];

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
                :erros="errosLocais"
            />

            <PassoParticipacao v-if="passo === 'participacao'" :dias="dias" :selecao="selecao" :mostrar-problemas="mostrarProblemasDaParticipacao" />

            <section v-if="passo === 'revisao'" class="space-y-3">
                <p class="text-sm text-muted-foreground">
                    {{ selecao.totalSelecionadas.value }} atividade(s) escolhida(s). A revisão completa e o aceite do regulamento chegam na próxima
                    entrega.
                </p>
            </section>

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

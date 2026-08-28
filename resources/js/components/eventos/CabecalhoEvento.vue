<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { contarVagas, formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';
import { computed } from 'vue';

/**
 * Rosto do evento: o convite e os fatos que a pessoa precisa saber ANTES de
 * decidir se clica.
 *
 * A ordem nao e estetica. Quando, quanto custa e quantas vagas restam sao as
 * tres perguntas que alguem faz antes de gastar tres minutos preenchendo um
 * formulario — e a versao anterior desta tela obrigava a rolar ate a
 * programacao para responder duas delas.
 */
const props = defineProps<{
    evento: EventoPublico;
}>();

const valor = computed<string>(() => formatarValor(props.evento.valor_centavos, props.evento.moeda));

/**
 * Quantos dias faltam para as inscricoes fecharem.
 *
 * Conta dias de calendario, e nao intervalos de 24 horas: quem le "encerram em
 * 1 dia" numa quinta entende "amanha", nao "daqui a 24 horas". Devolve null
 * quando a data ja passou ou nao da para ler, e nesse caso a etiqueta some em
 * vez de mostrar numero negativo.
 */
const diasParaFechar = computed<number | null>(() => {
    const fecham = new Date(props.evento.inscricoes_fecham_em);

    if (Number.isNaN(fecham.getTime())) {
        return null;
    }

    const hoje = new Date();
    const inicioDeHoje = Date.UTC(hoje.getFullYear(), hoje.getMonth(), hoje.getDate());
    const inicioDoFim = Date.UTC(fecham.getFullYear(), fecham.getMonth(), fecham.getDate());

    const dias = Math.round((inicioDoFim - inicioDeHoje) / 86_400_000);

    return dias > 0 ? dias : null;
});

const rotuloDoPrazo = computed<string | null>(() => {
    const dias = diasParaFechar.value;

    if (dias === null) {
        return null;
    }

    return dias === 1 ? 'Encerram amanhã' : `Encerram em ${dias} dias`;
});

/** "62 de 200 restantes" quando ha teto; so a contagem quando nao ha. */
const rotuloDeVagas = computed<string>(() => {
    if (props.evento.esgotado) {
        return 'Vagas esgotadas';
    }

    if (props.evento.vagas_disponiveis === null) {
        return 'Sem limite de vagas';
    }

    if (props.evento.capacidade === null) {
        return `${contarVagas(props.evento.vagas_disponiveis)} restantes`;
    }

    return `${props.evento.vagas_disponiveis} de ${props.evento.capacidade} restantes`;
});

/**
 * Quanto da capacidade ja foi tomada, de 0 a 100.
 *
 * A barra mede o que JA FOI OCUPADO, e nao o que sobra: e assim que se le uma
 * barra que enche. Sem capacidade declarada nao ha fracao possivel, e a barra
 * simplesmente nao aparece — inventar um denominador seria desenhar uma
 * informacao que ninguem forneceu.
 */
const percentualOcupado = computed<number | null>(() => {
    const { capacidade, vagas_disponiveis: disponiveis } = props.evento;

    if (capacidade === null || capacidade <= 0 || disponiveis === null) {
        return null;
    }

    const ocupadas = Math.min(Math.max(capacidade - disponiveis, 0), capacidade);

    return Math.round((ocupadas / capacidade) * 100);
});
</script>

<template>
    <header class="space-y-5">
        <img
            v-if="evento.banner_url"
            :src="evento.banner_url"
            :alt="`Imagem de divulgação do evento ${evento.nome}`"
            class="border-border w-full rounded-lg border object-cover"
            loading="lazy"
            decoding="async"
        />

        <div class="flex flex-wrap gap-2">
            <Badge :variant="evento.inscricoes_abertas ? 'sucesso' : 'secondary'">
                {{ evento.inscricoes_abertas ? 'Inscrições abertas' : evento.situacao_rotulo }}
            </Badge>

            <Badge v-if="evento.inscricoes_abertas && rotuloDoPrazo" variant="atencao">
                {{ rotuloDoPrazo }}
            </Badge>
        </div>

        <div class="space-y-2">
            <h1 class="text-3xl leading-tight font-bold tracking-tight sm:text-4xl">{{ evento.nome }}</h1>

            <p v-if="evento.descricao" class="text-muted-foreground max-w-prose text-base leading-relaxed whitespace-pre-line">
                {{ evento.descricao }}
            </p>
        </div>

        <!-- Os fatos, em caixas do mesmo peso. Lista de descricao e nao tabela:
             cada caixa e um par pergunta/resposta, e e assim que um leitor de
             tela vai anuncia-la. -->
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="border-border space-y-1 rounded-lg border p-4">
                <dt class="text-muted-foreground text-xs font-bold tracking-wider uppercase">Quando</dt>
                <dd class="text-sm font-semibold">{{ evento.periodo_rotulo }}</dd>
            </div>

            <div class="border-border space-y-1 rounded-lg border p-4">
                <dt class="text-muted-foreground text-xs font-bold tracking-wider uppercase">Investimento</dt>
                <dd class="text-sm font-semibold tabular-nums">{{ valor }}</dd>
                <dd class="text-muted-foreground text-xs">por pessoa</dd>
            </div>

            <div class="border-border space-y-1 rounded-lg border p-4">
                <dt class="text-muted-foreground text-xs font-bold tracking-wider uppercase">Vagas</dt>
                <dd class="text-sm font-semibold" :class="evento.esgotado ? 'text-destructive' : 'text-sucesso-texto'">
                    {{ rotuloDeVagas }}
                </dd>
                <dd v-if="percentualOcupado !== null" class="pt-1">
                    <!-- A barra repete o que a linha de cima ja diz por extenso,
                         entao ela e decoracao: escondida do leitor de tela para
                         nao anunciar o mesmo numero duas vezes. -->
                    <div aria-hidden="true" class="bg-muted h-1.5 overflow-hidden rounded-full">
                        <div class="bg-informacao h-full rounded-full" :style="{ width: `${percentualOcupado}%` }"></div>
                    </div>
                </dd>
            </div>
        </dl>
    </header>
</template>

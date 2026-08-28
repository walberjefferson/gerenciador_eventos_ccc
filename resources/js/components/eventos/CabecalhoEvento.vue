<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { contarVagas, formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';
import { computed } from 'vue';

/**
 * Rosto do evento: o convite e os fatos que a pessoa precisa saber ANTES de
 * decidir se clica.
 *
 * A ordem nao e estetica. Quando, onde, quanto custa e quantas vagas restam sao
 * as perguntas que alguem faz antes de gastar tres minutos preenchendo um
 * formulario — e a versao anterior desta tela obrigava a rolar ate a
 * programacao para responder metade delas.
 *
 * As caixas de fatos usam um truque de borda que vem do prototipo: a grade tem
 * `gap: 1px` sobre um fundo da cor da linha, e cada caixa e branca. O resultado
 * e uma malha de linhas de UM pixel entre as caixas, sem borda dupla nos
 * encontros e sem que nenhuma caixa precise saber se e a primeira ou a ultima
 * da fileira — o que muda a cada largura de tela.
 */
const props = defineProps<{
    evento: EventoPublico;
}>();

const valor = computed<string>(() => formatarValor(props.evento.valor_centavos, props.evento.moeda));

/** "62 de 200 livres" quando ha teto; so a contagem quando nao ha. */
const rotuloDeVagas = computed<string>(() => {
    if (props.evento.esgotado) {
        return 'Vagas esgotadas';
    }

    if (props.evento.vagas_disponiveis === null) {
        return 'Sem limite de vagas';
    }

    if (props.evento.capacidade === null) {
        return `${contarVagas(props.evento.vagas_disponiveis)} livres`;
    }

    return `${props.evento.vagas_disponiveis} de ${props.evento.capacidade} livres`;
});

/**
 * Quanto da capacidade ja foi tomada, de 0 a 100.
 *
 * A barra mede o que JA FOI OCUPADO, e nao o que sobra — e assim que se le uma
 * barra que enche, e e o mesmo criterio da barra da porta da rua (DA-61). Sem
 * capacidade declarada nao ha fracao possivel, e a barra nao aparece.
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
    <header>
        <img
            v-if="evento.banner_url"
            :src="evento.banner_url"
            :alt="`Imagem de divulgação do evento ${evento.nome}`"
            class="border-border mb-6 w-full rounded-lg border object-cover"
            loading="lazy"
            decoding="async"
        />

        <!-- .pills — gap de 8px -->
        <div class="flex flex-wrap gap-2">
            <Badge :variant="evento.inscricoes_abertas ? 'sucesso' : 'secondary'">
                {{ evento.inscricoes_abertas ? 'Inscrições abertas' : evento.situacao_rotulo }}
            </Badge>

            <Badge v-if="evento.prazo_rotulo" variant="atencao">{{ evento.prazo_rotulo }}</Badge>
        </div>

        <!-- .ev-hero h1 — clamp(34px, 5.6vw, 54px), peso 600, 14px acima -->
        <h1 class="mt-[14px] text-[clamp(34px,5.6vw,54px)] leading-[1.08] font-semibold tracking-[-0.02em]">
            {{ evento.nome }}
        </h1>

        <!-- .ev-hero__sub — 18px, 52ch, 14px acima -->
        <p v-if="evento.descricao" class="text-muted-foreground mt-[14px] max-w-[52ch] text-[18px] leading-[1.55] whitespace-pre-line">
            {{ evento.descricao }}
        </p>

        <!-- .facts — a malha de linhas de 1px descrita acima -->
        <dl class="bg-border border-border my-8 grid gap-px overflow-hidden rounded-[14px] border sm:grid-cols-2 lg:grid-cols-4">
            <div class="bg-card px-5 py-[18px]">
                <dt class="text-muted-foreground text-[11.5px] font-semibold tracking-[0.12em] uppercase">Quando</dt>
                <dd class="mt-[7px] text-[17px] font-semibold tracking-[-0.01em]">{{ evento.periodo_rotulo }}</dd>
            </div>

            <!-- "Onde" so existe depois que alguem cadastrou o lugar. Caixa com
                 titulo e nada embaixo nao informa quem visita: informa que falta
                 alguem preencher, o que e assunto de quem administra. -->
            <div v-if="evento.local" class="bg-card px-5 py-[18px]">
                <dt class="text-muted-foreground text-[11.5px] font-semibold tracking-[0.12em] uppercase">Onde</dt>
                <dd class="mt-[7px] text-[17px] font-semibold tracking-[-0.01em]">{{ evento.local }}</dd>
                <dd v-if="evento.local_detalhe" class="text-muted-foreground mt-[3px] text-[13.5px]">{{ evento.local_detalhe }}</dd>
            </div>

            <div class="bg-card px-5 py-[18px]">
                <dt class="text-muted-foreground text-[11.5px] font-semibold tracking-[0.12em] uppercase">Investimento</dt>
                <dd class="mt-[7px] text-[17px] font-semibold tracking-[-0.01em] tabular-nums">{{ valor }}</dd>
                <dd class="text-muted-foreground mt-[3px] text-[13.5px]">por pessoa</dd>
            </div>

            <div class="bg-card px-5 py-[18px]">
                <dt class="text-muted-foreground text-[11.5px] font-semibold tracking-[0.12em] uppercase">Vagas</dt>
                <dd class="mt-[7px] text-[17px] font-semibold tracking-[-0.01em]" :class="evento.esgotado ? 'text-destructive' : ''">
                    {{ rotuloDeVagas }}
                </dd>

                <!-- .bar — 5px de altura, 10px acima. A barra repete o que a
                     linha de cima ja diz por extenso, entao e decoracao e sai
                     do leitor de tela. -->
                <dd v-if="percentualOcupado !== null" aria-hidden="true" class="bg-secondary mt-[10px] h-[5px] overflow-hidden rounded-full">
                    <div class="bg-acao h-full rounded-full" :style="{ width: `${percentualOcupado}%` }"></div>
                </dd>
            </div>
        </dl>
    </header>
</template>

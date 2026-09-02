<script setup lang="ts">
import { formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';
import { computed } from 'vue';

/**
 * O resumo que acompanha o formulario em tela grande — o `.summary` do
 * prototipo.
 *
 * Ele responde, a qualquer momento, as duas perguntas que fazem alguem
 * abandonar um formulario no meio: "o que eu ja escolhi?" e "quanto vai
 * custar?". Antes, as duas so tinham resposta na etapa de revisao — ou seja,
 * depois de todo o trabalho.
 *
 * Ele NAO substitui a etapa de revisao. Revisar e um ato deliberado, com
 * aceite de regulamento, e continua sendo um passo com nome proprio.
 *
 * O cartao "Precisa de ajuda?" SAIU daqui: o mesmo telefone e o mesmo e-mail
 * ja estao no rodape de toda tela publica, e o prototipo so traz esse bloco na
 * vitrine. Dizer duas vezes na mesma tela nao ajuda ninguem — so empurra o
 * total para baixo.
 */
const props = defineProps<{
    evento: EventoPublico;
    atividadesPorDia: Array<{
        id: number;
        nome: string;
        data_rotulo: string;
        /** `horario_rotulo` é nulo quando a atividade não tem hora marcada. */
        atividades: Array<{ id: number; nome: string; horario_rotulo: string | null }>;
    }>;
}>();

const emit = defineEmits<{
    (e: 'editar'): void;
}>();

/**
 * "17 e 18 de outubro · Sítio Santa Clara" — o `.summary__ev`.
 *
 * O separador so existe quando ha os dois lados. Evento sem local cadastrado
 * mostra so a data, e nao uma data seguida de um ponto orfao.
 */
const quandoEOnde = computed<string>(() => [props.evento.quando_rotulo, props.evento.local].filter((parte) => Boolean(parte)).join(' · '));

/** As escolhas, uma linha por dia: "Sábado — Futebol, Vôlei". */
const linhas = computed<Array<{ id: number; rotulo: string; valor: string }>>(() =>
    props.atividadesPorDia
        .filter((dia) => dia.atividades.length > 0)
        .map((dia) => ({
            id: dia.id,
            rotulo: dia.nome,
            valor: dia.atividades.map((atividade) => atividade.nome).join(', '),
        })),
);
</script>

<template>
    <!-- .summary — 22px de padding, raio 14px, borda de 1px e a sombra baixa
         da identidade. Sem o titulo "Resumo": o desenho abre pelo nome do
         evento, e uma palavra que so diz o que a caixa e nao informa nada a
         quem esta olhando para ela. -->
    <aside aria-labelledby="titulo-resumo" class="border-border bg-card rounded-[14px] border p-[22px] shadow-sm">
        <!-- .summary h3 — 16px -->
        <h2 id="titulo-resumo" class="text-base font-semibold">{{ evento.nome }}</h2>

        <!-- .summary__ev — 14px muted, 6px abaixo do nome -->
        <p v-if="quandoEOnde" class="text-muted-foreground mt-[6px] text-sm">{{ quandoEOnde }}</p>

        <!-- .summary__rows — 16px de topo, linha em cima, 11px entre as linhas -->
        <dl v-if="linhas.length > 0" class="border-border mt-4 grid gap-[11px] border-t pt-4 text-sm">
            <!-- .srow — rotulo muted a esquerda, valor empurrado para a direita -->
            <div v-for="linha in linhas" :key="linha.id" class="flex items-baseline gap-3">
                <dt class="text-muted-foreground flex-none">{{ linha.rotulo }}</dt>
                <dd class="ml-auto min-w-0 text-right font-medium">{{ linha.valor }}</dd>
            </div>
        </dl>

        <!-- .summary__tot — o total em Bricolage Grotesque de 24px, como todo
             preco desta identidade -->
        <div class="border-border mt-4 flex items-baseline border-t pt-4">
            <span class="text-muted-foreground text-sm">Total</span>
            <strong class="font-titulo ml-auto text-2xl font-semibold tracking-[-0.02em] tabular-nums">
                {{ formatarValor(evento.valor_centavos, evento.moeda) }}
            </strong>
        </div>

        <!-- .buy__n — a frase que tira o susto: ninguem paga por engano no meio
             do formulario -->
        <p class="text-muted-foreground mt-[14px] text-[13.5px]">Você só paga na última etapa.</p>

        <!-- O desenho nao tem este botao. Ele fica porque e o unico caminho de
             volta as atividades sem passar pela revisao — e ganha os 44px de
             alvo que o texto puro nao teria (DA-42, DA-69). -->
        <button
            v-if="linhas.length > 0"
            type="button"
            class="text-acao-texto mt-1 inline-flex min-h-11 items-center text-[13px] font-semibold"
            @click="emit('editar')"
        >
            Alterar atividades
        </button>
    </aside>
</template>

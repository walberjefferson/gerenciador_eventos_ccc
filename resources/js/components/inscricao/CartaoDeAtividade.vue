<script setup lang="ts">
import { contarVagas } from '@/lib/formato';
import type { AtividadePublica } from '@/types/evento';
import type { SituacaoDaAtividade } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Uma atividade para escolher — o `.opt` do prototipo.
 *
 * A linha inteira e um rotulo de caixa de selecao de verdade: funciona no
 * teclado, no leitor de tela e no toque, e o alvo passa bem dos 44px. A caixa
 * de marcar e DESENHADA (a de verdade fica escondida), porque a nativa nao
 * aceita os 22px com raio de 6px do arquivo — mas ela continua sendo a que
 * recebe o foco e o teclado.
 *
 * O horario fica numa coluna de largura fixa e monoespacada, como na
 * programacao: e o que alinha os horarios de linhas diferentes na vertical, e
 * comparar horario e exatamente o que a pessoa faz aqui.
 */
const props = defineProps<{
    atividade: AtividadePublica;
    situacao: SituacaoDaAtividade;
}>();

const emit = defineEmits<{
    (evento: 'alternar', atividadeId: number): void;
}>();

const idMotivo = computed<string>(() => `atividade-${props.atividade.id}-motivo`);

const descrita = computed<string | undefined>(() => (props.situacao.motivo !== null ? idMotivo.value : undefined));

const desabilitada = computed<boolean>(() => !props.situacao.selecionavel);
</script>

<template>
    <!-- .opt — borda de 1.5px, raio de 10px, recheio de 14px/16px -->
    <label
        class="group/opt relative mt-[9px] block rounded-[10px] border-[1.5px] p-[14px_16px] transition-colors first:mt-0"
        :class="
            desabilitada
                ? 'border-border bg-muted/50 cursor-not-allowed opacity-60'
                : situacao.selecionada
                  ? 'border-acao bg-sucesso-suave cursor-pointer'
                  : 'border-border bg-card hover:border-input cursor-pointer'
        "
    >
        <input
            type="checkbox"
            class="peer sr-only"
            :checked="situacao.selecionada"
            :disabled="desabilitada"
            :aria-describedby="descrita"
            @change="emit('alternar', atividade.id)"
        />

        <!-- .opt__in — 14px entre as partes -->
        <span
            class="peer-focus-visible:ring-ring flex flex-wrap items-center gap-x-[14px] gap-y-1 rounded-[6px] peer-focus-visible:ring-2 peer-focus-visible:ring-offset-4"
        >
            <!-- .opt__box — 22px, raio de 6px, borda de 1.5px -->
            <span
                aria-hidden="true"
                class="grid size-[22px] shrink-0 place-items-center rounded-[6px] border-[1.5px] transition-colors"
                :class="situacao.selecionada ? 'border-acao bg-acao' : 'border-input bg-card'"
            >
                <svg
                    class="text-acao-foreground size-[13px] transition-opacity"
                    :class="situacao.selecionada ? 'opacity-100' : 'opacity-0'"
                    viewBox="0 0 13 13"
                    fill="none"
                >
                    <path d="M2 6.8L5 9.6L11 3.4" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>

            <!-- .opt__t — coluna fixa e monoespacada -->
            <span class="text-muted-foreground w-[124px] shrink-0 font-mono text-[13.5px] whitespace-nowrap tabular-nums">
                {{ atividade.horario_rotulo }}
            </span>

            <!-- .opt__n — peso 500, com a explicacao menor embaixo -->
            <span class="min-w-0 flex-1 basis-32">
                <span class="block text-[15px] font-medium">{{ atividade.nome }}</span>
                <span v-if="atividade.descricao" class="text-muted-foreground block text-[13px] font-normal">{{ atividade.descricao }}</span>
            </span>

            <!-- .opt__v — empurrado para a direita -->
            <span class="ml-auto shrink-0 text-right text-[13px]" :class="atividade.esgotado ? 'text-destructive' : 'text-muted-foreground'">
                {{ atividade.esgotado ? 'Esgotado' : atividade.vagas_disponiveis !== null ? contarVagas(atividade.vagas_disponiveis) : '' }}
            </span>
        </span>

        <span v-if="situacao.motivo" :id="idMotivo" class="text-atencao-texto mt-2 block text-[13px] font-medium">
            {{ situacao.motivo }}
        </span>
    </label>
</template>

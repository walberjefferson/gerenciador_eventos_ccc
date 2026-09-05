<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import type { ResultadoDaLeitura } from '@/types/ingresso';
import { CheckCircle2, RotateCcw, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * O veredito da conferência, do jeito que quem está no portão precisa ler.
 *
 * ## Uma resposta por vez, do tamanho da tela
 *
 * Quem está no portão olha o celular por um segundo, com a fila andando e sol
 * na tela. Por isso o desenho é o mais grosseiro do sistema: uma palavra grande
 * — ENTRADA LIBERADA ou ENTRADA RECUSADA —, o nome de quem está na frente e
 * uma frase explicando. Nada de tabela, nada de detalhe secundário.
 *
 * ## A cor nunca decide sozinha (WCAG 1.4.1)
 *
 * Verde e vermelho são reforço. O ícone e a palavra escrita dizem a mesma coisa
 * para quem não distingue as duas cores — e é isso, e não o tom, que o leitor
 * de tela anuncia. O bloco inteiro é `role="alert"`: o resultado precisa ser
 * falado assim que chega, porque ninguém vai procurá-lo na tela.
 *
 * ## Por que o "já utilizado" mostra a hora e o nome
 *
 * A conversa seguinte no portão é sempre a mesma: "mas eu não entrei ainda".
 * Com "entrada registrada às 14h02 por Fulano", quem está no portão tem o que
 * responder — e sabe a quem perguntar.
 */
const props = defineProps<{
    resultado: ResultadoDaLeitura;
    /** Desfazer não é da portaria: o botão nem se desenha para quem não pode. */
    podeDesfazer: boolean;
    /** Enquanto o pedido de desfazer está indo e voltando. */
    desfazendo?: boolean;
}>();

const emit = defineEmits<{ (evento: 'desfazer', ingressoId: number): void }>();

const aceito = computed(() => props.resultado.aceito);

/**
 * O ingresso que o botão de desfazer alcança.
 *
 * Só existe quando a entrada acabou de ser aceita. Na recusa por "já
 * utilizado" o identificador também vem, mas desfazer ali seria desfazer a
 * entrada de OUTRA pessoa a partir de uma leitura recusada — decisão que se
 * toma olhando a ficha, não no meio da fila.
 */
const ingressoParaDesfazer = computed<number | null>(() => (props.resultado.aceito ? props.resultado.ingresso_id : null));
</script>

<template>
    <div
        role="alert"
        aria-live="assertive"
        data-testid="resultado-da-leitura"
        :data-resultado="aceito ? 'aceito' : 'recusado'"
        :data-motivo="props.resultado.aceito ? 'aceito' : props.resultado.motivo"
        class="rounded-lg border p-4"
        :class="aceito ? 'border-sucesso bg-sucesso-suave text-sucesso-suave-foreground' : 'border-destructive bg-erro-suave text-erro-suave-foreground'"
    >
        <p class="flex items-center gap-2 text-lg font-semibold tracking-tight">
            <component :is="aceito ? CheckCircle2 : XCircle" aria-hidden="true" class="size-6 shrink-0" />
            <span data-testid="veredito-da-leitura">{{ aceito ? 'Entrada liberada' : 'Entrada recusada' }}</span>
        </p>

        <template v-if="props.resultado.aceito">
            <!-- O nome grande: é o que a pessoa do portão confere no rosto de quem está na frente. -->
            <p class="mt-3 text-2xl font-semibold tracking-tight" data-testid="nome-de-quem-entrou">
                {{ props.resultado.participante.nome }}
            </p>

            <p v-if="props.resultado.participante.grupo" class="text-sm">{{ props.resultado.participante.grupo }}</p>

            <p v-if="props.resultado.participante.atividades.length > 0" class="mt-2 text-sm">
                <span class="font-medium">Atividades:</span>
                {{ props.resultado.participante.atividades.join(', ') }}
            </p>

            <p class="mt-3 text-sm">
                Ingresso {{ props.resultado.codigo_formatado }} · entrada registrada em {{ props.resultado.usado_em }} por
                {{ props.resultado.usado_por }}.
            </p>

            <div v-if="props.podeDesfazer && ingressoParaDesfazer !== null" class="mt-4">
                <BotaoDeAcao
                    intencao="excluir"
                    tamanho="md"
                    :icone="RotateCcw"
                    :disabled="props.desfazendo"
                    data-testid="desfazer-entrada"
                    class="bg-background"
                    @click="emit('desfazer', ingressoParaDesfazer)"
                >
                    {{ props.desfazendo ? 'Desfazendo…' : 'Foi engano: desfazer esta entrada' }}
                </BotaoDeAcao>
            </div>
        </template>

        <template v-else>
            <p class="mt-3 text-base" data-testid="motivo-da-recusa">{{ props.resultado.mensagem }}</p>

            <!--
                A situação da inscricao, quando é ela que derruba o ingresso.
                Vem da mesma etiqueta do resto do painel: cor de situação mora
                no `lib/situacoes.ts`, e não em classe solta desta tela.
            -->
            <div v-if="props.resultado.dados.situacao_da_inscricao" class="mt-3">
                <EtiquetaDeSituacao
                    dominio="inscricao"
                    :situacao="props.resultado.dados.situacao_da_inscricao"
                    :rotulo="props.resultado.dados.situacao_rotulo ?? null"
                />
            </div>
        </template>
    </div>
</template>

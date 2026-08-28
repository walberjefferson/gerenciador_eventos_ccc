<script setup lang="ts">
import { formatarValor } from '@/lib/formato';
import type { EventoPublico } from '@/types/evento';

/**
 * O resumo que acompanha o formulario em tela grande.
 *
 * Ele responde, a qualquer momento, as duas perguntas que fazem alguem
 * abandonar um formulario no meio: "o que eu ja escolhi?" e "quanto vai
 * custar?". Antes, as duas so tinham resposta na etapa de revisao — ou seja,
 * depois de todo o trabalho.
 *
 * Ele NAO substitui a etapa de revisao. Revisar e um ato deliberado, com
 * aceite de regulamento, e continua sendo um passo com nome proprio.
 */
defineProps<{
    evento: EventoPublico;
    atividadesPorDia: Array<{
        id: number;
        nome: string;
        data_rotulo: string;
        atividades: Array<{ id: number; nome: string; horario_rotulo: string }>;
    }>;
    contatoTelefone?: string | null;
    contatoEmail?: string | null;
}>();

const emit = defineEmits<{
    (e: 'editar'): void;
}>();
</script>

<template>
    <aside aria-labelledby="titulo-resumo" class="space-y-3">
        <div class="border-border bg-card rounded-[14px] border p-[22px] shadow-sm">
            <h2 id="titulo-resumo" class="text-base font-semibold">Resumo</h2>

            <dl class="border-border mt-4 grid gap-[11px] border-t pt-4 text-sm">
                <div>
                    <dt class="font-medium">{{ evento.nome }}</dt>
                    <dd class="text-muted-foreground text-xs">{{ evento.periodo_rotulo }}</dd>
                </div>

                <template v-for="dia in atividadesPorDia" :key="dia.id">
                    <div v-for="atividade in dia.atividades" :key="atividade.id" class="flex items-baseline justify-between gap-3">
                        <dt class="min-w-0 truncate">{{ atividade.nome }}</dt>
                        <dd class="text-muted-foreground shrink-0 font-mono text-xs tabular-nums">{{ atividade.horario_rotulo }}</dd>
                    </div>
                </template>
            </dl>

            <div class="border-border mt-4 flex items-baseline border-t pt-4">
                <span class="text-muted-foreground text-sm">Total</span>
                <strong class="font-titulo ml-auto text-2xl font-semibold tracking-[-0.02em] tabular-nums">{{
                    formatarValor(evento.valor_centavos, evento.moeda)
                }}</strong>
            </div>

            <button
                v-if="atividadesPorDia.some((dia) => dia.atividades.length > 0)"
                type="button"
                class="text-acao-texto mt-3 inline-block text-[13px] font-semibold"
                @click="emit('editar')"
            >
                Alterar atividades
            </button>
        </div>

        <div v-if="contatoTelefone || contatoEmail" class="border-border bg-card mt-3 space-y-1 rounded-[14px] border p-[22px]">
            <p class="text-xs font-semibold">Precisa de ajuda?</p>

            <p v-if="contatoTelefone" class="text-xs">
                <a class="text-acao-texto font-medium underline-offset-4 hover:underline" :href="`tel:${contatoTelefone}`">
                    {{ contatoTelefone }}
                </a>
            </p>

            <p v-if="contatoEmail" class="text-xs">
                <a class="text-acao-texto font-medium underline-offset-4 hover:underline" :href="`mailto:${contatoEmail}`">
                    {{ contatoEmail }}
                </a>
            </p>

            <p class="text-muted-foreground text-xs leading-relaxed">Seus dados são usados apenas para a organização deste evento.</p>
        </div>
    </aside>
</template>

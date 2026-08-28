<script setup lang="ts">
import { formatarDataHora } from '@/lib/formato';
import type { MarcoDaInscricao } from '@/types/participante';
import { CalendarClock, CheckCircle2, CircleAlert, CircleDot } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Um passo da historia da inscricao.
 *
 * O estado nunca e dito so pela cor: cada marco traz um icone diferente e uma
 * palavra escrita ("Concluído", "Agora", "A seguir", "Encerrado"). Quem nao
 * distingue as cores — ou esta no sol, olhando o celular — le a mesma coisa.
 */
const props = defineProps<{
    marco: MarcoDaInscricao;
    ultimo: boolean;
}>();

const aparencia = computed(() => {
    switch (props.marco.estado) {
        case 'concluido':
            return { icone: CheckCircle2, rotulo: 'Concluído', circulo: 'bg-sucesso text-sucesso-foreground', texto: 'text-sucesso-texto' };
        case 'atual':
            return { icone: CircleDot, rotulo: 'Agora', circulo: 'bg-informacao text-informacao-foreground', texto: 'text-acao-texto' };
        case 'futuro':
            return { icone: CalendarClock, rotulo: 'A seguir', circulo: 'bg-muted text-muted-foreground', texto: 'text-muted-foreground' };
        default:
            return { icone: CircleAlert, rotulo: 'Encerrado', circulo: 'bg-muted text-foreground', texto: 'text-muted-foreground' };
    }
});
</script>

<template>
    <li class="relative flex gap-3 pb-6 last:pb-0" :data-marco="marco.chave" :data-estado="marco.estado">
        <!-- O fio que liga um passo ao outro e enfeite: o leitor de tela nao
             precisa dele para entender a sequencia da lista. -->
        <span v-if="!ultimo" class="bg-border absolute top-9 left-[1.125rem] h-[calc(100%-2.25rem)] w-px" aria-hidden="true" />

        <span class="relative z-10 flex size-9 shrink-0 items-center justify-center rounded-full" :class="aparencia.circulo">
            <component :is="aparencia.icone" class="size-5" aria-hidden="true" />
        </span>

        <div class="min-w-0 flex-1 space-y-1 pt-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <h3 class="text-base leading-tight font-semibold">{{ marco.titulo }}</h3>
                <span class="text-xs font-medium tracking-wide uppercase" :class="aparencia.texto">{{ aparencia.rotulo }}</span>
            </div>

            <p v-if="marco.momento" class="text-muted-foreground text-sm">
                <time :datetime="marco.momento">{{ formatarDataHora(marco.momento) }}</time>
            </p>

            <p class="text-muted-foreground text-sm leading-relaxed">{{ marco.descricao }}</p>
        </div>
    </li>
</template>

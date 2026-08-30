<script setup lang="ts">
import { contarVagas } from '@/lib/formato';
import type { VagaDaAtividade } from '@/types/painel';

/**
 * Vagas por atividade.
 *
 * Os números vêm dos contadores gravados na própria atividade, que são a fonte
 * da verdade do domínio. A tabela não recalcula nada: só mostra.
 */
defineProps<{
    vagas: VagaDaAtividade[];
}>();

function textoDasRestantes(vaga: VagaDaAtividade): string {
    if (vaga.restantes === null) {
        return 'Sem limite';
    }

    return contarVagas(vaga.restantes);
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full min-w-[36rem] caption-bottom text-sm">
            <caption class="px-4 py-3 text-left text-sm text-muted-foreground">
                Vagas de cada atividade. “Reservadas” são as de quem ainda não pagou; “confirmadas”, as de quem já pagou. Atividade sem limite de
                vagas aparece como “sem limite”.
            </caption>
            <thead>
                <tr class="border-b border-border bg-muted/50">
                    <th scope="col" class="px-4 py-2 text-left font-medium">Atividade</th>
                    <th scope="col" class="px-4 py-2 text-left font-medium">Dia</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Capacidade</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Reservadas</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Confirmadas</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Restantes</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="vaga in vagas" :key="vaga.atividade_id" class="border-b border-border last:border-0">
                    <th scope="row" class="px-4 py-2 text-left font-medium">
                        {{ vaga.atividade }}
                        <span class="block text-xs font-normal text-muted-foreground">{{ vaga.grupo }}</span>
                    </th>
                    <td class="px-4 py-2">{{ vaga.dia }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ vaga.capacidade ?? 'Sem limite' }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ vaga.reservadas }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ vaga.confirmadas }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">
                        <span :class="vaga.restantes === 0 ? 'font-semibold text-atencao-texto' : ''">
                            {{ textoDasRestantes(vaga) }}
                        </span>
                    </td>
                </tr>
                <tr v-if="vagas.length === 0">
                    <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">
                        Este evento ainda não tem atividade cadastrada, por isso não há vaga para acompanhar.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

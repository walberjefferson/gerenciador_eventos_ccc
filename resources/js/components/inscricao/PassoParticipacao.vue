<script setup lang="ts">
import GrupoDeAtividades from '@/components/inscricao/GrupoDeAtividades.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { useSelecaoAtividades } from '@/composables/useSelecaoAtividades';
import type { DiaEventoPublico } from '@/types/evento';
import { computed } from 'vue';

/**
 * Segunda etapa: o que a pessoa vai fazer em cada dia. As regras aparecem na
 * tela antes de o servidor precisar recusar — mas quem decide continua sendo
 * ele.
 */
const props = defineProps<{
    dias: DiaEventoPublico[];
    selecao: ReturnType<typeof useSelecaoAtividades>;
    mostrarProblemas: boolean;
}>();

const problemaDoGrupo = computed<Record<number, string>>(() => {
    if (!props.mostrarProblemas) {
        return {};
    }

    return Object.fromEntries(props.selecao.problemas.value.map((problema) => [problema.grupoId, problema.mensagem]));
});
</script>

<template>
    <div class="space-y-8">
        <Alert variant="informacao">
            <AlertTitle>Escolha a sua participação</AlertTitle>
            <AlertDescription>
                Cada bloco tem a sua regra logo abaixo do título. Atividades no mesmo horário não podem ser escolhidas juntas.
            </AlertDescription>
        </Alert>

        <p v-if="dias.length === 0" class="text-muted-foreground text-sm">A programação ainda será divulgada. Você pode seguir para a revisão.</p>

        <section v-for="dia in dias" :key="dia.id" class="space-y-4" :aria-labelledby="`dia-${dia.id}`">
            <div>
                <h3 :id="`dia-${dia.id}`" class="text-lg font-semibold">{{ dia.nome }}</h3>
                <p class="text-muted-foreground text-sm">{{ dia.data_rotulo }}</p>
            </div>

            <GrupoDeAtividades
                v-for="grupo in dia.grupos"
                :key="grupo.id"
                :grupo="grupo"
                :situacao-de="selecao.situacaoDe"
                :rotulo-de-contagem="selecao.rotuloDeContagem(grupo)"
                :problema="problemaDoGrupo[grupo.id] ?? null"
                @alternar="selecao.alternar"
            />
        </section>
    </div>
</template>

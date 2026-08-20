import type { CidadePublica, GrupoParticipantePublico } from '@/types/inscricao';
import { computed, watch, type ComputedRef, type Ref } from 'vue';

/**
 * O grupo de participante pertence a uma cidade. Esta funcao mantem a lista de
 * grupos coerente com a cidade escolhida e, quando a cidade muda, esquece o
 * grupo anterior — para ninguem enviar um grupo de outra cidade sem perceber.
 */
export function useGruposDaCidade(
    cidades: Ref<CidadePublica[]> | ComputedRef<CidadePublica[]>,
    grupos: Ref<GrupoParticipantePublico[]> | ComputedRef<GrupoParticipantePublico[]>,
    cidadeId: Ref<number | null>,
    grupoId: Ref<number | null>,
) {
    const gruposDaCidade = computed<GrupoParticipantePublico[]>(() => {
        if (cidadeId.value === null) {
            return [];
        }

        return grupos.value.filter((grupo) => grupo.cidade_id === cidadeId.value);
    });

    const cidadeEscolhida = computed<CidadePublica | null>(() => cidades.value.find((cidade) => cidade.id === cidadeId.value) ?? null);

    const grupoEscolhido = computed<GrupoParticipantePublico | null>(() => grupos.value.find((grupo) => grupo.id === grupoId.value) ?? null);

    /** Frase para quando a cidade escolhida ainda nao tem grupo cadastrado. */
    const avisoSemGrupos = computed<string | null>(() => {
        if (cidadeId.value === null || gruposDaCidade.value.length > 0) {
            return null;
        }

        return 'Ainda não há grupos cadastrados para esta cidade. Fale com a organização para saber como proceder.';
    });

    watch(cidadeId, (nova, anterior) => {
        if (nova === anterior) {
            return;
        }

        if (grupoId.value !== null && !gruposDaCidade.value.some((grupo) => grupo.id === grupoId.value)) {
            grupoId.value = null;
        }
    });

    return { gruposDaCidade, cidadeEscolhida, grupoEscolhido, avisoSemGrupos };
}

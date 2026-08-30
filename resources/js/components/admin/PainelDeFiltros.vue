<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown, SlidersHorizontal } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * O cartao que guarda os filtros de uma lista administrativa, e que recolhe.
 *
 * POR QUE ELE RECOLHE: a lista de inscricoes tem NOVE campos de filtro. Abertos
 * o tempo todo, eles empurram para baixo justamente aquilo que a pessoa veio
 * ver — e na maior parte das visitas ninguem filtra nada, so olha a lista.
 * Recolhido, o filtro continua a um clique e devolve a tela ao conteudo.
 *
 * ABERTO OU FECHADO, A REGRA E A MESMA EM TODA TELA: comeca FECHADO quando nao
 * ha filtro nenhum aplicado, e comeca ABERTO quando ha. O segundo caso e o que
 * importa: uma lista curta por causa de um filtro que a pessoa nao esta vendo
 * parece dado que sumiu. Se ha filtro ativo, ele fica a vista.
 *
 * A CONTAGEM no cabecalho existe pelo mesmo motivo — mesmo recolhido, o cartao
 * diz quantos filtros estao pegando. "Filtros" sozinho nao distingue "nenhum
 * filtro" de "tres filtros escondidos aqui dentro".
 *
 * O `Collapsible` do reka-ui v2 MONTA o conteudo mesmo fechado e o esconde com
 * o atributo `hidden` — que leitor de tela tambem respeita. Por isso nao ha
 * conteudo invisivel aos olhos e audivel ao leitor, que seria pior que
 * quebrado. Nao use `forceMount` aqui: e o que quebraria essa garantia.
 */
const props = withDefaults(
    defineProps<{
        /** Quantos filtros estao valendo agora. Zero esconde a contagem. */
        ativos?: number;
        /** Identificador unico na pagina — vira o `id` do titulo. */
        id?: string;
        titulo?: string;
    }>(),
    {
        ativos: 0,
        id: 'filtros',
        titulo: 'Filtros',
    },
);

const aberto = ref(props.ativos > 0);

/**
 * Filtro que passa a valer sem a pessoa ter aberto o painel — voltando de um
 * link com filtro no endereco, por exemplo — abre o painel. O contrario nao
 * vale: depois de aberto, quem fecha e quem clicou.
 */
watch(
    () => props.ativos,
    (agora, antes) => {
        if (agora > 0 && antes === 0) {
            aberto.value = true;
        }
    },
);

const resumo = computed<string>(() => (props.ativos === 1 ? '1 filtro ativo' : `${props.ativos} filtros ativos`));
</script>

<template>
    <Collapsible v-model:open="aberto" class="border-border rounded-lg border">
        <CollapsibleTrigger
            data-testid="abrir-filtros"
            class="focus-visible:ring-ring flex min-h-11 w-full items-center gap-2 px-4 py-3 text-left focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <SlidersHorizontal class="size-4 shrink-0" aria-hidden="true" />

            <h2 :id="`titulo-${props.id}`" class="text-lg font-semibold">{{ props.titulo }}</h2>

            <!-- A contagem some quando e zero: "0 filtros ativos" ocupa espaco
                 para dizer que nao ha nada a dizer. -->
            <span v-if="props.ativos > 0" class="bg-secondary text-secondary-foreground rounded-full px-2 py-0.5 text-xs font-medium">
                {{ resumo }}
            </span>

            <span class="text-muted-foreground ml-auto flex items-center gap-1 text-sm">
                {{ aberto ? 'Recolher' : 'Mostrar' }}
                <ChevronDown :class="['size-4 transition-transform', aberto ? 'rotate-180' : '']" aria-hidden="true" />
            </span>
        </CollapsibleTrigger>

        <CollapsibleContent>
            <div class="border-border border-t p-4">
                <slot />
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>

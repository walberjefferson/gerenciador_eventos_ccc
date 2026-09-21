<script setup lang="ts">
import FiltrosDeInscricao from '@/components/admin/FiltrosDeInscricao.vue';
import TabelaDeInscricoes from '@/components/admin/TabelaDeInscricoes.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { FiltrosAplicados, OpcoesDeFiltro, PaginaDeInscricoes } from '@/types/admin';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * A lista de inscrições.
 *
 * É onde o organizador acha uma pessoa: filtra por evento, situação, setor,
 * grupo, atividade escolhida, situação da cobrança e período, ou busca pelo
 * nome, pelo e-mail ou pelo código da inscrição.
 *
 * A paginação leva os filtros junto no endereço, então a página seguinte é a
 * continuação do mesmo resultado — e o endereço pode ser guardado ou mandado
 * para outra pessoa sem perder o que estava sendo visto.
 */
const props = defineProps<{
    inscricoes: PaginaDeInscricoes;
    filtros: FiltrosAplicados;
    opcoes: OpcoesDeFiltro;
    pode_exportar: boolean;
    sucesso: string | null;
}>();

const resumo = computed(() => {
    if (props.inscricoes.total === 0) {
        return 'Nenhuma inscrição encontrada com esses filtros.';
    }

    const primeira = (props.inscricoes.pagina_atual - 1) * props.inscricoes.por_pagina + 1;
    const ultima = primeira + props.inscricoes.dados.length - 1;

    return `Mostrando ${primeira} a ${ultima} de ${props.inscricoes.total} inscrição(ões).`;
});

/**
 * O endereço da exportação leva os mesmos filtros que estão na tela.
 *
 * É o ponto em que a planilha deixa de ser "tudo o que existe" e passa a ser
 * "exatamente o que eu estou vendo" — que é o que a pessoa espera ao clicar
 * logo depois de filtrar.
 */
const enderecoDaExportacao = computed(() => {
    const aplicados = Object.fromEntries(Object.entries(props.filtros).filter(([, valor]) => valor !== null && valor !== ''));

    return route('admin.inscricoes.exportar', aplicados);
});
</script>

<template>
    <AdminLayout
        titulo="Inscrições"
        descricao="Todas as inscrições, de todos os eventos. Os filtros se combinam e a busca olha nome, e-mail e código da inscrição — o CPF fica guardado cifrado e não é buscável, nem por pedaço."
    >
        <!-- O texto continua aqui para quem voltar à tela depois; quem
             anuncia a ação recém-feita é o aviso rápido da moldura, e por
             isso este parágrafo não é mais uma região viva: senão o leitor
             de tela ouviria a mesma frase duas vezes. -->
        <p v-if="props.sucesso" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">{{ props.sucesso }}</p>

        <FiltrosDeInscricao :filtros="props.filtros" :opcoes="props.opcoes" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p role="status" class="text-muted-foreground text-sm">{{ resumo }}</p>

            <a
                v-if="props.pode_exportar"
                :href="enderecoDaExportacao"
                data-testid="exportar-csv"
                class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Exportar para planilha (CSV)
            </a>
        </div>

        <TabelaDeInscricoes v-if="props.inscricoes.dados.length > 0" :inscricoes="props.inscricoes.dados" />

        <nav v-if="props.inscricoes.ultima_pagina > 1" aria-label="Paginação da lista de inscrições" class="flex items-center gap-3">
            <Link
                v-if="props.inscricoes.links.anterior"
                :href="props.inscricoes.links.anterior"
                preserve-scroll
                class="border-border focus-visible:ring-ring h-10 rounded-md border px-4 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Página anterior
            </Link>

            <span class="text-muted-foreground text-sm"> Página {{ props.inscricoes.pagina_atual }} de {{ props.inscricoes.ultima_pagina }} </span>

            <Link
                v-if="props.inscricoes.links.proxima"
                :href="props.inscricoes.links.proxima"
                preserve-scroll
                class="border-border focus-visible:ring-ring h-10 rounded-md border px-4 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Próxima página
            </Link>
        </nav>
    </AdminLayout>
</template>

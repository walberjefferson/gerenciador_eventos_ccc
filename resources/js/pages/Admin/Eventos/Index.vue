<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { EventoDaLista } from '@/types/admin';
import { Link, router, usePage } from '@inertiajs/vue3';
import { CalendarDays, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A lista de eventos.
 *
 * É a porta de entrada do cadastro: daqui se abre a ficha do evento (os campos
 * gerais) ou a programação dele (dias, grupos, atividades e conflitos).
 *
 * Evento com inscrição não é excluído. Apagá-lo levaria junto o histórico de
 * quem se inscreveu e o registro dos pagamentos, então a tela nem oferece o
 * botão — e o servidor recusa de novo se alguém insistir.
 */
const props = defineProps<{
    eventos: EventoDaLista[];
    sucesso: string | null;
}>();

const confirmandoExclusao = ref<number | null>(null);
const excluindo = ref(false);

const erroDeExclusao = computed<string | undefined>(() => usePage().props.errors?.exclusao);

function moeda(centavos: number): string {
    return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function data(iso: string): string {
    const [ano, mes, dia] = iso.split('-');

    return `${dia}/${mes}/${ano}`;
}

function vagas(evento: EventoDaLista): string {
    return evento.capacidade === null ? `${evento.vagas_ocupadas} (sem limite)` : `${evento.vagas_ocupadas} de ${evento.capacidade}`;
}

function excluir(evento: EventoDaLista): void {
    excluindo.value = true;

    router.delete(route('admin.eventos.destroy', { evento: evento.id }), {
        preserveScroll: true,
        onFinish: () => {
            excluindo.value = false;
            confirmandoExclusao.value = null;
        },
    });
}
</script>

<template>
    <AdminLayout
        titulo="Eventos"
        descricao="Cada evento tem uma ficha com os dados gerais e uma programação com os dias, os grupos e as atividades. Evento que já recebeu inscrição não pode ser excluído: mude a situação para cancelado ou finalizado."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p v-if="erroDeExclusao" role="alert" class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm">
            {{ erroDeExclusao }}
        </p>

        <div>
            <Link
                :href="route('admin.eventos.create')"
                class="bg-acao text-acao-foreground focus-visible:ring-ring inline-flex h-10 items-center rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Novo evento
            </Link>
        </div>

        <section aria-labelledby="titulo-lista-eventos" class="border-border rounded-lg border">
            <h2 id="titulo-lista-eventos" class="border-border border-b px-4 py-3 text-lg font-semibold">Eventos cadastrados</h2>

            <p v-if="props.eventos.length === 0" class="text-muted-foreground px-4 py-6 text-sm">
                Nenhum evento cadastrado ainda. Comece por “Novo evento”.
            </p>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Eventos cadastrados, com o período, a situação, as vagas ocupadas, o valor e quantas inscrições cada um recebeu.
                    </caption>
                    <thead>
                        <tr class="border-border border-b text-left">
                            <th scope="col" class="px-4 py-2 font-medium">Evento</th>
                            <th scope="col" class="px-4 py-2 font-medium">Período</th>
                            <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                            <th scope="col" class="px-4 py-2 font-medium">Vagas</th>
                            <th scope="col" class="px-4 py-2 font-medium">Valor</th>
                            <th scope="col" class="px-4 py-2 font-medium">Inscrições</th>
                            <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="evento in props.eventos" :key="evento.id" class="border-border border-b last:border-0">
                            <th scope="row" class="px-4 py-2 text-left font-normal">{{ evento.nome }}</th>
                            <td class="px-4 py-2 whitespace-nowrap">{{ data(evento.data_inicio) }} a {{ data(evento.data_fim) }}</td>
                            <td class="px-4 py-2">
                                <EtiquetaDeSituacao dominio="evento" :situacao="evento.situacao" :rotulo="evento.situacao_rotulo" />
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ vagas(evento) }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ moeda(evento.valor_centavos) }}</td>
                            <td class="px-4 py-2">{{ evento.inscricoes }}</td>
                            <td class="px-4 py-2">
                                <!--
                                    "Programação" é NEUTRA de propósito, mesmo
                                    sendo navegação: a regra de composição da
                                    linha é uma ação colorida de cada tipo, e a
                                    de editar já é "Editar". Dois botões azuis
                                    lado a lado não hierarquizam nada.
                                -->
                                <div class="flex flex-wrap items-center gap-2">
                                    <BotaoDeAcao
                                        tamanho="sm"
                                        intencao="editar"
                                        :icone="Pencil"
                                        :href="route('admin.eventos.edit', { evento: evento.id })"
                                    >
                                        Editar
                                    </BotaoDeAcao>
                                    <BotaoDeAcao
                                        tamanho="sm"
                                        :icone="CalendarDays"
                                        :href="route('admin.eventos.estrutura', { evento: evento.id })"
                                    >
                                        Programação
                                    </BotaoDeAcao>

                                    <span v-if="evento.inscricoes > 0" class="text-muted-foreground"> Não pode ser excluído: já tem inscrição. </span>
                                    <template v-else-if="confirmandoExclusao === evento.id">
                                        <span class="text-muted-foreground">Excluir mesmo?</span>
                                        <BotaoDeAcao
                                            tamanho="sm"
                                            intencao="excluir"
                                            :icone="Trash2"
                                            :disabled="excluindo"
                                            @click="excluir(evento)"
                                        >
                                            Sim, excluir
                                        </BotaoDeAcao>
                                        <BotaoDeAcao tamanho="sm" @click="confirmandoExclusao = null">Não</BotaoDeAcao>
                                    </template>
                                    <BotaoDeAcao
                                        v-else
                                        tamanho="sm"
                                        intencao="excluir"
                                        :icone="Trash2"
                                        @click="confirmandoExclusao = evento.id"
                                    >
                                        Excluir
                                    </BotaoDeAcao>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>

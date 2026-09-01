<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import type { InscricaoDaLista } from '@/types/admin';
import { Eye } from 'lucide-vue-next';

/**
 * A tabela de inscrições encontradas.
 *
 * As colunas mostram nome, e-mail, setor, grupo, situação, valor e prazo.
 * **CPF não é uma delas** — nem aqui, nem no CSV.
 */
const props = defineProps<{
    inscricoes: InscricaoDaLista[];
}>();

function moeda(centavos: number): string {
    return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function momento(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <div class="border-border overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
            <caption class="sr-only">
                Inscrições encontradas, com o evento, o setor, o grupo, a situação, o valor e o prazo de pagamento de cada uma.
            </caption>
            <thead>
                <tr class="border-border border-b text-left">
                    <th scope="col" class="px-4 py-2 font-medium">Pessoa</th>
                    <th scope="col" class="px-4 py-2 font-medium">Evento</th>
                    <th scope="col" class="px-4 py-2 font-medium">Setor</th>
                    <th scope="col" class="px-4 py-2 font-medium">Grupo</th>
                    <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                    <th scope="col" class="px-4 py-2 font-medium">Cobrança</th>
                    <th scope="col" class="px-4 py-2 font-medium">Valor</th>
                    <th scope="col" class="px-4 py-2 font-medium">Prazo</th>
                    <th scope="col" class="px-4 py-2 font-medium">Ficha</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="inscricao in props.inscricoes" :key="inscricao.id" class="border-border border-b last:border-0">
                    <th scope="row" class="px-4 py-2 text-left font-normal">
                        <span class="block">{{ inscricao.nome_completo }}</span>
                        <span class="text-muted-foreground block">{{ inscricao.email }}</span>
                    </th>
                    <td class="px-4 py-2">{{ inscricao.evento }}</td>
                    <td class="px-4 py-2">{{ inscricao.cidade || '—' }}</td>
                    <td class="px-4 py-2">{{ inscricao.grupo || '—' }}</td>
                    <td class="px-4 py-2">
                        <EtiquetaDeSituacao dominio="inscricao" :situacao="inscricao.situacao" :rotulo="inscricao.situacao_rotulo" />
                    </td>
                    <td class="px-4 py-2">
                        <!-- Inscrição sem cobrança emitida não tem situação de pagamento: a etiqueta vira travessão em vez de inventar um estado. -->
                        <EtiquetaDeSituacao
                            dominio="pagamento"
                            :situacao="inscricao.situacao_pagamento"
                            :rotulo="inscricao.situacao_pagamento_rotulo"
                        />
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">{{ moeda(inscricao.valor_centavos) }}</td>
                    <td class="px-4 py-2 whitespace-nowrap">{{ momento(inscricao.prazo_pagamento) }}</td>
                    <td class="px-4 py-2">
                        <!--
                            O nome da pessoa vai no texto que só o leitor de tela
                            ouve: numa tabela de trinta linhas, trinta links
                            chamados "Abrir" não dizem abrir o quê.

                            O "relative" que ancora esse texto mora na base do
                            BotaoDeAcao, e o comentário de lá explica por quê —
                            sem âncora, o `sr-only` estica o documento e a página
                            inteira encolhe no celular.
                        -->
                        <BotaoDeAcao
                            tamanho="sm"
                            intencao="ver"
                            :icone="Eye"
                            :href="route('admin.inscricoes.show', { inscricao: inscricao.id })"
                        >
                            <span class="sr-only">Abrir a ficha de {{ inscricao.nome_completo }}</span>
                            <span aria-hidden="true">Abrir</span>
                        </BotaoDeAcao>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

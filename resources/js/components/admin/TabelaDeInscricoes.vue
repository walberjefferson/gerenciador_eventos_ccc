<script setup lang="ts">
import type { InscricaoDaLista } from '@/types/admin';
import { Link } from '@inertiajs/vue3';

/**
 * A tabela de inscrições encontradas.
 *
 * As colunas mostram nome, e-mail, cidade, grupo, situação, valor e prazo.
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
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <caption class="sr-only">
                Inscrições encontradas, com o evento, a cidade, o grupo, a situação, o valor e o prazo de pagamento de cada uma.
            </caption>
            <thead>
                <tr class="border-b border-border text-left">
                    <th scope="col" class="px-4 py-2 font-medium">Pessoa</th>
                    <th scope="col" class="px-4 py-2 font-medium">Evento</th>
                    <th scope="col" class="px-4 py-2 font-medium">Cidade</th>
                    <th scope="col" class="px-4 py-2 font-medium">Grupo</th>
                    <th scope="col" class="px-4 py-2 font-medium">Situação</th>
                    <th scope="col" class="px-4 py-2 font-medium">Cobrança</th>
                    <th scope="col" class="px-4 py-2 font-medium">Valor</th>
                    <th scope="col" class="px-4 py-2 font-medium">Prazo</th>
                    <th scope="col" class="px-4 py-2 font-medium">Ficha</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="inscricao in props.inscricoes" :key="inscricao.id" class="border-b border-border last:border-0">
                    <th scope="row" class="px-4 py-2 text-left font-normal">
                        <span class="block">{{ inscricao.nome_completo }}</span>
                        <span class="block text-muted-foreground">{{ inscricao.email }}</span>
                    </th>
                    <td class="px-4 py-2">{{ inscricao.evento }}</td>
                    <td class="px-4 py-2">{{ inscricao.cidade || '—' }}</td>
                    <td class="px-4 py-2">{{ inscricao.grupo || '—' }}</td>
                    <td class="px-4 py-2">{{ inscricao.situacao_rotulo }}</td>
                    <td class="px-4 py-2">{{ inscricao.situacao_pagamento_rotulo ?? '—' }}</td>
                    <td class="px-4 py-2 whitespace-nowrap">{{ moeda(inscricao.valor_centavos) }}</td>
                    <td class="px-4 py-2 whitespace-nowrap">{{ momento(inscricao.prazo_pagamento) }}</td>
                    <td class="px-4 py-2">
                        <!--
                            "relative": o texto que só o leitor de tela ouve fica
                            posicionado de forma absoluta. Sem uma âncora aqui, ele
                            se prende à moldura da página inteira, escapa da caixa
                            que rola e estica o documento para a largura da tabela —
                            no celular, a página inteira encolhe por causa disso.
                        -->
                        <Link
                            :href="route('admin.inscricoes.show', { inscricao: inscricao.id })"
                            class="relative rounded-md border border-border px-3 py-1 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <span class="sr-only">Abrir a ficha de {{ inscricao.nome_completo }}</span>
                            <span aria-hidden="true">Abrir</span>
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

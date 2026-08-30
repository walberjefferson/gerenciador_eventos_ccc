<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { PapelDaMatriz, PermissaoDaMatriz } from '@/types/admin';
import { Link } from '@inertiajs/vue3';

/**
 * O que cada papel alcança.
 *
 * **Esta tela só lê, e isso é decisão, não falta de tempo.** Não há botão de
 * criar papel, de editar permissão nem de apagar nada. O conjunto nasce no
 * `PapeisSeeder`, que é versionado, passa por revisão e roda igual em todo
 * ambiente a cada subida do container. Editar isso pela tela faria a mesma
 * instalação ter conjuntos de permissão diferentes em cada lugar, e um papel
 * mal montado viraria brecha sem ninguém ter revisado.
 *
 * Ela existe porque a lista de usuários fala em "administrador" e
 * "organizador", e quem administra precisa poder responder "o que isso quer
 * dizer, exatamente?" sem abrir código. Por isso cada linha traz a **frase em
 * português** da permissão, lida do próprio seeder — e não o nome técnico
 * sozinho, que não explica nada a quem não escreveu o sistema.
 *
 * A tabela tem cabeçalho de coluna E de linha (`<th scope>`): é assim que um
 * leitor de tela consegue dizer "organizador, catálogo: não" em vez de ler uma
 * sequência de "sim, não, sim" sem referência nenhuma.
 */
const props = defineProps<{
    papeis: PapelDaMatriz[];
    permissoes: PermissaoDaMatriz[];
}>();

function alcanca(papel: PapelDaMatriz, permissao: string): boolean {
    return papel.permissoes.includes(permissao);
}
</script>

<template>
    <AdminLayout
        titulo="Papéis"
        descricao="Cada conta administrativa tem um papel, e o papel é que decide o que ela alcança. Esta tela é só de consulta: papéis e permissões nascem no código, passam por revisão e são os mesmos em todo ambiente — ninguém cria nem edita permissão por aqui."
    >
        <div class="border-border overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm" data-testid="tabela-papeis">
                <caption class="sr-only">
                    O que cada papel alcança, permissão por permissão
                </caption>
                <thead class="bg-muted/40">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">O que a permissão alcança</th>
                        <th v-for="papel in props.papeis" :key="papel.nome" scope="col" class="px-4 py-3 font-medium">
                            {{ papel.rotulo }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="permissao in props.permissoes" :key="permissao.nome" class="border-border border-t align-top">
                        <th scope="row" class="px-4 py-3 text-left font-normal">
                            <span class="block font-medium">{{ permissao.explicacao }}</span>
                            <code class="text-muted-foreground text-xs">{{ permissao.nome }}</code>
                        </th>
                        <td v-for="papel in props.papeis" :key="papel.nome" class="px-4 py-3">
                            <!-- A palavra escrita, e não só a cor ou um ícone:
                                 quem não distingue as duas cores precisa ler o
                                 estado (WCAG 1.4.1, DA-42). -->
                            <span
                                :class="
                                    alcanca(papel, permissao.nome)
                                        ? 'bg-sucesso-suave text-sucesso-suave-foreground rounded-full px-2 py-0.5 text-xs font-medium'
                                        : 'bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs font-medium'
                                "
                                :data-testid="`${papel.nome}-${permissao.nome}`"
                            >
                                {{ alcanca(papel, permissao.nome) ? 'Alcança' : 'Não alcança' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/40">
                    <tr class="border-border border-t">
                        <th scope="row" class="px-4 py-3 text-left font-medium">Quantas permissões</th>
                        <td v-for="papel in props.papeis" :key="papel.nome" class="px-4 py-3">
                            {{ papel.quantas }} de {{ props.permissoes.length }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <section class="border-border rounded-lg border p-4">
            <h2 class="text-lg font-semibold">Por que não dá para editar isto aqui</h2>
            <p class="text-muted-foreground mt-1 max-w-3xl text-sm">
                Papéis e permissões nascem no código do sistema, junto com as telas que eles abrem. Assim o conjunto é o mesmo em todo lugar onde o
                sistema estiver instalado, e qualquer mudança passa por revisão antes de valer. O que se decide pela tela é outra coisa: qual papel
                cada pessoa tem.
            </p>
            <Link
                :href="route('admin.usuarios.index')"
                data-testid="voltar-para-usuarios"
                class="border-border focus-visible:ring-ring mt-3 inline-flex min-h-11 items-center rounded-md border px-4 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Voltar para os usuários
            </Link>
        </section>
    </AdminLayout>
</template>

<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import EtiquetaDeSituacao from '@/components/admin/EtiquetaDeSituacao.vue';
import PainelDeFiltros from '@/components/admin/PainelDeFiltros.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AvisoDoProvedor, FiltrosDeAvisos, OpcoesDeAvisos, PaginaDeAvisos } from '@/types/admin';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * Os avisos automáticos que o provedor de pagamento mandou.
 *
 * **Esta tela só lê.** Não há botão de reprocessar, de editar nem de apagar:
 * o aviso é o registro de algo que aconteceu fora daqui, e reescrevê-lo seria
 * inventar um passado que o provedor não conhece.
 *
 * Quem chega aqui costuma estar atrás de uma pergunta só — "o provedor está
 * mesmo chamando?" —, então a lista vem do mais recente para o mais antigo e
 * os filtros são os que respondem às perguntas seguintes: quando, em que
 * situação, de qual provedor e com assinatura válida ou não.
 */
const props = defineProps<{
    avisos: PaginaDeAvisos;
    filtros: FiltrosDeAvisos;
    opcoes: OpcoesDeAvisos;
}>();

const campos = reactive<FiltrosDeAvisos>({ ...props.filtros });

/**
 * Quantos filtros vieram APLICADOS do servidor — e nao quantos estao digitados.
 * Quem preencheu um campo e ainda nao clicou em "Filtrar" nao mudou a lista, e
 * o cabecalho do painel estaria mentindo se ja contasse.
 */
const filtrosAtivos = computed<number>(
    () => Object.values(props.filtros).filter((valor) => valor !== null && valor !== '' && valor !== undefined).length,
);

/** Quais avisos estão com o payload aberto. Fechado é o estado normal. */
const abertos = ref<number[]>([]);

function estaAberto(id: number): boolean {
    return abertos.value.includes(id);
}

function alternar(id: number): void {
    abertos.value = estaAberto(id) ? abertos.value.filter((item) => item !== id) : [...abertos.value, id];
}

function aplicar(): void {
    const parametros: Record<string, string> = {};

    for (const [chave, valor] of Object.entries(campos)) {
        if (valor !== null && valor !== '') {
            parametros[chave] = String(valor);
        }
    }

    abertos.value = [];

    router.get(route('admin.pagamentos.avisos'), parametros, { preserveState: true, preserveScroll: true });
}

function limpar(): void {
    for (const chave of Object.keys(campos) as (keyof FiltrosDeAvisos)[]) {
        campos[chave] = null;
    }

    abertos.value = [];

    router.get(route('admin.pagamentos.avisos'));
}

const resumo = computed(() => {
    if (props.avisos.total === 0) {
        return 'Nenhum aviso encontrado com esses filtros.';
    }

    const primeira = (props.avisos.pagina_atual - 1) * props.avisos.por_pagina + 1;
    const ultima = primeira + props.avisos.dados.length - 1;

    return `Mostrando ${primeira} a ${ultima} de ${props.avisos.total} aviso(s).`;
});

/** Verdadeiro só quando a tabela inteira está vazia, e não por causa de filtro. */
const nenhumAvisoJamais = computed<boolean>(() => props.avisos.total === 0 && Object.values(props.filtros).every((valor) => valor === null));

/*
 * A cor de cada situação saiu daqui.
 *
 * O mapa desta tela era um dos três iguais espalhados pelo sistema, e o
 * raciocínio que ele carregava — "ignorado" é neutro porque não é erro — agora
 * mora em `resources/js/lib/situacoes.ts`, com a justificativa por escrito, ao
 * lado dos outros quatro domínios. A tela só diz de que domínio é a situação.
 */

/** O payload formatado, com indentação, do jeito que se lê um aviso. */
function payloadLegivel(aviso: AvisoDoProvedor): string {
    if (aviso.payload === null) {
        return 'Este aviso foi gravado sem conteúdo.';
    }

    return JSON.stringify(aviso.payload, null, 2);
}
</script>

<template>
    <AdminLayout
        titulo="Avisos do provedor"
        descricao="Todo aviso automático que o provedor de pagamento enviou, do mais recente para o mais antigo. A lista é só de leitura: nada aqui reprocessa, altera ou apaga um aviso — ele é o registro do que aconteceu fora do sistema."
    >
        <PainelDeFiltros id="filtros-avisos" :ativos="filtrosAtivos">
            <form aria-labelledby="titulo-filtros-avisos" class="grid gap-4" @submit.prevent="aplicar">
                <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
                    <div class="flex flex-col gap-1">
                        <label for="avisos-de" class="text-sm font-medium">A partir de</label>
                        <input
                            id="avisos-de"
                            v-model="campos.de"
                            type="date"
                            data-testid="avisos-filtro-de"
                            class="border-input bg-background focus-visible:ring-ring h-11 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="avisos-ate" class="text-sm font-medium">Até</label>
                        <input
                            id="avisos-ate"
                            v-model="campos.ate"
                            type="date"
                            data-testid="avisos-filtro-ate"
                            class="border-input bg-background focus-visible:ring-ring h-11 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="avisos-situacao" class="text-sm font-medium">Situação</label>
                        <select
                            id="avisos-situacao"
                            v-model="campos.situacao"
                            data-testid="avisos-filtro-situacao"
                            aria-describedby="ajuda-avisos-situacao"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option :value="null">Todas</option>
                            <option v-for="situacao in props.opcoes.situacoes" :key="situacao.valor" :value="situacao.valor">
                                {{ situacao.rotulo }}
                            </option>
                        </select>
                        <!-- A explicação fica aqui, à vista, e não escondida atrás de um ícone: "ignorado" é a situação que mais assusta quem lê pela primeira vez, e ela é justamente a que não é problema. -->
                        <p id="ajuda-avisos-situacao" class="text-muted-foreground text-sm">
                            <strong class="font-medium">Ignorado não é erro:</strong> é o aviso que chegou sem assinatura válida, que falava de uma
                            cobrança que não existe aqui, ou que repetia algo já resolvido. Quem exige atenção é
                            <strong class="font-medium">Falhou</strong>.
                        </p>
                    </div>

                    <!-- Em `md` a grade tem tres colunas e os cinco campos
                         deixavam a segunda linha pela metade: Provedor e
                         Assinatura ocupavam duas das tres celulas e a terceira
                         ficava vazia. O Provedor toma as duas — e nao a
                         Assinatura — porque nome de provedor e o texto mais
                         longo dos dois. Em `lg` sao cinco colunas para cinco
                         campos, e cada um volta a valer por um. -->
                    <div class="flex flex-col gap-1 md:col-span-2 lg:col-span-1">
                        <label for="avisos-gateway" class="text-sm font-medium">Provedor</label>
                        <select
                            id="avisos-gateway"
                            v-model="campos.gateway"
                            data-testid="avisos-filtro-gateway"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option :value="null">Todos</option>
                            <option v-for="gateway in props.opcoes.gateways" :key="gateway" :value="gateway">{{ gateway }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="avisos-assinatura" class="text-sm font-medium">Assinatura</label>
                        <select
                            id="avisos-assinatura"
                            v-model="campos.assinatura_valida"
                            data-testid="avisos-filtro-assinatura"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option :value="null">Tanto faz</option>
                            <option value="sim">Válida</option>
                            <option value="nao">Inválida</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        Filtrar
                    </button>
                    <button
                        type="button"
                        class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        @click="limpar"
                    >
                        Limpar filtros
                    </button>
                </div>
            </form>
        </PainelDeFiltros>

        <p role="status" class="text-muted-foreground text-sm" data-testid="avisos-resumo">{{ resumo }}</p>

        <!-- Nenhum aviso na tabela inteira não é defeito, e a tela precisa dizer isso: em desenvolvimento o provedor é simulado e nunca chama; em produção pode ser o endereço de aviso que ficou por registrar. -->
        <div v-if="nenhumAvisoJamais" class="border-border bg-muted/40 rounded-lg border p-6" data-testid="avisos-vazio">
            <h2 class="text-base font-semibold">Nenhum aviso recebido até agora</h2>
            <p class="text-muted-foreground mt-1 max-w-3xl text-sm">
                Isso é o esperado enquanto o sistema estiver cobrando pelo provedor simulado (<code>PAYMENT_GATEWAY=fake</code>): não existe provedor
                de verdade para chamar. Com a cobrança real ligada, porém, uma lista vazia costuma querer dizer que o endereço de aviso não foi
                registrado no painel da Efí — as cobranças nascem normalmente e nenhuma se confirma sozinha.
            </p>
        </div>

        <div v-else-if="props.avisos.dados.length > 0" class="border-border overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm" data-testid="tabela-avisos">
                <caption class="sr-only">
                    Avisos recebidos do provedor de pagamento, do mais recente para o mais antigo
                </caption>
                <thead class="bg-muted/40">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Recebido em</th>
                        <th scope="col" class="px-4 py-3 font-medium">Provedor</th>
                        <th scope="col" class="px-4 py-3 font-medium">Tipo de aviso</th>
                        <!-- Duas colunas parecidas que respondem a perguntas diferentes: o txid diz QUAL COBRANÇA foi paga (e é ele que se cola na busca de inscrições para chegar à pessoa); o "fim a fim" diz QUAL TRANSFERÊNCIA pagou, e é o número que o banco usa. O rótulo antigo, "Identificador no provedor", cobria os dois e por isso não servia para nenhum. -->
                        <th scope="col" class="px-4 py-3 font-medium">Cobrança na Efí (txid)</th>
                        <th scope="col" class="px-4 py-3 font-medium">Fim a fim (E2E)</th>
                        <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                        <th scope="col" class="px-4 py-3 font-medium">Assinatura</th>
                        <th scope="col" class="px-4 py-3 font-medium">Processado em</th>
                        <th scope="col" class="px-4 py-3 font-medium">Motivo</th>
                        <th scope="col" class="px-4 py-3 font-medium">Conteúdo</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="aviso in props.avisos.dados" :key="aviso.id">
                        <tr class="border-border border-t align-top" :data-testid="`avisos-linha-${aviso.id}`">
                            <td class="px-4 py-3 whitespace-nowrap">{{ aviso.recebido_em ?? '—' }}</td>
                            <td class="px-4 py-3">{{ aviso.gateway }}</td>
                            <td class="px-4 py-3">{{ aviso.tipo_evento ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono break-all" :data-testid="`avisos-txid-${aviso.id}`">{{ aviso.id_externo ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono break-all">{{ aviso.id_evento_externo ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <EtiquetaDeSituacao dominio="webhook" :situacao="aviso.situacao" :rotulo="aviso.situacao_rotulo" />
                            </td>
                            <td class="px-4 py-3">
                                <!-- Assinatura inválida é informação de segurança, não de erro: alguém bateu na porta com a chave errada. Por isso o destaque é de atenção, e não o vermelho de falha. -->
                                <span
                                    class="inline-flex items-center rounded-md border px-2 py-1 text-xs font-medium"
                                    :class="
                                        aviso.assinatura_valida
                                            ? 'border-border bg-muted text-foreground'
                                            : 'border-atencao/60 bg-atencao-suave text-atencao-suave-foreground'
                                    "
                                >
                                    {{ aviso.assinatura_valida ? 'Válida' : 'Inválida' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ aviso.processado_em ?? '—' }}</td>
                            <td class="px-4 py-3">{{ aviso.erro ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <!-- Neutro, e não `ver`: a cor de "abrir a ficha" fica reservada para a ação que leva a outra tela. Aqui a linha só se desdobra. -->
                                <BotaoDeAcao
                                    tamanho="xs"
                                    :data-testid="`avisos-expandir-${aviso.id}`"
                                    :aria-expanded="estaAberto(aviso.id)"
                                    :aria-controls="`avisos-payload-${aviso.id}`"
                                    class="min-w-11"
                                    @click="alternar(aviso.id)"
                                >
                                    {{ estaAberto(aviso.id) ? 'Ocultar conteúdo do aviso' : 'Ver conteúdo do aviso' }}
                                </BotaoDeAcao>
                            </td>
                        </tr>

                        <!-- O conteúdo só aparece quando alguém pede: um jsonb inteiro em cada linha tornaria a lista ilegível. -->
                        <tr v-if="estaAberto(aviso.id)" class="border-border bg-muted/20 border-t">
                            <td :id="`avisos-payload-${aviso.id}`" colspan="10" class="px-4 py-3">
                                <p class="text-muted-foreground mb-2 text-sm">
                                    O que o provedor mandou, como foi gravado. Campos que costumam carregar segredo já entraram no banco como
                                    <code>[removido]</code>.
                                </p>
                                <pre
                                    :data-testid="`avisos-payload-${aviso.id}`"
                                    class="border-border bg-background max-h-80 overflow-auto rounded-md border p-3 text-xs"
                                    >{{ payloadLegivel(aviso) }}</pre
                                >
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <nav v-if="props.avisos.ultima_pagina > 1" aria-label="Paginação dos avisos do provedor" class="flex items-center gap-3">
            <Link
                v-if="props.avisos.links.anterior"
                :href="props.avisos.links.anterior"
                preserve-scroll
                class="border-border focus-visible:ring-ring flex h-11 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Página anterior
            </Link>

            <span class="text-muted-foreground text-sm"> Página {{ props.avisos.pagina_atual }} de {{ props.avisos.ultima_pagina }} </span>

            <Link
                v-if="props.avisos.links.proxima"
                :href="props.avisos.links.proxima"
                preserve-scroll
                class="border-border focus-visible:ring-ring flex h-11 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Próxima página
            </Link>
        </nav>
    </AdminLayout>
</template>

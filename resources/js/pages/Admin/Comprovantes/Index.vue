<script setup lang="ts">
import BotaoDeAcao from '@/components/admin/BotaoDeAcao.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatarDataHora, formatarValor } from '@/lib/formato';
import type { EscopoDaFilaDeComprovantes, LinhaDaFilaDeComprovantes } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { Check, CircleAlert, Download, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A fila de conferência dos comprovantes de pagamento.
 *
 * Quem abre esta tela vê APENAS o próprio setor, e o recorte é do servidor: ele
 * não é um filtro daqui, não viaja na URL e não tem como ser trocado (RN-S9). O
 * administrador vê tudo, e a tela diz qual dos dois casos é o dele.
 *
 * **A ordem da fila não é estética.** O prazo da inscrição continua correndo
 * enquanto o comprovante espera conferência, e a rotina de expiração — que não
 * sabe o que é comprovante — pode devolver a vaga com o dinheiro já na conta do
 * responsável. Por isso a fila chega ordenada pelo prazo mais próximo e o que
 * vence em menos de 24 horas aparece em vermelho, com o aviso escrito. Isso
 * reduz a chance; não a elimina.
 *
 * Aceitar exige a observação; recusar exige o motivo. Os dois textos são
 * cobrados de novo no servidor: este formulário só evita a viagem.
 */
const props = defineProps<{
    comprovantes: LinhaDaFilaDeComprovantes[];
    escopo: EscopoDaFilaDeComprovantes;
    horas_de_alerta: number;
    sucesso: string | null;
}>();

/** Qual linha está aberta, e para qual das duas decisões. */
const emConferencia = ref<LinhaDaFilaDeComprovantes | null>(null);
const decisao = ref<'aceitar' | 'recusar'>('aceitar');

const formulario = useForm({
    observacao: '',
    motivo: '',
});

const urgentes = computed(() => props.comprovantes.filter((linha) => linha.urgente).length);

const tituloDoModal = computed(() => (decisao.value === 'aceitar' ? 'Aceitar o comprovante e confirmar a inscrição' : 'Recusar o comprovante'));

function abrir(linha: LinhaDaFilaDeComprovantes, qual: 'aceitar' | 'recusar'): void {
    emConferencia.value = linha;
    decisao.value = qual;
    formulario.clearErrors();
    formulario.reset();
}

function aoTrocarAbertura(aberto: boolean): void {
    if (!aberto) {
        emConferencia.value = null;
        formulario.clearErrors();
        formulario.reset();
    }
}

function confirmar(): void {
    const linha = emConferencia.value;

    if (linha === null) {
        return;
    }

    const rota =
        decisao.value === 'aceitar'
            ? route('admin.comprovantes.aceitar', { comprovante: linha.id })
            : route('admin.comprovantes.recusar', { comprovante: linha.id });

    formulario.post(rota, {
        preserveScroll: true,
        onSuccess: () => {
            emConferencia.value = null;
            formulario.reset();
        },
    });
}

/** "vence em 6h", "vence em 3 dias", "prazo vencido" — em uma frase só. */
function prazoEmPalavras(linha: LinhaDaFilaDeComprovantes): string {
    if (linha.horas_ate_o_prazo === null) {
        return 'sem prazo definido';
    }

    if (linha.horas_ate_o_prazo < 0) {
        return 'prazo já vencido';
    }

    if (linha.horas_ate_o_prazo < 48) {
        return `vence em ${linha.horas_ate_o_prazo}h`;
    }

    return `vence em ${Math.floor(linha.horas_ate_o_prazo / 24)} dias`;
}
</script>

<template>
    <AdminLayout
        titulo="Comprovantes"
        descricao="Os comprovantes de pagamento enviados por quem se inscreveu em evento que recebe pela chave Pix do setor. Aceitar confirma a inscrição; recusar pede o motivo, que o participante lê na tela dele."
    >
        <p v-if="props.sucesso" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm" data-testid="aviso-da-fila">
            {{ props.sucesso }}
        </p>

        <!-- De quem é esta fila. Sem isso, o responsável de um setor poderia
             achar que a lista curta é a lista inteira do sistema. -->
        <p class="text-muted-foreground text-sm" data-testid="escopo-da-fila">
            <template v-if="props.escopo.recortado_por_setor">
                Você está vendo apenas
                <strong>{{ props.escopo.setores.length > 0 ? props.escopo.setores.join(', ') : 'nenhum setor' }}</strong
                >: são os setores pelos quais você responde.
            </template>
            <template v-else>Você está vendo os comprovantes de todos os setores.</template>
        </p>

        <p
            v-if="urgentes > 0"
            role="alert"
            class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2 text-sm"
            data-testid="aviso-de-urgencia"
        >
            {{ urgentes }} comprovante(s) de inscrição que vence(m) em menos de {{ props.horas_de_alerta }} horas. Se o prazo passar antes da
            conferência, a vaga volta para a fila mesmo com o dinheiro já na conta.
        </p>

        <p v-if="props.comprovantes.length === 0" class="text-muted-foreground px-4 py-6 text-sm" role="status" data-testid="fila-vazia">
            Nenhum comprovante aguardando conferência.
        </p>

        <div v-else class="border-border overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    Comprovantes aguardando conferência, do prazo mais próximo para o mais distante.
                </caption>
                <thead>
                    <tr class="border-border border-b text-left">
                        <th scope="col" class="px-4 py-2 font-medium">Participante</th>
                        <th scope="col" class="px-4 py-2 font-medium">Setor</th>
                        <th scope="col" class="px-4 py-2 font-medium">Valor</th>
                        <th scope="col" class="px-4 py-2 font-medium">Prazo</th>
                        <th scope="col" class="px-4 py-2 font-medium">Enviado em</th>
                        <th scope="col" class="px-4 py-2 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="linha in props.comprovantes"
                        :key="linha.id"
                        class="border-border border-b last:border-0"
                        :class="linha.urgente ? 'bg-destructive/5' : ''"
                        :data-testid="`linha-comprovante-${linha.id}`"
                    >
                        <th scope="row" class="px-4 py-2 text-left font-normal">
                            <span class="block font-medium">{{ linha.inscricao.nome_completo }}</span>
                            <span class="text-muted-foreground block font-mono text-xs">{{ linha.inscricao.codigo_publico }}</span>
                            <span class="text-muted-foreground block text-xs">{{ linha.inscricao.evento }}</span>
                        </th>
                        <td class="px-4 py-2">
                            <span class="block">{{ linha.inscricao.setor ?? '—' }}</span>
                            <span class="text-muted-foreground block text-xs">{{ linha.inscricao.grupo }}</span>
                        </td>
                        <td class="px-4 py-2 tabular-nums">{{ formatarValor(linha.inscricao.valor_centavos, 'BRL') }}</td>
                        <td class="px-4 py-2">
                            <span :class="linha.urgente ? 'text-destructive inline-flex items-center gap-1 font-medium' : ''">
                                <CircleAlert v-if="linha.urgente" class="size-4" aria-hidden="true" />
                                {{ prazoEmPalavras(linha) }}
                            </span>
                            <span v-if="linha.inscricao.prazo_pagamento" class="text-muted-foreground block text-xs">
                                {{ formatarDataHora(linha.inscricao.prazo_pagamento) }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <span class="block">{{ linha.enviado_em ? formatarDataHora(linha.enviado_em) : '—' }}</span>
                            <span class="text-muted-foreground block text-xs">{{ linha.nome_original }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <!-- O arquivo sai por rota autenticada, com o
                                     mesmo escopo de setor (RN-S11): é um link
                                     comum porque é um download, e não uma ação
                                     que muda alguma coisa. -->
                                <a
                                    :href="route('admin.comprovantes.arquivo', { comprovante: linha.id })"
                                    class="border-border focus-visible:ring-ring inline-flex h-8 items-center gap-1 rounded-md border px-2 text-xs font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                                    :data-testid="`abrir-comprovante-${linha.id}`"
                                >
                                    <Download class="size-3.5" aria-hidden="true" />
                                    Abrir comprovante
                                </a>

                                <BotaoDeAcao
                                    tamanho="xs"
                                    intencao="ver"
                                    :icone="Check"
                                    :data-testid="`aceitar-${linha.id}`"
                                    @click="abrir(linha, 'aceitar')"
                                >
                                    Aceitar
                                </BotaoDeAcao>

                                <BotaoDeAcao
                                    tamanho="xs"
                                    intencao="excluir"
                                    :icone="X"
                                    :data-testid="`recusar-${linha.id}`"
                                    @click="abrir(linha, 'recusar')"
                                >
                                    Recusar
                                </BotaoDeAcao>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog :open="emConferencia !== null" @update:open="aoTrocarAbertura">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ tituloDoModal }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="decisao === 'aceitar'">
                            Confirmar aqui é declarar que o dinheiro entrou na conta do setor. A inscrição de
                            {{ emConferencia?.inscricao.nome_completo }} passa a confirmada, e o que você escrever fica no histórico do pagamento.
                        </template>
                        <template v-else>
                            A inscrição continua aguardando pagamento até o prazo, e o participante pode enviar outro comprovante. O motivo é o que
                            ele vai ler para corrigir.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="confirmar">
                    <div v-if="decisao === 'aceitar'" class="flex flex-col gap-1">
                        <label for="conferencia-observacao" class="text-sm font-medium">O que você conferiu</label>
                        <textarea
                            id="conferencia-observacao"
                            v-model="formulario.observacao"
                            rows="4"
                            required
                            aria-describedby="ajuda-conferencia-observacao"
                            :aria-invalid="formulario.errors.observacao ? true : undefined"
                            data-testid="campo-observacao"
                            class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        ></textarea>
                        <p id="ajuda-conferencia-observacao" class="text-muted-foreground text-sm">
                            Exemplo: “Pix de R$ 150,00 recebido às 14h12, nome confere com o da inscrição.”
                        </p>
                        <p v-if="formulario.errors.observacao" role="alert" class="text-destructive text-sm" data-testid="erro-observacao">
                            {{ formulario.errors.observacao }}
                        </p>
                    </div>

                    <div v-else class="flex flex-col gap-1">
                        <label for="conferencia-motivo" class="text-sm font-medium">Por que o comprovante não foi aceito</label>
                        <textarea
                            id="conferencia-motivo"
                            v-model="formulario.motivo"
                            rows="4"
                            required
                            aria-describedby="ajuda-conferencia-motivo"
                            :aria-invalid="formulario.errors.motivo ? true : undefined"
                            data-testid="campo-motivo"
                            class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        ></textarea>
                        <p id="ajuda-conferencia-motivo" class="text-muted-foreground text-sm">
                            Escreva para a pessoa, não para o sistema: é o texto que aparece na tela dela.
                        </p>
                        <p v-if="formulario.errors.motivo" role="alert" class="text-destructive text-sm" data-testid="erro-motivo">
                            {{ formulario.errors.motivo }}
                        </p>
                    </div>

                    <DialogFooter>
                        <button
                            type="button"
                            class="border-border focus-visible:ring-ring h-11 rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            @click="aoTrocarAbertura(false)"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formulario.processing"
                            data-testid="confirmar-conferencia"
                            class="bg-acao text-acao-foreground focus-visible:ring-ring h-11 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                        >
                            {{ decisao === 'aceitar' ? 'Aceitar e confirmar' : 'Recusar comprovante' }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>

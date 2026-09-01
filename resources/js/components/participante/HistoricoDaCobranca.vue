<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { formatarDataHora, formatarValor } from '@/lib/formato';
import { varianteDoPagamento } from '@/lib/situacoes';
import type { PagamentoDoHistorico } from '@/types/participante';

/**
 * Todas as cobrancas desta inscricao, da mais recente para a mais antiga.
 *
 * Pode haver mais de uma: se a primeira vencer, ou se o participante pedir a
 * segunda via, outra e emitida. Aqui ele ve o caminho inteiro do dinheiro,
 * sem nenhum dado da conversa com a instituicao financeira.
 */
defineProps<{
    pagamentos: PagamentoDoHistorico[];
    moeda: string;
}>();

/*
 * A cor de cada cobrança vem do mapeamento central (`lib/situacoes.ts`), a
 * mesma fonte que a ficha do painel consulta. Antes eram dois mapas separados,
 * e uma cobrança estornada aparecia cinza aqui e sem cor nenhuma lá.
 */
</script>

<template>
    <div>
        <p v-if="pagamentos.length === 0" class="text-muted-foreground text-sm" data-testid="historico-vazio">
            Ainda não emitimos nenhuma cobrança para esta inscrição.
        </p>

        <ul v-else class="space-y-3" data-testid="historico-da-cobranca">
            <li v-for="pagamento in pagamentos" :key="pagamento.codigo_publico" class="border-border rounded-lg border p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Badge :variant="varianteDoPagamento(pagamento.situacao)">{{ pagamento.situacao_rotulo }}</Badge>
                    <span class="text-base font-semibold">{{ formatarValor(pagamento.valor_centavos, moeda) }}</span>
                </div>

                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Forma de pagamento</dt>
                        <dd class="font-medium">{{ pagamento.metodo_rotulo }}</dd>
                    </div>
                    <div v-if="pagamento.criado_em">
                        <dt class="text-muted-foreground">Emitida em</dt>
                        <dd class="font-medium">{{ formatarDataHora(pagamento.criado_em) }}</dd>
                    </div>
                    <div v-if="pagamento.expira_em">
                        <dt class="text-muted-foreground">Vale até</dt>
                        <dd class="font-medium">{{ formatarDataHora(pagamento.expira_em) }}</dd>
                    </div>
                    <div v-if="pagamento.pago_em">
                        <dt class="text-muted-foreground">Pagamento recebido em</dt>
                        <dd class="font-medium">{{ formatarDataHora(pagamento.pago_em) }}</dd>
                    </div>
                    <div v-if="pagamento.cancelado_em">
                        <dt class="text-muted-foreground">Cancelada em</dt>
                        <dd class="font-medium">{{ formatarDataHora(pagamento.cancelado_em) }}</dd>
                    </div>
                    <div v-if="pagamento.estornado_em">
                        <dt class="text-muted-foreground">Valor devolvido em</dt>
                        <dd class="font-medium">
                            {{ formatarDataHora(pagamento.estornado_em) }}
                            <template v-if="pagamento.valor_estornado_centavos !== null">
                                ({{ formatarValor(pagamento.valor_estornado_centavos, moeda) }})
                            </template>
                        </dd>
                    </div>
                </dl>
            </li>
        </ul>
    </div>
</template>

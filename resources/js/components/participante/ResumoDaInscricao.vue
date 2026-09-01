<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { formatarValor } from '@/lib/formato';
import { varianteDaInscricao } from '@/lib/situacoes';
import type { InscricaoAcompanhada } from '@/types/participante';
import { computed } from 'vue';

/**
 * O retrato da inscricao: onde, quem, quanto e o que a pessoa escolheu fazer.
 *
 * A situacao aparece com o mesmo rotulo que o dominio usa, e a cor vem sempre
 * de token semantico — nunca de cor escrita no componente.
 */
const props = defineProps<{
    inscricao: InscricaoAcompanhada;
}>();

const valor = computed(() => formatarValor(props.inscricao.valor_centavos, props.inscricao.moeda));

/*
 * A cor da situação vem do mapeamento central, e não de um `switch` daqui.
 *
 * Havia três cópias desse mapa no sistema, e elas já discordavam entre si:
 * "aguardando pagamento" era azul nesta tela e cinza na do painel, para a mesma
 * inscrição. Agora as duas leem a mesma linha de `lib/situacoes.ts` — e é ela
 * que diz que esperar pagamento é ATENÇÃO, porque o prazo está correndo.
 */
const variante = computed(() => varianteDaInscricao(props.inscricao.situacao));

const grupo = computed(() => {
    const dados = props.inscricao.grupo_participante;

    if (dados === null) {
        return null;
    }

    // "Batalha (Sede) — Setor Batalha", sem a UF.
    //
    // Ela saiu do rotulo em todo o resto do sistema quando cidade virou setor:
    // existia para desambiguar cidades homonimas de estados diferentes, e os
    // cinco setores sao todos da mesma regiao. Aqui ela tinha ficado para tras,
    // e a tela de acompanhamento dizia "(AL)" enquanto o formulario, ao lado,
    // nao dizia — o mesmo dado escrito de dois jeitos na mesma inscricao.
    return dados.cidade ? `${dados.nome} — ${dados.cidade}` : dados.nome;
});
</script>

<template>
    <Card data-testid="resumo-da-inscricao">
        <CardHeader class="pb-3">
            <h2 class="text-muted-foreground text-base leading-none font-medium tracking-tight">Sua inscrição</h2>

            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="variante" data-testid="situacao-da-inscricao">{{ inscricao.situacao_rotulo }}</Badge>
                <span class="text-muted-foreground text-sm">
                    código <span class="font-mono">{{ inscricao.codigo_publico }}</span>
                </span>
            </div>
        </CardHeader>

        <CardContent class="space-y-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Participante</dt>
                    <dd class="font-medium">{{ inscricao.nome_completo }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Valor da inscrição</dt>
                    <dd class="font-medium" data-testid="valor-da-inscricao">{{ valor }}</dd>
                </div>
                <div v-if="inscricao.evento.nome">
                    <dt class="text-muted-foreground">Evento</dt>
                    <dd class="font-medium">{{ inscricao.evento.nome }}</dd>
                </div>
                <div v-if="grupo">
                    <dt class="text-muted-foreground">Grupo</dt>
                    <dd class="font-medium">{{ grupo }}</dd>
                </div>
            </dl>

            <div v-if="inscricao.atividades.length > 0">
                <h3 class="text-sm font-semibold">O que você escolheu</h3>

                <ul class="mt-2 space-y-2" data-testid="atividades-escolhidas">
                    <li v-for="atividade in inscricao.atividades" :key="atividade.nome" class="border-border rounded-lg border p-3 text-sm">
                        <p class="font-medium">{{ atividade.nome }}</p>
                        <p class="text-muted-foreground">
                            <template v-if="atividade.dia">{{ atividade.dia }} · </template>{{ atividade.horario_rotulo }}
                        </p>
                    </li>
                </ul>
            </div>
        </CardContent>
    </Card>
</template>

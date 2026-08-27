<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { formatarValor } from '@/lib/formato';
import type { AtividadePublica, EventoPublico } from '@/types/evento';
import type { FormularioInscricao } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Terceira etapa: a pessoa confere tudo antes de gravar. Nada e enviado ate
 * aqui — e daqui em diante quem decide e o servidor.
 */
const props = defineProps<{
    evento: EventoPublico;
    resumoPessoal: Array<{ rotulo: string; valor: string }>;
    atividadesPorDia: Array<{ id: number; nome: string; data_rotulo: string; atividades: AtividadePublica[] }>;
    erros: Record<string, string>;
    enviando: boolean;
}>();

const formulario = defineModel<FormularioInscricao>({ required: true });

const emit = defineEmits<{
    (evento: 'editar', passo: 'dados' | 'participacao'): void;
    (evento: 'enviar'): void;
}>();

const aceite = computed<boolean>({
    get: () => formulario.value.aceite_termos,
    set: (valor: boolean) => {
        formulario.value.aceite_termos = valor;
    },
});

const nenhumaAtividade = computed<boolean>(() => props.atividadesPorDia.every((dia) => dia.atividades.length === 0));

const prazoEmPalavras = 'Depois de confirmar, você verá o código Pix e o prazo para pagar.';
</script>

<template>
    <div class="space-y-6">
        <Card>
            <CardHeader class="flex-row items-center justify-between gap-3 space-y-0 pb-3">
                <CardTitle class="text-lg">Seus dados</CardTitle>
                <Button type="button" variant="ghost" class="h-11 text-informacao-texto" @click="emit('editar', 'dados')">Editar</Button>
            </CardHeader>
            <CardContent>
                <dl class="space-y-2 text-sm">
                    <div v-for="linha in resumoPessoal" :key="linha.rotulo" class="flex flex-wrap justify-between gap-x-4">
                        <dt class="text-muted-foreground">{{ linha.rotulo }}</dt>
                        <dd class="font-medium">{{ linha.valor }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="flex-row items-center justify-between gap-3 space-y-0 pb-3">
                <CardTitle class="text-lg">Sua participação</CardTitle>
                <Button type="button" variant="ghost" class="h-11 text-informacao-texto" @click="emit('editar', 'participacao')">Editar</Button>
            </CardHeader>
            <CardContent class="space-y-4">
                <p v-if="nenhumaAtividade" class="text-sm text-muted-foreground">Você não escolheu nenhuma atividade.</p>

                <div v-for="dia in atividadesPorDia" :key="dia.id" class="space-y-1">
                    <template v-if="dia.atividades.length > 0">
                        <h3 class="text-sm font-semibold">{{ dia.nome }} — {{ dia.data_rotulo }}</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="atividade in dia.atividades" :key="atividade.id" class="flex flex-wrap justify-between gap-x-4">
                                <span>{{ atividade.nome }}</span>
                                <span class="text-muted-foreground">{{ atividade.horario_rotulo }}</span>
                            </li>
                        </ul>
                    </template>
                </div>

                <p v-if="erros.atividades" role="alert" class="text-sm font-medium text-destructive">{{ erros.atividades }}</p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-lg">Valor</CardTitle>
            </CardHeader>
            <CardContent class="space-y-1">
                <p class="text-2xl font-semibold">{{ formatarValor(evento.valor_centavos, evento.moeda) }}</p>
                <p class="text-sm text-muted-foreground">{{ prazoEmPalavras }}</p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-lg">Regulamento</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div v-if="evento.regulamento" class="max-h-64 overflow-y-auto rounded-md border border-border p-3 text-sm leading-relaxed">
                    <p class="whitespace-pre-line">{{ evento.regulamento }}</p>
                </div>
                <p v-else class="text-sm text-muted-foreground">O regulamento será divulgado pela organização.</p>

                <!--
                    A caixa de marcar continua com 24 px, que e o tamanho certo
                    para ela ser lida como caixa. O que cresce e a area que
                    responde ao dedo: 44 px de folga em volta da caixa e a
                    frase inteira ao lado, que tambem marca ao ser tocada.
                -->
                <div class="flex items-center gap-1 py-1">
                    <span class="flex size-11 shrink-0 items-center justify-center">
                        <Checkbox
                            id="aceite_termos"
                            v-model="aceite"
                            class="size-6"
                            :aria-describedby="erros.aceite_termos ? 'erro-aceite_termos' : undefined"
                        />
                    </span>
                    <label for="aceite_termos" class="flex min-h-11 cursor-pointer items-center text-sm leading-relaxed">
                        <span>
                            Li e aceito o regulamento do evento
                            <span v-if="evento.versao_termos" class="text-muted-foreground">(versão {{ evento.versao_termos }})</span>.
                        </span>
                    </label>
                </div>

                <p v-if="erros.aceite_termos" id="erro-aceite_termos" role="alert" class="text-sm font-medium text-destructive">
                    {{ erros.aceite_termos }}
                </p>
            </CardContent>
        </Card>

        <Alert v-if="erros.geral" variant="destructive">
            <AlertTitle>Não conseguimos concluir a sua inscrição</AlertTitle>
            <AlertDescription>{{ erros.geral }}</AlertDescription>
        </Alert>

        <Button
            type="button"
            class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90"
            :disabled="enviando"
            @click="emit('enviar')"
        >
            {{ enviando ? 'Enviando…' : 'Confirmar inscrição' }}
        </Button>

        <p v-if="enviando" role="status" aria-live="polite" class="text-center text-sm text-muted-foreground">
            Estamos guardando a sua inscrição. Não feche esta página.
        </p>
    </div>
</template>

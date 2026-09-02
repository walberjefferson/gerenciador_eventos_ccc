<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
    <!--
        A revisao e feita de BLOCOS, e nao de cartoes soltos — o `.rev` do
        prototipo. Cada bloco tem uma faixa de titulo com o "Editar" a direita e,
        embaixo, linhas de rotulo e valor com o rotulo numa coluna de largura
        fixa. E o formato de comprovante: os valores alinham numa coluna so, e a
        pessoa confere descendo os olhos por ela em vez de cacar cada um.
    -->
    <div>
        <!-- .rev -->
        <section class="border-border overflow-hidden rounded-[10px] border">
            <!-- .rev__h — faixa de 13px em caixa alta, com 0.1em -->
            <h3
                class="border-border bg-muted/40 text-muted-foreground flex items-center gap-3 border-b px-4 py-[13px] text-[13px] font-semibold tracking-[0.1em] uppercase"
            >
                Seus dados
                <!-- O prototipo desenha este "Editar" como texto puro, e assim
                     ele mede 37x20 — abaixo dos 44px de alvo de toque. A
                     aparencia foi mantida e a AREA cresceu, com margem negativa
                     para a faixa nao engordar junto. -->
                <button
                    type="button"
                    class="text-acao-texto -my-3 ml-auto inline-flex min-h-11 items-center px-2 text-[13px] font-semibold tracking-normal normal-case"
                    @click="emit('editar', 'dados')"
                >
                    Editar
                </button>
            </h3>

            <!-- .rev__b -->
            <dl class="px-4 pt-[6px] pb-[14px]">
                <!-- .rev__r — rotulo numa coluna de 148px -->
                <div
                    v-for="linha in resumoPessoal"
                    :key="linha.rotulo"
                    class="border-border flex flex-wrap gap-4 border-b py-[9px] text-[15px] last:border-b-0"
                >
                    <dt class="text-muted-foreground basis-[148px]">{{ linha.rotulo }}</dt>
                    <!-- "break-words": e-mail longo nao tem espaco onde quebrar, e no
                         celular ele saia cortado pela borda do bloco. -->
                    <dd class="min-w-0 flex-1 font-medium break-words">{{ linha.valor }}</dd>
                </div>
            </dl>
        </section>

        <section class="border-border mt-[22px] overflow-hidden rounded-[10px] border">
            <h3
                class="border-border bg-muted/40 text-muted-foreground flex items-center gap-3 border-b px-4 py-[13px] text-[13px] font-semibold tracking-[0.1em] uppercase"
            >
                Sua participação
                <button
                    type="button"
                    class="text-acao-texto -my-3 ml-auto inline-flex min-h-11 items-center px-2 text-[13px] font-semibold tracking-normal normal-case"
                    @click="emit('editar', 'participacao')"
                >
                    Editar
                </button>
            </h3>

            <div class="px-4 pt-[6px] pb-[14px]">
                <p v-if="nenhumaAtividade" class="text-muted-foreground py-[9px] text-[15px]">Você não escolheu nenhuma atividade.</p>

                <template v-for="dia in atividadesPorDia" :key="dia.id">
                    <div
                        v-for="atividade in dia.atividades"
                        :key="atividade.id"
                        class="border-border flex flex-wrap gap-4 border-b py-[9px] text-[15px] last:border-b-0"
                    >
                        <span class="text-muted-foreground basis-[148px]">{{ dia.nome }}</span>
                        <span class="min-w-0 flex-1 font-medium">
                            {{ atividade.nome }}
                            <!-- O separador vem junto do horário: sem hora marcada,
                                 nem o ponto sobra na linha. -->
                            <span v-if="atividade.horario_rotulo" class="text-muted-foreground font-mono text-[13px] tabular-nums"
                                >· {{ atividade.horario_rotulo }}</span
                            >
                        </span>
                    </div>
                </template>

                <p v-if="erros.atividades" role="alert" class="text-destructive py-[9px] text-[13.5px] font-medium">{{ erros.atividades }}</p>
            </div>
        </section>

        <section class="border-border mt-[22px] overflow-hidden rounded-[10px] border">
            <h3 class="border-border bg-muted/40 text-muted-foreground border-b px-4 py-[13px] text-[13px] font-semibold tracking-[0.1em] uppercase">
                Valor
            </h3>

            <div class="px-4 py-[14px]">
                <!-- Bricolage Grotesque, como todo preco desta identidade -->
                <p class="font-titulo text-[24px] font-semibold tracking-[-0.02em] tabular-nums">
                    {{ formatarValor(evento.valor_centavos, evento.moeda) }}
                </p>
                <p class="text-muted-foreground mt-1 text-[13.5px]">{{ prazoEmPalavras }}</p>
            </div>
        </section>

        <section class="border-border mt-[22px] overflow-hidden rounded-[10px] border">
            <h3 class="border-border bg-muted/40 text-muted-foreground border-b px-4 py-[13px] text-[13px] font-semibold tracking-[0.1em] uppercase">
                Regulamento
            </h3>

            <div class="px-4 py-[14px]">
                <div v-if="evento.regulamento" class="border-border max-h-64 overflow-y-auto rounded-[10px] border p-3 text-[15px] leading-relaxed">
                    <p class="whitespace-pre-line">{{ evento.regulamento }}</p>
                </div>
                <p v-else class="text-muted-foreground text-[15px]">O regulamento será divulgado pela organização.</p>

                <!--
                    .check — 12px entre a caixa e a frase, 24px acima.

                    A caixa de marcar continua com 24px, que e o tamanho certo
                    para ela ser lida como caixa. O que cresce e a area que
                    responde ao dedo: 44px de folga em volta dela, e a frase
                    inteira ao lado tambem marca ao ser tocada.
                -->
                <div class="mt-6 flex items-center gap-1">
                    <span class="flex size-11 shrink-0 items-center justify-center">
                        <Checkbox
                            id="aceite_termos"
                            v-model="aceite"
                            class="size-6"
                            :aria-describedby="erros.aceite_termos ? 'erro-aceite_termos' : undefined"
                        />
                    </span>
                    <label for="aceite_termos" class="flex min-h-11 cursor-pointer items-center text-[14.5px] leading-relaxed">
                        <span>
                            Li e aceito o regulamento do evento
                            <span v-if="evento.versao_termos" class="text-muted-foreground">(versão {{ evento.versao_termos }})</span>.
                        </span>
                    </label>
                </div>

                <p v-if="erros.aceite_termos" id="erro-aceite_termos" role="alert" class="text-destructive text-[13.5px] font-medium">
                    {{ erros.aceite_termos }}
                </p>
            </div>
        </section>

        <Alert v-if="erros.geral" variant="destructive" class="mt-[22px]">
            <AlertTitle>Não conseguimos concluir a sua inscrição</AlertTitle>
            <AlertDescription>{{ erros.geral }}</AlertDescription>
        </Alert>

        <!-- .actions — 30px acima, 24px de respiro, linha em cima -->
        <div class="border-border mt-[30px] border-t pt-6">
            <!--
                O botao so liga depois do aceite.

                Antes ele estava sempre ligado, e quem clicava sem marcar a
                caixa era recusado pelo servidor e voltava para esta mesma tela
                — sem sair do lugar e sem entender por que. O caminho continua
                existindo no servidor, que e quem decide de verdade
                (`aceite_termos` => `accepted`); o que mudou e que a tela para
                de convidar para um clique que ja se sabe que nao vai passar.

                A frase abaixo do botao existe por causa do efeito colateral
                disso: botao desligado nao explica por que esta desligado, e
                sem ela a pessoa trocaria uma frustracao por outra. Ela some
                assim que o aceite e marcado.
            -->
            <Button
                type="button"
                class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base"
                :disabled="enviando || !aceite"
                :aria-describedby="!aceite ? 'aviso-aceite-pendente' : undefined"
                @click="emit('enviar')"
            >
                {{ enviando ? 'Enviando…' : 'Confirmar inscrição' }}
            </Button>

            <p v-if="!aceite" id="aviso-aceite-pendente" class="text-muted-foreground mt-3 text-center text-[13.5px]">
                Marque o aceite do regulamento, acima, para confirmar.
            </p>

            <p v-if="enviando" role="status" aria-live="polite" class="text-muted-foreground mt-3 text-center text-[13.5px]">
                Estamos guardando a sua inscrição. Não feche esta página.
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import CartaoDeNumero from '@/components/admin/CartaoDeNumero.vue';
import LeitorDeQrCode from '@/components/admin/LeitorDeQrCode.vue';
import ResultadoDaLeitura from '@/components/admin/ResultadoDaLeitura.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { EventoDaPortaria, ResultadoDaLeitura as Resultado } from '@/types/ingresso';
import type { PresencaDoEvento } from '@/types/painel';
import { router, useForm } from '@inertiajs/vue3';
import { ref, useTemplateRef, watch } from 'vue';

/**
 * O portão, no dia do evento.
 *
 * ## A digitação é o caminho principal, e a câmera é o atalho
 *
 * Parece o contrário do esperado, e é de propósito. A câmera depende de
 * permissão do navegador, de endereço seguro, de luz e de foco — e falha
 * justamente no pior momento, com a fila esperando. O campo de digitação está
 * SEMPRE visível, sempre em foco, e nunca desaparece quando a câmera liga: um
 * portão que só funciona com câmera é um portão que um dia não funciona.
 *
 * ## Uma pergunta por vez
 *
 * A tela mostra o veredito da ÚLTIMA leitura e mais nada. Não há histórico do
 * que já entrou, não há lista de inscritos, não há busca por nome: quem está no
 * portão não alcança nada disso (o papel `portaria` tem uma permissão só), e a
 * tela não finge que alcança.
 *
 * ## Os dois números
 *
 * Presentes e faltantes ficam no alto, e recalculam a cada conferência, porque
 * é a única pergunta que a organização faz durante o evento inteiro: "quanta
 * gente já chegou?".
 */
const props = defineProps<{
    eventos: EventoDaPortaria[];
    evento: EventoDaPortaria | null;
    numeros: PresencaDoEvento | null;
    /** O veredito da última conferência. Vem da sessão: recarregar a tela não o repete. */
    resultado: Resultado | null;
    /** O aviso curto do desfazer. */
    sucesso: string | null;
    pode_desfazer: boolean;
}>();

const eventoSelecionado = ref<number | null>(props.evento?.id ?? null);

const campoDoCodigo = useTemplateRef<InstanceType<typeof Input>>('campoDoCodigo');
const leitor = useTemplateRef<InstanceType<typeof LeitorDeQrCode>>('leitor');

const desfazendo = ref(false);

const formulario = useForm({
    evento_id: props.evento?.id ?? null,
    codigo: '',
});

watch(
    () => props.evento?.id ?? null,
    (novo) => {
        eventoSelecionado.value = novo;
        formulario.evento_id = novo;
    },
);

function trocarEvento(): void {
    if (eventoSelecionado.value === null || eventoSelecionado.value === props.evento?.id) {
        return;
    }

    router.get(route('admin.portaria.index'), { evento: eventoSelecionado.value }, { preserveScroll: true });
}

/**
 * Manda o código para o servidor.
 *
 * `preserveState` mantém a câmera ligada entre uma conferência e a próxima —
 * sem isso o componente seria remontado a cada leitura, a luz da câmera piscaria
 * e o navegador pediria permissão de novo.
 */
function conferir(): void {
    if (formulario.processing || formulario.codigo.trim() === '' || formulario.evento_id === null) {
        return;
    }

    formulario.post(route('admin.portaria.validar'), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            formulario.reset('codigo');

            // Solta a trava de repetição do leitor: apontar duas vezes para o
            // mesmo ingresso — o gesto de quem quer conferir de novo — precisa
            // funcionar.
            leitor.value?.liberarRepeticao();

            // O foco volta ao campo: quem digita confere um atrás do outro.
            campoDoCodigo.value?.$el?.focus?.();
        },
    });
}

/** A câmera leu alguma coisa: é o mesmo caminho da digitação, sem a mão. */
function lidoPelaCamera(codigo: string): void {
    if (formulario.processing) {
        return;
    }

    formulario.codigo = codigo;
    conferir();
}

function desfazer(ingressoId: number): void {
    desfazendo.value = true;

    router.post(
        route('admin.portaria.desfazer', { ingresso: ingressoId }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                desfazendo.value = false;
                leitor.value?.liberarRepeticao();
            },
        },
    );
}
</script>

<template>
    <AdminLayout titulo="Portaria" descricao="Confira o ingresso de quem chega: leia o QR Code com a câmera ou digite o código.">
        <!-- Sem evento nenhum não há portão para abrir; a tela diz isso em vez de mostrar um formulário que não vai a lugar nenhum. -->
        <div v-if="props.eventos.length === 0" class="border-border bg-muted/40 rounded-lg border p-6" data-testid="portaria-sem-evento">
            <h2 class="text-base font-semibold">Nenhum evento para conferir</h2>
            <p class="text-muted-foreground mt-1 text-sm">
                Assim que um evento sair do rascunho, ele aparece aqui e a conferência de ingressos passa a funcionar.
            </p>
        </div>

        <template v-else>
            <div class="flex flex-col gap-2 sm:max-w-md">
                <label for="seletor-de-evento-portaria" class="text-sm font-medium">Evento</label>
                <select
                    id="seletor-de-evento-portaria"
                    v-model.number="eventoSelecionado"
                    class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                    data-testid="portaria-evento"
                    @change="trocarEvento"
                >
                    <option v-for="item in props.eventos" :key="item.id" :value="item.id">{{ item.nome }} — {{ item.periodo }}</option>
                </select>
                <!--
                    O evento escolhido escrito por extenso, e não só dentro do
                    seletor: conferir o ingresso do evento errado é o engano mais
                    fácil de cometer no portão, e o mais difícil de perceber.
                -->
                <p v-if="props.evento" class="text-muted-foreground text-sm" data-testid="portaria-evento-atual">
                    Conferindo entradas de <strong class="text-foreground">{{ props.evento.nome }}</strong> ({{ props.evento.periodo }}).
                </p>
            </div>

            <section v-if="props.numeros" aria-labelledby="titulo-presenca" class="flex flex-col gap-3" data-testid="portaria-numeros">
                <h2 id="titulo-presenca" class="sr-only">Quantas pessoas já entraram</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div data-testid="portaria-presentes">
                        <CartaoDeNumero
                            rotulo="Já entraram"
                            :valor="String(props.numeros.presentes)"
                            tom="sucesso"
                            significado="Ingressos conferidos no portão."
                        />
                    </div>
                    <div data-testid="portaria-faltantes">
                        <CartaoDeNumero
                            rotulo="Ainda faltam"
                            :valor="String(props.numeros.faltantes)"
                            tom="informacao"
                            significado="Inscrições confirmadas que ainda não chegaram."
                        />
                    </div>
                    <div data-testid="portaria-confirmadas">
                        <CartaoDeNumero
                            rotulo="Esperados"
                            :valor="String(props.numeros.confirmadas)"
                            significado="Todo mundo com inscrição confirmada."
                        />
                    </div>
                </div>
            </section>

            <p
                v-if="props.sucesso"
                role="status"
                class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm"
                data-testid="portaria-aviso"
            >
                {{ props.sucesso }}
            </p>

            <ResultadoDaLeitura
                v-if="props.resultado"
                :resultado="props.resultado"
                :pode-desfazer="props.pode_desfazer"
                :desfazendo="desfazendo"
                @desfazer="desfazer"
            />

            <section aria-labelledby="titulo-conferencia" class="flex flex-col gap-4">
                <h2 id="titulo-conferencia" class="text-lg font-semibold">Conferir um ingresso</h2>

                <!--
                    A DIGITAÇÃO VEM PRIMEIRO NO CÓDIGO E NA TELA. Ela é o
                    caminho que funciona sempre: sem câmera, sem permissão, sem
                    luz e sem internet boa.
                -->
                <form class="flex flex-col gap-3 sm:max-w-md" @submit.prevent="conferir">
                    <label for="codigo-do-ingresso" class="text-sm font-medium">Código do ingresso</label>

                    <Input
                        id="codigo-do-ingresso"
                        ref="campoDoCodigo"
                        v-model="formulario.codigo"
                        data-testid="portaria-codigo"
                        name="codigo"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        inputmode="text"
                        placeholder="ABCD-EFGH-JKMN"
                        class="h-12 font-mono text-lg tracking-widest uppercase"
                        :aria-invalid="formulario.errors.codigo ? true : undefined"
                        aria-describedby="ajuda-do-codigo"
                    />

                    <p id="ajuda-do-codigo" class="text-muted-foreground text-xs">
                        Doze caracteres, com ou sem os hífens. O código está impresso no ingresso, embaixo do QR Code.
                    </p>

                    <p v-if="formulario.errors.codigo" class="text-destructive text-sm" data-testid="portaria-erro-codigo">
                        {{ formulario.errors.codigo }}
                    </p>

                    <Button type="submit" size="lg" :disabled="formulario.processing" data-testid="portaria-conferir">
                        {{ formulario.processing ? 'Conferindo…' : 'Conferir' }}
                    </Button>
                </form>

                <!--
                    A câmera é o atalho, e vive embaixo da digitação. Quando ela
                    não estiver disponível, o componente diz por quê e o
                    formulário acima continua inteiro.
                -->
                <LeitorDeQrCode ref="leitor" @lido="lidoPelaCamera" />
            </section>
        </template>
    </AdminLayout>
</template>

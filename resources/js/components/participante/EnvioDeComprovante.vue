<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatarDataHora } from '@/lib/formato';
import type { ComprovanteEnviado, LimitesDoComprovante } from '@/types/pagamento';
import { useForm } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Clock, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * O envio do comprovante de pagamento, na tela do participante.
 *
 * Quatro estados, e o que muda entre eles e o que a pessoa precisa fazer:
 *   nada enviado — o campo de arquivo e a explicacao do que vale;
 *   em conferencia — o que ela mandou, ate quando a conferencia acontece, e a
 *                    possibilidade de trocar o arquivo por outro;
 *   recusado — o motivo escrito por quem conferiu, e o caminho para mandar
 *              outro. O motivo aparece INTEIRO: e o unico texto que explica a
 *              ela o que corrigir;
 *   aceito — o fim da linha por aqui.
 *
 * **Nada disto e situacao de inscricao.** O comprovante conta o que aconteceu
 * com o arquivo; quem diz se a inscricao esta confirmada e a tela em volta, que
 * le a situacao do dominio.
 *
 * Acessibilidade: o campo tem rotulo visivel, a explicacao de formatos e
 * tamanho esta ligada a ele por `aria-describedby`, e o erro — do servidor ou
 * do proprio navegador — e anunciado por uma regiao viva, e nao apenas pintado
 * de vermelho.
 */
const props = defineProps<{
    url: string;
    comprovante: ComprovanteEnviado | null;
    limites: LimitesDoComprovante;
    /** Ate quando a conferencia precisa acontecer: o prazo da inscricao. */
    prazo: string | null;
}>();

/** Os tipos que o campo sugere ao seletor do sistema. */
const TIPOS_ACEITOS = 'image/jpeg,image/png,image/webp,application/pdf';

const formulario = useForm<{ comprovante: File | null }>({ comprovante: null });

const campo = ref<HTMLInputElement | null>(null);
const erroLocal = ref('');
const nomeEscolhido = ref('');

const situacao = computed(() => props.comprovante?.situacao ?? null);
const emConferencia = computed(() => situacao.value === 'enviado');
const foiAceito = computed(() => situacao.value === 'aceito');
const foiRecusado = computed(() => situacao.value === 'recusado');

/** O aviso que o leitor de tela le: o erro do servidor ou o do navegador. */
const erro = computed(() => erroLocal.value || formulario.errors.comprovante || '');

const explicacao = computed(
    () =>
        `Aceitamos ${props.limites.tipos.join(', ')} com até ${props.limites.tamanho_maximo_mb} MB. ` +
        'Pode ser a foto da tela do comprovante ou o PDF que o aplicativo do banco gera.',
);

const rotuloDoBotao = computed(() => {
    if (formulario.processing) {
        return 'Enviando...';
    }

    return emConferencia.value || foiRecusado.value ? 'Enviar outro comprovante' : 'Enviar comprovante';
});

function escolher(evento: Event): void {
    const arquivo = (evento.target as HTMLInputElement).files?.[0] ?? null;

    erroLocal.value = '';
    nomeEscolhido.value = arquivo?.name ?? '';
    formulario.comprovante = arquivo;

    if (arquivo && arquivo.size > props.limites.tamanho_maximo_mb * 1024 * 1024) {
        // A conferencia de verdade e a do servidor; esta existe so para a
        // pessoa nao esperar o envio de um arquivo que ja se sabe grande demais.
        erroLocal.value = `Este arquivo tem mais de ${props.limites.tamanho_maximo_mb} MB. Envie uma foto menor ou o PDF do comprovante.`;
    }
}

function enviar(): void {
    if (!formulario.comprovante) {
        erroLocal.value = 'Escolha o arquivo do comprovante antes de enviar.';
        campo.value?.focus();

        return;
    }

    if (erroLocal.value !== '') {
        return;
    }

    formulario.post(props.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            formulario.reset();
            nomeEscolhido.value = '';

            if (campo.value) {
                campo.value.value = '';
            }
        },
    });
}
</script>

<template>
    <section class="space-y-4" data-testid="envio-de-comprovante" aria-labelledby="titulo-do-comprovante">
        <h2 id="titulo-do-comprovante" class="text-base font-semibold">Comprovante do pagamento</h2>

        <!-- ESTADO: aceito. Nao ha mais nada a fazer por aqui. -->
        <Alert v-if="foiAceito" variant="sucesso" data-testid="comprovante-aceito">
            <CheckCircle2 aria-hidden="true" />
            <AlertTitle>Comprovante aceito</AlertTitle>
            <AlertDescription>
                O responsável pelo seu setor conferiu o pagamento<template v-if="comprovante?.conferido_em">
                    em {{ formatarDataHora(comprovante.conferido_em) }} </template
                >. Sua inscrição está confirmada.
            </AlertDescription>
        </Alert>

        <template v-else>
            <!-- ESTADO: em conferência. Inclui, com todas as letras, até quando
                 a conferência precisa acontecer — porque o prazo da inscrição
                 continua correndo enquanto o comprovante espera. -->
            <Alert v-if="emConferencia" variant="informacao" data-testid="comprovante-em-conferencia">
                <Clock aria-hidden="true" />
                <AlertTitle>Comprovante enviado, em conferência</AlertTitle>
                <AlertDescription>
                    Recebemos <strong>{{ comprovante?.nome_original }}</strong
                    ><template v-if="comprovante?.enviado_em"> em {{ formatarDataHora(comprovante.enviado_em) }}</template
                    >. O responsável pelo seu setor precisa conferir
                    <template v-if="prazo"
                        >até <strong>{{ formatarDataHora(prazo) }}</strong></template
                    ><template v-else>em breve</template>: até lá, sua vaga continua guardada. Se ninguém conferir até esse prazo, a vaga volta para a
                    fila — se estiver perto de vencer, procure a organização pelo contato no rodapé.
                </AlertDescription>
            </Alert>

            <!-- ESTADO: recusado. O motivo aparece inteiro: é o que ela precisa
                 para saber o que corrigir. -->
            <Alert v-if="foiRecusado" variant="atencao" data-testid="comprovante-recusado">
                <CircleAlert aria-hidden="true" />
                <AlertTitle>Comprovante não aceito</AlertTitle>
                <AlertDescription>
                    <span class="block">{{ comprovante?.motivo_recusa }}</span>
                    <span class="mt-1 block">Você pode enviar outro comprovante aqui embaixo. Sua vaga continua guardada até o prazo.</span>
                </AlertDescription>
            </Alert>

            <div class="space-y-3">
                <label class="block text-[14.5px] font-medium" for="arquivo-do-comprovante">
                    {{ emConferencia || foiRecusado ? 'Escolher outro arquivo' : 'Arquivo do comprovante' }}
                </label>

                <p id="explicacao-do-comprovante" class="text-muted-foreground text-sm">{{ explicacao }}</p>

                <input
                    id="arquivo-do-comprovante"
                    ref="campo"
                    type="file"
                    name="comprovante"
                    :accept="TIPOS_ACEITOS"
                    aria-describedby="explicacao-do-comprovante"
                    :aria-invalid="erro !== '' ? 'true' : undefined"
                    :aria-errormessage="erro !== '' ? 'erro-do-comprovante' : undefined"
                    data-testid="campo-do-comprovante"
                    class="border-input bg-background file:bg-muted file:text-foreground focus-visible:ring-ring focus-visible:ring-offset-background block w-full cursor-pointer rounded-[10px] border-[1.5px] text-sm file:mr-3 file:cursor-pointer file:border-0 file:px-4 file:py-3 file:text-sm file:font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                    @change="escolher"
                />

                <p v-if="nomeEscolhido" class="text-muted-foreground text-sm" data-testid="arquivo-escolhido">
                    Selecionado: <span class="font-medium">{{ nomeEscolhido }}</span>
                </p>

                <!-- O erro é lido por leitor de tela, e não só pintado. -->
                <p id="erro-do-comprovante" role="alert" aria-live="assertive" class="text-destructive text-sm" data-testid="erro-do-comprovante">
                    {{ erro }}
                </p>

                <Button
                    type="button"
                    class="bg-acao text-acao-foreground hover:bg-acao/90 h-12 w-full text-base"
                    :disabled="formulario.processing"
                    data-testid="botao-enviar-comprovante"
                    @click="enviar"
                >
                    <Upload aria-hidden="true" />
                    {{ rotuloDoBotao }}
                </Button>

                <p class="text-muted-foreground text-sm">
                    Enviar o comprovante <strong>não confirma</strong> a inscrição sozinho: uma pessoa do seu setor precisa conferir. Você vê o
                    resultado nesta mesma página.
                </p>
            </div>
        </template>
    </section>
</template>

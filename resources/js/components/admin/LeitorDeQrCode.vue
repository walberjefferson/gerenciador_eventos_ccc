<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Camera, CameraOff } from 'lucide-vue-next';
import { onBeforeUnmount, ref, shallowRef } from 'vue';

/**
 * A câmera lendo o QR Code do ingresso.
 *
 * ## Por que NÃO usamos a biblioteca mais popular
 *
 * A escolha óbvia seria o `qr-scanner` da Nimiq: é a mais usada, e a mais
 * simples de ligar. Ela decodifica dentro de um *worker* criado a partir de uma
 * URL `blob:` — e a Content-Security-Policy deste projeto tem
 * `default-src 'self'` e **não tem** `worker-src`. Usá-la obrigaria a
 * acrescentar `worker-src 'self' blob:` à política do sistema inteiro, ou seja,
 * pagar com a segurança de todas as telas a conveniência de uma. A política não
 * se afrouxa por causa desta tela.
 *
 * ## Os dois caminhos, nesta ordem
 *
 * 1. **`BarcodeDetector` nativo**, quando o navegador tem (Chrome e Android).
 *    É o decodificador do próprio sistema: zero JavaScript baixado, mais rápido
 *    e mais tolerante a foco ruim — que é o normal de um celular apontado para
 *    a tela de outro celular, no sol.
 * 2. **`jsQR`**, para todo o resto (Safari, Firefox). Ele decodifica no próprio
 *    thread da página, a partir de um quadro copiado para um `<canvas>`: sem
 *    worker, sem `blob:`, sem exceção na política.
 *
 * A leitura roda com `setTimeout` a cada ~200 ms, e não a cada quadro: sem
 * worker, decodificar 60 vezes por segundo no thread da interface travaria a
 * própria tela — e ninguém precisa de 60 leituras por segundo do mesmo papel.
 *
 * ## A câmera pode simplesmente não existir
 *
 * Sem permissão, sem câmera, ou fora de HTTPS (o `getUserMedia` não funciona em
 * origem insegura, e isso é do navegador, não deste código), este componente
 * avisa por que falhou e **some do caminho**. Quem decide o que fazer com isso
 * é a tela da portaria, onde a digitação está sempre visível: uma tela morta no
 * portão, com a fila esperando, é o pior desfecho possível.
 */

const emit = defineEmits<{
    /** Um QR foi lido. O conteúdo vai cru; quem normaliza é o servidor. */
    (evento: 'lido', codigo: string): void;
    /** A câmera não vai funcionar aqui, e este é o motivo em português. */
    (evento: 'indisponivel', motivo: string): void;
}>();

/**
 * O decodificador nativo do navegador. Não está nas tipagens do TypeScript
 * porque ainda não é padrão em todos eles — daí a declaração mínima, com só o
 * que de fato usamos.
 */
interface DetectorNativo {
    detect(fonte: CanvasImageSource): Promise<{ rawValue: string }[]>;
}

type ConstrutorDeDetector = new (opcoes: { formats: string[] }) => DetectorNativo;

interface JanelaComDetector extends Window {
    BarcodeDetector?: ConstrutorDeDetector & { getSupportedFormats?: () => Promise<string[]> };
}

/** Entre uma leitura e a próxima. Ver o comentário do topo. */
const INTERVALO_MS = 200;

const video = ref<HTMLVideoElement | null>(null);
const ligada = ref(false);
const iniciando = ref(false);
const erro = ref<string | null>(null);
const usandoNativo = ref(false);

const fluxo = shallowRef<MediaStream | null>(null);
const detector = shallowRef<DetectorNativo | null>(null);
const tela = shallowRef<HTMLCanvasElement | null>(null);
const relogio = shallowRef<number | null>(null);

/** O último conteúdo lido, para não enviar dez vezes o mesmo papel parado. */
const ultimoLido = ref<string | null>(null);

/**
 * Traduz a recusa do navegador para uma frase que alguém no portão entenda.
 *
 * O nome do erro é o que o padrão define; a frase é nossa. "NotAllowedError" na
 * tela não ajuda ninguém a resolver nada.
 */
function motivoLegivel(falha: unknown): string {
    const nome = falha instanceof DOMException ? falha.name : '';

    if (nome === 'NotAllowedError' || nome === 'SecurityError') {
        return 'O navegador não liberou a câmera. Autorize o acesso nas permissões do site — ou digite o código, que funciona do mesmo jeito.';
    }

    if (nome === 'NotFoundError' || nome === 'OverconstrainedError') {
        return 'Este aparelho não tem câmera disponível. Digite o código do ingresso.';
    }

    if (nome === 'NotReadableError') {
        return 'A câmera está ocupada por outro aplicativo. Feche-o e tente de novo, ou digite o código.';
    }

    return 'Não consegui ligar a câmera neste aparelho. Digite o código do ingresso.';
}

async function ligar(): Promise<void> {
    if (ligada.value || iniciando.value) {
        return;
    }

    erro.value = null;
    iniciando.value = true;

    // Fora de HTTPS o navegador nem oferece o objeto. Dizer isso antes de
    // tentar evita um erro genérico que não explica nada.
    if (navigator.mediaDevices?.getUserMedia === undefined) {
        const motivo = 'A câmera só funciona em endereços seguros (https). Digite o código do ingresso.';

        erro.value = motivo;
        iniciando.value = false;
        emit('indisponivel', motivo);

        return;
    }

    try {
        // "environment" é a câmera de trás, que é a que aponta para o ingresso
        // de quem está na fila. Sem isso, o celular abre a de selfie.
        fluxo.value = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
            audio: false,
        });
    } catch (falha) {
        const motivo = motivoLegivel(falha);

        erro.value = motivo;
        iniciando.value = false;
        emit('indisponivel', motivo);

        return;
    }

    ligada.value = true;
    iniciando.value = false;

    // O `<video>` recebe o fluxo por `srcObject`, e nunca por uma URL `blob:`.
    // Não é detalhe: `blob:` em `src` de mídia esbarraria na mesma política que
    // fez o `qr-scanner` ser descartado.
    await new Promise((resolve) => setTimeout(resolve, 0));

    if (video.value !== null && fluxo.value !== null) {
        video.value.srcObject = fluxo.value;
        await video.value.play().catch(() => undefined);
    }

    await prepararDecodificador();

    procurar();
}

async function prepararDecodificador(): Promise<void> {
    const janela = window as JanelaComDetector;

    if (typeof janela.BarcodeDetector === 'function') {
        try {
            detector.value = new janela.BarcodeDetector({ formats: ['qr_code'] });
            usandoNativo.value = true;

            return;
        } catch {
            // Existe mas não aceita QR: cai no jsQR, sem alarde.
            detector.value = null;
        }
    }

    usandoNativo.value = false;
}

/** Um quadro do vídeo copiado para o canvas, do tamanho real da imagem. */
function quadro(): { canvas: HTMLCanvasElement; contexto: CanvasRenderingContext2D } | null {
    const elemento = video.value;

    if (elemento === null || elemento.videoWidth === 0) {
        return null;
    }

    tela.value ??= document.createElement('canvas');

    const canvas = tela.value;
    canvas.width = elemento.videoWidth;
    canvas.height = elemento.videoHeight;

    // `willReadFrequently` é o que impede o navegador de mandar este canvas
    // para a placa de vídeo: como lemos os pixels a cada leitura, mantê-lo na
    // memória comum é bem mais rápido.
    const contexto = canvas.getContext('2d', { willReadFrequently: true });

    if (contexto === null) {
        return null;
    }

    contexto.drawImage(elemento, 0, 0, canvas.width, canvas.height);

    return { canvas, contexto };
}

async function procurar(): Promise<void> {
    if (!ligada.value) {
        return;
    }

    const atual = quadro();

    if (atual !== null) {
        try {
            const conteudo = detector.value !== null ? await lerComNativo(atual.canvas) : await lerComJsQr(atual);

            if (conteudo !== null && conteudo !== ultimoLido.value) {
                ultimoLido.value = conteudo;
                emit('lido', conteudo);
            }
        } catch {
            // Quadro ruim acontece o tempo todo (mão tremendo, foco indo e
            // voltando). Não é erro: é o próximo quadro que resolve.
        }
    }

    relogio.value = window.setTimeout(() => void procurar(), INTERVALO_MS);
}

async function lerComNativo(canvas: HTMLCanvasElement): Promise<string | null> {
    const achados = await detector.value!.detect(canvas);

    return achados[0]?.rawValue ?? null;
}

async function lerComJsQr({ canvas, contexto }: { canvas: HTMLCanvasElement; contexto: CanvasRenderingContext2D }): Promise<string | null> {
    // Carregado sob demanda: quem nunca liga a câmera — e quem usa o
    // decodificador nativo — não baixa a biblioteca.
    const { default: jsQR } = await import('jsqr');

    const imagem = contexto.getImageData(0, 0, canvas.width, canvas.height);

    // "attemptBoth" lê tanto o QR escuro sobre claro quanto o invertido: papel
    // impresso e tela de celular no escuro se comportam de jeitos diferentes.
    return jsQR(imagem.data, imagem.width, imagem.height, { inversionAttempts: 'attemptBoth' })?.data ?? null;
}

function desligar(): void {
    ligada.value = false;
    ultimoLido.value = null;

    if (relogio.value !== null) {
        window.clearTimeout(relogio.value);
        relogio.value = null;
    }

    // Cada faixa do fluxo precisa ser parada explicitamente: sem isso a luz da
    // câmera fica acesa e o aparelho esquenta até a página ser fechada.
    fluxo.value?.getTracks().forEach((faixa) => faixa.stop());
    fluxo.value = null;

    if (video.value !== null) {
        video.value.srcObject = null;
    }
}

/**
 * Solta a leitura anterior para que o MESMO ingresso possa ser lido de novo.
 *
 * Existe porque a tela precisa disso depois de cada resposta do servidor: sem
 * soltar, apontar duas vezes para o mesmo papel — que é exatamente o gesto de
 * quem quer conferir de novo — não faria nada.
 */
function liberarRepeticao(): void {
    ultimoLido.value = null;
}

defineExpose({ liberarRepeticao, desligar });

onBeforeUnmount(desligar);
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="leitor-de-qrcode">
        <div class="flex flex-wrap items-center gap-2">
            <Button v-if="!ligada" type="button" variant="outline" :disabled="iniciando" @click="ligar">
                <Camera aria-hidden="true" class="size-4" />
                {{ iniciando ? 'Ligando a câmera…' : 'Ler com a câmera' }}
            </Button>

            <Button v-else type="button" variant="outline" data-testid="desligar-camera" @click="desligar">
                <CameraOff aria-hidden="true" class="size-4" />
                Desligar a câmera
            </Button>
        </div>

        <!--
            A recusa da câmera é informação, não defeito: a tela continua
            inteira, e a digitação logo abaixo continua sendo o caminho.
        -->
        <p v-if="erro" role="status" class="border-atencao bg-atencao-suave text-atencao-suave-foreground rounded-md border px-3 py-2 text-sm">
            {{ erro }}
        </p>

        <div v-show="ligada" class="border-border overflow-hidden rounded-lg border">
            <!--
                `playsinline` é obrigatório no iPhone: sem ele o Safari abre o
                vídeo em tela cheia e a pessoa perde a tela da portaria de vista.
                `muted` porque não há som nenhum a reproduzir.
            -->
            <video ref="video" class="aspect-square w-full bg-black object-cover" autoplay muted playsinline aria-label="Imagem da câmera"></video>
        </div>

        <p v-if="ligada" class="text-muted-foreground text-xs">
            Aponte para o QR Code do ingresso.
            <span v-if="usandoNativo">Leitura pelo próprio navegador.</span>
            <span v-else>Leitura pela biblioteca da página.</span>
        </p>
    </div>
</template>

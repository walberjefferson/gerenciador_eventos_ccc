<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { useClipboard } from '@vueuse/core';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref, toRef } from 'vue';

/**
 * O codigo "copia e cola" do Pix, com um toque para copiar.
 *
 * Quem esta com o celular na mao nao vai digitar 150 caracteres: o botao e
 * grande, fica logo abaixo do codigo e confirma em dois lugares — muda para
 * "Código copiado" e ainda anuncia por um aviso rapido, que o leitor de tela
 * tambem le.
 */
const props = defineProps<{
    codigo: string;
}>();

const { copy, copied, isSupported } = useClipboard({
    source: toRef(props, 'codigo'),
    legacy: true,
    copiedDuring: 4000,
});

const falhou = ref(false);
const campo = ref<HTMLTextAreaElement | null>(null);

const rotuloDoBotao = computed(() => (copied.value ? 'Código copiado' : 'Copiar código Pix'));

async function copiar(): Promise<void> {
    falhou.value = false;

    try {
        await copy(props.codigo);

        if (!copied.value) {
            throw new Error('nao copiou');
        }

        toast({
            titulo: 'Código Pix copiado',
            descricao: 'Agora abra o aplicativo do seu banco e cole o código na área do Pix.',
            tom: 'sucesso',
        });
    } catch {
        falhou.value = true;
        // Sem area de transferencia, o caminho ainda existe: o texto fica
        // selecionado para a pessoa copiar do jeito de sempre.
        campo.value?.select();

        toast({
            titulo: 'Não consegui copiar por aqui',
            descricao: 'O código já está selecionado: use o "copiar" do seu aparelho.',
            tom: 'erro',
        });
    }
}
</script>

<template>
    <div class="space-y-3">
        <label class="block text-sm font-medium" for="codigo-pix">Código Pix (copia e cola)</label>

        <textarea
            id="codigo-pix"
            ref="campo"
            :value="codigo"
            readonly
            rows="5"
            data-testid="codigo-copia-e-cola"
            class="w-full resize-none break-all rounded-md border border-input bg-muted px-3 py-2 font-mono text-xs leading-5 text-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
            @focus="campo?.select()"
        />

        <Button
            type="button"
            data-testid="botao-copiar-pix"
            class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90"
            @click="copiar"
        >
            <Check v-if="copied" aria-hidden="true" />
            <Copy v-else aria-hidden="true" />
            {{ rotuloDoBotao }}
        </Button>

        <p aria-live="polite" class="min-h-5 text-sm">
            <span v-if="copied" class="font-medium text-sucesso-texto">Código copiado. Cole no aplicativo do seu banco.</span>
            <span v-else-if="falhou || !isSupported" class="text-muted-foreground">
                Se o botão não funcionar no seu aparelho, toque no código acima e copie manualmente.
            </span>
        </p>
    </div>
</template>

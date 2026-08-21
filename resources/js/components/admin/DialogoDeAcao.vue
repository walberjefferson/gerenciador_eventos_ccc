<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { computed } from 'vue';

/**
 * O diálogo que pede a justificativa antes de uma ação administrativa.
 *
 * Serve para as duas ações que mexem em vaga e em dinheiro: cancelar uma
 * inscrição e reconhecer um pagamento recebido por fora. Em ambas o texto é
 * obrigatório — ação administrativa sem justificativa é rastro que não explica
 * nada, e daqui a algumas fases esses textos viram o registro de auditoria.
 *
 * O diálogo prende o foco enquanto está aberto, fecha com Esc e devolve o foco
 * ao botão que o abriu: isso vem do componente de diálogo do projeto, que já
 * cuida disso.
 */
const props = defineProps<{
    aberto: boolean;
    titulo: string;
    descricao: string;
    aviso?: string;
    rotuloDoCampo: string;
    textoDeAjuda?: string;
    textoDoBotao: string;
    texto: string;
    erro?: string;
    processando: boolean;
}>();

const emit = defineEmits<{
    (evento: 'update:aberto', valor: boolean): void;
    (evento: 'update:texto', valor: string): void;
    (evento: 'confirmar'): void;
}>();

const idDoCampo = computed(() => `campo-${props.titulo.toLowerCase().replace(/\s+/g, '-')}`);

const descritoPor = computed(() => {
    const partes = [`${idDoCampo.value}-ajuda`];

    if (props.erro) {
        partes.push(`${idDoCampo.value}-erro`);
    }

    return partes.join(' ');
});
</script>

<template>
    <Dialog :open="props.aberto" @update:open="(valor: boolean) => emit('update:aberto', valor)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ props.titulo }}</DialogTitle>
                <DialogDescription>{{ props.descricao }}</DialogDescription>
            </DialogHeader>

            <p v-if="props.aviso" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {{ props.aviso }}
            </p>

            <form class="grid gap-4" @submit.prevent="emit('confirmar')">
                <slot name="campos" />

                <div class="flex flex-col gap-1">
                    <label :for="idDoCampo" class="text-sm font-medium">{{ props.rotuloDoCampo }}</label>
                    <textarea
                        :id="idDoCampo"
                        :value="props.texto"
                        rows="3"
                        required
                        :aria-describedby="descritoPor"
                        :aria-invalid="props.erro ? true : undefined"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @input="emit('update:texto', ($event.target as HTMLTextAreaElement).value)"
                    ></textarea>
                    <p :id="`${idDoCampo}-ajuda`" class="text-sm text-muted-foreground">
                        {{ props.textoDeAjuda ?? 'Fica registrado na inscrição. Escreva o suficiente para outra pessoa entender depois.' }}
                    </p>
                    <p v-if="props.erro" :id="`${idDoCampo}-erro`" role="alert" class="text-sm text-destructive">{{ props.erro }}</p>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        class="h-10 rounded-md border border-border px-4 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="emit('update:aberto', false)"
                    >
                        Voltar
                    </button>
                    <button
                        type="submit"
                        :disabled="props.processando"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        {{ props.textoDoBotao }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

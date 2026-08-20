<script setup lang="ts">
import { ToastProvider } from 'radix-vue';
import Toast from './Toast.vue';
import ToastClose from './ToastClose.vue';
import ToastDescription from './ToastDescription.vue';
import ToastTitle from './ToastTitle.vue';
import ToastViewport from './ToastViewport.vue';
import { useToast } from './use-toast';

const { avisos, dispensarAviso } = useToast();

const bordaDoTom: Record<string, string> = {
    padrao: 'border-border',
    sucesso: 'border-sucesso',
    erro: 'border-destructive',
};
</script>

<template>
    <ToastProvider>
        <Toast
            v-for="aviso in avisos"
            :key="aviso.id"
            :duration="aviso.duracao ?? 4000"
            :class="bordaDoTom[aviso.tom ?? 'padrao']"
            @update:open="(aberto: boolean) => !aberto && dispensarAviso(aviso.id)"
        >
            <div class="grid gap-1">
                <ToastTitle>{{ aviso.titulo }}</ToastTitle>
                <ToastDescription v-if="aviso.descricao">{{ aviso.descricao }}</ToastDescription>
            </div>
            <ToastClose />
        </Toast>

        <ToastViewport />
    </ToastProvider>
</template>

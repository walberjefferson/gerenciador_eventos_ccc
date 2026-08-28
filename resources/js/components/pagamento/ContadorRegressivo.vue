<script setup lang="ts">
import { useContadorRegressivo } from '@/composables/useContadorRegressivo';
import { formatarDataHora } from '@/lib/formato';
import { Clock } from 'lucide-vue-next';
import { computed, watch } from 'vue';

/**
 * Quanto tempo ainda ha para pagar.
 *
 * Escrito em portugues comum ("Faltam 2 horas e 15 minutos"), nunca em numero
 * negativo: quando o prazo termina, a frase muda e a tela avisa quem a usa
 * para conferir a situacao com o servidor.
 *
 * O amarelo entra so na ultima hora — e sempre com texto preto por cima, que
 * e a unica combinacao que passa em contraste.
 */
const props = defineProps<{
    prazo: string | null;
}>();

const emit = defineEmits<{
    (evento: 'expirou'): void;
}>();

const { rotulo, expirado, proximoDoFim } = useContadorRegressivo(() => props.prazo);

const prazoPorExtenso = computed(() => (props.prazo ? formatarDataHora(props.prazo) : null));

watch(expirado, (acabou) => {
    if (acabou) {
        emit('expirou');
    }
});
</script>

<template>
    <div
        v-if="prazo"
        class="flex items-start gap-3 rounded-full px-[13px] py-[7px] text-[13.5px] font-semibold"
        :class="proximoDoFim ? 'bg-atencao text-atencao-foreground' : 'bg-atencao-suave text-atencao-suave-foreground'"
        data-testid="contador-regressivo"
    >
        <Clock class="mt-[2px] size-4 shrink-0" aria-hidden="true" />

        <div class="min-w-0">
            <p class="font-semibold" aria-live="polite">
                <span v-if="expirado">O prazo para pagar terminou</span>
                <span v-else>{{ rotulo }} para pagar</span>
            </p>

            <p class="text-[13px] font-normal opacity-90">
                <span v-if="expirado">O prazo era até {{ prazoPorExtenso }}.</span>
                <span v-else>Você tem até {{ prazoPorExtenso }} para fazer o pagamento.</span>
            </p>
        </div>
    </div>
</template>

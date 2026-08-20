import { useIntervalFn } from '@vueuse/core';
import { computed, ref, unref, type ComputedRef, type MaybeRefOrGetter, type Ref } from 'vue';

/**
 * Conta o tempo que falta ate um prazo e escreve isso em portugues comum.
 *
 * Nao decide nada: quem diz se a cobranca venceu e o servidor. O contador
 * serve para a pessoa se organizar — e para a tela pedir a situacao de novo
 * quando o prazo chega ao fim.
 */

export interface ContadorRegressivo {
    /** Milissegundos que faltam. Nunca negativo. */
    restanteMs: Ref<number>;
    /** Verdadeiro quando o prazo ja passou (ou quando nao ha prazo nenhum). */
    expirado: ComputedRef<boolean>;
    /** Verdadeiro na ultima hora: e quando a tela chama atencao. */
    proximoDoFim: ComputedRef<boolean>;
    /** "Faltam 2 horas e 15 minutos" — pronto para ir na tela. */
    rotulo: ComputedRef<string>;
    parar: () => void;
}

const UM_SEGUNDO = 1000;
const UM_MINUTO = 60 * UM_SEGUNDO;
const UMA_HORA = 60 * UM_MINUTO;
const UM_DIA = 24 * UMA_HORA;

/** A partir daqui a tela passa a destacar o contador. */
const PERTO_DO_FIM_MS = UMA_HORA;

function plural(quantidade: number, singular: string, plural_: string): string {
    return `${quantidade} ${quantidade === 1 ? singular : plural_}`;
}

/**
 * Escreve o tempo restante do jeito que se fala: as duas maiores unidades
 * bastam. Ninguem precisa saber que faltam 23 horas, 4 minutos e 12 segundos.
 */
export function escreverTempoRestante(restanteMs: number): string {
    if (restanteMs <= 0) {
        return 'O prazo terminou';
    }

    if (restanteMs >= UM_DIA) {
        const dias = Math.floor(restanteMs / UM_DIA);
        const horas = Math.floor((restanteMs % UM_DIA) / UMA_HORA);

        return horas > 0
            ? `Faltam ${plural(dias, 'dia', 'dias')} e ${plural(horas, 'hora', 'horas')}`
            : `${dias === 1 ? 'Falta' : 'Faltam'} ${plural(dias, 'dia', 'dias')}`;
    }

    if (restanteMs >= UMA_HORA) {
        const horas = Math.floor(restanteMs / UMA_HORA);
        const minutos = Math.floor((restanteMs % UMA_HORA) / UM_MINUTO);

        return minutos > 0
            ? `Faltam ${plural(horas, 'hora', 'horas')} e ${plural(minutos, 'minuto', 'minutos')}`
            : `${horas === 1 ? 'Falta' : 'Faltam'} ${plural(horas, 'hora', 'horas')}`;
    }

    if (restanteMs >= UM_MINUTO) {
        const minutos = Math.floor(restanteMs / UM_MINUTO);
        const segundos = Math.floor((restanteMs % UM_MINUTO) / UM_SEGUNDO);

        return segundos > 0
            ? `Faltam ${plural(minutos, 'minuto', 'minutos')} e ${plural(segundos, 'segundo', 'segundos')}`
            : `${minutos === 1 ? 'Falta' : 'Faltam'} ${plural(minutos, 'minuto', 'minutos')}`;
    }

    const segundos = Math.max(1, Math.ceil(restanteMs / UM_SEGUNDO));

    return `${segundos === 1 ? 'Falta' : 'Faltam'} ${plural(segundos, 'segundo', 'segundos')}`;
}

export function useContadorRegressivo(prazo: MaybeRefOrGetter<string | null | undefined>): ContadorRegressivo {
    const alvo = computed<number | null>(() => {
        const valor = typeof prazo === 'function' ? prazo() : unref(prazo);

        if (!valor) {
            return null;
        }

        const instante = new Date(valor).getTime();

        return Number.isNaN(instante) ? null : instante;
    });

    const calcular = (): number => {
        if (alvo.value === null) {
            return 0;
        }

        return Math.max(0, alvo.value - Date.now());
    };

    const restanteMs = ref<number>(calcular());

    const { pause } = useIntervalFn(
        () => {
            restanteMs.value = calcular();
        },
        UM_SEGUNDO,
        { immediateCallback: true },
    );

    return {
        restanteMs,
        expirado: computed(() => restanteMs.value <= 0),
        proximoDoFim: computed(() => restanteMs.value > 0 && restanteMs.value <= PERTO_DO_FIM_MS),
        rotulo: computed(() => escreverTempoRestante(restanteMs.value)),
        parar: pause,
    };
}

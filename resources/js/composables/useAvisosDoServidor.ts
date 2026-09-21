import { toast } from '@/components/ui/toast';
import type { AvisoDoServidor, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Transforma o aviso que o servidor deixou na resposta em aviso rapido (toast).
 *
 * Quem confirma uma acao no painel — cancelar uma inscricao, reconhecer um
 * pagamento, salvar um cadastro — precisa de resposta onde estava olhando, e
 * nao no alto de uma pagina que talvez nem esteja a vista. O paragrafo dentro
 * do conteudo continua existindo para quem voltar a tela depois; este aviso e
 * o que aparece na hora.
 *
 * Duas decisoes que parecem detalhe e nao sao:
 *
 * 1. **A vigia e sobre o identificador, nao sobre o texto.** Cancelar duas
 *    inscricoes seguidas produz a mesma frase; uma vigia sobre o texto nao
 *    veria diferenca na segunda vez e o aviso nao apareceria. O servidor manda
 *    um identificador novo a cada resposta justamente para isso.
 *
 * 2. **Erro de campo NAO passa por aqui.** O que chega em `flash.erro` e a
 *    recusa que nao pertence a campo nenhum: permissao negada, regra de
 *    negocio, falha inesperada. Campo errado continua com a mensagem ao lado
 *    do campo, onde a pessoa vai corrigir.
 */
export function useAvisosDoServidor(): void {
    const pagina = usePage<SharedData>();

    watch(
        // Ler o identificador, e so ele, e o que faz a mesma frase repetida
        // continuar sendo um aviso novo.
        () => pagina.props.flash?.id,
        () => {
            const aviso: AvisoDoServidor | undefined = pagina.props.flash;

            if (aviso === undefined) {
                return;
            }

            if (aviso.sucesso) {
                toast({ titulo: aviso.sucesso, tom: 'sucesso' });
            }

            if (aviso.erro) {
                // A recusa fica mais tempo na tela do que a confirmacao: ela
                // traz uma razao para ler, e nao so um "pronto".
                toast({ titulo: aviso.erro, tom: 'erro', duracao: 8000 });
            }
        },
        // A acao termina em redirecionamento, e o aviso ja chega com a tela
        // nova: sem isto, a vigia so acordaria na acao SEGUINTE.
        { immediate: true },
    );
}

import { test as testeBase } from '@playwright/test';
import { URL_BASE } from './ambiente';

/**
 * O `test` que todos os cenarios usam.
 *
 * Ele corta qualquer pedido para fora do proprio servidor — a fonte hospedada
 * em fonts.bunny.net, por exemplo. Duas razoes: a suite precisa rodar sem
 * internet, e o tempo de uma rede alheia nao pode virar teste intermitente. A
 * tela continua a mesma; so a familia tipografica cai para a do sistema.
 */
export const test = testeBase.extend<{ semRedeExterna: void }>({
    semRedeExterna: [
        async ({ page }, usar) => {
            const anfitriao = new URL(URL_BASE).host;

            await page.route('**/*', async (rota) => {
                const destino = new URL(rota.request().url());
                const interno = destino.host === anfitriao || destino.protocol === 'data:' || destino.protocol === 'blob:';

                await (interno ? rota.continue() : rota.abort());
            });

            await usar();
        },
        { auto: true },
    ],
});

export { expect } from '@playwright/test';

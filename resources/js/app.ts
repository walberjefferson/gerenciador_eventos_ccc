import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Inscrições';

/**
 * Esta tela e do visitante ou de quem administra o evento?
 *
 * A conta e a MESMA que `resources/views/app.blade.php` faz no servidor, e as
 * duas precisam continuar iguais: o Blade acerta a primeira pintura, esta
 * funcao acerta toda navegacao seguinte. Publicas sao as seis telas do
 * visitante — a porta da rua, a vitrine e as quatro de inscricao.
 *
 * `Admin/Inscricoes/Index` NAO e publica: a comparacao e de prefixo, do inicio
 * do nome, e o nome dela comeca com `Admin/`.
 */
function ehTelaPublica(componente: string): boolean {
    return componente === 'Home' || componente.startsWith('Eventos/') || componente.startsWith('Inscricoes/');
}

/**
 * Escreve o tema no `<html>`.
 *
 * Precisa ser no `<html>`, e nao numa div de layout, porque a lista de escolha
 * e os dialogos sao teleportados para o `document.body` (portal do reka-ui).
 * Escopo posto dentro da pagina nao alcancaria nenhum dos dois.
 */
function aplicarTema(componente: string): void {
    document.documentElement.dataset.tema = ehTelaPublica(componente) ? 'publico' : 'admin';
}

/**
 * O Inertia troca so o corpo da pagina: o `<html>` que o servidor mandou fica
 * ali, com o tema da PRIMEIRA tela, para sempre. Sem este ouvinte, sair da
 * porta da rua para o painel manteria a tela verde — e o contrario tambem.
 */
router.on('navigate', (evento) => aplicarTema(evento.detail.page.component));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // A primeira tela ja veio com o atributo certo do servidor; refazer a
        // conta aqui custa nada e cobre o caso de a pagina ter sido servida por
        // uma resposta que nao passou pelo Blade (uma restauracao de historico,
        // por exemplo).
        aplicarTema(props.initialPage.component);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

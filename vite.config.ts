import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import { defineConfig } from 'vite';

export default defineConfig({
    // Escuta em todas as interfaces para funcionar tanto rodando na maquina
    // quanto dentro do conteiner do Sail; o HMR responde sempre por localhost.
    server: {
        host: '0.0.0.0',
        port: Number(process.env.VITE_PORT ?? 5173),
        hmr: { host: 'localhost' },
    },
    plugins: [
        // O Tailwind 4 entra como plugin do Vite: nao ha mais etapa de PostCSS
        // nem autoprefixer, porque a propria v4 gera os prefixos de que precisa.
        tailwindcss(),
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
});

/// <reference types="vite/client" />

/**
 * As variaveis de ambiente que o Vite entrega ao navegador.
 *
 * A declaracao vive aqui, e nao dentro de app.ts, porque "vite/client" e um
 * arquivo de declaracoes globais: tentar aumenta-lo com "declare module" nao
 * funciona. Declarando a interface no escopo global, o TypeScript junta esta
 * definicao com a do proprio Vite.
 */
interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
}

@php
    /*
     * O tema visual desta pagina, escrito no `<html>` para que a PRIMEIRA
     * pintura ja saia certa.
     *
     * Sem isto, o navegador desenharia a tela no tema azul do painel e o
     * JavaScript a corrigiria um quadro depois — a piscada que qualquer pessoa
     * ve e ninguem consegue explicar. E so o Blade tambem nao basta: o Inertia
     * troca apenas o corpo da pagina a cada navegacao e nunca reescreve o
     * `<html>`, entao `resources/js/app.ts` refaz esta mesma conta a cada troca
     * de tela. As duas pontas sao necessarias, e a regra abaixo e a MESMA nas
     * duas — se uma mudar, a outra tem de mudar junto.
     *
     * A regra olha para o nome do componente Inertia, que e o unico dado que
     * existe nos dois lados. Publicas sao as seis telas do visitante: a porta
     * da rua, a vitrine do evento e as quatro de inscricao. `Admin/Inscricoes/…`
     * nao entra: o prefixo comparado e o do inicio do nome.
     */
    $componente = $page['component'] ?? '';

    $ehTelaPublica = $componente === 'Home'
        || str_starts_with($componente, 'Eventos/')
        || str_starts_with($componente, 'Inscricoes/');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-tema="{{ $ehTelaPublica ? 'publico' : 'admin' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Inscrições') }}</title>

        {{-- O favicon e um SVG, e nao um .ico: o `public/favicon.ico` que veio
             do pacote inicial tinha ZERO byte, entao ate aqui nao havia icone
             nenhum na aba. SVG escala para qualquer tamanho num arquivo so e e
             lido por todo navegador atual; o `.ico` vazio foi removido para
             ninguem achar que ele ainda serve para alguma coisa. --}}
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/favicon.svg">

        {{-- As tres familias vem todas do fonts.bunny.net, e a razao e a CSP:
             `CabecalhosDeSeguranca` libera essa origem, e so ela, em `style-src`
             e `font-src`. Acrescentar o Google Fonts exigiria afrouxar a
             politica e reescrever o cenario que a prova — e o bunny.net serve
             as tres. A Bricolage Grotesque veste os titulos e a DM Mono, os
             numeros; as duas so sao aplicadas no lado publico. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:400,500,600,700|dm-mono:400,500|instrument-sans:400,500,600" rel="stylesheet" />

        {{-- O nonce vem do middleware CabecalhosDeSeguranca. Sem ele, a CSP
             bloquearia a tabela de rotas do Ziggy, que e escrita na propria
             pagina, e nenhum link do sistema funcionaria. --}}
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

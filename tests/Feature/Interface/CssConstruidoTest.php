<?php

declare(strict_types=1);

/**
 * A vistoria do CSS que o navegador realmente recebe.
 *
 * Existe um tipo de defeito de interface que nao aparece em lugar nenhum: uma
 * classe do Tailwind escrita na sintaxe da versao 3 continua sendo aceita pela
 * versao 4, so que o valor gerado deixa de fazer sentido. `w-[--sidebar-width]`
 * virava a regra `width:--sidebar-width` — um nome de variavel solto, que o
 * navegador simplesmente joga fora. Nenhum erro no terminal, nenhum aviso na
 * tela: a largura some, e o que sobra e alguem reclamando que "a barra lateral
 * ficou por cima do conteudo".
 *
 * Foi exatamente isso que aconteceu com a barra lateral administrativa
 * (decisao D-86). Este arquivo e a rede que impede o retorno: ele le o CSS que
 * o `npm run build` produziu e recusa qualquer declaracao cujo valor seja um
 * nome cru de variavel.
 *
 * Ele mora no PHPUnit, e nao num script solto, por um motivo pratico: o
 * `.github/workflows/tests.yml` roda `npm run build` **antes** do PHPUnit, e
 * esse e o unico momento em que o CSS construido existe e alguem olha para
 * ele. Script que ninguem chama nao e rede de protecao.
 */
/** A raiz do projeto, calculada do proprio arquivo: este teste roda antes de a aplicacao subir. */
$raiz = dirname(__DIR__, 3);
$manifesto = $raiz.'/public/build/manifest.json';

/**
 * Os arquivos .css que a construcao mais recente declarou.
 *
 * A fonte e o manifesto, e nao um curinga em `public/build/assets/*.css`, de
 * proposito: sobra de uma construcao antiga ficaria acusando defeito ja
 * corrigido, ou — pior — escondendo um novo atras de um arquivo que ninguem
 * mais carrega.
 */
function folhasDeEstiloConstruidas(string $manifesto): array
{
    $raiz = dirname($manifesto, 3);

    $entradas = json_decode((string) file_get_contents($manifesto), true, flags: JSON_THROW_ON_ERROR);

    $arquivos = [];

    foreach ($entradas as $entrada) {
        foreach ([...(array) ($entrada['css'] ?? []), $entrada['file'] ?? null] as $caminho) {
            if (is_string($caminho) && str_ends_with($caminho, '.css')) {
                $arquivos[] = $raiz.'/public/build/'.$caminho;
            }
        }
    }

    return array_values(array_unique(array_filter($arquivos, 'is_file')));
}

/**
 * As declaracoes cujo valor e um nome de variavel sem `var(...)` em volta.
 *
 * A propriedade precisa comecar com letra e vir logo depois de `{` ou `;`.
 * Assim ficam de fora as definicoes de variavel (`--cor-base: --outra`), que
 * sao legitimas: uma variavel pode guardar qualquer sequencia de simbolos.
 */
function declaracoesInvalidas(string $arquivo): array
{
    $css = (string) file_get_contents($arquivo);

    preg_match_all(
        '/[{;]\s*([a-z][a-z-]*)\s*:\s*(--[a-zA-Z0-9_-]+)\s*(?=[;}])/',
        $css,
        $achados,
        PREG_OFFSET_CAPTURE
    );

    $problemas = [];

    foreach ($achados[0] as $indice => [, $posicao]) {
        $problemas[] = [
            'propriedade' => $achados[1][$indice][0],
            'valor' => $achados[2][$indice][0],
            'seletor' => seletorAntesDe($css, $posicao),
        ];
    }

    return $problemas;
}

/** O seletor da regra onde a declaracao mora — e por ele que se acha a classe. */
function seletorAntesDe(string $css, int $posicao): string
{
    $trecho = substr($css, max(0, $posicao - 300), min(300, $posicao));
    $inicio = strcspn(strrev($trecho), '}{,');

    return trim(substr($trecho, strlen($trecho) - $inicio));
}

/** A classe do Tailwind que gerou o seletor, sem as barras de escape do CSS. */
function classeDoSeletor(string $seletor): string
{
    return ltrim(str_replace('\\', '', $seletor), '.');
}

test('o CSS construido nao tem nenhuma declaracao com valor de variavel solto', function () use ($manifesto): void {
    if (! is_file($manifesto)) {
        // Em CI isto nunca acontece: o workflow constroi antes de testar.
        $this->markTestSkipped(
            'Nao ha CSS construido para vistoriar. Rode `npm run build` antes do PHPUnit '.
            '(e o CI ja roda: veja o passo "Build Assets" em .github/workflows/tests.yml).'
        );
    }

    $folhas = folhasDeEstiloConstruidas($manifesto);

    expect($folhas)->not->toBeEmpty(
        "O manifesto {$manifesto} nao aponta nenhum arquivo .css existente. ".
        'Ou a construcao falhou pela metade, ou public/build ficou desatualizado: rode `npm run build`.'
    );

    $relatorio = [];

    foreach ($folhas as $folha) {
        foreach (declaracoesInvalidas($folha) as $problema) {
            $classe = classeDoSeletor($problema['seletor']);
            $sugestao = str_replace(['[', ']'], ['(', ')'], $classe);

            $relatorio[] = <<<TEXTO

            Arquivo:     {$folha}
            Seletor:     {$problema['seletor']}
            Declaracao:  {$problema['propriedade']}: {$problema['valor']}

            O navegador descarta essa declaracao: um nome de variavel sozinho nao e
            um valor de CSS. E a sintaxe do Tailwind 3 sobrevivendo no Tailwind 4 —
            o compilador nao reclama, so gera uma regra que nao faz nada.

            Como consertar: procure a classe no codigo-fonte

                grep -rnF '{$classe}' resources/js

            e troque o colchete por parentese: `{$classe}` vira `{$sugestao}`.

            Nao troque colchete em outros lugares: seletor de atributo
            (`data-[state=open]`, `group-data-[collapsible=icon]`) e valor com
            `calc(var(...))` ja estao certos e quebrariam se mudassem.
            TEXTO;
        }
    }

    expect($relatorio)->toBeEmpty(
        count($relatorio).' declaracao(oes) invalida(s) no CSS construido:'.PHP_EOL.implode(PHP_EOL, $relatorio)
    );
});

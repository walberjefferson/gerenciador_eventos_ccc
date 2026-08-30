<?php

declare(strict_types=1);

use App\Models\Evento;
use Tests\Feature\Admin\Cenario;

/**
 * A vistoria do tema do lado publico.
 *
 * Duas coisas podem dar errado nesta identidade, e nenhuma das duas aparece
 * olhando a tela:
 *
 * 1. **Um tom bonito que ninguem enxerga.** A paleta veio de um prototipo, e
 *    prototipo nao mede contraste. Dois tons dele reprovavam. Este arquivo LE o
 *    `resources/css/app.css` e RECALCULA a razao de cada par pela formula da
 *    WCAG 2.1 — ele nao confia no numero escrito no comentario. Se alguem
 *    trocar um hexadecimal e esquecer de refazer a conta, e aqui que aparece.
 *
 * 2. **Um token que ficou para tras.** O tema publico redeclara a paleta
 *    inteira. Token que ele nao redeclare continua valendo o do painel — ou
 *    seja, azul do studio no meio de uma tela verde, num canto que ninguem
 *    abre todo dia. A vistoria de paridade abaixo exige que cada token do
 *    `:root` tenha par no bloco publico, e cada token do `.dark` tenha par no
 *    bloco publico escuro.
 *
 * E, no fim, a prova de que o atributo que liga tudo isso sai certo do
 * servidor — porque e ele que faz a PRIMEIRA pintura nao piscar.
 */
$arquivoDoCss = dirname(__DIR__, 3).'/resources/css/app.css';

/**
 * Os tokens declarados dentro de um bloco do CSS, pelo seletor.
 *
 * A leitura e do arquivo-fonte, e nao do CSS construido, de proposito: e no
 * fonte que mora o comentario com a razao ao lado do valor, e e ele que a
 * proxima pessoa vai editar.
 *
 * @return array<string, string> nome do token => cor em hexadecimal
 */
function tokensDoBloco(string $css, string $seletor): array
{
    $inicio = strpos($css, $seletor.' {');

    if ($inicio === false) {
        return [];
    }

    // O bloco termina na primeira linha que fecha chave no mesmo recuo dele.
    $fim = strpos($css, "\n    }", $inicio);
    $trecho = substr($css, $inicio, ($fim === false ? strlen($css) : $fim) - $inicio);

    preg_match_all('/(--[a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{6})\s*;/', $trecho, $achados, PREG_SET_ORDER);

    $tokens = [];

    foreach ($achados as $achado) {
        $tokens[$achado[1]] = strtoupper($achado[2]);
    }

    return $tokens;
}

/** Todos os nomes de token de um bloco, inclusive os que nao sao cor. */
function nomesDeTokenDoBloco(string $css, string $seletor): array
{
    $inicio = strpos($css, $seletor.' {');

    if ($inicio === false) {
        return [];
    }

    $fim = strpos($css, "\n    }", $inicio);
    $trecho = substr($css, $inicio, ($fim === false ? strlen($css) : $fim) - $inicio);

    preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/m', $trecho, $achados);

    return array_values(array_unique($achados[1]));
}

/** A luminancia relativa de uma cor, pela formula da WCAG 2.1. */
function luminanciaRelativa(string $hexadecimal): float
{
    $numero = (int) hexdec(ltrim($hexadecimal, '#'));

    $canal = static function (int $valor): float {
        $parte = $valor / 255;

        return $parte <= 0.03928 ? $parte / 12.92 : (($parte + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $canal(($numero >> 16) & 255)
        + 0.7152 * $canal(($numero >> 8) & 255)
        + 0.0722 * $canal($numero & 255);
}

/** A razao de contraste entre duas cores. 1:1 e invisivel, 21:1 e preto no branco. */
function razaoDeContraste(string $frente, string $fundo): float
{
    $a = luminanciaRelativa($frente);
    $b = luminanciaRelativa($fundo);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/**
 * Os pares que precisam passar, e o limiar de cada um.
 *
 * Texto comum pede 4.5:1 (WCAG 1.4.3). Contorno de controle — borda de campo,
 * anel de foco — pede 3:1 (WCAG 1.4.11): ele nao e lido, mas precisa ser visto
 * para que alguem saiba onde clicar.
 *
 * Cada linha e "este tom, por cima daquele". `--fundo` e `--cartao` sao
 * apelidos que a funcao troca pelo `--background` e pelo `--card` do bloco.
 *
 * @return array<int, array{0: string, 1: string, 2: float}>
 */
function paresQuePrecisamPassar(): array
{
    $texto = [
        ['--foreground', '--background'],
        ['--card-foreground', '--card'],
        ['--popover-foreground', '--popover'],
        ['--primary-foreground', '--primary'],
        ['--primary', '--background'],
        ['--secondary-foreground', '--secondary'],
        ['--muted-foreground', '--muted'],
        ['--muted-foreground', '--background'],
        ['--muted-foreground', '--card'],
        ['--accent-foreground', '--accent'],
        ['--destructive-foreground', '--destructive'],
        ['--destructive', '--background'],
        ['--destructive', '--card'],
        ['--cor-acao-contraste', '--cor-acao'],
        ['--cor-acao-texto', '--background'],
        ['--cor-acao-texto', '--card'],
        ['--cor-sucesso-contraste', '--cor-sucesso'],
        ['--cor-sucesso-texto', '--background'],
        ['--cor-sucesso-texto', '--card'],
        ['--cor-sucesso-suave-contraste', '--cor-sucesso-suave'],
        ['--cor-informacao-contraste', '--cor-informacao'],
        ['--cor-informacao-texto', '--background'],
        ['--cor-informacao-texto', '--card'],
        ['--cor-atencao-contraste', '--cor-atencao'],
        ['--cor-atencao-contraste', '--cor-atencao-forte'],
        ['--cor-atencao-texto', '--background'],
        ['--cor-atencao-texto', '--card'],
        ['--cor-atencao-suave-contraste', '--cor-atencao-suave'],
        ['--sidebar-foreground', '--sidebar-background'],
        ['--sidebar-primary-foreground', '--sidebar-primary'],
        ['--sidebar-accent-foreground', '--sidebar-accent'],
    ];

    $componente = [
        ['--input', '--background'],
        ['--input', '--card'],
        ['--ring', '--background'],
    ];

    $pares = [];

    foreach ($texto as [$frente, $fundo]) {
        $pares[] = [$frente, $fundo, 4.5];
    }

    foreach ($componente as [$frente, $fundo]) {
        $pares[] = [$frente, $fundo, 3.0];
    }

    return $pares;
}

test('todo tom do tema publico claro passa em AA, com a razao recalculada', function () use ($arquivoDoCss): void {
    $css = (string) file_get_contents($arquivoDoCss);
    $tokens = tokensDoBloco($css, "[data-tema='publico']");

    expect($tokens)->not->toBeEmpty("O bloco [data-tema='publico'] sumiu de {$arquivoDoCss}.");

    $reprovados = [];

    foreach (paresQuePrecisamPassar() as [$frente, $fundo, $limiar]) {
        expect($tokens)->toHaveKeys([$frente, $fundo]);

        $razao = razaoDeContraste($tokens[$frente], $tokens[$fundo]);

        if ($razao < $limiar) {
            $reprovados[] = sprintf(
                '%s (%s) sobre %s (%s) = %.2f:1, precisa de %.1f:1',
                $frente, $tokens[$frente], $fundo, $tokens[$fundo], $razao, $limiar
            );
        }
    }

    expect($reprovados)->toBeEmpty(
        'Tons do tema publico claro abaixo do minimo da WCAG 2.1:'.PHP_EOL.implode(PHP_EOL, $reprovados).PHP_EOL.
        'Escureca o tom ate passar e registre os DOIS valores no comentario (DA-42).'
    );
});

test('todo tom do tema publico escuro passa em AA, com a razao recalculada', function () use ($arquivoDoCss): void {
    $css = (string) file_get_contents($arquivoDoCss);
    $tokens = tokensDoBloco($css, "[data-tema='publico'].dark");

    expect($tokens)->not->toBeEmpty("O bloco [data-tema='publico'].dark sumiu de {$arquivoDoCss}.");

    $reprovados = [];

    foreach (paresQuePrecisamPassar() as [$frente, $fundo, $limiar]) {
        expect($tokens)->toHaveKeys([$frente, $fundo]);

        $razao = razaoDeContraste($tokens[$frente], $tokens[$fundo]);

        if ($razao < $limiar) {
            $reprovados[] = sprintf(
                '%s (%s) sobre %s (%s) = %.2f:1, precisa de %.1f:1',
                $frente, $tokens[$frente], $fundo, $tokens[$fundo], $razao, $limiar
            );
        }
    }

    expect($reprovados)->toBeEmpty(
        'Tons do tema publico escuro abaixo do minimo da WCAG 2.1:'.PHP_EOL.implode(PHP_EOL, $reprovados)
    );
});

test('o tema publico redeclara todos os tokens do tema do painel', function () use ($arquivoDoCss): void {
    $css = (string) file_get_contents($arquivoDoCss);

    $faltando = [];

    foreach ([[':root', "[data-tema='publico']"], ['.dark', "[data-tema='publico'].dark"]] as [$origem, $destino]) {
        $doPainel = nomesDeTokenDoBloco($css, $origem);
        $doPublico = nomesDeTokenDoBloco($css, $destino);

        expect($doPainel)->not->toBeEmpty("O bloco {$origem} sumiu.");

        foreach (array_diff($doPainel, $doPublico) as $token) {
            $faltando[] = "{$token} existe em {$origem} e nao em {$destino}";
        }
    }

    expect($faltando)->toBeEmpty(
        'Token sem par no tema publico — ele continuaria valendo a cor do painel:'.PHP_EOL.
        implode(PHP_EOL, $faltando)
    );
});

test('o tema do painel nao mudou de cor', function () use ($arquivoDoCss): void {
    $css = (string) file_get_contents($arquivoDoCss);

    // As ancoras do tema do studio (DA-40). Se qualquer uma delas mudar, alguma
    // tela administrativa mudou de cor — e este plano jurou que nenhuma mudaria.
    $claro = tokensDoBloco($css, ':root');
    $escuro = tokensDoBloco($css, '.dark');

    expect($claro['--background'])->toBe('#FFFFFF')
        ->and($claro['--primary'])->toBe('#155DFC')
        ->and($claro['--cor-acao'])->toBe('#155DFC')
        ->and($claro['--cor-sucesso'])->toBe('#007A55')
        ->and($claro['--cor-informacao'])->toBe('#0069A8')
        ->and($claro['--cor-atencao'])->toBe('#FE9A00')
        ->and($claro['--sidebar-background'])->toBe('#FAFAFA')
        ->and($escuro['--background'])->toBe('#09090B')
        ->and($escuro['--primary'])->toBe('#2B7FFF')
        ->and($escuro['--cor-acao'])->toBe('#2B7FFF');
});

test('a primeira pintura de uma tela publica ja sai no tema publico', function (): void {
    $evento = Evento::factory()->create();

    $this->get('/')->assertOk()->assertSee('data-tema="publico"', escape: false);

    $this->get("/eventos/{$evento->slug}")->assertOk()->assertSee('data-tema="publico"', escape: false);

    $this->get('/acesso')->assertOk()->assertSee('data-tema="publico"', escape: false);
});

test('a primeira pintura de uma tela administrativa continua no tema do painel', function (): void {
    Cenario::semearPapeis();

    $this->get('/login')->assertOk()->assertSee('data-tema="admin"', escape: false);

    $resposta = $this->actingAs(Cenario::usuarioCom('administrador'))->get('/admin/painel');

    $resposta->assertOk()->assertSee('data-tema="admin"', escape: false);

    // A prova pelo avesso: nenhuma tela do painel pode sair no tema verde.
    $resposta->assertDontSee('data-tema="publico"', escape: false);
});

test('as tres familias tipograficas continuam vindo do fonts.bunny.net', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    // A CSP libera essa origem, e so ela. Origem nova exigiria afrouxar a
    // politica e reescrever o cenario que a prova (tests/e2e/seguranca-csp).
    expect($html)->toContain('https://fonts.bunny.net/css?family=')
        ->and($html)->toContain('bricolage-grotesque')
        ->and($html)->toContain('dm-mono')
        ->and($html)->toContain('instrument-sans')
        ->and($html)->not->toContain('fonts.googleapis.com')
        ->and($html)->not->toContain('fonts.gstatic.com');

    $politica = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($politica)->toContain("style-src 'self' 'unsafe-inline' https://fonts.bunny.net")
        ->and($politica)->toContain("font-src 'self' data: https://fonts.bunny.net")
        ->and($politica)->not->toContain('googleapis');
});

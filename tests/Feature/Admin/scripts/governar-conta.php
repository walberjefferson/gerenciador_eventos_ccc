<?php

declare(strict_types=1);

/*
 * Processo independente que executa UMA acao de governanca sobre uma conta:
 * trocar o papel ou trocar a situacao.
 *
 * Existe para o teste de concorrencia da trava do ultimo administrador: dois
 * destes rodam ao mesmo tempo, cada um com a sua propria conexao com o banco,
 * tentando tirar do ar o penultimo e o ultimo administrador. Duas chamadas em
 * sequencia dentro do mesmo processo nao provariam nada — o risco real mora
 * entre duas conexoes, cada uma enxergando o mundo por conta propria (D-84).
 *
 * Uso: php governar-conta.php <alvo_id> <responsavel_id> <papel|situacao> <valor> <largada>
 * Escreve na saida: "ok", "recusado" ou "erro: ...".
 */

use App\Actions\Usuarios\GovernarConta;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$raiz = dirname(__DIR__, 4);

require $raiz.'/vendor/autoload.php';

/** @var Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$alvoId, $responsavelId, $acao, $valor, $largada] = array_slice($argv, 1, 5);

// Os dois processos esperam o mesmo instante para comecar: sem isso, o
// primeiro a subir terminaria antes de o outro nascer e nao haveria disputa.
$espera = (float) $largada - microtime(true);

if ($espera > 0) {
    usleep((int) ($espera * 1_000_000));
}

try {
    $alvo = User::query()->findOrFail((int) $alvoId);
    $responsavel = User::query()->findOrFail((int) $responsavelId);

    $governar = $app->make(GovernarConta::class);

    $recusa = $acao === 'papel'
        ? $governar->trocarPapel($alvo, (string) $valor, $responsavel)
        : $governar->trocarSituacao($alvo, $valor === '1', $responsavel);

    echo $recusa === null ? 'ok' : 'recusado';
} catch (Throwable $erro) {
    echo 'erro: '.$erro::class.': '.$erro->getMessage();
}

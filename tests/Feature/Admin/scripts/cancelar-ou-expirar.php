<?php

declare(strict_types=1);

/*
 * Processo independente que executa UMA acao sobre uma inscricao: cancelar
 * pela organizacao ou expirar por prazo vencido.
 *
 * Existe para o teste de concorrencia do cancelamento administrativo: dois
 * destes rodam ao mesmo tempo, cada um com a sua propria conexao com o banco,
 * disputando a mesma inscricao. O risco real — devolver a mesma vaga duas
 * vezes — mora entre duas conexoes, e so aparece com processos de verdade.
 *
 * Uso: php cancelar-ou-expirar.php <inscricao_id> <acao> <largada>
 * Escreve na saida: "mudou", "nao mudou" ou "erro: ...".
 */

use App\Actions\Inscricoes\CancelarInscricaoAdministrativa;
use App\Actions\Inscricoes\ExpirarInscricoesVencidas;
use App\Models\Inscricao;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$raiz = dirname(__DIR__, 4);

require $raiz.'/vendor/autoload.php';

/** @var Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$inscricaoId, $acao, $largada] = array_slice($argv, 1, 3);

// Os dois processos esperam o mesmo instante para comecar: sem isso, o
// primeiro a subir terminaria antes de o outro nascer e nao haveria disputa.
$espera = (float) $largada - microtime(true);

if ($espera > 0) {
    usleep((int) ($espera * 1_000_000));
}

try {
    $inscricao = Inscricao::query()->findOrFail((int) $inscricaoId);

    $mudou = $acao === 'cancelar'
        ? $app->make(CancelarInscricaoAdministrativa::class)($inscricao, 'Desistencia avisada por telefone')
        : $app->make(ExpirarInscricoesVencidas::class)($inscricao->evento) > 0;

    echo $mudou ? 'mudou' : 'nao mudou';
} catch (Throwable $erro) {
    echo 'erro: '.$erro::class.': '.$erro->getMessage();
}

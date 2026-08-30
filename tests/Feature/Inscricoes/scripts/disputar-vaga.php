<?php

declare(strict_types=1);

/*
 * Processo independente que tenta criar UMA inscricao no evento indicado.
 *
 * Existe para o teste de concorrencia: varios destes rodam ao mesmo tempo,
 * cada um com a sua propria conexao com o banco, disputando a ultima vaga.
 * Threads simuladas dentro do mesmo processo nao provariam nada — o que
 * precisa ser provado e que duas conexoes de verdade nao conseguem vender a
 * mesma vaga.
 *
 * Uso: php disputar-vaga.php <evento_id> <cidade_id> <grupo_id> <atividades> <indice> <comeco> [formato]
 * Escreve na saida: "ok", "esgotado" ou "erro: ...".
 *
 * Com o formato "com-tempo", a saida vira "ok|0.412": o mesmo resultado de
 * sempre, mais quantos segundos a inscricao levou de ponta a ponta. Serve ao
 * teste de carga, que precisa dizer no relatorio quanto tempo o caminho da
 * inscricao leva sob disputa. O formato padrao continua sendo o de antes, para
 * nao mexer em quem ja usa este script.
 */

use App\Actions\Inscricoes\CriarInscricao;
use App\DTOs\Inscricoes\DadosNovaInscricao;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

$raiz = dirname(__DIR__, 4);

require $raiz.'/vendor/autoload.php';

/** @var Application $app */
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$eventoId, $cidadeId, $grupoId, $atividades, $indice, $comeco] = array_slice($argv, 1, 6);

$comTempo = ($argv[7] ?? 'simples') === 'com-tempo';

$atividadeIds = array_map('intval', array_filter(explode(',', (string) $atividades)));

// Todos os processos esperam o mesmo instante para comecar: sem isso, o
// primeiro a subir terminaria antes de o ultimo nascer e nao haveria disputa.
$espera = (float) $comeco - microtime(true);

if ($espera > 0) {
    usleep((int) ($espera * 1_000_000));
}

$dados = new DadosNovaInscricao(
    eventoId: (int) $eventoId,
    cidadeId: (int) $cidadeId,
    grupoParticipanteId: (int) $grupoId,
    nomeCompleto: "Participante {$indice}",
    email: "disputa{$indice}@example.com",
    telefone: '(16) 98888-0000',
    documento: cpfDeTeste((int) $indice),
    dataNascimento: Carbon::now()->subYears(30)->startOfDay(),
    atividadeIds: $atividadeIds,
    aceitouTermos: true,
    chaveIdempotencia: (string) Str::uuid(),
);

// O relogio comeca aqui, e nao no inicio do processo: o que interessa medir e
// o caminho da inscricao, nao o tempo de o PHP subir a aplicacao — que num
// servidor de verdade acontece uma vez so, e nao a cada pedido.
$relogio = microtime(true);

try {
    $app->make(CriarInscricao::class)($dados);
    $resultado = 'ok';
} catch (VagasEsgotadasException) {
    $resultado = 'esgotado';
} catch (Throwable $erro) {
    $resultado = 'erro: '.$erro::class.': '.$erro->getMessage();
}

$segundos = microtime(true) - $relogio;

echo $comTempo ? sprintf('%s|%.4F', $resultado, $segundos) : $resultado;

function cpfDeTeste(int $semente): string
{
    $digitos = str_pad((string) (($semente * 7919) % 1000000000), 9, '0', STR_PAD_LEFT);

    foreach ([9, 10] as $posicao) {
        $soma = 0;

        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $digitos[$i] * (($posicao + 1) - $i);
        }

        $digitos .= (string) (((10 * $soma) % 11) % 10);
    }

    return $digitos;
}

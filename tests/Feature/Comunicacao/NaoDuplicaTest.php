<?php

declare(strict_types=1);

use App\Enums\TipoComunicacao;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use App\Services\Comunicacao\RegistrarEnvio;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| A mesma mensagem nunca chega duas vezes
|--------------------------------------------------------------------------
|
| A protecao nao esta em nenhuma linha de PHP: esta no indice unico
| (inscricao_id, tipo, canal) da tabela comunicacoes_enviadas. Estes testes
| exercitam a trava pelo banco, que e onde ela mora.
|
*/

it('recusa dois registros da mesma mensagem para a mesma inscricao', function (): void {
    $inscricao = Inscricao::factory()->create();

    $registro = fn (): ComunicacaoEnviada => ComunicacaoEnviada::query()->create([
        'inscricao_id' => $inscricao->id,
        'tipo' => TipoComunicacao::InscricaoRecebida->value,
        'canal' => ComunicacaoEnviada::CANAL_EMAIL,
        'destino' => $inscricao->email,
        'enviada_em' => Carbon::now(),
    ]);

    $registro();

    // A segunda gravacao vai dentro de uma transacao propria: no PostgreSQL,
    // um erro deixa a transacao aberta inutilizavel, e sem esse cerco nao
    // sobraria conexao para conferir o resultado.
    expect(fn () => DB::transaction($registro))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('aceita mensagens de tipos diferentes para a mesma inscricao', function (): void {
    $inscricao = Inscricao::factory()->create();

    foreach (TipoComunicacao::cases() as $tipo) {
        ComunicacaoEnviada::query()->create([
            'inscricao_id' => $inscricao->id,
            'tipo' => $tipo->value,
            'canal' => ComunicacaoEnviada::CANAL_EMAIL,
            'destino' => $inscricao->email,
            'enviada_em' => Carbon::now(),
        ]);
    }

    expect(ComunicacaoEnviada::query()->count())->toBe(count(TipoComunicacao::cases()));
});

it('envia uma vez so, mesmo chamado duas vezes', function (): void {
    $inscricao = Inscricao::factory()->create();
    $registrar = app(RegistrarEnvio::class);
    $envios = 0;

    $primeira = $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function () use (&$envios): void {
            $envios++;
        },
    );

    $segunda = $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function () use (&$envios): void {
            $envios++;
        },
    );

    expect($primeira)->toBeTrue()
        ->and($segunda)->toBeFalse()
        ->and($envios)->toBe(1)
        ->and(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('desfaz o registro quando o envio falha, para que a fila possa tentar de novo', function (): void {
    $inscricao = Inscricao::factory()->create();
    $registrar = app(RegistrarEnvio::class);

    $tentativa = fn (): bool => $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function (): void {
            throw new RuntimeException('servidor de e-mail fora do ar');
        },
    );

    expect($tentativa)->toThrow(RuntimeException::class);

    // Nenhum registro sobrou: a mensagem nao saiu, entao o caminho continua
    // livre para a proxima tentativa da fila.
    expect(ComunicacaoEnviada::query()->count())->toBe(0)
        ->and($registrar->jaEnviada($inscricao, TipoComunicacao::InscricaoRecebida))->toBeFalse();
});

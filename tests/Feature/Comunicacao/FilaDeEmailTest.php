<?php

declare(strict_types=1);

use App\Enums\TipoComunicacao;
use App\Events\InscricaoCriada;
use App\Listeners\EnviarEmailInscricaoCancelada;
use App\Listeners\EnviarEmailInscricaoRecebida;
use App\Listeners\EnviarEmailPagamentoConfirmado;
use App\Listeners\EnviarEmailPrazoVencido;
use App\Mail\InscricaoCanceladaMail;
use App\Mail\InscricaoRecebidaMail;
use App\Mail\LembretePrazoMail;
use App\Mail\PagamentoConfirmadoMail;
use App\Mail\PrazoVencidoMail;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tudo pela fila, e falha de e-mail nao derruba nada
|--------------------------------------------------------------------------
|
| Duas promessas desta fase sao verificadas aqui.
|
| A primeira: nenhum e-mail e enviado durante o pedido da pessoa. Um servidor
| de e-mail lento pode custar segundos; se ele estivesse no caminho da
| inscricao, custaria a inscricao.
|
| A segunda, mais importante: quando o envio falha de vez, o estrago fica
| contido no e-mail. A inscricao continua onde estava, a vaga continua
| ocupada, o pagamento continua reconhecido, e o que sobra e uma linha em
| "failed_jobs" para alguem investigar.
|
*/

/**
 * Um e-mail montado sem depender do banco, so para inspecionar como ele sai.
 */
function exemploDe(string $classe): object
{
    return match ($classe) {
        InscricaoRecebidaMail::class => new InscricaoRecebidaMail('Maria', 'Retiro', 'R$ 150,00', 'amanhã', 'https://exemplo.test/a'),
        LembretePrazoMail::class => new LembretePrazoMail('Maria', 'Retiro', 'menos de um dia', 'R$ 150,00', 'amanhã', 'https://exemplo.test/a'),
        PagamentoConfirmadoMail::class => new PagamentoConfirmadoMail('Maria', 'Retiro', 'R$ 150,00', 'hoje', 'ABC123', [], 'https://exemplo.test/a'),
        PrazoVencidoMail::class => new PrazoVencidoMail('Maria', 'Retiro', 'ontem', 'https://exemplo.test/a'),
        InscricaoCanceladaMail::class => new InscricaoCanceladaMail('Maria', 'Retiro', 'hoje', true, 'contato@exemplo.test', 'https://exemplo.test/a'),
        default => throw new InvalidArgumentException($classe),
    };
}

it('manda todo e-mail pela fila "emails", com tres tentativas e espera crescente', function (string $classe): void {
    $email = exemploDe($classe);

    expect($email)->toBeInstanceOf(ShouldQueue::class)
        ->and($email->queue)->toBe('emails')
        ->and($email->tries)->toBe(3)
        ->and($email->backoff())->toBe([60, 300, 900]);
})->with([
    InscricaoRecebidaMail::class,
    LembretePrazoMail::class,
    PagamentoConfirmadoMail::class,
    PrazoVencidoMail::class,
    InscricaoCanceladaMail::class,
]);

it('faz o mesmo com os quatro ouvintes dos anuncios do dominio', function (string $classe): void {
    // A copia sem construtor e exatamente a que o Laravel usa para decidir em
    // qual fila o trabalho entra. Se as opcoes dependessem do construtor, esta
    // copia responderia "nao sei" e o trabalho iria para a fila "default".
    $copia = (new ReflectionClass($classe))->newInstanceWithoutConstructor();

    expect($copia)->toBeInstanceOf(ShouldQueue::class)
        ->and($copia->viaQueue())->toBe('emails')
        ->and($copia->tries())->toBe(3)
        ->and($copia->backoff())->toBe([60, 300, 900]);
})->with([
    EnviarEmailInscricaoRecebida::class,
    EnviarEmailPagamentoConfirmado::class,
    EnviarEmailPrazoVencido::class,
    EnviarEmailInscricaoCancelada::class,
]);

it('usa a conexao configurada, para que em producao a fila seja o redis', function (): void {
    // Em teste a conexao fica vazia e vale a padrao ("sync"): o e-mail sai na
    // hora e o teste consegue conferi-lo. Em producao, QUEUE_CONNECTION=redis.
    config(['inscricoes.comunicacao.conexao' => 'redis']);

    expect(app(EnviarEmailInscricaoRecebida::class)->viaConnection())->toBe('redis')
        ->and(exemploDe(InscricaoRecebidaMail::class)->connection)->toBe('redis');
});

it('tem a tabela failed_jobs, onde a falha definitiva vai parar', function (): void {
    expect(Schema::hasTable('failed_jobs'))->toBeTrue();
});

it('falha definitiva de envio vai para failed_jobs sem tocar em inscricao, vaga ou pagamento', function (): void {
    // Fila de verdade (tabela "jobs") e um servidor de e-mail que nao existe:
    // porta 1 de localhost nao atende ninguem.
    config([
        'queue.default' => 'database',
        'inscricoes.comunicacao.conexao' => 'database',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.timeout' => 1,
        // Uma tentativa so: o teste nao vai esperar os 21 minutos de espera
        // crescente que valem em producao. O que se quer ver aqui e o fim da
        // linha, quando as tentativas acabam.
        'inscricoes.comunicacao.tentativas' => 1,
    ]);

    $inscricao = Inscricao::factory()->confirmada()->create();

    /** @var Closure(Inscricao): array<string, string|int|null> $retrato */
    $retrato = fn (Inscricao $i): array => [
        'situacao' => $i->situacao->value,
        'valor' => (int) $i->valor_centavos,
        'confirmada_em' => $i->confirmada_em?->toIso8601String(),
        'prazo_pagamento' => $i->prazo_pagamento?->toIso8601String(),
        'cancelada_em' => $i->cancelada_em?->toIso8601String(),
    ];

    $antes = $retrato($inscricao);
    $vagasAntes = $inscricao->evento->inscricoes()->count();
    $pagamentosAntes = DB::table('pagamentos')->count();

    InscricaoCriada::dispatch($inscricao);

    // O anuncio nao enviou nada: apenas deixou trabalho na fila "emails" — e
    // nao na "default", que nenhum trabalhador desta fase escuta.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('queue'))->toBe('emails');

    $this->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'emails',
        '--stop-when-empty' => true,
        '--tries' => 1,
    ])->assertSuccessful();

    // O e-mail nao chegou, e isso esta anotado onde alguem consegue investigar.
    expect(DB::table('failed_jobs')->count())->toBeGreaterThan(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    // E a inscricao, a vaga e o pagamento seguem exatamente como estavam.
    expect($retrato($inscricao->fresh()))->toBe($antes)
        ->and($inscricao->evento->inscricoes()->count())->toBe($vagasAntes)
        ->and(DB::table('pagamentos')->count())->toBe($pagamentosAntes);
});

it('nao registra envio quando a mensagem nem chegou a ser posta no caminho', function (): void {
    // Sem trabalhador nenhum: o que fica na fila fica na fila, e nenhum
    // registro de envio e criado antes da hora.
    config(['queue.default' => 'database', 'inscricoes.comunicacao.conexao' => 'database']);

    $inscricao = Inscricao::factory()->create();
    InscricaoCriada::dispatch($inscricao);

    expect(ComunicacaoEnviada::query()->where('tipo', TipoComunicacao::InscricaoRecebida->value)->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('queue'))->toBe('emails');
});

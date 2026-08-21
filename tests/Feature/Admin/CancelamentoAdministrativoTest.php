<?php

declare(strict_types=1);

use App\Actions\Inscricoes\CancelarInscricaoAdministrativa;
use App\Actions\Inscricoes\ExpirarInscricoesVencidas;
use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoCancelada;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Admin\Cenario as CenarioAdmin;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O cancelamento feito pela organizacao.
 *
 * O que precisa ficar provado aqui e sempre a mesma coisa vista de angulos
 * diferentes: a vaga volta uma vez, e uma vez so — nao importa quantas vezes
 * alguem aperte o botao, nem quem mais esteja mexendo na mesma inscricao no
 * mesmo instante.
 */

/** Cancela usando a Action de verdade, do jeito que o controller vai usar. */
function cancelar(Inscricao $inscricao, string $motivo = 'Desistencia avisada por telefone', $responsavel = null): bool
{
    return app(CancelarInscricaoAdministrativa::class)($inscricao, $motivo, $responsavel);
}

/**
 * Monta o cenario numa conexao propria, fora da transacao do teste.
 *
 * Os processos que disputam a inscricao sao processos de verdade: eles so
 * enxergam o que ja foi confirmado no banco.
 */
function cenarioCommitadoParaCancelamento(): Cenario
{
    $padrao = config('database.default');

    config(['database.connections.disputa' => config("database.connections.{$padrao}")]);
    config(['database.default' => 'disputa']);

    try {
        return Cenario::montar(['capacidade' => 10, 'prazo_pagamento_minutos' => 60]);
    } finally {
        config(['database.default' => $padrao]);
    }
}

/** Executa alguma coisa com a conexao commitada como padrao. */
function naConexaoCommitada(callable $acao): mixed
{
    $padrao = config('database.default');

    config(['database.default' => 'disputa']);

    try {
        return $acao();
    } finally {
        config(['database.default' => $padrao]);
    }
}

/**
 * Apaga o que foi gravado fora da transacao do teste.
 *
 * Unica excecao a regra de nunca apagar registro: aqui nao ha dominio, e sim
 * sujeira que a transacao do RefreshDatabase nao alcanca.
 */
function limparCancelamentoCommitado(Cenario $cenario): void
{
    $conexao = DB::connection('disputa');

    $inscricoes = $conexao->table('inscricoes')
        ->where('evento_id', $cenario->evento->id)
        ->pluck('id')
        ->all();

    $conexao->table('pagamentos')->whereIn('inscricao_id', $inscricoes)->delete();
    $conexao->table('inscricoes')->where('evento_id', $cenario->evento->id)->delete();
    $conexao->table('eventos')->where('id', $cenario->evento->id)->delete();
    $conexao->table('grupos_participantes')->where('id', $cenario->grupoParticipante->id)->delete();
    $conexao->table('cidades')->where('id', $cenario->cidade->id)->delete();
}

/**
 * Dispara os dois processos que disputam a mesma inscricao.
 *
 * @return list<string>
 */
function disputarCancelamento(int $inscricaoId, float $margemDeLargada = 2.0): array
{
    $raiz = base_path();
    $script = $raiz.'/tests/Feature/Admin/scripts/cancelar-ou-expirar.php';
    $conexao = config('database.connections.pgsql');

    $ambiente = array_merge(getenv(), [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $conexao['host'],
        'DB_PORT' => (string) $conexao['port'],
        'DB_DATABASE' => (string) $conexao['database'],
        'DB_USERNAME' => (string) $conexao['username'],
        'DB_PASSWORD' => (string) $conexao['password'],
    ]);

    $largada = microtime(true) + $margemDeLargada;
    $processos = [];

    foreach (['cancelar', 'expirar'] as $acao) {
        $tubos = [];

        $processo = proc_open(
            [PHP_BINARY, $script, (string) $inscricaoId, $acao, sprintf('%.6F', $largada)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubos,
            $raiz,
            $ambiente,
        );

        if (! is_resource($processo)) {
            throw new RuntimeException("Nao foi possivel iniciar o processo de {$acao}.");
        }

        $processos[] = [$processo, $tubos];
    }

    $saidas = [];

    foreach ($processos as [$processo, $tubos]) {
        $saida = trim((string) stream_get_contents($tubos[1]));
        $erro = trim((string) stream_get_contents($tubos[2]));

        fclose($tubos[1]);
        fclose($tubos[2]);
        proc_close($processo);

        $saidas[] = $saida !== '' ? $saida : 'sem saida: '.$erro;
    }

    return $saidas;
}

describe('devolucao de vaga', function () {
    it('devolve a vaga do evento e a de cada atividade escolhida', function () {
        $cenario = Cenario::montar(['capacidade' => 10]);

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ]);

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(1);

        expect(cancelar($inscricao))->toBeTrue();

        // Os contadores voltam exatamente ao que eram antes da inscricao.
        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(0);

        $inscricao->refresh();

        expect($inscricao->situacao)->toBe(SituacaoInscricao::Cancelada)
            ->and($inscricao->cancelada_em)->not->toBeNull()
            ->and($inscricao->motivo_cancelamento)->toBe('Desistencia avisada por telefone');
    });

    it('encerra a cobranca em aberto para que ninguem pague uma vaga devolvida', function () {
        $cenario = Cenario::montar(['capacidade' => 10]);

        $inscricao = $cenario->inscrever();
        $pagamento = $inscricao->pagamentoPendente();

        expect($pagamento)->not->toBeNull();

        cancelar($inscricao);

        expect($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Cancelado)
            ->and($pagamento->fresh()->cancelado_em)->not->toBeNull();
    });

    it('nao apaga nada: a inscricao e a cobranca continuam no banco', function () {
        $cenario = Cenario::montar(['capacidade' => 10]);

        $inscricao = $cenario->inscrever();

        cancelar($inscricao);

        expect(Inscricao::count())->toBe(1)
            ->and(DB::table('pagamentos')->count())->toBe(1)
            ->and(DB::table('inscricoes_atividades')->count())->toBe(1);
    });
});

describe('motivo obrigatorio', function () {
    it('recusa cancelamento sem motivo', function () {
        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        expect(fn () => cancelar($inscricao, '   '))
            ->toThrow(InvalidArgumentException::class, 'Informe o motivo do cancelamento.');

        // Nada mudou: a inscricao segue de pe e a vaga continua presa.
        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1);
    });

    it('guarda quem cancelou junto do anuncio', function () {
        CenarioAdmin::semearPapeis();
        Event::fake([InscricaoCancelada::class]);

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();
        $responsavel = CenarioAdmin::usuarioCom('organizador');

        cancelar($inscricao, 'Participante desistiu', $responsavel);

        Event::assertDispatched(
            InscricaoCancelada::class,
            fn (InscricaoCancelada $anuncio): bool => $anuncio->inscricao->is($inscricao)
                && $anuncio->motivo === 'Participante desistiu'
                && $anuncio->responsavel?->is($responsavel) === true
                && $anuncio->estavaConfirmada === false,
        );
    });
});

describe('cancelar duas vezes', function () {
    it('nao devolve a vaga em dobro', function () {
        $cenario = Cenario::montar(['capacidade' => 10]);

        // Duas pessoas inscritas: se a vaga voltasse duas vezes, o contador
        // cairia de 2 para 0 em vez de para 1.
        $primeira = $cenario->inscrever();
        $cenario->inscrever($cenario->outraPessoa(41));

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(2);

        expect(cancelar($primeira))->toBeTrue()
            ->and(cancelar($primeira->fresh()))->toBeFalse();

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1);
    });

    it('anuncia o cancelamento uma unica vez', function () {
        Event::fake([InscricaoCancelada::class]);

        $cenario = Cenario::montar(['capacidade' => 10]);
        $inscricao = $cenario->inscrever();

        cancelar($inscricao);
        cancelar($inscricao->fresh());

        Event::assertDispatchedTimes(InscricaoCancelada::class, 1);
    });

    it('nao ressuscita inscricao ja expirada nem devolve a vaga de novo', function () {
        $cenario = Cenario::montar(['capacidade' => 10, 'prazo_pagamento_minutos' => 60]);

        $inscricao = $cenario->inscrever();

        Inscricao::whereKey($inscricao->id)->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

        expect(app(ExpirarInscricoesVencidas::class)())->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0);

        expect(cancelar($inscricao->fresh()))->toBeFalse()
            ->and($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0);
    });
});

describe('inscricao ja confirmada', function () {
    it('pode ser cancelada e devolve a vaga paga, sem estorno', function () {
        Event::fake([InscricaoCancelada::class]);

        $cenario = Cenario::montar(['capacidade' => 10]);

        $inscricao = $cenario->inscrever();
        $pagamento = $inscricao->pagamentoPendente();

        app(ConfirmarPagamento::class)($pagamento);

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Confirmada)
            ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0);

        expect(cancelar($inscricao->fresh(), 'Desistiu depois de pagar'))->toBeTrue();

        expect($cenario->evento->fresh()->vagas_confirmadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_confirmadas)->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0);

        // O dinheiro nao se mexe sozinho: a cobranca continua paga e nao ha
        // estorno nenhum registrado. Devolver valor depende de politica que
        // ainda nao existe.
        $pagamento->refresh();

        expect($pagamento->situacao)->toBe(SituacaoPagamento::Pago)
            ->and($pagamento->pago_em)->not->toBeNull()
            ->and($pagamento->estornado_em)->toBeNull()
            ->and($pagamento->valor_estornado_centavos)->toBeNull();

        Event::assertDispatched(
            InscricaoCancelada::class,
            fn (InscricaoCancelada $anuncio): bool => $anuncio->estavaConfirmada === true,
        );
    });
});

describe('anuncio de dominio', function () {
    it('nao tem ouvinte registrado nesta fase', function () {
        expect(Event::getRawListeners()[InscricaoCancelada::class] ?? [])->toBe([]);
    });
});

describe('disputa real entre processos', function () {
    it('nao devolve a vaga em dobro quando o cancelamento encontra a rotina de expiracao', function () {
        $cenario = cenarioCommitadoParaCancelamento();

        try {
            $alvo = naConexaoCommitada(fn () => $cenario->inscrever($cenario->outraPessoa(51)));

            // Uma segunda inscricao, que ninguem toca: se a vaga do alvo voltasse
            // duas vezes, o contador cairia de 2 para 0 em vez de para 1.
            naConexaoCommitada(fn () => $cenario->inscrever($cenario->outraPessoa(52)));

            // O prazo do alvo acabou de vencer: a rotina de expiracao e o
            // organizador olham para a mesma inscricao no mesmo instante.
            DB::connection('disputa')->table('inscricoes')
                ->where('id', $alvo->id)
                ->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

            $saidas = disputarCancelamento($alvo->id);

            $erros = array_values(array_filter(
                $saidas,
                fn (string $saida): bool => ! in_array($saida, ['mudou', 'nao mudou'], true),
            ));

            expect($erros)->toBe([], 'Algum processo falhou por motivo alheio a disputa: '.implode(' | ', $erros));

            $evento = DB::connection('disputa')->table('eventos')->where('id', $cenario->evento->id)->first();
            $futebol = DB::connection('disputa')->table('atividades')->where('id', $cenario->futebol->id)->first();

            expect(array_count_values($saidas)['mudou'] ?? 0)->toBe(1)
                ->and(array_count_values($saidas)['nao mudou'] ?? 0)->toBe(1)
                ->and($evento->vagas_reservadas)->toBe(1)
                ->and($evento->vagas_confirmadas)->toBe(0)
                ->and($futebol->vagas_reservadas)->toBe(1);
        } finally {
            limparCancelamentoCommitado($cenario);
        }
    });
});

<?php

declare(strict_types=1);

use App\Actions\Ingressos\EmitirIngresso;
use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Enums\SituacaoIngresso;
use App\Events\InscricaoConfirmada;
use App\Models\Ingresso;
use App\Models\Inscricao;
use App\Services\Ingressos\GeradorDeCodigo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Inscricoes\Cenario;

/*
|--------------------------------------------------------------------------
| O ingresso nasce quando a inscricao vira confirmada
|--------------------------------------------------------------------------
|
| Tres coisas sao provadas aqui, e as tres podem custar caro no dia do evento:
| que o codigo nao tem parentesco nenhum com o que ja circulou por e-mail, que
| emitir duas vezes nao cria dois ingressos, e que quem foi confirmado antes de
| tudo isto existir tambem recebe o seu.
|
*/

/**
 * O caminho real do dinheiro: a cobranca que nasceu com a inscricao e
 * reconhecida, exatamente como o aviso do provedor faria. Nada de mexer na
 * coluna "situacao" a mao — e o anuncio InscricaoConfirmada que faz o ingresso
 * nascer, e ele so sai do caminho de verdade.
 */
function confirmarParaIngresso(Inscricao $inscricao): void
{
    app(ConfirmarPagamento::class)($inscricao->pagamentoPendente());
}

beforeEach(function (): void {
    // O ouvinte do e-mail escuta o mesmo anuncio: sem isto, cada confirmacao
    // tentaria montar uma mensagem — que nao e o que estes testes examinam.
    Mail::fake();
});

it('sorteia um codigo de doze caracteres no alfabeto de Crockford', function (): void {
    $gerador = new GeradorDeCodigo;

    $codigos = [];

    for ($i = 0; $i < 200; $i++) {
        $codigo = $gerador();

        expect($codigo)->toHaveLength(12)
            ->and($codigo)->toMatch('/^[0-9A-HJKMNP-TV-Z]{12}$/');

        $codigos[] = $codigo;
    }

    // As letras que se confundem com numero na mao de quem le um papel
    // amassado nao existem no alfabeto.
    expect(implode('', $codigos))->not->toContain('I')
        ->and(implode('', $codigos))->not->toContain('L')
        ->and(implode('', $codigos))->not->toContain('O')
        ->and(implode('', $codigos))->not->toContain('U');

    // Duzentos sorteios, duzentos codigos diferentes: nao ha sequencia aqui.
    expect(array_unique($codigos))->toHaveCount(200);
});

it('normaliza o que a pessoa digita e formata o que ela le', function (): void {
    // Hifen, espaco, minuscula e as confusoes que o alfabeto preve.
    expect(GeradorDeCodigo::normalizar('abcd-2345-jkmn'))->toBe('ABCD2345JKMN')
        ->and(GeradorDeCodigo::normalizar('  abcd 2345 jkmn '))->toBe('ABCD2345JKMN')
        ->and(GeradorDeCodigo::normalizar('OI2345JKMNPQ'))->toBe('012345JKMNPQ')
        ->and(GeradorDeCodigo::normalizar('L2345JKMNPQR'))->toBe('12345JKMNPQR');

    expect(GeradorDeCodigo::formatar('ABCD2345JKMN'))->toBe('ABCD-2345-JKMN')
        ->and(GeradorDeCodigo::formatar('abcd-2345-jkmn'))->toBe('ABCD-2345-JKMN')
        ->and(GeradorDeCodigo::formatar(''))->toBe('');
});

it('emite o ingresso quando o pagamento e reconhecido', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    expect($inscricao->ingresso)->toBeNull();

    confirmarParaIngresso($inscricao);

    $ingresso = $inscricao->fresh()->ingresso;

    expect($ingresso)->not->toBeNull()
        ->and($ingresso->codigo)->toHaveLength(12)
        ->and($ingresso->emitido_em)->not->toBeNull()
        ->and($ingresso->usado_em)->toBeNull()
        ->and($ingresso->usado_por)->toBeNull()
        ->and($ingresso->situacao())->toBe(SituacaoIngresso::Emitido);
});

it('devolve o mesmo ingresso quando a emissao e chamada de novo', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();
    confirmarParaIngresso($inscricao);

    $emitir = app(EmitirIngresso::class);
    $inscricao = $inscricao->fresh();

    $primeiro = $emitir($inscricao);
    $segundo = $emitir($inscricao);
    $terceiro = $emitir($inscricao);

    expect($segundo->getKey())->toBe($primeiro->getKey())
        ->and($terceiro->getKey())->toBe($primeiro->getKey())
        ->and($segundo->codigo)->toBe($primeiro->codigo)
        ->and(Ingresso::query()->count())->toBe(1);
});

it('recusa emitir ingresso para inscricao que ainda nao esta confirmada', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    expect(fn () => app(EmitirIngresso::class)($inscricao))
        ->toThrow(InvalidArgumentException::class);

    expect(Ingresso::query()->count())->toBe(0);
});

it('e o banco, e nao o codigo, que recusa o segundo ingresso da mesma inscricao', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();
    confirmarParaIngresso($inscricao);

    // Gravacao crua, por fora da Action: e a unicidade da coluna que precisa
    // segurar, porque verificacao em PHP nao segura duas requisicoes ao mesmo
    // tempo.
    expect(fn () => DB::table('ingressos')->insert([
        'inscricao_id' => $inscricao->getKey(),
        'codigo' => 'ZZZZ9999YYYY',
        'emitido_em' => Carbon::now(),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('nao deriva o codigo do codigo publico nem de qualquer dado da pessoa', function (): void {
    // A MESMA pessoa, com o mesmo nome e o mesmo e-mail, em dois eventos.
    $primeiroEvento = Cenario::montar(['nome' => 'Retiro de janeiro']);
    $segundoEvento = Cenario::montar(['nome' => 'Retiro de julho']);

    $dados = ['nome_completo' => 'Maria Aparecida Souza', 'email' => 'maria.souza@example.com'];

    $uma = $primeiroEvento->inscrever($dados);
    $outra = $segundoEvento->inscrever($dados);

    confirmarParaIngresso($uma);
    confirmarParaIngresso($outra);

    $ingressoUm = $uma->fresh()->ingresso;
    $ingressoDois = $outra->fresh()->ingresso;

    expect($ingressoUm->codigo)->not->toBe($ingressoDois->codigo);

    foreach ([[$uma, $ingressoUm], [$outra, $ingressoDois]] as [$inscricao, $ingresso]) {
        $publico = GeradorDeCodigo::normalizar((string) $inscricao->codigo_publico);

        // Nem o codigo inteiro, nem um pedaco dele: qualquer sequencia de
        // quatro caracteres em comum ja seria parentesco demais.
        expect($publico)->not->toContain($ingresso->codigo);

        for ($inicio = 0; $inicio + 4 <= strlen($ingresso->codigo); $inicio++) {
            expect($publico)->not->toContain(substr($ingresso->codigo, $inicio, 4));
        }
    }
});

it('da ingresso a quem ja estava confirmado antes de o ingresso existir', function (): void {
    $cenario = Cenario::montar();

    // Tres confirmadas sem ingresso — como ficariam as inscricoes antigas — e
    // uma ainda aguardando pagamento, que nao pode receber nada.
    $antigas = collect(range(1, 3))->map(function (int $indice) use ($cenario): Inscricao {
        // Pessoas diferentes: o dominio recusa duas inscricoes ativas com o
        // mesmo e-mail ou o mesmo CPF no mesmo evento.
        $inscricao = $cenario->inscrever($cenario->outraPessoa($indice));
        confirmarParaIngresso($inscricao);
        $inscricao->fresh()->ingresso?->delete();

        return $inscricao->fresh();
    });

    $aguardando = $cenario->inscrever($cenario->outraPessoa(90));

    expect(Ingresso::query()->count())->toBe(0);

    $this->artisan('ingressos:emitir-pendentes')
        ->expectsOutputToContain('Ingressos emitidos nesta execucao: 3.')
        ->assertSuccessful();

    expect(Ingresso::query()->count())->toBe(3)
        ->and($aguardando->fresh()->ingresso)->toBeNull();

    $codigos = $antigas->map(fn (Inscricao $inscricao): string => (string) $inscricao->fresh()->ingresso->codigo);

    expect($codigos->unique())->toHaveCount(3);

    // Rodar de novo nao cria um segundo ingresso para ninguem.
    $this->artisan('ingressos:emitir-pendentes')
        ->expectsOutputToContain('Nenhuma inscricao confirmada estava sem ingresso.')
        ->assertSuccessful();

    expect(Ingresso::query()->count())->toBe(3);
});

it('nao derruba a confirmacao quando a emissao do ingresso falha', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    // Uma emissao que estoura no meio do caminho. O anuncio nao pode explodir
    // junto: o dinheiro ja foi reconhecido e a transacao ja fechou.
    $this->instance(EmitirIngresso::class, new class(app(GeradorDeCodigo::class)) extends EmitirIngresso
    {
        public function __invoke(Inscricao $inscricao, ?Carbon $momento = null): Ingresso
        {
            throw new RuntimeException('banco fora do ar');
        }
    });

    InscricaoConfirmada::dispatch($inscricao);

    expect(Ingresso::query()->count())->toBe(0);
});

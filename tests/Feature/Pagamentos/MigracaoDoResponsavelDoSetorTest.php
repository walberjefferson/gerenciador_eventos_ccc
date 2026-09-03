<?php

declare(strict_types=1);

use App\Models\Cidade;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A migracao que tira os quatro campos do setor e os leva para o cadastro de
 * responsaveis.
 *
 * Ela e a unica migracao destrutiva do sistema: depois do drop nao ha volta, e
 * o que ela move e chave de recebimento de dinheiro. Backfill sem prova e
 * backfill que ninguem conferiu — por isso o que se prova aqui e que NADA se
 * perde no caminho:
 *
 * 1. cada setor que sabia receber virou uma ficha de responsavel, com o mesmo
 *    nome, a mesma chave, o mesmo telefone e a mesma conta do painel, vinculada
 *    ao mesmo setor;
 * 2. setor com chave e sem titular cadastrado usa o NOME DO SETOR, em vez de
 *    descartar a chave;
 * 3. setor sem chave nao vira ficha nenhuma — nao ha pessoa a cadastrar;
 * 4. a mesma pessoa em dois setores vira UMA ficha, atendendo os dois;
 * 5. a mesma conta do painel com chaves diferentes preserva as duas chaves,
 *    porque perder chave e o pior desfecho possivel;
 * 6. as quatro colunas realmente saem de `cidades` no fim.
 *
 * O teste desfaz a migracao (o que devolve as quatro colunas), escreve dados no
 * formato antigo e a roda de novo — exercitando o codigo de verdade, e nao uma
 * copia dele.
 */

/**
 * A migracao sob teste, carregada do arquivo.
 */
function migracaoDoResponsavel(): Migration
{
    /** @var Migration $migracao */
    $migracao = require database_path(
        'migrations/2026_09_03_120004_mover_responsavel_do_setor_para_responsaveis.php'
    );

    return $migracao;
}

/**
 * Devolve o banco ao formato antigo e escreve nele os setores informados.
 *
 * @param  array<int, array<string, mixed>>  $setores
 * @return array<string, int> o id de cada setor, pelo nome
 */
function comSetoresNoFormatoAntigo(array $setores): array
{
    migracaoDoResponsavel()->down();

    $ids = [];

    foreach ($setores as $setor) {
        $ids[(string) $setor['nome']] = (int) DB::table('cidades')->insertGetId([
            'nome' => $setor['nome'],
            'uf' => $setor['uf'] ?? 'SP',
            'ativo' => true,
            'responsavel_id' => $setor['responsavel_id'] ?? null,
            'chave_pix' => $setor['chave_pix'] ?? null,
            'titular_chave_pix' => $setor['titular_chave_pix'] ?? null,
            'telefone_responsavel' => $setor['telefone_responsavel'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    migracaoDoResponsavel()->up();

    return $ids;
}

it('leva o setor inteiro para a ficha do responsavel, sem perder campo nenhum', function (): void {
    $conta = User::factory()->create(['name' => 'Joana do Painel']);

    $ids = comSetoresNoFormatoAntigo([[
        'nome' => 'Setor Norte',
        'responsavel_id' => $conta->getKey(),
        'chave_pix' => 'joana@example.com',
        'titular_chave_pix' => 'Joana da Silva',
        'telefone_responsavel' => '(82) 99999-1111',
    ]]);

    $ficha = DB::table('responsaveis')->first();

    expect($ficha)->not->toBeNull()
        ->and($ficha->nome)->toBe('Joana da Silva')
        ->and($ficha->chave_pix)->toBe('joana@example.com')
        ->and($ficha->telefone)->toBe('(82) 99999-1111')
        ->and((int) $ficha->user_id)->toBe((int) $conta->getKey())
        ->and((bool) $ficha->ativo)->toBeTrue();

    expect(DB::table('responsaveis_setores')
        ->where('cidade_id', $ids['Setor Norte'])
        ->where('responsavel_id', $ficha->id)
        ->exists())->toBeTrue();
});

it('usa o nome do setor quando a chave foi cadastrada sem titular', function (): void {
    comSetoresNoFormatoAntigo([[
        'nome' => 'Setor Sem Titular',
        'chave_pix' => '11122233344',
    ]]);

    $ficha = DB::table('responsaveis')->first();

    // A chave nunca e descartada: nome aproximado vale mais do que chave
    // perdida.
    expect($ficha->nome)->toBe('Setor Sem Titular')
        ->and($ficha->chave_pix)->toBe('11122233344')
        ->and($ficha->user_id)->toBeNull()
        ->and($ficha->telefone)->toBeNull();
});

it('nao cria ficha para setor que nunca teve chave', function (): void {
    comSetoresNoFormatoAntigo([
        ['nome' => 'Setor Sem Chave'],
        ['nome' => 'Setor Vazio', 'chave_pix' => '   '],
    ]);

    expect(DB::table('responsaveis')->count())->toBe(0)
        ->and(DB::table('responsaveis_setores')->count())->toBe(0);
});

it('cria uma ficha so quando a mesma pessoa responde por dois setores', function (): void {
    $conta = User::factory()->create();

    $ids = comSetoresNoFormatoAntigo([
        [
            'nome' => 'Setor Um',
            'responsavel_id' => $conta->getKey(),
            'chave_pix' => 'pedro@example.com',
            'titular_chave_pix' => 'Pedro Alves',
            'telefone_responsavel' => '(82) 98888-2222',
        ],
        [
            'nome' => 'Setor Dois',
            'uf' => 'MG',
            'responsavel_id' => $conta->getKey(),
            'chave_pix' => 'pedro@example.com',
            'titular_chave_pix' => 'Pedro Alves',
            'telefone_responsavel' => '(82) 98888-2222',
        ],
    ]);

    expect(DB::table('responsaveis')->count())->toBe(1);

    $ficha = DB::table('responsaveis')->first();

    expect(DB::table('responsaveis_setores')->where('responsavel_id', $ficha->id)->pluck('cidade_id')->all())
        ->toEqualCanonicalizing([$ids['Setor Um'], $ids['Setor Dois']]);
});

it('preserva as duas chaves quando a mesma conta usava chaves diferentes', function (): void {
    $conta = User::factory()->create();

    comSetoresNoFormatoAntigo([
        [
            'nome' => 'Setor A',
            'responsavel_id' => $conta->getKey(),
            'chave_pix' => 'chave-a@example.com',
            'titular_chave_pix' => 'Ana Tesoureira',
        ],
        [
            'nome' => 'Setor B',
            'uf' => 'BA',
            'responsavel_id' => $conta->getKey(),
            'chave_pix' => 'chave-b@example.com',
            'titular_chave_pix' => 'Ana Tesoureira',
        ],
    ]);

    // Duas fichas, porque o unico parcial em user_id nao aceita duas para a
    // mesma conta: a segunda nasce sem conta, e a CHAVE — que e o que nao pode
    // se perder — fica de pe nas duas.
    expect(DB::table('responsaveis')->pluck('chave_pix')->all())
        ->toEqualCanonicalizing(['chave-a@example.com', 'chave-b@example.com'])
        ->and(DB::table('responsaveis')->whereNotNull('user_id')->count())->toBe(1);
});

it('deixa cidades sem os quatro campos depois de mover tudo', function (): void {
    comSetoresNoFormatoAntigo([[
        'nome' => 'Setor Final',
        'chave_pix' => 'final@example.com',
    ]]);

    expect(Schema::hasColumn('cidades', 'responsavel_id'))->toBeFalse()
        ->and(Schema::hasColumn('cidades', 'chave_pix'))->toBeFalse()
        ->and(Schema::hasColumn('cidades', 'titular_chave_pix'))->toBeFalse()
        ->and(Schema::hasColumn('cidades', 'telefone_responsavel'))->toBeFalse();

    // E o cadastro continua utilizavel depois da mudanca.
    expect(Cidade::query()->where('nome', 'Setor Final')->exists())->toBeTrue();
});

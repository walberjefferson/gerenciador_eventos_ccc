<?php

declare(strict_types=1);

use App\Http\Resources\CidadeResource;
use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Database\Seeders\CidadeSeeder;

/**
 * O catalogo real da comunidade: cinco setores e vinte e nove grupos.
 *
 * A tabela continua se chamando `cidades` e a coluna, `cidade_id` — o renome
 * para "setor" vale para a tela e para as rotas do administrativo, nao para o
 * banco. Por isso as asseveracoes aqui falam de setor e consultam `Cidade`.
 *
 * O seeder roda a cada subida do container (ver docs/DEPLOY.md), entao a
 * idempotencia nao e detalhe: e o que impede o catalogo de dobrar de tamanho a
 * cada implantacao.
 */
function semearCatalogo(): void
{
    (new CidadeSeeder)->run();
}

it('semeia exatamente cinco setores e vinte e nove grupos', function (): void {
    semearCatalogo();

    expect(Cidade::query()->count())->toBe(5)
        ->and(GrupoParticipante::query()->count())->toBe(29);
});

it('distribui os grupos entre os setores como a fonte manda', function (): void {
    semearCatalogo();

    $porSetor = Cidade::query()
        ->withCount('gruposParticipantes')
        ->orderBy('nome')
        ->get()
        ->mapWithKeys(fn (Cidade $setor): array => [$setor->nome => $setor->grupos_participantes_count])
        ->all();

    expect($porSetor)->toBe([
        'Setor Batalha' => 8,
        'Setor Delmiro' => 2,
        "Setor Olho d'água das Flores" => 5,
        'Setor Palmeira' => 6,
        'Setor Santana' => 8,
    ]);
});

it('guarda AL em todos os setores, porque a coluna e obrigatoria e entra na chave unica', function (): void {
    semearCatalogo();

    expect(Cidade::query()->where('uf', 'AL')->count())->toBe(5);
});

// Rodar duas vezes e o que acontece de verdade: o entrypoint chama o seeder a
// cada subida do container.
it('pode ser semeado de novo sem duplicar nada', function (): void {
    semearCatalogo();
    semearCatalogo();

    expect(Cidade::query()->count())->toBe(5)
        ->and(GrupoParticipante::query()->count())->toBe(29);
});

it('liga cada grupo ao setor a que ele pertence', function (): void {
    semearCatalogo();

    $grupo = GrupoParticipante::query()->where('nome', 'Santana do Ipanema (Sede)')->sole();

    expect($grupo->cidade->nome)->toBe('Setor Santana');
});

// Os nomes reais trazem apostrofo, acento e parentese. Se algum passo do
// caminho os maltratasse, e aqui que apareceria.
it('guarda inteiros os nomes com apostrofo, acento e parentese', function (): void {
    semearCatalogo();

    $setor = Cidade::query()->where('nome', "Setor Olho d'água das Flores")->sole();

    expect($setor->gruposParticipantes()->pluck('nome')->sort()->values()->all())->toBe([
        'Carneiros',
        "Olho d'água das Flores",
        'Palestina',
        'Pão de Açúcar',
        'Senador Rui Palmeira',
    ]);
});

// O rotulo publico perdeu a UF: ela existia para separar cidades homonimas de
// estados diferentes, e os cinco setores sao todos da mesma regiao.
it('mostra o setor pelo nome, sem a UF entre parenteses', function (): void {
    $setor = Cidade::factory()->create(['nome' => 'Setor Batalha', 'uf' => 'AL']);

    $rotulo = (new CidadeResource($setor))->toArray(request())['rotulo'];

    expect($rotulo)->toBe('Setor Batalha')
        ->and($rotulo)->not->toContain('(AL)');
});

<?php

declare(strict_types=1);

use App\Models\Cidade;
use App\Models\Responsavel;
use App\Models\User;
use Database\Seeders\PapeisSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;

/*
 * O cadastro de quem recebe o Pix dos setores.
 *
 * O que se prova aqui:
 *
 * - o cadastro e proprio e vive sob "catalogo.gerenciar", como o resto do
 *   catalogo;
 * - RN-R1 — responsavel EXISTE sem conta no painel. "Recebe, mas nao confere" e
 *   o caso real do tesoureiro que nao usa o sistema, e por isso a conta e
 *   opcional — mas quando informada e unica: duas fichas para o mesmo login
 *   fariam o escopo de conferencia responder duas coisas para a mesma pessoa;
 * - RN-R2 — o vinculo e N:N, e a chave e da PESSOA: o mesmo responsavel atende
 *   varios setores com a mesma chave;
 * - RN-R9 — excluir quem ja recebeu e recusado EM PORTUGUES, com a saida certa
 *   oferecida junto (desativar), e desativar nao toca em cobranca nenhuma.
 */

beforeEach(function (): void {
    Cenario::semearPapeis();
});

it('abre a lista para quem gerencia o catalogo', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana Tesoureira']);
    $setor = Cidade::factory()->create(['nome' => 'Setor Batalha', 'uf' => 'AL']);
    $setor->responsaveis()->sync([$ana->getKey()]);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->get('/admin/catalogo/responsaveis')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Catalogo/Responsaveis')
            ->has('responsaveis', 1)
            ->where('responsaveis.0.nome', 'Ana Tesoureira')
            ->where('responsaveis.0.apto', true)
            ->where('responsaveis.0.cobrancas', 0)
            ->where('responsaveis.0.setores.0.nome', 'Setor Batalha')
            ->has('setores')
            ->has('contas'));
});

it('recusa com 403 quem nao tem papel nenhum', function (): void {
    $this->actingAs(Cenario::usuarioCom())
        ->get('/admin/catalogo/responsaveis')
        ->assertForbidden();
});

it('cadastra um responsavel sem conta no painel', function (): void {
    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->post('/admin/catalogo/responsaveis', [
            'nome' => '  Ana Tesoureira  ',
            'chave_pix' => '  ana@example.com  ',
            'telefone' => '(82) 99999-1234',
        ])
        ->assertSessionHasNoErrors();

    $ana = Responsavel::query()->where('nome', 'Ana Tesoureira')->firstOrFail();

    // Os espacos da ponta somem: chave copiada com espaco junto e recusada pelo
    // banco de quem paga.
    expect($ana->chave_pix)->toBe('ana@example.com')
        ->and($ana->telefone)->toBe('(82) 99999-1234')
        // "Recebe, mas nao confere" (RN-R1).
        ->and($ana->user_id)->toBeNull()
        ->and($ana->ativo)->toBeTrue()
        ->and($ana->estaApto())->toBeTrue();
});

it('exige a chave pix, que e a razao de o cadastro existir', function (): void {
    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->post('/admin/catalogo/responsaveis', ['nome' => 'Sem Chave'])
        ->assertSessionHasErrors('chave_pix');

    expect(Responsavel::query()->count())->toBe(0);
});

it('liga o responsavel a uma conta do painel e recusa a mesma conta duas vezes', function (): void {
    $conta = User::factory()->create(['ativo' => true]);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->post('/admin/catalogo/responsaveis', [
            'nome' => 'Ana Tesoureira',
            'chave_pix' => 'ana@example.com',
            'user_id' => $conta->getKey(),
        ])
        ->assertSessionHasNoErrors();

    // A segunda ficha para o MESMO login e recusada em portugues, antes de o
    // unico parcial do banco reclamar.
    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->post('/admin/catalogo/responsaveis', [
            'nome' => 'Ana de Novo',
            'chave_pix' => 'outra@example.com',
            'user_id' => $conta->getKey(),
        ])
        ->assertSessionHasErrors('user_id');

    expect(Responsavel::query()->count())->toBe(1);
});

it('recusa conta desativada, que nao consegue mais entrar para conferir', function (): void {
    $conta = User::factory()->create(['ativo' => false]);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->post('/admin/catalogo/responsaveis', [
            'nome' => 'Fantasma',
            'chave_pix' => 'fantasma@example.com',
            'user_id' => $conta->getKey(),
        ])
        ->assertSessionHasErrors('user_id');
});

it('vincula o mesmo responsavel a varios setores, com a mesma chave', function (): void {
    $norte = Cidade::factory()->create(['nome' => 'Setor Norte', 'uf' => 'AL']);
    $sul = Cidade::factory()->create(['nome' => 'Setor Sul', 'uf' => 'AL']);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->post('/admin/catalogo/responsaveis', [
            'nome' => 'Ana Tesoureira',
            'chave_pix' => 'ana@example.com',
            'setores' => [$norte->getKey(), $sul->getKey()],
        ])
        ->assertSessionHasNoErrors();

    $ana = Responsavel::query()->where('nome', 'Ana Tesoureira')->firstOrFail();

    // A chave e da PESSOA, e nao do vinculo (RN-R2): os dois setores mostram a
    // mesma, e o cadastro nao foi duplicado.
    expect($ana->setores()->pluck('cidades.id')->all())
        ->toEqualCanonicalizing([$norte->getKey(), $sul->getKey()])
        ->and($norte->estaPreparadaParaReceber())->toBeTrue()
        ->and($sul->estaPreparadaParaReceber())->toBeTrue();
});

it('troca os setores atendidos na edicao', function (): void {
    $norte = Cidade::factory()->create(['nome' => 'Setor Norte', 'uf' => 'AL']);
    $sul = Cidade::factory()->create(['nome' => 'Setor Sul', 'uf' => 'AL']);

    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $ana->setores()->sync([$norte->getKey()]);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->put(route('admin.catalogo.responsaveis.update', ['responsavel' => $ana->getKey()]), [
            'nome' => 'Ana',
            'chave_pix' => $ana->chave_pix,
            'ativo' => true,
            'setores' => [$sul->getKey()],
        ])
        ->assertSessionHasNoErrors();

    expect($ana->fresh()->setores()->pluck('cidades.id')->all())->toBe([$sul->getKey()]);
});

// Um formulario que nao fala de setores nao pode desvincular a pessoa de todos
// eles por omissao: seria tirar setor do ar sem ninguem pedir.
it('nao desfaz o vinculo quando o formulario nao fala de setores', function (): void {
    $norte = Cidade::factory()->create(['nome' => 'Setor Norte', 'uf' => 'AL']);
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $ana->setores()->sync([$norte->getKey()]);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->put(route('admin.catalogo.responsaveis.update', ['responsavel' => $ana->getKey()]), [
            'nome' => 'Ana Maria',
            'chave_pix' => $ana->chave_pix,
            'ativo' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($ana->fresh()->nome)->toBe('Ana Maria')
        ->and($ana->fresh()->setores()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// RN-R9 — os dois lados
// ---------------------------------------------------------------------------

it('recusa excluir responsavel que ja recebeu, oferecendo desativar', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);

    // Uma cobranca ja aponta para ela. Nao passa por inscricao nenhuma aqui: o
    // que importa e a chave estrangeira, e ela e a mesma.
    $inscricao = DB::table('inscricoes')->orderBy('id')->first();

    $ana->pagamentos()->create([
        'inscricao_id' => $inscricao?->id ?? cobrancaMinimaParaResponsavel(),
        'gateway' => 'setor',
        'metodo' => 'pix',
        'valor_centavos' => 10000,
        'situacao' => 'pendente',
    ]);

    $resposta = $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->delete(route('admin.catalogo.responsaveis.destroy', ['responsavel' => $ana->getKey()]));

    $resposta->assertSessionHasErrors('exclusao');

    expect((string) session('errors')->first('exclusao'))
        ->toContain('não pode ser excluído')
        ->toContain('Desative')
        ->and(Responsavel::query()->whereKey($ana->getKey())->exists())->toBeTrue();
});

it('desativa sem tocar na cobranca ja emitida', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);

    $cobranca = $ana->pagamentos()->create([
        'inscricao_id' => cobrancaMinimaParaResponsavel(),
        'gateway' => 'setor',
        'metodo' => 'pix',
        'valor_centavos' => 10000,
        'situacao' => 'pendente',
    ]);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->put(route('admin.catalogo.responsaveis.update', ['responsavel' => $ana->getKey()]), [
            'nome' => 'Ana',
            'chave_pix' => $ana->chave_pix,
            'ativo' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($ana->fresh()->ativo)->toBeFalse()
        ->and($ana->fresh()->estaApto())->toBeFalse()
        // A cobranca continua apontando para quem recebeu: foi ele quem
        // recebeu, e isso nao muda depois.
        ->and((int) $cobranca->fresh()->responsavel_id)->toBe((int) $ana->getKey());
});

it('exclui quem nunca recebeu nada', function (): void {
    $novato = Responsavel::factory()->semConta()->create(['nome' => 'Novato']);

    $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->delete(route('admin.catalogo.responsaveis.destroy', ['responsavel' => $novato->getKey()]))
        ->assertSessionHasNoErrors();

    expect(Responsavel::query()->whereKey($novato->getKey())->exists())->toBeFalse();
});

/**
 * Uma inscricao qualquer, so para a cobranca ter em que se pendurar.
 *
 * Os testes de RN-R9 falam de chave estrangeira, e nao de dominio: montar um
 * evento inteiro para provar que o banco recusa um DELETE seria trocar o que se
 * prova pelo caminho ate ele.
 */
function cobrancaMinimaParaResponsavel(): int
{
    $inscricao = Tests\Feature\Inscricoes\Cenario::montar()->inscrever();

    return (int) $inscricao->getKey();
}

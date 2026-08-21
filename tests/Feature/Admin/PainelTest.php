<?php

declare(strict_types=1);

use App\Enums\MetodoPagamento;
use App\Enums\SituacaoEvento;
use App\Enums\SituacaoPagamento;
use App\Models\Atividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Admin\NumerosDoEvento;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;

/**
 * Cria uma cobranca ja na situacao pedida, sem passar pelas Actions: aqui o
 * que interessa e o retrato final no banco, nao o caminho ate ele.
 */
function cobrancaDe(Inscricao $inscricao, SituacaoPagamento $situacao, int $valorCentavos, ?int $estornado = null): Pagamento
{
    return Pagamento::query()->create([
        'inscricao_id' => $inscricao->id,
        'gateway' => 'fake',
        'id_externo' => 'ext-'.uniqid(),
        'metodo' => MetodoPagamento::Pix,
        'valor_centavos' => $valorCentavos,
        'situacao' => $situacao,
        'pago_em' => $situacao === SituacaoPagamento::Pago ? Carbon::now() : null,
        'estornado_em' => $situacao === SituacaoPagamento::Estornado ? Carbon::now() : null,
        'valor_estornado_centavos' => $estornado,
    ]);
}

/**
 * Um evento com dois dias de programacao, tres atividades, cinco inscricoes em
 * situacoes diferentes e cobrancas paga, pendente e estornada.
 *
 * @return array{evento: Evento, outro: Evento}
 */
function eventoComNumeros(): array
{
    $evento = Evento::factory()->create([
        'nome' => 'Encontro de Agosto',
        'slug' => 'encontro-de-agosto',
        'capacidade' => 100,
        'data_inicio' => Carbon::now()->addMonths(2)->toDateString(),
        'data_fim' => Carbon::now()->addMonths(2)->addDay()->toDateString(),
    ]);

    $dia = DiaEvento::factory()->for($evento)->create(['nome' => 'Sábado', 'posicao' => 1]);
    $grupo = GrupoAtividade::factory()->for($dia, 'diaEvento')->obrigatorio(1, 2)->create(['nome' => 'Manhã', 'posicao' => 1]);

    Atividade::factory()->for($grupo, 'grupoAtividade')->comCapacidade(30)->create([
        'nome' => 'Futebol',
        'posicao' => 1,
        'vagas_reservadas' => 4,
        'vagas_confirmadas' => 6,
    ]);

    Atividade::factory()->for($grupo, 'grupoAtividade')->create([
        'nome' => 'Roda de conversa',
        'posicao' => 2,
        'capacidade' => null,
        'vagas_reservadas' => 2,
        'vagas_confirmadas' => 3,
    ]);

    Atividade::factory()->for($grupo, 'grupoAtividade')->comCapacidade(10)->create([
        'nome' => 'Oficina lotada',
        'posicao' => 3,
        'vagas_reservadas' => 0,
        'vagas_confirmadas' => 10,
    ]);

    $confirmada = Inscricao::factory()->for($evento)->confirmada()->create(['valor_centavos' => 15000]);
    $outraConfirmada = Inscricao::factory()->for($evento)->confirmada()->create(['valor_centavos' => 15000]);
    $aguardando = Inscricao::factory()->for($evento)->create(['valor_centavos' => 15000]);
    Inscricao::factory()->for($evento)->expirada()->create(['valor_centavos' => 15000]);
    $cancelada = Inscricao::factory()->for($evento)->cancelada()->create(['valor_centavos' => 15000]);

    cobrancaDe($confirmada, SituacaoPagamento::Pago, 15000);
    cobrancaDe($outraConfirmada, SituacaoPagamento::Pago, 15000);
    cobrancaDe($aguardando, SituacaoPagamento::Pendente, 15000);
    cobrancaDe($cancelada, SituacaoPagamento::Estornado, 15000, 15000);

    // Um segundo evento, com uma inscricao paga, so para provar que os numeros
    // de um evento nao vazam para o outro.
    $outro = Evento::factory()->create([
        'nome' => 'Outro evento',
        'slug' => 'outro-evento',
        'data_inicio' => Carbon::now()->addMonths(6)->toDateString(),
        'data_fim' => Carbon::now()->addMonths(6)->addDay()->toDateString(),
    ]);

    $doOutro = Inscricao::factory()->for($outro)->confirmada()->create(['valor_centavos' => 99000]);
    cobrancaDe($doOutro, SituacaoPagamento::Pago, 99000);

    return ['evento' => $evento, 'outro' => $outro];
}

it('conta as inscricoes de cada situacao, e so as do evento pedido', function (): void {
    ['evento' => $evento] = eventoComNumeros();

    $numeros = app(NumerosDoEvento::class)->inscricoesPorSituacao($evento);

    expect($numeros['total'])->toBe(5);

    $porSituacao = collect($numeros['por_situacao'])->keyBy('situacao');

    expect($porSituacao['aguardando_pagamento']['total'])->toBe(1)
        ->and($porSituacao['confirmada']['total'])->toBe(2)
        ->and($porSituacao['expirada']['total'])->toBe(1)
        ->and($porSituacao['cancelada']['total'])->toBe(1)
        ->and($porSituacao['lista_espera']['total'])->toBe(0)
        ->and($porSituacao['confirmada']['rotulo'])->toBe('Confirmada');
});

it('le a vaga restante do contador da atividade, sem recontar escolhas', function (): void {
    ['evento' => $evento] = eventoComNumeros();

    $vagas = collect(app(NumerosDoEvento::class)->vagasPorAtividade($evento))->keyBy('atividade');

    expect($vagas)->toHaveCount(3);

    expect($vagas['Futebol']['capacidade'])->toBe(30)
        ->and($vagas['Futebol']['reservadas'])->toBe(4)
        ->and($vagas['Futebol']['confirmadas'])->toBe(6)
        ->and($vagas['Futebol']['ocupadas'])->toBe(10)
        ->and($vagas['Futebol']['restantes'])->toBe(20);

    // Sem limite de vagas: "restantes" e nulo, que e diferente de zero.
    expect($vagas['Roda de conversa']['capacidade'])->toBeNull()
        ->and($vagas['Roda de conversa']['restantes'])->toBeNull()
        ->and($vagas['Roda de conversa']['ocupadas'])->toBe(5);

    expect($vagas['Oficina lotada']['restantes'])->toBe(0);
});

it('soma o dinheiro recebido, o pendente e o estornado', function (): void {
    ['evento' => $evento] = eventoComNumeros();

    $dinheiro = app(NumerosDoEvento::class)->dinheiro($evento);

    expect($dinheiro['recebido_centavos'])->toBe(30000)
        ->and($dinheiro['pendente_centavos'])->toBe(15000)
        ->and($dinheiro['estornado_centavos'])->toBe(15000)
        ->and($dinheiro['pagamentos_pagos'])->toBe(2)
        ->and($dinheiro['pagamentos_pendentes'])->toBe(1);
});

it('nao mistura o dinheiro de um evento com o de outro', function (): void {
    ['outro' => $outro] = eventoComNumeros();

    $dinheiro = app(NumerosDoEvento::class)->dinheiro($outro);

    expect($dinheiro['recebido_centavos'])->toBe(99000)
        ->and($dinheiro['pendente_centavos'])->toBe(0);
});

it('mostra zeros, e nao tela vazia, para um evento sem inscricao nenhuma', function (): void {
    $vazio = Evento::factory()->create(['nome' => 'Ainda sem gente', 'slug' => 'ainda-sem-gente']);

    $numeros = app(NumerosDoEvento::class)->paraEvento($vazio);

    expect($numeros['inscricoes']['total'])->toBe(0)
        ->and($numeros['inscricoes']['por_situacao'])->toHaveCount(5)
        ->and($numeros['vagas'])->toBe([])
        ->and($numeros['dinheiro']['recebido_centavos'])->toBe(0)
        ->and($numeros['dinheiro']['pendente_centavos'])->toBe(0)
        ->and($numeros['dinheiro']['estornado_centavos'])->toBe(0);
});

it('entrega os tres blocos de numeros para a tela do painel', function (): void {
    Cenario::semearPapeis();
    ['evento' => $evento] = eventoComNumeros();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/painel?evento='.$evento->id)
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Painel')
            ->where('evento.id', $evento->id)
            ->where('evento.nome', 'Encontro de Agosto')
            ->where('numeros.inscricoes.total', 5)
            ->where('numeros.dinheiro.recebido_centavos', 30000)
            ->where('numeros.dinheiro.pendente_centavos', 15000)
            ->has('numeros.vagas', 3)
            ->has('eventos', 2)
        );
});

it('abre no evento mais recente que nao seja rascunho', function (): void {
    Cenario::semearPapeis();
    ['outro' => $outro] = eventoComNumeros();

    Evento::factory()->create([
        'nome' => 'Rascunho do ano que vem',
        'slug' => 'rascunho-do-ano-que-vem',
        'situacao' => SituacaoEvento::Rascunho,
        'data_inicio' => Carbon::now()->addYear()->toDateString(),
        'data_fim' => Carbon::now()->addYear()->addDay()->toDateString(),
    ]);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/painel')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('evento.id', $outro->id)
            ->has('eventos', 2)
        );
});

it('nao quebra quando nao existe evento publicado', function (): void {
    Cenario::semearPapeis();

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/painel')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('evento', null)
            ->where('numeros', null)
            ->has('eventos', 0)
        );
});

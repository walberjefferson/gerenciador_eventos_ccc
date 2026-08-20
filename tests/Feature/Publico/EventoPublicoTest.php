<?php

declare(strict_types=1);

use App\Enums\SituacaoEvento;
use App\Models\Atividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Monta um evento com um dia, um bloco obrigatorio e duas atividades.
 */
function eventoPublicoDeExemplo(): Evento
{
    $evento = Evento::factory()->create([
        'nome' => 'Caminhada 2026',
        'slug' => 'caminhada-2026',
    ]);

    $dia = DiaEvento::factory()->for($evento)->create([
        'nome' => 'Sábado',
        'posicao' => 1,
    ]);

    $grupo = GrupoAtividade::factory()->for($dia, 'diaEvento')->obrigatorio(1, 2)->create([
        'nome' => 'Modalidades da manhã',
    ]);

    Atividade::factory()->for($grupo, 'grupoAtividade')->create([
        'nome' => 'Futebol',
        'posicao' => 1,
    ]);

    Atividade::factory()->for($grupo, 'grupoAtividade')->comCapacidade(10)->create([
        'nome' => 'Vôlei',
        'posicao' => 2,
    ]);

    return $evento;
}

it('mostra a pagina publica do evento com programacao, valor e regulamento', function (): void {
    eventoPublicoDeExemplo();

    $this->get('/eventos/caminhada-2026')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Eventos/Show')
            ->where('evento.nome', 'Caminhada 2026')
            ->where('evento.slug', 'caminhada-2026')
            ->where('evento.valor_centavos', 15000)
            ->where('evento.regulamento', 'Regulamento de teste.')
            ->where('evento.inscricoes_abertas', true)
            ->where('evento.motivo_inscricoes_fechadas', null)
            ->has('evento.dias', 1)
            ->where('evento.dias.0.nome', 'Sábado')
            ->has('evento.dias.0.grupos', 1)
            ->where('evento.dias.0.grupos.0.regra_rotulo', 'Escolha de 1 a 2 atividades.')
            ->has('evento.dias.0.grupos.0.atividades', 2)
            ->where('evento.dias.0.grupos.0.atividades.0.nome', 'Futebol')
            ->where('evento.dias.0.grupos.0.atividades.1.vagas_disponiveis', 10)
            ->where('evento.dias.0.grupos.0.atividades.1.esgotado', false)
        );
});

it('entrega vagas_disponiveis ja calculado a partir da capacidade', function (): void {
    $evento = eventoPublicoDeExemplo();

    Evento::query()->whereKey($evento->id)->update([
        'capacidade' => 100,
        'vagas_reservadas' => 30,
        'vagas_confirmadas' => 25,
    ]);

    $this->get('/eventos/caminhada-2026')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('evento.capacidade', 100)
            ->where('evento.vagas_disponiveis', 45)
            ->where('evento.esgotado', false)
        );
});

it('nao vaza contadores internos nem configuracoes do evento', function (): void {
    eventoPublicoDeExemplo();

    $resposta = $this->get('/eventos/caminhada-2026')->assertOk();

    $resposta->assertInertia(fn (Assert $pagina) => $pagina
        ->missing('evento.vagas_reservadas')
        ->missing('evento.vagas_confirmadas')
        ->missing('evento.configuracoes')
        ->missing('evento.dias.0.grupos.0.atividades.0.vagas_reservadas')
        ->missing('evento.dias.0.grupos.0.atividades.0.vagas_confirmadas')
        ->missing('evento.dias.0.grupos.0.atividades.0.configuracoes')
        ->etc()
    );

    $conteudo = $resposta->getContent();

    expect($conteudo)->not->toContain('documento')
        ->and($conteudo)->not->toContain('vagas_reservadas')
        ->and($conteudo)->not->toContain('vagas_confirmadas');
});

it('esconde dias, blocos e atividades desativados', function (): void {
    $evento = eventoPublicoDeExemplo();

    DiaEvento::factory()->for($evento)->inativo()->create(['nome' => 'Dia escondido', 'posicao' => 2]);

    $dia = $evento->diasEvento()->first();
    GrupoAtividade::factory()->for($dia, 'diaEvento')->create(['nome' => 'Bloco escondido', 'ativo' => false]);

    $grupo = $dia->gruposAtividades()->where('ativo', true)->first();
    Atividade::factory()->for($grupo, 'grupoAtividade')->create(['nome' => 'Atividade escondida', 'ativo' => false]);

    $this->get('/eventos/caminhada-2026')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('evento.dias', 1)
            ->has('evento.dias.0.grupos', 1)
            ->has('evento.dias.0.grupos.0.atividades', 2)
        );
});

it('responde 404 para evento em rascunho', function (): void {
    Evento::factory()->rascunho()->create(['slug' => 'ainda-em-rascunho']);

    $this->get('/eventos/ainda-em-rascunho')->assertNotFound();
});

it('responde 404 para evento cancelado', function (): void {
    Evento::factory()->create([
        'slug' => 'evento-cancelado',
        'situacao' => SituacaoEvento::Cancelado,
    ]);

    $this->get('/eventos/evento-cancelado')->assertNotFound();
});

it('responde 404 para slug que nao existe', function (): void {
    $this->get('/eventos/nao-existe')->assertNotFound();
});

it('explica que as inscricoes ainda nao comecaram', function (): void {
    Evento::factory()->inscricoesAindaNaoAbriram()->create([
        'slug' => 'ainda-nao-abriu',
        'situacao' => SituacaoEvento::Publicado,
    ]);

    $this->get('/eventos/ainda-nao-abriu')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('evento.inscricoes_abertas', false)
            ->where('evento.motivo_inscricoes_fechadas', fn (?string $motivo) => str_contains((string) $motivo, 'ainda não começaram'))
        );
});

it('explica que o prazo de inscricao terminou', function (): void {
    Evento::factory()->inscricoesEncerradas()->create(['slug' => 'prazo-vencido']);

    $this->get('/eventos/prazo-vencido')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('evento.inscricoes_abertas', false)
            ->where('evento.motivo_inscricoes_fechadas', fn (?string $motivo) => str_contains((string) $motivo, 'prazo para se inscrever terminou'))
        );
});

it('explica que as vagas acabaram', function (): void {
    $evento = eventoPublicoDeExemplo();

    Evento::query()->whereKey($evento->id)->update([
        'capacidade' => 10,
        'vagas_reservadas' => 4,
        'vagas_confirmadas' => 6,
    ]);

    $this->get('/eventos/caminhada-2026')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('evento.vagas_disponiveis', 0)
            ->where('evento.esgotado', true)
            ->where('evento.inscricoes_abertas', false)
            ->where('evento.motivo_inscricoes_fechadas', 'Todas as vagas deste evento já foram preenchidas.')
        );
});

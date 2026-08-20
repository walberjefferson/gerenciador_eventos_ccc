<?php

declare(strict_types=1);

use App\Enums\SituacaoEvento;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\ConflitoAtividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use App\Models\GrupoParticipante;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Evento com um bloco obrigatorio, duas atividades e um conflito declarado
 * entre elas — o cenario que o formulario precisa saber explicar.
 */
function eventoComFormulario(): Evento
{
    $evento = Evento::factory()->create([
        'nome' => 'Caminhada 2026',
        'slug' => 'caminhada-2026',
    ]);

    $dia = DiaEvento::factory()->for($evento)->create(['nome' => 'Sábado', 'posicao' => 1]);
    $grupo = GrupoAtividade::factory()->for($dia, 'diaEvento')->obrigatorio(1, 2)->create();

    $futebol = Atividade::factory()->for($grupo, 'grupoAtividade')->create(['nome' => 'Futebol', 'posicao' => 1]);
    $volei = Atividade::factory()->for($grupo, 'grupoAtividade')->create(['nome' => 'Vôlei', 'posicao' => 2]);

    ConflitoAtividade::factory()->create([
        'atividade_a_id' => $futebol->id,
        'atividade_b_id' => $volei->id,
    ]);

    return $evento;
}

it('abre o formulario com evento, cidades, grupos e conflitos', function (): void {
    eventoComFormulario();

    $sabara = Cidade::factory()->create(['nome' => 'Sabará', 'uf' => 'MG']);
    $outra = Cidade::factory()->create(['nome' => 'Caeté', 'uf' => 'MG']);

    GrupoParticipante::factory()->for($sabara)->create(['nome' => 'Grupo Sagrada Família']);
    GrupoParticipante::factory()->for($outra)->create(['nome' => 'Grupo São José']);

    $this->get('/eventos/caminhada-2026/inscricao')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inscricoes/Criar')
            ->where('evento.slug', 'caminhada-2026')
            ->has('evento.dias.0.grupos.0.atividades', 2)
            ->has('cidades', 2)
            ->where('cidades.0.rotulo', 'Caeté (MG)')
            ->has('grupos_participantes', 2)
            ->where('grupos_participantes.0.cidade_id', $sabara->id)
            ->has('conflitos', 1)
            ->has('conflitos.0.atividade_a_id')
            ->has('conflitos.0.atividade_b_id')
        );
});

it('nao lista cidade nem grupo desativado', function (): void {
    eventoComFormulario();

    $ativa = Cidade::factory()->create(['nome' => 'Sabará', 'uf' => 'MG']);
    $inativa = Cidade::factory()->create(['nome' => 'Nova Lima', 'uf' => 'MG', 'ativo' => false]);

    GrupoParticipante::factory()->for($ativa)->create(['nome' => 'Ativo']);
    GrupoParticipante::factory()->for($ativa)->create(['nome' => 'Desativado', 'ativo' => false]);
    GrupoParticipante::factory()->for($inativa)->create(['nome' => 'De cidade inativa']);

    $this->get('/eventos/caminhada-2026/inscricao')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('cidades', 1)
            ->where('cidades.0.nome', 'Sabará')
            ->has('grupos_participantes', 1)
            ->where('grupos_participantes.0.nome', 'Ativo')
        );
});

it('nao vaza contadores internos das atividades no formulario', function (): void {
    eventoComFormulario();

    $resposta = $this->get('/eventos/caminhada-2026/inscricao')->assertOk();

    expect($resposta->getContent())
        ->not->toContain('vagas_reservadas')
        ->not->toContain('vagas_confirmadas')
        ->not->toContain('documento_hash');
});

it('devolve o visitante para a pagina do evento quando as inscricoes estao fechadas', function (): void {
    $evento = eventoComFormulario();
    $evento->update(['situacao' => SituacaoEvento::InscricoesEncerradas]);

    $this->get('/eventos/caminhada-2026/inscricao')
        ->assertRedirect('/eventos/caminhada-2026');
});

it('responde 404 para evento em rascunho', function (): void {
    $evento = eventoComFormulario();
    $evento->update(['situacao' => SituacaoEvento::Rascunho]);

    $this->get('/eventos/caminhada-2026/inscricao')->assertNotFound();
});

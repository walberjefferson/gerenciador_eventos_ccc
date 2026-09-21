<?php

declare(strict_types=1);

use App\Enums\AcaoAuditada;
use App\Enums\Sexo;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use App\Models\LogAuditoria;
use App\Models\Responsavel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * A correcao de uma inscricao que ja existe.
 *
 * Duas perguntas atravessam todos os casos daqui. A primeira e de cadastro:
 * consigo consertar o nome errado, o e-mail trocado e o grupo escolhido por
 * engano? A segunda e de vaga, e e a dificil: quando alguem troca de atividade,
 * os contadores precisam acompanhar — nem vaga presa por atividade que a pessoa
 * nao faz mais, nem atividade escolhida sem vaga presa.
 *
 * O CPF nao aparece em nenhum caminho: nem no formulario, nem na resposta, nem
 * na auditoria.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

/**
 * O corpo do formulario de edicao, ja preenchido com o que a inscricao tem
 * hoje. Cada teste diz apenas o que quer mudar.
 *
 * @param  array<string, mixed>  $sobrescritas
 * @return array<string, mixed>
 */
function edicaoDe(Inscricao $inscricao, array $sobrescritas = []): array
{
    return array_merge([
        'nome_completo' => (string) $inscricao->nome_completo,
        'email' => (string) $inscricao->email,
        'telefone' => (string) $inscricao->telefone,
        'data_nascimento' => $inscricao->data_nascimento?->toDateString(),
        'sexo' => $inscricao->sexo?->value ?? Sexo::Masculino->value,
        'grupo_participante_id' => $inscricao->grupo_participante_id,
        'atividades' => $inscricao->atividadeIdsOrdenados(),
    ], $sobrescritas);
}

/** Quanto uma atividade tem em cada contador, lido direto do banco. */
function contadoresDe(Atividade $atividade): array
{
    $linha = DB::table('atividades')->where('id', $atividade->id)->first();

    return ['reservadas' => (int) $linha->vagas_reservadas, 'confirmadas' => (int) $linha->vagas_confirmadas];
}

it('abre o formulario com os dados da inscricao e sem CPF', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}/editar");

    $resposta->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Inscricoes/Editar')
            ->where('inscricao.nome_completo', 'Maria da Silva')
            ->where('inscricao.atividades', [$cenario->futebol->id])
            ->has('grupos')
            ->has('dias')
            ->missing('inscricao.documento')
            ->missing('inscricao.documento_hash')
            ->etc());

    // Nem o numero, nem a impressao digital dele.
    expect($resposta->getContent())->not->toContain('52998224725');
});

it('corrige os dados pessoais e o grupo, e o setor vem junto', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // Outro setor, com grupo proprio: trocar o grupo e trocar o setor num gesto
    // so, porque o setor nao e coluna da inscricao — ele vem pelo grupo.
    $outroSetor = Cidade::factory()->create(['uf' => 'SP']);
    $outroGrupo = GrupoParticipante::factory()->for($outroSetor)->create(['nome' => 'Grupo do Vizinho']);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'nome_completo' => 'Maria da Silva Sauro',
            'email' => '  MARIA.NOVA@EXAMPLE.COM ',
            'telefone' => '(16) 90000-1111',
            'data_nascimento' => '1990-05-04',
            'sexo' => Sexo::Feminino->value,
            'grupo_participante_id' => $outroGrupo->id,
        ]))
        ->assertRedirect("/admin/inscricoes/{$inscricao->id}");

    $inscricao->refresh();

    expect($inscricao->nome_completo)->toBe('Maria da Silva Sauro')
        // Minusculo e sem espacos nas pontas, como na criacao.
        ->and($inscricao->email)->toBe('maria.nova@example.com')
        ->and($inscricao->telefone)->toBe('(16) 90000-1111')
        ->and($inscricao->data_nascimento->toDateString())->toBe('1990-05-04')
        ->and($inscricao->sexo)->toBe(Sexo::Feminino)
        ->and($inscricao->grupo_participante_id)->toBe($outroGrupo->id)
        ->and($inscricao->setor()->id)->toBe($outroSetor->id);
});

it('nao deixa o CPF ser alterado nem pelo corpo do pedido', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $documentoAntes = $inscricao->documento;

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'documento' => '11144477735',
            'documento_hash' => 'qualquer-coisa',
        ]))
        ->assertRedirect();

    $inscricao->refresh();

    // O campo nem chega a ser lido: ele nao esta no FormRequest, entao nao
    // sobrevive ao validated().
    expect($inscricao->documento)->toBe($documentoAntes);
});

it('move as vagas da atividade que sai para a que entra, aguardando pagamento', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    expect(contadoresDe($cenario->futebol)['reservadas'])->toBe(1)
        ->and(contadoresDe($cenario->volei)['reservadas'])->toBe(0);

    $eventoAntes = (int) DB::table('eventos')->where('id', $cenario->evento->id)->value('vagas_reservadas');

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'atividades' => [$cenario->volei->id],
        ]))
        ->assertRedirect();

    expect(contadoresDe($cenario->futebol)['reservadas'])->toBe(0)
        ->and(contadoresDe($cenario->volei)['reservadas'])->toBe(1);

    // RN-E5 — a vaga do evento nao se move: continua sendo a mesma pessoa
    // ocupando o mesmo lugar.
    expect((int) DB::table('eventos')->where('id', $cenario->evento->id)->value('vagas_reservadas'))
        ->toBe($eventoAntes);
});

it('mexe no contador de confirmadas quando a inscricao ja foi paga', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // O caminho do dinheiro tem teste proprio; aqui o que importa e a situacao.
    DB::table('inscricoes')->where('id', $inscricao->id)->update([
        'situacao' => 'confirmada',
        'confirmada_em' => Carbon::now(),
    ]);
    DB::table('atividades')->where('id', $cenario->futebol->id)
        ->update(['vagas_reservadas' => 0, 'vagas_confirmadas' => 1]);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao->fresh(), [
            'atividades' => [$cenario->volei->id],
        ]))
        ->assertRedirect();

    // A vaga sai de confirmadas e entra em confirmadas — nunca passa por
    // reservadas pelo caminho.
    expect(contadoresDe($cenario->futebol))->toBe(['reservadas' => 0, 'confirmadas' => 0])
        ->and(contadoresDe($cenario->volei))->toBe(['reservadas' => 0, 'confirmadas' => 1]);
});

it('nao toca contador nenhum quando a inscricao nao tem vaga presa', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // Cancelada: a vaga ja voltou quando o cancelamento aconteceu. Corrigir o
    // cadastro de quem cancelou continua sendo legitimo (RN-E8), mas nao ha
    // vaga a mover.
    DB::table('inscricoes')->where('id', $inscricao->id)->update([
        'situacao' => 'cancelada',
        'cancelada_em' => Carbon::now(),
    ]);
    DB::table('atividades')->where('id', $cenario->futebol->id)->update(['vagas_reservadas' => 0]);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao->fresh(), [
            'nome_completo' => 'Maria da Silva Corrigida',
            'atividades' => [$cenario->volei->id],
        ]))
        ->assertRedirect();

    expect($inscricao->fresh()->nome_completo)->toBe('Maria da Silva Corrigida')
        ->and(contadoresDe($cenario->futebol))->toBe(['reservadas' => 0, 'confirmadas' => 0])
        ->and(contadoresDe($cenario->volei))->toBe(['reservadas' => 0, 'confirmadas' => 0]);
});

it('nao solta e prende de novo a atividade que continuou escolhida', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever(['atividades' => [$cenario->futebol->id]]);

    // Acrescenta a trilha e mantem o futebol. Se o futebol fosse liberado e
    // preso de novo, outra pessoa poderia levar a vaga no meio do caminho — e o
    // contador ficaria igual, escondendo o defeito. Por isso a prova e a
    // capacidade apertada: com uma vaga so, soltar e prender falharia.
    DB::table('atividades')->where('id', $cenario->futebol->id)->update(['capacidade' => 1]);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(contadoresDe($cenario->futebol)['reservadas'])->toBe(1)
        ->and(contadoresDe($cenario->trilha)['reservadas'])->toBe(1);
});

it('recusa a troca que cria choque de horario, com a frase do validador', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            // Futebol 09-11 e natacao 10-12 se sobrepoem.
            'atividades' => [$cenario->futebol->id, $cenario->natacao->id],
        ]))
        ->assertSessionHasErrors('atividades');

    // Nada foi gravado: a transacao inteira voltou atras.
    expect($inscricao->fresh()->atividadeIdsOrdenados())->toBe([$cenario->futebol->id])
        ->and(contadoresDe($cenario->natacao)['reservadas'])->toBe(0);
});

it('recusa a atividade lotada e diz que da para confirmar mesmo assim', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // Trilha cheia: capacidade 1, ja ocupada por outra pessoa.
    DB::table('atividades')->where('id', $cenario->trilha->id)
        ->update(['capacidade' => 1, 'vagas_reservadas' => 1]);

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ]));

    $resposta->assertSessionHasErrors('atividades');

    $erro = session('errors')->first('atividades');

    expect($erro)->toContain('lotação esgotada')
        // A saida precisa estar escrita: sem isso, quem precisa mesmo colocar
        // mais uma pessoa acharia que o sistema simplesmente nao deixa.
        ->and($erro)->toContain('mesmo assim');

    // O contador nao subiu: a recusa desfez tudo.
    expect(contadoresDe($cenario->trilha)['reservadas'])->toBe(1)
        ->and($inscricao->fresh()->atividadeIdsOrdenados())->toBe([$cenario->futebol->id]);
});

it('sobe a lotacao quando alguem confirma, e registra o excedente na auditoria', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    DB::table('atividades')->where('id', $cenario->trilha->id)
        ->update(['capacidade' => 1, 'vagas_reservadas' => 1]);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
            'permitir_exceder_capacidade' => true,
        ]))
        ->assertRedirect()
        ->assertSessionHas('sucesso', fn (string $mensagem): bool => str_contains($mensagem, 'lotação foi excedida')
            && str_contains($mensagem, 'Trilha da Pedra')
            && str_contains($mensagem, 'de 1 para 2'));

    // Duas pessoas onde cabia uma. O banco nao aceita contador acima da
    // capacidade (CHECK "atividades_capacidade_check"), entao furar a lotacao e
    // SUBIR a lotacao: os dois numeros sobem juntos e a atividade continua
    // cheia para quem vier depois.
    $trilha = DB::table('atividades')->where('id', $cenario->trilha->id)->first();

    expect((int) $trilha->vagas_reservadas)->toBe(2)
        ->and((int) $trilha->capacidade)->toBe(2);

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::Alterou->value)
        ->where('entidade', 'inscricao')
        ->get()
        ->first(fn (LogAuditoria $log): bool => isset($log->dados['atividades']));

    expect($registro)->not->toBeNull()
        ->and($registro->dados['atividades']['lotacao_excedida'][0]['atividade'])->toBe('Trilha da Pedra')
        ->and($registro->dados['atividades']['lotacao_excedida'][0]['capacidade_antes'])->toBe(1)
        ->and($registro->dados['atividades']['lotacao_excedida'][0]['capacidade_depois'])->toBe(2)
        ->and($registro->dados['atividades']['atividades_adicionadas'])->toBe(['Trilha da Pedra']);
});

it('registra o que mudou na auditoria, sem nunca guardar o CPF', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao, [
            'nome_completo' => 'Maria Aparecida da Silva',
        ]))
        ->assertRedirect();

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::Alterou->value)
        ->where('entidade', 'inscricao')
        ->get()
        ->first(fn (LogAuditoria $log): bool => isset($log->dados['alteracoes']));

    expect($registro)->not->toBeNull()
        ->and($registro->dados['alteracoes'])->toHaveKey('nome_completo')
        ->and($registro->dados['alteracoes']['nome_completo']['depois'])->toBe('Maria Aparecida da Silva')
        // O documento nao muda e nao entra: o diff so conhece o que foi tocado.
        ->and($registro->dados['alteracoes'])->not->toHaveKey('documento')
        ->and($registro->dados['alteracoes'])->not->toHaveKey('documento_hash');

    expect(json_encode($registro->dados))->not->toContain('52998224725');
});

it('recusa quem nao tem a permissao de editar', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // A portaria alcanca uma tela so, e nao e esta.
    $this->actingAs(Cenario::usuarioCom('portaria'))
        ->get("/admin/inscricoes/{$inscricao->id}/editar")
        ->assertForbidden();

    $this->actingAs(Cenario::usuarioCom('portaria'))
        ->put("/admin/inscricoes/{$inscricao->id}", edicaoDe($inscricao))
        ->assertForbidden();
});

it('nao deixa o responsavel de um setor editar inscricao de outro', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $usuario = Cenario::usuarioCom('responsavel-setor');

    // Ele responde por OUTRO setor: a inscricao acima nao esta no alcance dele
    // (RN-S9). O recorte nao e filtro de tela — e trocar o numero na URL que
    // precisa levar 403.
    $outroSetor = Cidade::factory()->create(['uf' => 'SP']);
    $responsavel = Responsavel::factory()->create(['user_id' => $usuario->id]);
    $responsavel->setores()->attach($outroSetor->id);

    $this->actingAs($usuario)
        ->get("/admin/inscricoes/{$inscricao->id}/editar")
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Enums\SituacaoEvento;
use App\Enums\SituacaoInscricao;
use App\Events\InscricaoCriada;
use App\Exceptions\Inscricoes\InscricaoIndisponivelException;
use App\Models\Cidade;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Corresponde ao RegistrationTest exigido pelo briefing.
 *
 * Cobre o caminho feliz da inscricao e as regras RN-01, RN-02, RN-12 e RN-13.
 */
describe('criacao da inscricao', function () {
    it('cria a inscricao, congela preco e regulamento e prende as vagas', function () {
        Event::fake([InscricaoCriada::class]);

        $cenario = Cenario::montar(['valor_centavos' => 18500, 'versao_termos' => '2026.2']);

        $inscricao = $cenario->inscrever([
            'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
        ]);

        expect($inscricao->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
            ->and($inscricao->codigo_publico)->toHaveLength(26)
            ->and($inscricao->valor_centavos)->toBe(18500)
            ->and($inscricao->versao_termos)->toBe('2026.2')
            ->and($inscricao->termos_aceitos_em)->not->toBeNull()
            ->and($inscricao->atividades->pluck('id')->all())
            ->toBe([$cenario->futebol->id, $cenario->trilha->id]);

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->volei->fresh()->vagas_reservadas)->toBe(0);

        Event::assertDispatched(InscricaoCriada::class);
    });

    it('congela o prazo de pagamento a partir da configuracao do evento', function () {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $cenario = Cenario::montar(['prazo_pagamento_minutos' => 90]);
        $inscricao = $cenario->inscrever();

        expect($inscricao->prazo_pagamento?->toDateTimeString())->toBe('2026-09-01 11:30:00');

        Carbon::setTestNow();
    });

    it('guarda o CPF cifrado e uma impressao digital para comparar', function () {
        $cenario = Cenario::montar();
        $inscricao = $cenario->inscrever(['documento' => '529.982.247-25']);

        $bruto = DB::table('inscricoes')->where('id', $inscricao->id)->first();

        expect($inscricao->documento)->toBe('52998224725')
            ->and($bruto->documento)->not->toContain('52998224725')
            ->and($bruto->documento_hash)->toBe(Inscricao::hashDocumento('52998224725'))
            ->and(strlen((string) $bruto->documento_hash))->toBe(64);
    });

    it('responde 201 com os dados da inscricao pelo formulario', function () {
        $cenario = Cenario::montar();

        $resposta = $this->postJson('/inscricoes', $cenario->payload());

        $resposta->assertCreated()
            ->assertJsonPath('inscricao.situacao', 'aguardando_pagamento')
            ->assertJsonPath('inscricao.situacao_rotulo', 'Aguardando pagamento')
            ->assertJsonPath('inscricao.atividades.0.nome', 'Futebol');

        expect(Inscricao::count())->toBe(1);
    });
});

describe('RN-01 — janela de inscricao', function () {
    it('recusa antes de as inscricoes abrirem', function () {
        $cenario = Cenario::montar([
            'inscricoes_abrem_em' => Carbon::now()->addDay(),
            'inscricoes_fecham_em' => Carbon::now()->addDays(10),
        ]);

        expect(fn () => $cenario->inscrever())
            ->toThrow(InscricaoIndisponivelException::class, 'As inscrições para este evento ainda não começaram.');

        expect(Inscricao::count())->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0);
    });

    it('recusa depois de as inscricoes fecharem', function () {
        $cenario = Cenario::montar([
            'inscricoes_abrem_em' => Carbon::now()->subDays(10),
            'inscricoes_fecham_em' => Carbon::now()->subDay(),
        ]);

        expect(fn () => $cenario->inscrever())
            ->toThrow(InscricaoIndisponivelException::class, 'As inscrições para este evento estão encerradas.');
    });

    it('recusa quando o evento nao esta com inscricoes abertas, mesmo dentro do prazo', function () {
        $cenario = Cenario::montar(['situacao' => SituacaoEvento::Publicado]);

        expect(fn () => $cenario->inscrever())
            ->toThrow(InscricaoIndisponivelException::class, 'As inscrições para este evento estão encerradas.');
    });
});

describe('RN-02 — cidade e grupo compativeis', function () {
    it('recusa grupo que pertence a outra cidade', function () {
        $cenario = Cenario::montar();
        $outraCidade = Cidade::factory()->create();
        $grupoDeOutraCidade = GrupoParticipante::factory()->for($outraCidade)->create();

        expect(fn () => $cenario->inscrever(['grupo_participante_id' => $grupoDeOutraCidade->id]))
            ->toThrow(
                InscricaoIndisponivelException::class,
                'O grupo escolhido não pertence à cidade selecionada. Escolha a cidade novamente.'
            );

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(0);
    });

    it('recusa grupo desativado', function () {
        $cenario = Cenario::montar();
        $cenario->grupoParticipante->update(['ativo' => false]);

        expect(fn () => $cenario->inscrever())->toThrow(InscricaoIndisponivelException::class);
    });

    it('recusa cidade desativada', function () {
        $cenario = Cenario::montar();
        $cenario->cidade->update(['ativo' => false]);

        expect(fn () => $cenario->inscrever())->toThrow(InscricaoIndisponivelException::class);
    });
});

describe('RN-12 — envio repetido', function () {
    it('devolve a mesma inscricao e nao prende vaga de novo', function () {
        $cenario = Cenario::montar();
        $dados = $cenario->payload();

        $primeira = $cenario->inscrever($dados);
        $segunda = $cenario->inscrever($dados);

        expect($segunda->id)->toBe($primeira->id)
            ->and(Inscricao::count())->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1)
            ->and(DB::table('inscricoes_atividades')->count())->toBe(1);
    });

    it('responde 201 no primeiro envio e 200 no repetido', function () {
        $cenario = Cenario::montar();
        $payload = $cenario->payload();

        $this->postJson('/inscricoes', $payload)->assertCreated();
        $this->postJson('/inscricoes', $payload)->assertOk();

        expect(Inscricao::count())->toBe(1)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1);
    });

    it('trata duas chaves diferentes como duas inscricoes distintas', function () {
        $cenario = Cenario::montar();

        $cenario->inscrever();
        $cenario->inscrever($cenario->outraPessoa(1));

        expect(Inscricao::count())->toBe(2)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(2);
    });
});

describe('RN-13 — aceite do regulamento', function () {
    it('recusa quem nao aceitou o regulamento', function () {
        $cenario = Cenario::montar();

        expect(fn () => $cenario->inscrever(['aceite_termos' => false]))
            ->toThrow(
                InscricaoIndisponivelException::class,
                'Você precisa aceitar o regulamento do evento para continuar.'
            );

        expect(Inscricao::count())->toBe(0);
    });

    it('devolve a mensagem do aceite pelo formulario', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload(['aceite_termos' => false]))
            ->assertStatus(422)
            ->assertJsonPath('errors.aceite_termos.0', 'Você precisa aceitar o regulamento do evento para continuar.');
    });
});

describe('conferencia de formato do formulario', function () {
    it('recusa CPF impossivel com mensagem para o participante', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload(['documento' => '111.111.111-11']))
            ->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'Este CPF não parece válido. Confira os números digitados.');
    });

    it('recusa envio sem os campos obrigatorios', function () {
        $this->postJson('/inscricoes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'evento_id', 'cidade_id', 'grupo_participante_id', 'nome_completo',
                'email', 'telefone', 'documento', 'data_nascimento', 'chave_idempotencia',
            ]);
    });

    it('recusa chave de idempotencia fora do formato', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload(['chave_idempotencia' => 'clique-duplo']))
            ->assertStatus(422)
            ->assertJsonPath('errors.chave_idempotencia.0', 'Recarregue a página e envie o formulário novamente.');
    });

    it('recusa data de nascimento no futuro', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload([
            'data_nascimento' => Carbon::now()->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['data_nascimento']);
    });

    it('recusa a mesma atividade escolhida duas vezes', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload([
            'atividades' => [$cenario->futebol->id, $cenario->futebol->id],
        ]))->assertStatus(422)->assertJsonValidationErrors(['atividades.1']);
    });

    it('recusa evento inexistente', function () {
        $cenario = Cenario::montar();

        $this->postJson('/inscricoes', $cenario->payload(['evento_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonPath('errors.evento_id.0', 'Este evento não existe mais.');
    });
});

it('gera um codigo publico diferente para cada inscricao', function () {
    $cenario = Cenario::montar();

    $primeira = $cenario->inscrever();
    $segunda = $cenario->inscrever($cenario->outraPessoa(7, ['chave_idempotencia' => (string) Str::uuid()]));

    expect($primeira->codigo_publico)->not->toBe($segunda->codigo_publico);
});

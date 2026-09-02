<?php

declare(strict_types=1);

use App\Models\Atividade;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * O horário da atividade é opcional — mas em par.
 *
 * Nem toda programação tem hora marcada: um mutirão, uma caminhada, um retiro
 * acontecem "no sábado", e obrigar quem cadastra a inventar 08:00 às 17:00 é
 * pedir um dado que ninguém tem. Quando o horário falta, a data da atividade
 * passa a ser a do dia de programação a que ela pertence.
 *
 * O que este arquivo precisa deixar provado é a outra metade da regra: horário
 * pela metade — só o começo ou só o fim — é recusado, e recusado em português,
 * no campo certo, antes de o PostgreSQL precisar recusar. E o banco recusa
 * também, para que nenhum caminho por fora do formulário grave meia-verdade.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

/**
 * Os campos de uma atividade válida, no formato que o formulário manda.
 *
 * @param  array<string, mixed>  $sobrescritas
 * @return array<string, mixed>
 */
function camposDaAtividade(CenarioInscricao $cenario, array $sobrescritas = []): array
{
    return array_merge([
        'grupo_atividade_id' => $cenario->esportes->id,
        'nome' => 'Mutirão de limpeza',
        'posicao' => 9,
    ], $sobrescritas);
}

describe('RN-A1 — o horário é opcional, mas em par', function () {
    it('grava a atividade sem hora de início nem de término', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/atividades", camposDaAtividade($cenario))
            ->assertSessionHasNoErrors();

        $atividade = Atividade::query()->where('nome', 'Mutirão de limpeza')->first();

        expect($atividade)->not->toBeNull()
            ->and($atividade->comeca_em)->toBeNull()
            ->and($atividade->termina_em)->toBeNull()
            ->and($atividade->temHorario())->toBeFalse();
    });

    it('a atividade sem horário acontece na data do dia da programação', function () {
        $cenario = CenarioInscricao::montar();

        $mutirao = Atividade::factory()->for($cenario->esportes)->semHorario()->create(['nome' => 'Mutirão']);

        expect($mutirao->data()->toDateString())->toBe($cenario->dataDoDia->toDateString());
    });

    it('recusa quando só a hora de início foi informada', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/atividades", camposDaAtividade($cenario, [
                'comeca_em' => $cenario->hora(10)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('termina_em');

        expect(Atividade::query()->where('nome', 'Mutirão de limpeza')->exists())->toBeFalse();
    });

    it('recusa quando só a hora de término foi informada', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/atividades", camposDaAtividade($cenario, [
                'termina_em' => $cenario->hora(11)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('comeca_em');

        expect(Atividade::query()->where('nome', 'Mutirão de limpeza')->exists())->toBeFalse();
    });

    it('continua recusando término anterior ao início', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/atividades", camposDaAtividade($cenario, [
                'comeca_em' => $cenario->hora(11)->format('Y-m-d\TH:i'),
                'termina_em' => $cenario->hora(10)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('termina_em');
    });

    it('apaga o horário de uma atividade que tinha hora marcada', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$cenario->evento->id}/atividades/{$cenario->trilha->id}", [
                'grupo_atividade_id' => $cenario->trilha->grupo_atividade_id,
                'nome' => $cenario->trilha->nome,
                'comeca_em' => null,
                'termina_em' => null,
                'posicao' => $cenario->trilha->posicao,
            ])
            ->assertSessionHasNoErrors();

        expect($cenario->trilha->fresh()->temHorario())->toBeFalse();
    });
});

describe('o banco recusa meia-verdade', function () {
    it('o CHECK do PostgreSQL não aceita só um dos dois campos preenchidos', function () {
        $cenario = CenarioInscricao::montar();

        // Sem passar pelo formulário, direto na tabela: e o banco continua
        // recusando, que é o ponto de a regra viver também no CHECK.
        expect(fn () => DB::table('atividades')->insert([
            'grupo_atividade_id' => $cenario->esportes->id,
            'nome' => 'Metade de um horário',
            'comeca_em' => $cenario->hora(10),
            'termina_em' => null,
            'posicao' => 9,
            'ativo' => true,
            'configuracoes' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('o CHECK do PostgreSQL aceita o par inteiro em branco', function () {
        $cenario = CenarioInscricao::montar();

        DB::table('atividades')->insert([
            'grupo_atividade_id' => $cenario->esportes->id,
            'nome' => 'Dia inteiro',
            'comeca_em' => null,
            'termina_em' => null,
            'posicao' => 9,
            'ativo' => true,
            'configuracoes' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(Atividade::query()->where('nome', 'Dia inteiro')->exists())->toBeTrue();
    });
});

describe('a programação chega à tela sem horário', function () {
    it('a estrutura do evento entrega horário nulo e o total de dias', function () {
        $cenario = CenarioInscricao::montar();

        Atividade::factory()->for($cenario->trilhas)->semHorario()->create(['nome' => 'Mutirão', 'posicao' => 9]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/eventos/{$cenario->evento->id}/estrutura")
            ->assertOk()
            ->assertInertia(fn ($pagina) => $pagina
                ->component('Admin/Eventos/Estrutura')
                ->where('evento.dias_total', 1)
                ->where('dias.0.grupos.1.atividades.1.nome', 'Mutirão')
                ->where('dias.0.grupos.1.atividades.1.comeca_em', null)
                ->where('dias.0.grupos.1.atividades.1.termina_em', null)
                ->etc());
    });

    it('a tela pública não escreve horário nenhum quando ele não existe', function () {
        $cenario = CenarioInscricao::montar();

        Atividade::factory()->for($cenario->trilhas)->semHorario()->create(['nome' => 'Mutirão', 'posicao' => 9]);

        $this->get("/eventos/{$cenario->evento->slug}")
            ->assertOk()
            ->assertInertia(fn ($pagina) => $pagina
                ->where('evento.dias.0.grupos.1.atividades.1.nome', 'Mutirão')
                ->where('evento.dias.0.grupos.1.atividades.1.horario_rotulo', null)
                ->where('evento.dias.0.grupos.1.atividades.1.data', $cenario->dataDoDia->toDateString())
                ->etc());
    });
});

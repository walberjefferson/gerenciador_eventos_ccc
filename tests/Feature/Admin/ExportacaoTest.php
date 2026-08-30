<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * A exportacao da lista em CSV.
 *
 * Quatro coisas precisam ficar provadas, e cada uma protege de um jeito
 * diferente: o arquivo traz **so** o que os filtros da tela selecionaram, sai
 * **em fluxo** (nunca montado inteiro na memoria), abre **com acento certo** no
 * Excel em portugues e **nao tem CPF em coluna nenhuma**.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

/**
 * Le o corpo de uma resposta em fluxo, que so e escrito quando alguem lê.
 */
function conteudoDoCsv(TestResponse $resposta): string
{
    return $resposta->streamedContent();
}

it('recusa com 403 quem nao pode exportar', function () {
    CenarioInscricao::montar();

    $this->actingAs(Cenario::usuarioCom())
        ->get('/admin/inscricoes/exportar')
        ->assertForbidden();
});

it('sai em fluxo, e nao montado na memoria', function () {
    $cenario = CenarioInscricao::montar();
    $cenario->inscrever();

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes/exportar');

    $resposta->assertOk();

    expect($resposta->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    $resposta->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($resposta->headers->get('content-disposition'))->toContain('inscricoes-');
});

it('comeca com o BOM UTF-8 e separa as colunas por ponto e virgula', function () {
    $cenario = CenarioInscricao::montar();
    $cenario->inscrever();

    $conteudo = conteudoDoCsv(
        $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes/exportar')
    );

    // O BOM e o que faz o Excel em portugues entender que o arquivo e UTF-8.
    expect(substr($conteudo, 0, 3))->toBe("\xEF\xBB\xBF");

    $cabecalho = strtok(substr($conteudo, 3), "\n");

    expect($cabecalho)->toContain(';')
        ->and($cabecalho)->toContain('Código')
        ->and($cabecalho)->toContain('Situação')
        ->and($cabecalho)->toContain('Situação da cobrança')
        // Acento chegando inteiro e a prova de que a codificacao esta certa.
        ->and($cabecalho)->not->toContain('SituaÃ§Ã£o');
});

it('nao tem coluna de CPF nem o numero em lugar nenhum', function () {
    $cenario = CenarioInscricao::montar();
    $cenario->inscrever();

    $conteudo = conteudoDoCsv(
        $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes/exportar')
    );

    expect($conteudo)->not->toContain('52998224725')
        ->and($conteudo)->not->toContain('529.982.247-25')
        ->and(strtolower($conteudo))->not->toContain('cpf')
        ->and(strtolower($conteudo))->not->toContain('documento');
});

it('traz os dados da inscricao que o organizador ve na tela', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever($cenario->outraPessoa(1, ['nome_completo' => 'Joana Pereira']));

    $conteudo = conteudoDoCsv(
        $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes/exportar')
    );

    expect($conteudo)->toContain('Joana Pereira')
        ->and($conteudo)->toContain($inscricao->codigo_publico)
        ->and($conteudo)->toContain($cenario->evento->nome)
        ->and($conteudo)->toContain($cenario->cidade->nome.'/SP')
        ->and($conteudo)->toContain('Grupo Central')
        // As atividades escolhidas vao junto, numa coluna so.
        ->and($conteudo)->toContain('Futebol');
});

describe('os filtros da tela valem no arquivo', function () {
    it('respeita a busca por nome', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever($cenario->outraPessoa(1, ['nome_completo' => 'Joana Pereira']));
        $cenario->inscrever($cenario->outraPessoa(2, ['nome_completo' => 'Carlos Souza']));

        $conteudo = conteudoDoCsv(
            $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes/exportar?busca=joana')
        );

        expect($conteudo)->toContain('Joana Pereira')
            ->and($conteudo)->not->toContain('Carlos Souza');
    });

    it('respeita o filtro de situacao', function () {
        $cenario = CenarioInscricao::montar();
        $cancelada = $cenario->inscrever($cenario->outraPessoa(1, ['nome_completo' => 'Rita Cancelada']));
        $cenario->inscrever($cenario->outraPessoa(2, ['nome_completo' => 'Pedro Aguardando']));

        $cancelada->forceFill(['situacao' => SituacaoInscricao::Cancelada->value])->save();

        $conteudo = conteudoDoCsv(
            $this->actingAs(Cenario::usuarioCom('organizador'))
                ->get('/admin/inscricoes/exportar?situacao='.SituacaoInscricao::Cancelada->value)
        );

        expect($conteudo)->toContain('Rita Cancelada')
            ->and($conteudo)->not->toContain('Pedro Aguardando');
    });

    it('respeita o filtro de evento', function () {
        $primeiro = CenarioInscricao::montar();
        $segundo = CenarioInscricao::montar();

        $primeiro->inscrever($primeiro->outraPessoa(1, ['nome_completo' => 'Gente do Primeiro']));
        $segundo->inscrever($segundo->outraPessoa(2, ['nome_completo' => 'Gente do Segundo']));

        $conteudo = conteudoDoCsv(
            $this->actingAs(Cenario::usuarioCom('organizador'))
                ->get('/admin/inscricoes/exportar?evento_id='.$primeiro->evento->id)
        );

        expect($conteudo)->toContain('Gente do Primeiro')
            ->and($conteudo)->not->toContain('Gente do Segundo');
    });
});

it('nao volta ao banco a cada linha escrita', function () {
    $cenario = CenarioInscricao::montar();
    $organizador = Cenario::usuarioCom('organizador');

    $consultasDaExportacao = function () use ($organizador): int {
        $contador = 0;

        DB::listen(function () use (&$contador): void {
            $contador++;
        });

        $resposta = $this->actingAs($organizador)->get('/admin/inscricoes/exportar');
        $antes = $contador;
        conteudoDoCsv($resposta);

        return $contador - $antes;
    };

    $cenario->inscrever($cenario->outraPessoa(1));
    $comUma = $consultasDaExportacao();

    $cenario->inscrever($cenario->outraPessoa(2));
    $cenario->inscrever($cenario->outraPessoa(3));
    $comTres = $consultasDaExportacao();

    // Uma consulta so, com cursor aberto: tres vezes mais gente nao custa tres
    // vezes mais idas ao banco. E isso que segura um evento com dez mil linhas.
    expect($comUma)->toBe(1)
        ->and($comTres)->toBe($comUma);
});

it('desarma texto que a planilha leria como formula', function () {
    $cenario = CenarioInscricao::montar();
    $cenario->inscrever($cenario->outraPessoa(1, ['nome_completo' => '=1+1 Fulano']));

    $conteudo = conteudoDoCsv(
        $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes/exportar')
    );

    // A aspa simples na frente faz o Excel mostrar o texto em vez de calcular.
    expect($conteudo)->toContain("'=1+1 Fulano");
});

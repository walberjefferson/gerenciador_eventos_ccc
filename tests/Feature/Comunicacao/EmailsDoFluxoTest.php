<?php

declare(strict_types=1);

use App\Enums\TipoComunicacao;
use App\Events\InscricaoCancelada;
use App\Events\InscricaoConfirmada;
use App\Events\InscricaoCriada;
use App\Events\InscricaoExpirada;
use App\Mail\InscricaoCanceladaMail;
use App\Mail\InscricaoRecebidaMail;
use App\Mail\PagamentoConfirmadoMail;
use App\Mail\PrazoVencidoMail;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Inscricoes\Cenario;

/*
|--------------------------------------------------------------------------
| Um anuncio do dominio, um e-mail
|--------------------------------------------------------------------------
|
| O dominio nao sabe que existe e-mail: ele apenas anuncia o que aconteceu.
| Estes testes provam que cada anuncio faz sair exatamente uma mensagem, com o
| conteudo que a pessoa precisa e com o link assinado de sempre.
|
*/

beforeEach(function (): void {
    Mail::fake();
});

/*
| Os testes perguntam pela fila (assertQueued), e nao pelo envio imediato:
| todo e-mail desta fase implementa ShouldQueue, entao o que o codigo faz e
| enfileirar. Quem entrega e o trabalhador da fila.
*/

it('manda a cobranca por e-mail quando a inscricao e criada', function (): void {
    $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval']);
    $inscricao = $cenario->inscrever(['nome_completo' => 'Maria da Silva']);

    InscricaoCriada::dispatch($inscricao);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(InscricaoRecebidaMail::class, function (InscricaoRecebidaMail $email) use ($inscricao): bool {
        $corpo = $email->render();

        expect($email->hasTo($inscricao->email))->toBeTrue()
            ->and($email->envelope()->subject)->toBe(TipoComunicacao::InscricaoRecebida->assunto())
            ->and($email->nome)->toBe('Maria')
            ->and($email->evento)->toBe('Retiro de Carnaval')
            ->and($email->valor)->toBe('R$ 150,00')
            ->and($email->link)->toContain('/acompanhar')
            ->and($email->link)->toContain('signature=')
            ->and($corpo)->toContain('Retiro de Carnaval')
            ->and($corpo)->toContain('R$ 150,00');

        return true;
    });

    // O envio ficou registrado: e esse registro que impede a segunda copia.
    expect(ComunicacaoEnviada::query()
        ->where('inscricao_id', $inscricao->id)
        ->where('tipo', TipoComunicacao::InscricaoRecebida->value)
        ->count())->toBe(1);
});

it('manda o comprovante quando o pagamento e confirmado', function (): void {
    // A inscricao nasce pronta pela fabrica: assim o unico anuncio deste teste
    // e o da confirmacao, e a contagem de e-mails fala so do que interessa.
    $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval']);
    $inscricao = Inscricao::factory()
        ->for($cenario->evento)
        ->for($cenario->grupoParticipante)
        ->confirmada()
        ->create(['nome_completo' => 'João Batista de Souza', 'valor_centavos' => 15000]);
    $inscricao->atividades()->attach([$cenario->futebol->id, $cenario->trilha->id]);

    InscricaoConfirmada::dispatch($inscricao->fresh());

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PagamentoConfirmadoMail::class, function (PagamentoConfirmadoMail $email) use ($inscricao): bool {
        $corpo = $email->render();

        expect($email->hasTo($inscricao->email))->toBeTrue()
            ->and($email->nome)->toBe('João')
            ->and($email->valor)->toBe('R$ 150,00')
            ->and($email->codigo)->toBe($inscricao->codigo_publico)
            ->and($email->atividades)->toContain('Futebol')
            ->and($email->atividades)->toContain('Trilha da Pedra')
            ->and($email->link)->toContain('signature=')
            ->and($corpo)->toContain('Futebol')
            ->and($corpo)->toContain('confirmada');

        return true;
    });
});

it('nao manda o mesmo e-mail duas vezes quando o anuncio se repete', function (): void {
    $inscricao = Inscricao::factory()->create();

    InscricaoCriada::dispatch($inscricao);
    InscricaoCriada::dispatch($inscricao);
    InscricaoCriada::dispatch($inscricao->fresh());

    Mail::assertQueuedCount(1);
    expect(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('avisa quando o prazo vence e convida a tentar de novo', function (): void {
    $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval', 'slug' => 'retiro-de-carnaval']);
    $inscricao = Inscricao::factory()
        ->for($cenario->evento)
        ->for($cenario->grupoParticipante)
        ->expirada()
        ->create(['nome_completo' => 'Ana Clara Ribeiro']);

    InscricaoExpirada::dispatch($inscricao);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PrazoVencidoMail::class, function (PrazoVencidoMail $email) use ($inscricao): bool {
        $corpo = $email->render();

        expect($email->hasTo($inscricao->email))->toBeTrue()
            ->and($email->nome)->toBe('Ana')
            // O convite aponta para a pagina do evento, e nao para a inscricao
            // encerrada: a unica acao util agora e se inscrever de novo.
            ->and($email->link)->toContain('/eventos/retiro-de-carnaval')
            ->and($corpo)->toContain('voltou para a fila');

        return true;
    });
});

it('avisa o cancelamento sem repassar o motivo interno', function (): void {
    $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval', 'contato_email' => 'secretaria@paroquia.example']);
    $inscricao = Inscricao::factory()
        ->for($cenario->evento)
        ->for($cenario->grupoParticipante)
        ->cancelada('Participante criou confusao na edicao passada')
        ->create(['nome_completo' => 'Pedro Henrique Alves']);

    InscricaoCancelada::dispatch($inscricao, (string) $inscricao->motivo_cancelamento, null, true);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(InscricaoCanceladaMail::class, function (InscricaoCanceladaMail $email) use ($inscricao): bool {
        $corpo = $email->render();
        // A parte em texto puro, renderizada a mao: o Mailable nao expoe um
        // atalho para ela, e ela precisa ser conferida tanto quanto o HTML.
        $conteudo = $email->content();
        $texto = view((string) $conteudo->text, $conteudo->with)->render();

        expect($email->nome)->toBe('Pedro')
            ->and($email->contato)->toBe('secretaria@paroquia.example')
            ->and($corpo)->toContain('cancelada pela organização')
            // A anotacao administrativa nao aparece em nenhum dos dois corpos.
            ->and($corpo)->not->toContain('confusao')
            ->and($texto)->not->toContain('confusao')
            // Nem sequer existe caminho para ela chegar aqui: o Mailable nao
            // recebe o motivo.
            ->and(property_exists($email, 'motivo'))->toBeFalse()
            // Estava confirmada: a mensagem fala da devolucao do valor.
            ->and($corpo)->toContain('devolução do valor');

        expect($inscricao->motivo_cancelamento)->toContain('confusao');

        return true;
    });
});

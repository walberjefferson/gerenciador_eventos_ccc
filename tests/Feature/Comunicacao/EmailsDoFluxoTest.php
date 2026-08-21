<?php

declare(strict_types=1);

use App\Enums\TipoComunicacao;
use App\Events\InscricaoConfirmada;
use App\Events\InscricaoCriada;
use App\Mail\InscricaoRecebidaMail;
use App\Mail\PagamentoConfirmadoMail;
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

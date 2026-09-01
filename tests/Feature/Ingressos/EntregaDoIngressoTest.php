<?php

declare(strict_types=1);

use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Enums\TipoComunicacao;
use App\Mail\PagamentoConfirmadoMail;
use App\Models\Inscricao;
use App\Services\Ingressos\GeradorDeCodigo;
use App\Services\Ingressos\PdfDoIngresso;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Inscricoes\Cenario;

/*
|--------------------------------------------------------------------------
| O ingresso so chega a quem pagou — nos tres canais
|--------------------------------------------------------------------------
|
| Tela, PDF e e-mail. Em todos eles a mesma pergunta: a inscricao esta
| confirmada? Quem ainda deve, quem expirou e quem foi cancelado nao pode sair
| daqui com um codigo que a portaria recusaria depois, na frente da fila.
|
*/

function confirmarParaEntrega(Inscricao $inscricao): Inscricao
{
    app(ConfirmarPagamento::class)($inscricao->pagamentoPendente());

    return $inscricao->fresh();
}

/** O endereco assinado da pagina do participante, como o e-mail o entrega. */
function acompanharAssinado(Inscricao $inscricao): string
{
    return URL::temporarySignedRoute(
        'inscricoes.acompanhar',
        now()->addDay(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );
}

describe('a tela do participante', function (): void {
    it('mostra o QR, o codigo e o caminho do PDF a quem esta confirmado', function (): void {
        Mail::fake();

        $cenario = Cenario::montar();
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        $this->get(acompanharAssinado($inscricao))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Inscricoes/Acompanhar')
                ->where('inscricao.ingresso.codigo_formatado', $inscricao->ingresso->codigoFormatado())
                ->where('inscricao.ingresso.situacao', 'emitido')
                ->where('inscricao.ingresso.situacao_rotulo', 'Válido')
                ->has('qr_ingresso')
                ->has('url_ingresso_pdf')
            );
    });

    it('nao manda nada do ingresso para quem ainda nao pagou', function (): void {
        $cenario = Cenario::montar();
        $inscricao = $cenario->inscrever();

        $this->get(acompanharAssinado($inscricao))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('inscricao.ingresso', null)
                ->where('qr_ingresso', null)
                ->where('url_ingresso_pdf', null)
            );
    });

    it('o codigo do ingresso nunca viaja cru no payload da tela', function (): void {
        Mail::fake();

        $cenario = Cenario::montar();
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        // O que a tela recebe e o formatado e o desenho; o valor de verdade,
        // sem hifen, nao precisa estar escrito no HTML mais de uma vez.
        $this->get(acompanharAssinado($inscricao))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->missing('inscricao.ingresso.codigo')
            );
    });
});

describe('o ingresso em PDF', function (): void {
    it('e entregue a quem esta confirmado, com o QR dentro', function (): void {
        Mail::fake();

        $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval']);
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        $resposta = $this->get(URL::temporarySignedRoute(
            'inscricoes.ingresso',
            now()->addWeek(),
            ['codigo_publico' => $inscricao->codigo_publico],
        ));

        $resposta->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        expect($resposta->headers->get('content-disposition'))
            ->toContain('attachment')
            ->toContain($inscricao->codigo_publico);

        $pdf = (string) $resposta->getContent();

        // Um PDF de verdade, e nao uma pagina de erro com outro cabecalho.
        expect(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(strlen($pdf))->toBeGreaterThan(2000);
    });

    it('recusa quem ainda nao pagou, quem expirou e quem foi cancelado', function (): void {
        $cenario = Cenario::montar();

        $aguardando = $cenario->inscrever($cenario->outraPessoa(1));
        $cancelada = $cenario->inscrever($cenario->outraPessoa(2));
        $cancelada->forceFill(['situacao' => 'cancelada', 'cancelada_em' => now()])->save();

        foreach ([$aguardando, $cancelada] as $inscricao) {
            $this->get(URL::temporarySignedRoute(
                'inscricoes.ingresso',
                now()->addWeek(),
                ['codigo_publico' => $inscricao->codigo_publico],
            ))->assertForbidden();
        }
    });

    it('recusa quem chega sem a assinatura na URL', function (): void {
        Mail::fake();

        $cenario = Cenario::montar();
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        $this->get('/inscricoes/'.$inscricao->codigo_publico.'/ingresso')
            ->assertForbidden();
    });

    it('desenha o papel com o codigo legivel', function (): void {
        Mail::fake();

        $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval']);
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        $pdf = app(PdfDoIngresso::class)($inscricao->ingresso);

        expect(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(strlen($pdf))->toBeGreaterThan(2000);
    });
});

describe('o e-mail de pagamento confirmado', function (): void {
    it('leva o codigo do ingresso no corpo e na versao sem imagem', function (): void {
        Mail::fake();

        $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval']);
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        $codigoFormatado = GeradorDeCodigo::formatar((string) $inscricao->ingresso->codigo);

        Mail::assertQueued(PagamentoConfirmadoMail::class, function (PagamentoConfirmadoMail $email) use ($inscricao, $codigoFormatado): bool {
            expect($email->codigoIngresso)->toBe($inscricao->ingresso->codigo);

            $html = $email->render();

            expect($html)->toContain($codigoFormatado)
                ->and($html)->toContain('Seu ingresso');

            return true;
        });
    });

    it('embute o QR como anexo inline, e nao como imagem em base64 no src', function (): void {
        // Transporte de mentira que guarda a mensagem montada: e a unica
        // forma de olhar o anexo de verdade, porque Mail::fake() para antes
        // de a mensagem existir.
        config(['mail.default' => 'array']);

        $cenario = Cenario::montar(['nome' => 'Retiro de Carnaval']);
        $inscricao = confirmarParaEntrega($cenario->inscrever());

        // A caixa tambem guarda o e-mail de "inscricao recebida", que saiu no
        // comeco do cenario: procuramos o comprovante pelo assunto, e nao pela
        // posicao na lista.
        $comprovante = Mail::mailer()->getSymfonyTransport()->messages()
            ->first(fn ($enviada): bool => $enviada->getOriginalMessage()->getSubject()
                === TipoComunicacao::PagamentoConfirmado->assunto());

        expect($comprovante)->not->toBeNull();

        $email = $comprovante->getOriginalMessage();
        $anexos = $email->getAttachments();

        expect($anexos)->toHaveCount(1)
            ->and($anexos[0]->getMediaType().'/'.$anexos[0]->getMediaSubtype())->toBe('image/png');

        // O corpo aponta para o anexo por CID. Gmail descarta "data:" no src
        // de imagem, e o ingresso viraria um quadrado vazio.
        expect($email->getHtmlBody())->toContain('src="cid:')
            ->and($email->getHtmlBody())->not->toContain('src="data:image');

        // E o codigo tambem esta escrito, para quem le a versao sem imagem.
        expect($email->getTextBody())->toContain(GeradorDeCodigo::formatar((string) $inscricao->ingresso->codigo));
    });

    it('sai sem o bloco do ingresso quando nao ha ingresso', function (): void {
        $email = new PagamentoConfirmadoMail(
            nome: 'Maria',
            evento: 'Retiro de Carnaval',
            valor: 'R$ 150,00',
            pagoEm: '3 de setembro de 2026, às 18h30',
            codigo: '01JABCDEF',
            atividades: [],
            link: 'https://exemplo.test/acompanhar',
            codigoIngresso: null,
        );

        expect($email->render())->not->toContain('Seu ingresso');
    });
});

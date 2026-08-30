<?php

declare(strict_types=1);

use App\Enums\MetodoPagamento;
use App\Events\InscricaoCancelada;
use App\Events\InscricaoConfirmada;
use App\Events\InscricaoCriada;
use App\Events\InscricaoExpirada;
use App\Mail\EmailDaInscricao;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| O e-mail leva link, nao ficha cadastral
|--------------------------------------------------------------------------
|
| Mensagem de caixa de entrada e encaminhada, impressa, aberta em telefone
| emprestado e guardada por anos. Por isso nenhum dos cinco e-mails carrega
| CPF, impressao digital do CPF, telefone ou o codigo Pix inteiro: o que ela
| carrega e um link assinado, que vence e pode ser trocado. E a mesma regra do
| e-mail de acesso (D-44).
|
| Este teste renderiza os cinco corpos — HTML e texto puro — e procura por
| cada um desses dados.
|
*/

const CPF_DA_PESSOA = '52998224725';

const TELEFONE_DA_PESSOA = '(16) 98888-7777';

const PIX_COMPLETO = '00020126580014BR.GOV.BCB.PIX0136chave-secreta-do-recebedor5204000053039865802BR';

/**
 * Renderiza um e-mail em HTML e em texto puro.
 *
 * @return array{html: string, texto: string}
 */
function corposDe(EmailDaInscricao $email): array
{
    $conteudo = $email->content();

    return [
        'html' => $email->render(),
        'texto' => view((string) $conteudo->text, $conteudo->with)->render(),
    ];
}

beforeEach(function (): void {
    Mail::fake();

    $evento = Evento::factory()->create(['nome' => 'Retiro de Carnaval']);

    $this->inscricao = Inscricao::factory()
        ->for($evento)
        ->comDocumento(CPF_DA_PESSOA)
        ->create([
            'nome_completo' => 'Maria da Silva',
            'telefone' => TELEFONE_DA_PESSOA,
            'prazo_pagamento' => Carbon::now()->addHours(6),
        ]);

    $this->pagamento = Pagamento::query()->create([
        'inscricao_id' => $this->inscricao->id,
        'gateway' => 'fake',
        'id_externo' => 'cob-123',
        'metodo' => MetodoPagamento::Pix->value,
        'valor_centavos' => $this->inscricao->valor_centavos,
        'situacao' => 'pago',
        'pix_copia_e_cola' => PIX_COMPLETO,
        'pago_em' => Carbon::now(),
    ]);
});

it('nao escreve CPF, impressao digital, telefone nem Pix em nenhum dos cinco e-mails', function (): void {
    $inscricao = $this->inscricao;

    // Os quatro que nascem de anuncios do dominio.
    InscricaoCriada::dispatch($inscricao);
    InscricaoConfirmada::dispatch($inscricao, $this->pagamento);
    InscricaoExpirada::dispatch($inscricao);
    InscricaoCancelada::dispatch($inscricao, 'Anotacao interna da secretaria', null, true);

    // E o quinto, que nasce da rotina agendada.
    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();

    $proibidos = [
        'CPF' => CPF_DA_PESSOA,
        'CPF formatado' => '529.982.247-25',
        'impressao digital do CPF' => $inscricao->documento_hash,
        'telefone' => TELEFONE_DA_PESSOA,
        'telefone so com digitos' => '16988887777',
        'Pix copia e cola' => PIX_COMPLETO,
        'chave do recebedor' => 'chave-secreta-do-recebedor',
    ];

    $enviados = 0;

    Mail::assertQueued(EmailDaInscricao::class, function (EmailDaInscricao $email) use ($proibidos, &$enviados): bool {
        $corpos = corposDe($email);
        $enviados++;

        foreach ($proibidos as $nome => $valor) {
            // Assert do PHPUnit, e nao expect()->not->toContain(): so ele
            // aceita uma mensagem de falha, e aqui a mensagem importa — quem
            // ler o erro precisa saber qual dado vazou em qual mensagem.
            Assert::assertStringNotContainsString(
                (string) $valor,
                $corpos['html'],
                "O HTML de \"{$email->tipo->rotulo()}\" contem {$nome}."
            );

            Assert::assertStringNotContainsString(
                (string) $valor,
                $corpos['texto'],
                "O texto puro de \"{$email->tipo->rotulo()}\" contem {$nome}."
            );
        }

        // O que ele leva no lugar: um link assinado, que vence sozinho.
        expect($corpos['html'])->toContain('http')
            ->and($corpos['texto'])->toContain('http');

        return true;
    });

    // Os cinco foram conferidos, e nao apenas o primeiro.
    expect($enviados)->toBe(5);
});

it('nao repassa a anotacao interna do cancelamento', function (): void {
    InscricaoCancelada::dispatch($this->inscricao, 'Pessoa criou confusao na edicao passada', null, false);

    Mail::assertQueued(EmailDaInscricao::class, function (EmailDaInscricao $email): bool {
        $corpos = corposDe($email);

        Assert::assertStringNotContainsString('confusao', $corpos['html'], 'A anotacao interna vazou no HTML.');
        Assert::assertStringNotContainsString('confusao', $corpos['texto'], 'A anotacao interna vazou no texto puro.');

        return true;
    });
});

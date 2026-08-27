<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Resend\Laravel\Transport\ResendTransportFactory;

/**
 * O transporte que entrega os e-mails em producao (decisao DA-29).
 *
 * A suite nao envia nada e nao tem chave da Resend — nem deve ter. O que estes
 * testes provam e mais modesto e mais util: que o transporte **existe e e
 * construivel**. O modo de falhar que eles impedem e o pior possivel neste
 * sistema: descobrir em producao, com a fila cheia, que o mailer "resend" nao
 * esta registrado e que nenhum e-mail saiu desde o primeiro boot.
 */
it('constroi o transporte da resend sem precisar de chave real', function (): void {
    config([
        'mail.default' => 'resend',
        'resend.api_key' => 'chave-de-teste-que-nunca-sai-daqui',
    ]);

    $transporte = Mail::mailer('resend')->getSymfonyTransport();

    expect($transporte)->toBeInstanceOf(ResendTransportFactory::class);
});

it('mantem o mailer da resend declarado em config/mail.php', function (): void {
    expect(config('mail.mailers.resend.transport'))->toBe('resend');
});

it('le a chave da resend de RESEND_API_KEY', function (): void {
    // O nome da variavel importa: o painel da Resend entrega a chave chamada
    // RESEND_API_KEY, e e esse nome que vai para o stack do Portainer.
    expect(file_get_contents(config_path('services.php')))
        ->toContain("env('RESEND_API_KEY'");
});

it('nao mantem chave da resend no repositorio', function (): void {
    // Em teste a chave e nula: nada de real pode estar commitado em .env.example
    // nem embutido em config/.
    expect(config('services.resend.key'))->toBeNull();
    expect(file_get_contents(base_path('.env.example')))
        ->not->toContain('re_');
});

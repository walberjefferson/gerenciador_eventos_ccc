<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\Payments\CreatePaymentData;
use App\Enums\MetodoPagamento;
use App\Exceptions\Payments\EfiException;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use App\Services\Payments\Efi\EfiClient;
use App\Services\Payments\Efi\EfiPaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Prova, a mao e contra a Efi de verdade, que a integracao funciona.
 *
 * A suite automatizada roda sem credencial e sem certificado, de proposito:
 * ela substitui o cliente da Efi por um duplo. Isso e o que a mantem verde no
 * computador de quem desenvolve e na integracao continua — e e tambem o que
 * ela NAO consegue provar: que o certificado deste servidor e aceito, que
 * estas credenciais valem e que a chave Pix cadastrada existe.
 *
 * Este comando cobre essa metade. Ele percorre os quatro passos na ordem em
 * que eles falham na vida real — certificado, token, cobranca, codigo Pix — e
 * diz em qual deles parou. Rodar contra homologacao antes da primeira
 * implantacao evita descobrir o problema com gente tentando se inscrever.
 *
 * Ele fica travado fora de local e testing porque emite uma cobranca de
 * verdade. Em homologacao ela nao move dinheiro; em producao, moveria.
 *
 * Nada do que ele imprime pode ser copiado para um chamado e virar vazamento:
 * o token nunca aparece, o caminho do certificado nunca aparece e as
 * credenciais nunca aparecem.
 */
class DiagnosticoEfi extends Command
{
    protected $signature = 'efi:diagnostico
                            {--centavos=1 : valor da cobranca de teste, em centavos}
                            {--documento=52998224725 : CPF de quem pagaria a cobranca de teste}';

    protected $description = 'Confere, contra a Efi de verdade, se certificado, credenciais e chave Pix funcionam';

    public function handle(
        ConfiguracaoEfi $configuracao,
        EfiClient $cliente,
    ): int {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->components->error(
                'Este comando emite uma cobranca de verdade e so roda em ambiente local ou de teste.'
            );

            return self::FAILURE;
        }

        $this->components->info("Ambiente da Efi: {$configuracao->ambiente()} ({$configuracao->urlBase()})");

        if (! $this->conferirConfiguracao($configuracao)) {
            return self::FAILURE;
        }

        if (! $this->conferirCertificado($configuracao)) {
            return self::FAILURE;
        }

        if (! $this->conferirToken($cliente)) {
            return self::FAILURE;
        }

        return $this->conferirCobranca($cliente, $configuracao);
    }

    private function conferirConfiguracao(ConfiguracaoEfi $configuracao): bool
    {
        try {
            $configuracao->exigirCompleta();
        } catch (EfiException $erro) {
            $this->components->error($erro->getMessage());

            return false;
        }

        $this->components->twoColumnDetail('1. Configuracao', '<fg=green>completa</>');

        return true;
    }

    /**
     * Le o certificado e diz ate quando ele vale.
     *
     * Certificado vencido e a falha mais comum e a mais chata de diagnosticar:
     * a Efi responde com um erro de conexao que nao explica nada.
     */
    private function conferirCertificado(ConfiguracaoEfi $configuracao): bool
    {
        $conteudo = @file_get_contents($configuracao->caminhoDoCertificado());

        if ($conteudo === false) {
            $this->components->error('Nao foi possivel ler o certificado indicado em EFI_CERT_PATH.');

            return false;
        }

        $dados = @openssl_x509_parse($conteudo);

        if (! is_array($dados) || ! isset($dados['validTo_time_t'])) {
            $this->components->error(
                'O arquivo em EFI_CERT_PATH nao parece um certificado PEM. '.
                'Converta o .p12 do painel com: openssl pkcs12 -in arquivo.p12 -out arquivo.pem -nodes'
            );

            return false;
        }

        $validade = Carbon::createFromTimestamp((int) $dados['validTo_time_t']);

        if ($validade->isPast()) {
            $this->components->error("O certificado venceu em {$validade->format('d/m/Y')}.");

            return false;
        }

        $this->components->twoColumnDetail(
            '2. Certificado',
            "<fg=green>valido ate {$validade->format('d/m/Y')}</>"
        );

        return true;
    }

    private function conferirToken(EfiClient $cliente): bool
    {
        try {
            // O token e descartado logo em seguida: o que interessa e saber se
            // a Efi aceitou o certificado e as credenciais. O valor dele nao
            // aparece na tela em hipotese nenhuma.
            $cliente->esquecerToken();
            $cliente->token();
        } catch (EfiException $erro) {
            $this->components->error($erro->getMessage());

            return false;
        } catch (Throwable $erro) {
            $this->components->error('Falha inesperada ao autenticar: '.$erro->getMessage());

            return false;
        }

        $this->components->twoColumnDetail('3. Token', '<fg=green>obtido e guardado com margem de renovacao</>');

        return true;
    }

    private function conferirCobranca(EfiClient $cliente, ConfiguracaoEfi $configuracao): int
    {
        $centavos = (int) $this->option('centavos');

        if ($configuracao->ehProducao() && ! $this->confirm(
            'O ambiente configurado e PRODUCAO. A cobranca de teste sera real. Continuar?',
            false
        )) {
            $this->components->warn('Cancelado antes de emitir a cobranca.');

            return self::SUCCESS;
        }

        $gateway = new EfiPaymentGateway($cliente, $configuracao);

        try {
            $cobranca = $gateway->createPayment(new CreatePaymentData(
                externalReference: 'DIAGNOSTICO',
                amountCents: $centavos,
                currency: (string) config('payments.currency', 'BRL'),
                method: MetodoPagamento::Pix->value,
                description: 'Diagnostico da integracao',
                payerName: 'Diagnostico da Integracao',
                payerEmail: 'diagnostico@example.com',
                payerDocument: (string) $this->option('documento'),
                expiresAt: Carbon::now()->addMinutes(10),
            ));
        } catch (EfiException $erro) {
            $this->components->error($erro->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('4. Cobranca', "<fg=green>criada ({$cobranca->externalId})</>");
        $this->components->twoColumnDetail('5. Pix copia e cola', '<fg=green>recebido da Efi</>');

        $this->newLine();
        $this->line($cobranca->pixPayload ?? '');
        $this->newLine();

        $situacao = $gateway->getPayment($cobranca->externalId);

        $this->components->info(
            "Consulta da cobranca respondeu \"{$situacao->status}\". ".
            'Em homologacao, cobrancas de ate R$ 10,00 costumam ser confirmadas sozinhas em alguns instantes — '.
            'rode o comando de novo para ver o aviso chegar.'
        );

        return self::SUCCESS;
    }
}

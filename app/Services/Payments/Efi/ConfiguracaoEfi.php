<?php

declare(strict_types=1);

namespace App\Services\Payments\Efi;

use App\Exceptions\Payments\EfiException;

/**
 * O unico lugar do sistema que le a configuracao da Efi.
 *
 * Nem o gateway, nem o cliente HTTP, nem o comando de diagnostico chamam
 * config('payments.efi.*') por conta propria: todos passam por aqui. A razao e
 * a fase seguinte, que troca a fonte da configuracao do arquivo de ambiente
 * para o banco de dados, com tela de cadastro. Quando isso acontecer, muda o
 * corpo destes metodos e mais nada — nenhum outro arquivo precisa saber que a
 * credencial passou a vir de outro lugar.
 *
 * E a mesma ideia do contrato de pagamento aplicada a configuracao: uma
 * fronteira, um lugar para troca-la.
 *
 * As URLs base sao constantes, e nao configuracao, de proposito. Uma variavel
 * de ambiente errada apontando producao para homologacao seria uma cobranca
 * que ninguem consegue pagar; o contrario seria cobrar de verdade em teste.
 */
class ConfiguracaoEfi
{
    public const AMBIENTE_PRODUCAO = 'producao';

    public const AMBIENTE_HOMOLOGACAO = 'homologacao';

    private const URL_PRODUCAO = 'https://pix.api.efipay.com.br';

    private const URL_HOMOLOGACAO = 'https://pix-h.api.efipay.com.br';

    /**
     * Qual ambiente da Efi esta em uso.
     *
     * Qualquer valor que nao seja exatamente "producao" vira homologacao:
     * diante de configuracao ambigua, o lado que nao move dinheiro.
     */
    public function ambiente(): string
    {
        $valor = mb_strtolower(trim((string) config('payments.efi.environment', self::AMBIENTE_HOMOLOGACAO)));

        return $valor === self::AMBIENTE_PRODUCAO
            ? self::AMBIENTE_PRODUCAO
            : self::AMBIENTE_HOMOLOGACAO;
    }

    public function ehProducao(): bool
    {
        return $this->ambiente() === self::AMBIENTE_PRODUCAO;
    }

    public function urlBase(): string
    {
        return $this->ehProducao() ? self::URL_PRODUCAO : self::URL_HOMOLOGACAO;
    }

    public function clientId(): string
    {
        return (string) config('payments.efi.client_id', '');
    }

    public function clientSecret(): string
    {
        return (string) config('payments.efi.client_secret', '');
    }

    /**
     * Caminho do certificado mTLS. Devolve o caminho, nunca o conteudo.
     */
    public function caminhoDoCertificado(): string
    {
        return (string) config('payments.efi.cert_path', '');
    }

    /**
     * A chave Pix da conta que recebe. Sem ela nao existe cobranca.
     */
    public function chavePix(): string
    {
        return (string) config('payments.efi.pix_key', '');
    }

    /**
     * O valor conferido no parametro "hmac" da URL do webhook.
     *
     * A Efi nao envia cabecalho de assinatura: ela devolve, em toda
     * notificacao, o parametro que registramos junto com a URL. E este o
     * segredo comparado com hash_equals.
     */
    public function segredoDoWebhook(): string
    {
        return (string) config('payments.efi.webhook_hmac', '');
    }

    public function tempoLimiteEmSegundos(): int
    {
        $valor = (int) config('payments.efi.timeout', 20);

        return $valor > 0 ? $valor : 20;
    }

    /**
     * Ha credencial e certificado para operar?
     *
     * Usado por quem prefere perguntar antes de tentar — o comando de
     * diagnostico, por exemplo. Quem simplesmente opera chama exigirCompleta().
     */
    public function estaCompleta(): bool
    {
        return $this->clientId() !== ''
            && $this->clientSecret() !== ''
            && $this->chavePix() !== ''
            && $this->certificadoExiste();
    }

    public function certificadoExiste(): bool
    {
        $caminho = $this->caminhoDoCertificado();

        return $caminho !== '' && is_file($caminho) && is_readable($caminho);
    }

    /**
     * Recusa operar com configuracao incompleta, dizendo o que falta.
     *
     * Falhar aqui e barato: e um erro claro antes de qualquer chamada de rede.
     * Nao falhar seria pior — uma requisicao sem certificado e recusada pela
     * Efi com uma mensagem de TLS que nao ajuda ninguem.
     *
     * A mensagem cita o NOME da variavel que falta, nunca o valor dela nem o
     * caminho do certificado: mensagem de erro vira log, e log vira anexo de
     * chamado.
     *
     * @throws EfiException
     */
    public function exigirCompleta(): void
    {
        $faltando = [];

        if ($this->clientId() === '') {
            $faltando[] = 'EFI_CLIENT_ID';
        }

        if ($this->clientSecret() === '') {
            $faltando[] = 'EFI_CLIENT_SECRET';
        }

        if ($this->chavePix() === '') {
            $faltando[] = 'EFI_PIX_KEY';
        }

        if ($this->caminhoDoCertificado() === '') {
            $faltando[] = 'EFI_CERT_PATH';
        } elseif (! $this->certificadoExiste()) {
            throw new EfiException(
                'O certificado da Efi indicado em EFI_CERT_PATH nao foi encontrado ou nao pode ser lido.'
            );
        }

        if ($faltando !== []) {
            throw new EfiException(
                'Configuracao da Efi incompleta. Falta preencher: '.implode(', ', $faltando).'.'
            );
        }
    }
}

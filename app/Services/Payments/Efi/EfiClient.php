<?php

declare(strict_types=1);

namespace App\Services\Payments\Efi;

use App\Exceptions\Payments\EfiException;
use Efi\Auth;
use Efi\EfiPay;
use Efi\Exception\EfiException as ExcecaoDoSdk;
use Efi\Request as RequisicaoDoSdk;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * O unico lugar do sistema que conhece o SDK da Efi.
 *
 * Existe por tres razoes, nesta ordem de importancia:
 *
 * 1. O SDK usa cliente HTTP proprio. Isso significa que o Http::fake() do
 *    Laravel nao o alcanca: sem este intermediario, provar o gateway exigiria
 *    credencial e certificado de verdade, e a suite deixaria de rodar sozinha.
 *    Com ele, a suite troca este objeto por um duplo e prova tudo o que
 *    importa sem falar com ninguem.
 * 2. Autenticacao com instituicao financeira tem duas partes chatas —
 *    certificado em toda requisicao e um token que vence a cada hora — e
 *    nenhuma delas deveria aparecer no codigo que emite cobranca.
 * 3. Se um dia o SDK sair, muda um arquivo.
 *
 * Sobre o token: guardamos com margem de renovacao, e nao ate o ultimo
 * segundo. Um token que vence entre a decisao de usa-lo e a chegada da
 * requisicao ao outro lado nao devolve erro util — devolve 401 no meio de uma
 * cobranca. E a chave inclui o ambiente, porque homologacao e producao tem
 * credenciais diferentes e trocar um token pelo outro seria falha silenciosa.
 *
 * O cache proprio do SDK fica DESLIGADO de proposito: ele grava o token em
 * arquivo na pasta temporaria do sistema, fora do nosso controle de retencao.
 * Aqui o token vive no cache da aplicacao, com prazo, como qualquer segredo
 * de curta duracao.
 */
class EfiClient
{
    /**
     * A Efi devolve token com validade de uma hora.
     */
    private const VALIDADE_DO_TOKEN_EM_SEGUNDOS = 3600;

    /**
     * Quanto antes do vencimento o token e renovado.
     */
    private const MARGEM_DE_RENOVACAO_EM_SEGUNDOS = 300;

    /**
     * Nome da API dentro do SDK. Decide o conjunto de endpoints e o formato
     * das mensagens de erro.
     */
    private const API = 'PIX';

    public function __construct(
        private readonly ConfiguracaoEfi $configuracao,
        private readonly Cache $cache,
    ) {}

    public function configuracao(): ConfiguracaoEfi
    {
        return $this->configuracao;
    }

    /**
     * Cria a cobranca imediata com um identificador escolhido por nos
     * (PUT /v2/cob/:txid).
     *
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed>
     */
    public function criarCobranca(string $txid, array $corpo): array
    {
        return $this->executar(
            fn (EfiPay $efi): mixed => $efi->pixCreateCharge(['txid' => $txid], $corpo)
        );
    }

    /**
     * Consulta a cobranca (GET /v2/cob/:txid).
     *
     * @return array<string, mixed>
     */
    public function consultarCobranca(string $txid): array
    {
        return $this->executar(
            fn (EfiPay $efi): mixed => $efi->pixDetailCharge(['txid' => $txid])
        );
    }

    /**
     * Remove a cobranca (PATCH /v2/cob/:txid).
     *
     * A Efi nao apaga cobranca: ela passa a valer como removida pelo
     * recebedor, que e o que a fronteira chama de cancelada.
     *
     * @return array<string, mixed>
     */
    public function removerCobranca(string $txid): array
    {
        return $this->executar(
            fn (EfiPay $efi): mixed => $efi->pixUpdateCharge(
                ['txid' => $txid],
                ['status' => TraducaoDeStatus::REMOVIDA_PELO_RECEBEDOR]
            )
        );
    }

    /**
     * Obtem um token valido, do cache ou da Efi.
     *
     * Publico porque o comando de diagnostico precisa provar, passo a passo,
     * que o certificado e as credenciais funcionam antes de emitir cobranca.
     * O token em si nunca sai daqui para tela nem para log.
     */
    public function token(): string
    {
        $chave = $this->chaveDoToken();
        $guardado = $this->cache->get($chave);

        if (is_string($guardado) && $guardado !== '') {
            return $guardado;
        }

        $token = $this->autenticar();

        $this->cache->put(
            $chave,
            $token,
            self::VALIDADE_DO_TOKEN_EM_SEGUNDOS - self::MARGEM_DE_RENOVACAO_EM_SEGUNDOS
        );

        return $token;
    }

    /**
     * Descarta o token guardado. Usado quando a Efi recusa por credencial:
     * insistir com um token que ela ja nao aceita so gasta tentativa.
     */
    public function esquecerToken(): void
    {
        $this->cache->forget($this->chaveDoToken());
    }

    /**
     * O SDK delega a requisicao a este metodo.
     *
     * Ele existe para satisfazer o SDK, nao para ser chamado de fora: e por
     * aqui que o token do cache entra na requisicao no lugar do fluxo de
     * autenticacao interno do SDK, que grava token em arquivo.
     *
     * @param  array<string, mixed>  $corpo
     */
    public function send(string $metodo, string $rota, string $escopo, array $corpo): mixed
    {
        $requisicao = new RequisicaoDoSdk($this->opcoesDoSdk());

        return $requisicao->send($metodo, $rota, [
            'json' => $corpo === [] ? null : $corpo,
            'timeout' => $this->configuracao->tempoLimiteEmSegundos(),
            'headers' => [
                'Authorization' => 'Bearer '.$this->token(),
                'Connection' => 'Keep-Alive',
            ],
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Roda uma chamada ao SDK ja com a configuracao conferida e com o erro
     * traduzido. Nenhum metodo publico fala com o SDK sem passar por aqui.
     *
     * @param  callable(EfiPay): mixed  $chamada
     * @return array<string, mixed>
     *
     * @throws EfiException
     */
    private function executar(callable $chamada): array
    {
        $this->configuracao->exigirCompleta();

        try {
            // O segundo argumento diz ao SDK para usar este objeto como
            // transporte. E o que mantem o token e o certificado sob o nosso
            // controle sem reescrever a lista de endpoints da Efi.
            $resposta = $chamada(new EfiPay($this->opcoesDoSdk(), $this));
        } catch (ExcecaoDoSdk $erro) {
            throw $this->traduzir($erro);
        } catch (EfiException $erro) {
            throw $erro;
        } catch (Throwable $erro) {
            throw new EfiException(
                'Nao foi possivel falar com a Efi agora. Tente novamente em instantes.',
                anterior: $erro,
            );
        }

        return is_array($resposta) ? $resposta : [];
    }

    /**
     * Pede um token novo a Efi, usando o fluxo de autenticacao do SDK — que
     * ja sabe apresentar o certificado — mas sem o cache em arquivo dele.
     *
     * @throws EfiException
     */
    private function autenticar(): string
    {
        $this->configuracao->exigirCompleta();

        try {
            $autenticacao = new Auth($this->opcoesDoSdk());
            $autenticacao->authorize();

            return $autenticacao->getAccessToken();
        } catch (ExcecaoDoSdk $erro) {
            throw $this->traduzir($erro);
        } catch (Throwable $erro) {
            throw new EfiException(
                'Nao foi possivel autenticar na Efi. Confira as credenciais e o certificado.',
                anterior: $erro,
            );
        }
    }

    private function chaveDoToken(): string
    {
        // O ambiente entra na chave porque as credenciais sao diferentes em
        // cada um: um token de homologacao usado em producao seria recusado
        // exatamente na hora em que alguem esta tentando pagar.
        return 'pagamentos:efi:token:'.$this->configuracao->ambiente();
    }

    /**
     * @return array<string, mixed>
     */
    private function opcoesDoSdk(): array
    {
        return [
            'api' => self::API,
            'url' => $this->configuracao->urlBase(),
            'sandbox' => ! $this->configuracao->ehProducao(),
            'debug' => false,
            // Ver o comentario do topo: o cache do SDK grava token em arquivo.
            'cache' => false,
            'responseHeaders' => false,
            'timeout' => $this->configuracao->tempoLimiteEmSegundos(),
            'client_id' => $this->configuracao->clientId(),
            'client_secret' => $this->configuracao->clientSecret(),
            'certificate' => $this->configuracao->caminhoDoCertificado(),
            'pwdCertificate' => '',
        ];
    }

    /**
     * Traduz o erro do SDK para o nosso, guardando o codigo HTTP e o nome que
     * a Efi da ao problema — e limpando da mensagem qualquer segredo nosso que
     * ela tenha repetido de volta.
     */
    private function traduzir(ExcecaoDoSdk $erro): EfiException
    {
        $codigo = is_int($erro->code) ? $erro->code : null;
        $identificador = is_string($erro->error) ? $erro->error : null;

        return new EfiException(
            $this->semSegredo($this->mensagemDe($erro, $codigo)),
            codigoHttp: $codigo,
            identificador: $identificador,
        );
    }

    private function mensagemDe(ExcecaoDoSdk $erro, ?int $codigo): string
    {
        $descricao = is_string($erro->errorDescription) && trim($erro->errorDescription) !== ''
            ? trim($erro->errorDescription)
            : trim($erro->getMessage());

        return match ($codigo) {
            401, 403 => 'A Efi recusou as credenciais ou o certificado desta aplicacao.',
            429 => 'A Efi recebeu pedidos demais deste sistema. Tente novamente em instantes.',
            default => $descricao !== ''
                ? 'A Efi recusou a operacao: '.$descricao
                : 'A Efi recusou a operacao e nao explicou o motivo.',
        };
    }

    /**
     * Rede de seguranca: nenhuma mensagem de erro sai daqui carregando
     * credencial, chave Pix ou caminho de certificado. Mensagem de erro vira
     * log, e log vira anexo de chamado aberto para gente de fora.
     */
    private function semSegredo(string $mensagem): string
    {
        $segredos = array_filter([
            $this->configuracao->clientId(),
            $this->configuracao->clientSecret(),
            $this->configuracao->caminhoDoCertificado(),
            $this->configuracao->segredoDoWebhook(),
        ], static fn (string $valor): bool => $valor !== '');

        foreach ($segredos as $segredo) {
            $mensagem = str_replace($segredo, '[removido]', $mensagem);
        }

        return $mensagem;
    }
}

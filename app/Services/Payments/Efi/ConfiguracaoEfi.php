<?php

declare(strict_types=1);

namespace App\Services\Payments\Efi;

use App\Exceptions\Payments\EfiException;
use App\Models\CredencialPagamento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O unico lugar do sistema que le a configuracao da Efi.
 *
 * Nem o gateway, nem o cliente HTTP, nem o comando de diagnostico perguntam de
 * onde vem a credencial: todos passam por aqui. Foi para este dia que a
 * fronteira existe (DA-24) — a Fase 8b trocou a fonte da configuracao do
 * arquivo de ambiente para o banco de dados, com tela de cadastro, e **este
 * foi o unico arquivo do provedor alterado**. EfiClient e EfiPaymentGateway
 * nao souberam da mudanca.
 *
 * ## De onde vem a credencial
 *
 * **O banco tem precedencia; o arquivo de ambiente e a reserva** (DA-26).
 *
 * A precedencia e por cadastro inteiro, e nao campo a campo. A tentacao de
 * misturar — pegar o que existe no banco e completar o resto com o ambiente —
 * produziria a pior falha possivel: identificacao de um ambiente com o segredo
 * do outro, recusada pela Efi no instante em que alguem tenta pagar. Ou o
 * cadastro ativo responde por tudo, ou nao responde por nada.
 *
 * A reserva nao e um detalhe de compatibilidade: sem ela, a maquina de quem
 * desenvolve e a integracao continua precisariam de banco semeado com
 * credencial de verdade para rodar qualquer coisa.
 *
 * ## O certificado
 *
 * No banco fica o **conteudo**, cifrado (DA-25). O SDK da Efi so aceita
 * caminho de arquivo, entao caminhoDoCertificado() escreve o conteudo em
 * disco, com permissao restrita, e devolve o caminho. Esse arquivo e cache:
 * some num redeploy e e reescrito na chamada seguinte.
 *
 * ## As URLs base
 *
 * Continuam sendo constantes, e nao configuracao, de proposito. Uma linha
 * errada apontando producao para homologacao seria uma cobranca que ninguem
 * consegue pagar; o contrario seria cobrar de verdade em teste.
 */
class ConfiguracaoEfi
{
    public const AMBIENTE_PRODUCAO = 'producao';

    public const AMBIENTE_HOMOLOGACAO = 'homologacao';

    /**
     * O comeco da chave sob a qual o token da Efi fica guardado.
     *
     * Mora aqui, e nao so dentro do EfiClient, porque quem troca a credencial
     * precisa jogar fora o token da credencial antiga — e quem troca a
     * credencial e a tela, que nao conhece o cliente HTTP. Ha um teste que
     * prova, pelo comportamento, que esta chave e exatamente a mesma que o
     * EfiClient usa: se um dos dois mudar sozinho, ele fica vermelho.
     */
    public const PREFIXO_CACHE_DO_TOKEN = 'pagamentos:efi:token:';

    private const URL_PRODUCAO = 'https://pix.api.efipay.com.br';

    private const URL_HOMOLOGACAO = 'https://pix-h.api.efipay.com.br';

    /**
     * O cadastro ativo, lido uma vez por instancia.
     *
     * Esta classe e registrada como singleton, entao "uma vez por instancia"
     * significa uma consulta por requisicao. Quem altera o cadastro chama
     * recarregar() e a proxima leitura ja enxerga o valor novo.
     */
    private ?CredencialPagamento $credencial = null;

    private bool $credencialLida = false;

    private ?string $caminhoMaterializado = null;

    /**
     * O cadastro que responde pela configuracao — ou nulo, quando ninguem
     * cadastrou nada e o arquivo de ambiente esta valendo.
     */
    public function credencialAtiva(): ?CredencialPagamento
    {
        if ($this->credencialLida) {
            return $this->credencial;
        }

        $this->credencialLida = true;

        try {
            $this->credencial = CredencialPagamento::ativaDe(CredencialPagamento::GATEWAY_EFI);
        } catch (Throwable $erro) {
            // A tabela pode nao existir ainda: esta classe e resolvida durante
            // migracoes e durante "config:cache", antes de qualquer schema.
            // Cair para o arquivo de ambiente e a saida certa — e o pior que
            // acontece e o provedor recusar operar por configuracao
            // incompleta, com mensagem clara.
            //
            // A mensagem do erro NAO vai para o log: ela pode repetir trechos
            // do comando SQL, e o comando SQL carrega o texto cifrado.
            Log::warning('Nao foi possivel ler a credencial de pagamento no banco. Usando o arquivo de ambiente.', [
                'gateway' => CredencialPagamento::GATEWAY_EFI,
                'tipo_do_erro' => $erro::class,
            ]);

            $this->credencial = null;
        }

        return $this->credencial;
    }

    /**
     * Diz de onde a configuracao em uso esta vindo. A tela mostra isso, e o
     * comando de diagnostico tambem: descobrir tarde que o sistema estava
     * lendo o arquivo de ambiente e uma hora perdida.
     */
    public function origem(): string
    {
        return $this->credencialAtiva() instanceof CredencialPagamento ? 'banco' : 'ambiente';
    }

    /**
     * Esquece o que foi lido e joga fora os tokens guardados.
     *
     * Chamado sempre que a credencial muda. Sem isso o sistema seguiria
     * usando o token emitido para a credencial antiga por ate uma hora — e o
     * sintoma em producao (401 intermitente que se cura sozinho) e
     * incompreensivel para quem estiver investigando.
     */
    public function recarregar(): void
    {
        $this->credencialLida = false;
        $this->credencial = null;
        $this->caminhoMaterializado = null;

        $this->esquecerTokensGuardados();
    }

    /**
     * Descarta o token dos DOIS ambientes.
     *
     * Dos dois, e nao so do que esta ativo: trocar de ambiente muda qual token
     * vale, e o que sobrou do ambiente anterior nao serve para nada.
     */
    public function esquecerTokensGuardados(): void
    {
        foreach ([self::AMBIENTE_HOMOLOGACAO, self::AMBIENTE_PRODUCAO] as $ambiente) {
            Cache::forget(self::chaveDoTokenDe($ambiente));
        }
    }

    public static function chaveDoTokenDe(string $ambiente): string
    {
        return self::PREFIXO_CACHE_DO_TOKEN.$ambiente;
    }

    /**
     * Qual ambiente da Efi esta em uso.
     *
     * Qualquer valor que nao seja exatamente "producao" vira homologacao:
     * diante de configuracao ambigua, o lado que nao move dinheiro.
     */
    public function ambiente(): string
    {
        $credencial = $this->credencialAtiva();

        $valor = $credencial instanceof CredencialPagamento
            ? $credencial->ambiente->value
            : mb_strtolower(trim((string) config('payments.efi.environment', self::AMBIENTE_HOMOLOGACAO)));

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
        return $this->valor('client_id', 'payments.efi.client_id');
    }

    public function clientSecret(): string
    {
        return $this->valor('client_secret', 'payments.efi.client_secret');
    }

    /**
     * Caminho do certificado mTLS. Devolve o caminho, nunca o conteudo.
     *
     * Quando a fonte e o banco, o arquivo e escrito na hora, com permissao
     * 0600 — ver CredencialPagamento::materializarCertificado(). O caminho
     * fica guardado na instancia para que uma requisicao que emita varias
     * cobrancas nao reescreva o arquivo a cada uma.
     */
    public function caminhoDoCertificado(): string
    {
        $credencial = $this->credencialAtiva();

        if (! $credencial instanceof CredencialPagamento) {
            return (string) config('payments.efi.cert_path', '');
        }

        if ($this->caminhoMaterializado !== null) {
            return $this->caminhoMaterializado;
        }

        return $this->caminhoMaterializado = $credencial->materializarCertificado();
    }

    /**
     * A chave Pix da conta que recebe. Sem ela nao existe cobranca.
     */
    public function chavePix(): string
    {
        return $this->valor('chave_pix', 'payments.efi.pix_key');
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
        return $this->valor('webhook_hmac', 'payments.efi.webhook_hmac');
    }

    /**
     * Continua vindo do arquivo de ambiente sempre: nao e segredo, e ninguem
     * precisa de uma tela para ajustar quantos segundos esperar.
     */
    public function tempoLimiteEmSegundos(): int
    {
        $valor = (int) config('payments.efi.timeout', 20);

        return $valor > 0 ? $valor : 20;
    }

    /**
     * Ha credencial e certificado para operar?
     *
     * Usado por quem prefere perguntar antes de tentar — o comando de
     * diagnostico e a tela de credenciais, por exemplo. Quem simplesmente
     * opera chama exigirCompleta().
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
     * A mensagem cita o NOME do que falta, nunca o valor nem o caminho do
     * certificado: mensagem de erro vira log, e log vira anexo de chamado. E o
     * nome muda com a fonte — quem cadastrou pela tela nao deve receber o nome
     * de uma variavel de ambiente que nunca viu.
     *
     * @throws EfiException
     */
    public function exigirCompleta(): void
    {
        $doBanco = $this->credencialAtiva() instanceof CredencialPagamento;

        $nomes = $doBanco
            ? [
                'client_id' => 'Identificacao da aplicacao',
                'client_secret' => 'Chave secreta da aplicacao',
                'pix_key' => 'Chave Pix da conta recebedora',
                'cert_path' => 'Certificado',
            ]
            : [
                'client_id' => 'EFI_CLIENT_ID',
                'client_secret' => 'EFI_CLIENT_SECRET',
                'pix_key' => 'EFI_PIX_KEY',
                'cert_path' => 'EFI_CERT_PATH',
            ];

        $faltando = [];

        if ($this->clientId() === '') {
            $faltando[] = $nomes['client_id'];
        }

        if ($this->clientSecret() === '') {
            $faltando[] = $nomes['client_secret'];
        }

        if ($this->chavePix() === '') {
            $faltando[] = $nomes['pix_key'];
        }

        if ($this->caminhoDoCertificado() === '') {
            $faltando[] = $nomes['cert_path'];
        } elseif (! $this->certificadoExiste()) {
            throw new EfiException(
                $doBanco
                    ? 'O certificado cadastrado da Efi nao pode ser gravado em disco para uso. '.
                        'Confira a permissao de escrita da pasta de armazenamento do sistema.'
                    : 'O certificado da Efi indicado em EFI_CERT_PATH nao foi encontrado ou nao pode ser lido.'
            );
        }

        if ($faltando !== []) {
            throw new EfiException(
                'Configuracao da Efi incompleta. Falta preencher: '.implode(', ', $faltando).'.'
            );
        }
    }

    /**
     * Le um campo do cadastro ativo, ou do arquivo de ambiente quando nao ha
     * cadastro nenhum.
     *
     * Repare que, havendo cadastro, o arquivo de ambiente **nao** completa o
     * que falta. Ver o cabecalho: a precedencia e por cadastro inteiro.
     */
    private function valor(string $campo, string $chaveDeConfiguracao): string
    {
        $credencial = $this->credencialAtiva();

        if ($credencial instanceof CredencialPagamento) {
            return (string) $credencial->getAttribute($campo);
        }

        return (string) config($chaveDeConfiguracao, '');
    }
}

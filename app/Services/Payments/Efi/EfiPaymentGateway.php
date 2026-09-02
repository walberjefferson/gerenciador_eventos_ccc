<?php

declare(strict_types=1);

namespace App\Services\Payments\Efi;

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\DTOs\Payments\PaymentResult;
use App\DTOs\Payments\PaymentStatusResult;
use App\DTOs\Payments\RefundResult;
use App\DTOs\Payments\WebhookRequestData;
use App\DTOs\Payments\WebhookResult;
use App\Exceptions\Payments\EfiException;
use App\Exceptions\Payments\EstornoNaoSuportadoException;
use App\Services\Payments\MomentoDoProvedor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * O provedor de pagamento de verdade: Efi, API Pix.
 *
 * Este arquivo TRADUZ, nunca age. Ele nao conhece inscricao, vaga, prazo nem
 * confirmacao — conhece cobranca. Quem muda o estado do dominio e a Action da
 * aplicacao, sempre, inclusive quando o aviso chega da instituicao financeira.
 * Por isso nao ha nenhum Model importado aqui.
 *
 * Duas particularidades da Efi moram nesta classe e em nenhum outro lugar:
 *
 * - Ela fala em reais com ponto decimal ("123.45"); o dominio guarda centavos
 *   inteiros. A conversao acontece aqui e sem numero de ponto flutuante em
 *   momento nenhum, porque em dinheiro ponto flutuante erra centavo.
 * - Ela nao tem situacao de cobranca vencida: passado o prazo, a consulta
 *   continua respondendo "ATIVA". Quem decide que o prazo venceu e o prazo da
 *   inscricao, do lado de ca.
 *
 * O identificador da cobranca (txid) e um ULID gerado por cobranca. A Efi
 * exige de 26 a 35 caracteres alfanumericos, sem hifen — que e exatamente o
 * formato de um ULID. Gerar por cobranca, e nao a partir do codigo da
 * inscricao, evita o 409 do dia em que uma inscricao precisar de uma segunda
 * cobranca depois de a primeira ser cancelada.
 */
class EfiPaymentGateway implements PaymentGateway
{
    /**
     * O nome do parametro que carrega a assinatura na URL do webhook.
     *
     * A Efi nao envia cabecalho de assinatura: ela devolve, em toda
     * notificacao, o parametro que registramos junto com a URL.
     */
    public const PARAMETRO_DE_ASSINATURA = 'hmac';

    /**
     * O tipo de evento gravado em webhooks_pagamento.tipo_evento. O aviso da
     * Efi nao traz tipo: ele significa uma coisa so, e essa coisa e esta.
     */
    private const TIPO_DE_EVENTO = 'pix.recebido';

    /**
     * Quanto tempo a cobranca vale quando ninguem informa um prazo.
     */
    private const EXPIRACAO_PADRAO_EM_SEGUNDOS = 3600;

    public function __construct(
        private readonly EfiClient $cliente,
        private readonly ConfiguracaoEfi $configuracao,
    ) {}

    public function name(): string
    {
        return 'efi';
    }

    public function createPayment(CreatePaymentData $data): PaymentResult
    {
        $corpo = $this->corpoDaCobranca($data);
        $txid = $this->novoTxid();

        try {
            $resposta = $this->cliente->criarCobranca($txid, $corpo);
        } catch (EfiException $erro) {
            if (! $erro->ehTxidDuplicado()) {
                throw $erro;
            }

            // Identificador ja usado. Como ele e sorteado, a colisao e quase
            // impossivel — mas "quase" com dinheiro na frente merece uma
            // segunda tentativa. Uma so: insistir mais seria transformar um
            // defeito de configuracao (por exemplo, duas instalacoes usando a
            // mesma conta) numa fila de tentativas com a pessoa esperando.
            $txid = $this->novoTxid();
            $resposta = $this->cliente->criarCobranca($txid, $corpo);
        }

        $pixCopiaECola = isset($resposta['pixCopiaECola']) ? (string) $resposta['pixCopiaECola'] : null;

        if ($pixCopiaECola === null || $pixCopiaECola === '') {
            // Sem o texto do Pix nao ha o que mostrar na tela nem o que copiar
            // para o aplicativo do banco: a cobranca existe e ninguem consegue
            // pagar. Melhor falhar agora, com a inscricao ainda sendo criada.
            throw new EfiException('A Efi criou a cobranca mas nao devolveu o codigo Pix copia e cola.');
        }

        return new PaymentResult(
            externalId: isset($resposta['txid']) ? (string) $resposta['txid'] : $txid,
            status: TraducaoDeStatus::daCobranca((string) ($resposta['status'] ?? TraducaoDeStatus::ATIVA)) ?? 'pending',
            amountCents: $data->amountCents,
            pixPayload: $pixCopiaECola,
            expiresAt: $data->expiresAt,
            raw: $resposta,
        );
    }

    public function getPayment(string $externalId): PaymentStatusResult
    {
        $resposta = $this->cliente->consultarCobranca($externalId);

        $pix = is_array($resposta['pix'] ?? null) ? $resposta['pix'] : [];
        $primeiroPix = is_array($pix[0] ?? null) ? $pix[0] : [];

        return new PaymentStatusResult(
            externalId: $externalId,
            // "ATIVA" vira sempre "pending", inclusive depois do prazo: a Efi
            // nao tem situacao de vencida e quem decide isso e o dominio.
            status: TraducaoDeStatus::daCobranca((string) ($resposta['status'] ?? '')) ?? 'pending',
            amountCents: $this->emCentavos((string) ($resposta['valor']['original'] ?? '0')),
            paidAt: MomentoDoProvedor::deTexto($primeiroPix['horario'] ?? null),
            refundedAmountCents: null,
            raw: $resposta,
        );
    }

    public function cancelPayment(string $externalId): void
    {
        try {
            $this->cliente->removerCobranca($externalId);
        } catch (EfiException $erro) {
            // A Efi recusa remover cobranca que ja saiu do ar — paga, ja
            // removida antes. Para quem chamou, o objetivo ja esta cumprido:
            // a cobranca nao aceita mais pagamento novo. Erro de verdade
            // (credencial, rede, indisponibilidade) continua subindo.
            if ($erro->codigoHttp !== 409) {
                throw $erro;
            }
        }
    }

    public function refundPayment(string $externalId, ?int $amountCents = null): RefundResult
    {
        throw EstornoNaoSuportadoException::paraProvedor($this->name());
    }

    public function webhookRequest(Request $request): WebhookRequestData
    {
        return WebhookRequestData::fromRequestQuery($request, self::PARAMETRO_DE_ASSINATURA);
    }

    public function verifyWebhookSignature(WebhookRequestData $request): bool
    {
        $segredo = $this->configuracao->segredoDoWebhook();

        // Sem segredo configurado nao ha como distinguir um aviso legitimo de
        // um forjado. Nesse caso, recusa: a falha e sempre para o lado seguro.
        if ($segredo === '' || $request->signature === null || $request->signature === '') {
            return false;
        }

        // hash_equals, e nunca "===": a comparacao comum de textos para no
        // primeiro caractere diferente, e o tempo que ela leva conta ao
        // atacante quantos caracteres ele ja acertou.
        return hash_equals($segredo, $request->signature);
    }

    /**
     * Traduz o aviso de Pix recebido.
     *
     * O aviso da Efi e uma LISTA: um unico POST pode informar varios
     * pagamentos. Cada item vira um evento. E ele nao tem campo de situacao —
     * o significado dele e um so: entrou dinheiro nesta cobranca, logo a
     * cobranca esta paga.
     *
     * @return list<WebhookResult>
     */
    public function parseWebhook(WebhookRequestData $request): array
    {
        $itens = $request->payload['pix'] ?? null;

        if (! is_array($itens)) {
            return [];
        }

        $eventos = [];

        foreach ($itens as $item) {
            if (! is_array($item)) {
                continue;
            }

            $identificadorDaTransferencia = isset($item['endToEndId']) ? (string) $item['endToEndId'] : null;

            $eventos[] = new WebhookResult(
                // O aviso nao tem identificador de evento proprio. O da
                // transferencia serve: ele e unico e vem repetido em toda
                // reentrega, que e exatamente o que a trava de repeticao
                // precisa para reconhecer o mesmo aviso duas vezes.
                eventId: $identificadorDaTransferencia,
                eventType: self::TIPO_DE_EVENTO,
                externalId: isset($item['txid']) ? (string) $item['txid'] : null,
                status: TraducaoDeStatus::doAvisoDePixRecebido(),
                amountCents: isset($item['valor']) ? $this->emCentavos((string) $item['valor']) : null,
                occurredAt: MomentoDoProvedor::deTexto($item['horario'] ?? null),
                // O recorte deste evento, no mesmo formato do aviso original,
                // para que ele possa ser relido mais tarde pelo mesmo
                // tradutor. A chave em separado existe porque o identificador
                // da transferencia precisa atravessar a fronteira com um nome
                // que nao seja o desta instituicao: quem grava e o job, e o
                // job nao conhece fornecedor.
                raw: array_filter([
                    'pix' => [$item],
                    'end_to_end_id' => $identificadorDaTransferencia,
                ], static fn (mixed $valor): bool => $valor !== null),
                payer: $this->pagadorDoItem($item),
            );
        }

        return $eventos;
    }

    // ------------------------------------------------------------------
    // A fronteira propriamente dita.
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function corpoDaCobranca(CreatePaymentData $data): array
    {
        $corpo = [
            'calendario' => ['expiracao' => $this->expiracaoEmSegundos($data)],
            'devedor' => $this->devedor($data),
            'valor' => ['original' => $this->emReais($data->amountCents)],
            'chave' => $this->configuracao->chavePix(),
        ];

        $solicitacao = trim($data->description);

        if ($solicitacao !== '') {
            // O texto que aparece no aplicativo do banco de quem paga.
            $corpo['solicitacaoPagador'] = Str::limit($solicitacao, 137);
        }

        if ($data->externalReference !== '') {
            // Fica visivel no extrato da conta recebedora. E o que permite a
            // quem cuida do dinheiro casar um Pix com uma inscricao sem
            // precisar abrir o sistema.
            $corpo['infoAdicionais'] = [
                ['nome' => 'Inscricao', 'valor' => Str::limit($data->externalReference, 195)],
            ];
        }

        return $corpo;
    }

    /**
     * @return array<string, string>
     */
    private function devedor(CreatePaymentData $data): array
    {
        $documento = preg_replace('/\D/', '', $data->payerDocument) ?? '';
        $nome = Str::limit(trim($data->payerName), 195);

        if (mb_strlen($documento) === 14) {
            return ['cnpj' => $documento, 'nome' => $nome];
        }

        if (mb_strlen($documento) === 11) {
            return ['cpf' => $documento, 'nome' => $nome];
        }

        throw new EfiException('A Efi exige um CPF ou CNPJ valido de quem vai pagar a cobranca.');
    }

    private function expiracaoEmSegundos(CreatePaymentData $data): int
    {
        if ($data->expiresAt === null) {
            return self::EXPIRACAO_PADRAO_EM_SEGUNDOS;
        }

        $segundos = Carbon::now()->diffInSeconds($data->expiresAt, false);

        // A Efi exige numero inteiro maior que zero. Prazo ja vencido nao gera
        // cobranca eterna: gera uma cobranca que morre em um minuto.
        return (int) max(60, (int) $segundos);
    }

    /**
     * Centavos inteiros para o texto decimal que a Efi espera.
     *
     * Sem divisao com virgula flutuante em lugar nenhum: 12345 vira "123.45"
     * por recorte de inteiro, e nao por 12345/100 — que, em ponto flutuante,
     * nao e exatamente 123,45.
     */
    private function emReais(int $centavos): string
    {
        if ($centavos <= 0) {
            throw new EfiException('Nao e possivel cobrar um valor menor ou igual a zero.');
        }

        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * O caminho de volta: o texto decimal da Efi para centavos inteiros.
     *
     * Tambem sem ponto flutuante — "0.07" multiplicado por 100 em ponto
     * flutuante da 7,000000000000001, e o corte para inteiro devolveria 7 por
     * sorte, nao por garantia.
     */
    private function emCentavos(string $valor): int
    {
        $limpo = trim(str_replace(',', '.', $valor));

        if ($limpo === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $limpo)) {
            return 0;
        }

        [$reais, $centavos] = array_pad(explode('.', $limpo, 2), 2, '0');

        return ((int) $reais) * 100 + (int) str_pad($centavos, 2, '0');
    }

    /**
     * Um ULID por cobranca: 26 caracteres, so letras e numeros, dentro do
     * formato que a Efi exige (^[a-zA-Z0-9]{26,35}$).
     */
    /**
     * Quem pagou, dito em palavras que nao sejam as desta instituicao.
     *
     * A Efi guarda esses campos em `gnExtras` — `gn` de Gerencianet, o nome
     * antigo dela. Nome de fornecedor nao pode atravessar a fronteira: quem
     * grava o pagador e o job, e o job nao conhece fornecedor nenhum. E a
     * mesma razao pela qual `end_to_end_id` viaja com esse nome, e nao com o
     * `endToEndId` do aviso original.
     *
     * O documento chega como `cpf` ou como `cnpj`, nunca os dois. O tipo viaja
     * junto porque quem le do outro lado nao tem como distinguir um numero
     * mascarado (`***.456.789-**`) de um CNPJ so pelo formato — e a tela
     * precisa saber qual dos dois esta mostrando.
     *
     * Este metodo roda DUAS vezes por aviso: uma quando ele chega, sobre o
     * corpo original, e outra quando o job releu o que foi guardado. E a
     * segunda leitura que vira dado: nela o CPF ja vem mascarado, porque quem
     * gravou o aviso mascarou antes. Por isso o resultado daqui viaja em campo
     * proprio do resultado, e nao dentro do recorte cru — o recorte e o que se
     * guarda, e nada que passe pela primeira leitura pode acabar guardado.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function pagadorDoItem(array $item): array
    {
        $extras = $item['gnExtras']['pagador'] ?? null;
        $extras = is_array($extras) ? $extras : [];

        $texto = static function (mixed $valor): ?string {
            $valor = is_scalar($valor) ? trim((string) $valor) : '';

            return $valor === '' ? null : $valor;
        };

        $cpf = $texto($extras['cpf'] ?? null);
        $cnpj = $texto($extras['cnpj'] ?? null);

        $pagador = array_filter([
            'nome' => $texto($extras['nome'] ?? null),
            'documento' => $cpf ?? $cnpj,
            'tipo_documento' => $cpf !== null ? 'cpf' : ($cnpj !== null ? 'cnpj' : null),
            'banco' => $texto($extras['codigoBanco'] ?? null),
            // A mensagem que a pessoa digitou no aplicativo do banco. Nao vem
            // em `gnExtras`: ela e do proprio Pix, um nivel acima.
            'mensagem' => $texto($item['infoPagador'] ?? null),
        ], static fn (?string $valor): bool => $valor !== null);

        return $pagador;
    }

    private function novoTxid(): string
    {
        return (string) Str::ulid();
    }
}

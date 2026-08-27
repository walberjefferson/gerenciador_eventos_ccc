<?php

declare(strict_types=1);

namespace Tests\Feature\Pagamentos\Efi;

use App\Exceptions\Payments\EfiException;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use App\Services\Payments\Efi\EfiClient;
use App\Services\Payments\Efi\TraducaoDeStatus;

/**
 * O duplo que substitui o SDK da Efi na suite automatizada.
 *
 * Ele existe porque o SDK usa cliente HTTP proprio: o Http::fake() do Laravel
 * nao o intercepta. Sem este duplo, provar a emissao de cobranca exigiria
 * credencial, certificado e rede — a suite deixaria de rodar no computador de
 * quem desenvolve e no processo de integracao.
 *
 * Ele imita o formato de resposta da Efi campo a campo (a varredura da
 * documentacao esta em .planning/feat/context/efi-api-pix.md) e permite
 * encomendar o erro que se quer provar: 409 de txid repetido, 429 de excesso,
 * queda de rede. Nao guarda regra nenhuma do dominio — so cobrancas.
 */
class EfiClientFake extends EfiClient
{
    /** @var array<string, array<string, mixed>> */
    public array $cobrancas = [];

    /** @var list<string> */
    public array $txidsRecebidos = [];

    /** @var list<array<string, mixed>> */
    public array $corposRecebidos = [];

    /**
     * Erros encomendados para as proximas chamadas de criacao, na ordem.
     *
     * @var list<EfiException>
     */
    public array $errosNaCriacao = [];

    public int $criacoes = 0;

    public ?EfiException $erroNaConsulta = null;

    public ?EfiException $erroNaRemocao = null;

    public function __construct(?ConfiguracaoEfi $configuracao = null)
    {
        // O duplo nunca chega a usar configuracao nem cache: ele responde de
        // memoria. Mas herda de EfiClient de proposito, para que uma mudanca
        // de assinatura no cliente de verdade quebre a suite em vez de passar
        // despercebida.
        parent::__construct($configuracao ?? new ConfiguracaoEfi, cache());
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed>
     */
    public function criarCobranca(string $txid, array $corpo): array
    {
        $this->criacoes++;
        $this->txidsRecebidos[] = $txid;
        $this->corposRecebidos[] = $corpo;

        if ($this->errosNaCriacao !== []) {
            throw array_shift($this->errosNaCriacao);
        }

        $cobranca = [
            'txid' => $txid,
            'revisao' => 0,
            'status' => TraducaoDeStatus::ATIVA,
            'calendario' => $corpo['calendario'] ?? ['expiracao' => 3600],
            'devedor' => $corpo['devedor'] ?? [],
            'valor' => $corpo['valor'] ?? ['original' => '0.00'],
            'chave' => $corpo['chave'] ?? 'chave-pix-de-teste',
            'loc' => [
                'id' => $this->criacoes,
                'location' => 'pix.example.com/qr/v2/'.$txid,
                'tipoCob' => 'cob',
            ],
            'pixCopiaECola' => '00020101021226880014br.gov.bcb.pix'.$txid.'6304ABCD',
        ];

        $this->cobrancas[$txid] = $cobranca;

        return $cobranca;
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarCobranca(string $txid): array
    {
        if ($this->erroNaConsulta !== null) {
            throw $this->erroNaConsulta;
        }

        if (! isset($this->cobrancas[$txid])) {
            throw new EfiException(
                'A Efi recusou a operacao: cobranca nao encontrada.',
                codigoHttp: 400,
                identificador: 'cobranca_nao_encontrada',
            );
        }

        return $this->cobrancas[$txid];
    }

    /**
     * @return array<string, mixed>
     */
    public function removerCobranca(string $txid): array
    {
        if ($this->erroNaRemocao !== null) {
            throw $this->erroNaRemocao;
        }

        $cobranca = $this->consultarCobranca($txid);
        $cobranca['status'] = TraducaoDeStatus::REMOVIDA_PELO_RECEBEDOR;
        $this->cobrancas[$txid] = $cobranca;

        return $cobranca;
    }

    // ------------------------------------------------------------------
    // Encomendas usadas pelos testes.
    // ------------------------------------------------------------------

    /**
     * Marca a cobranca como paga, como a Efi faria depois de o Pix cair.
     *
     * @param  array<string, mixed>  $pix
     */
    public function pagar(string $txid, array $pix = []): void
    {
        $cobranca = $this->consultarCobranca($txid);
        $cobranca['status'] = TraducaoDeStatus::CONCLUIDA;
        $cobranca['pix'] = [$pix === [] ? [
            'endToEndId' => 'E18036150202508271200'.mb_substr($txid, -6),
            'txid' => $txid,
            'valor' => $cobranca['valor']['original'] ?? '0.00',
            'horario' => '2026-08-27T12:00:00.000Z',
        ] : $pix];

        $this->cobrancas[$txid] = $cobranca;
    }

    public function encomendarTxidDuplicado(): void
    {
        $this->errosNaCriacao[] = new EfiException(
            'A Efi recusou a operacao: ja existe cobranca com este identificador.',
            codigoHttp: 409,
            identificador: 'txid_duplicado',
        );
    }

    public function encomendarExcessoDeRequisicoes(): void
    {
        $this->errosNaCriacao[] = new EfiException(
            'A Efi recebeu pedidos demais deste sistema. Tente novamente em instantes.',
            codigoHttp: 429,
            identificador: 'limite_de_requisicoes',
        );
    }

    public function encomendarQuedaDeRede(): void
    {
        $this->errosNaCriacao[] = new EfiException(
            'Nao foi possivel falar com a Efi agora. Tente novamente em instantes.'
        );
    }
}

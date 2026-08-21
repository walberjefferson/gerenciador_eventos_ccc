<?php

declare(strict_types=1);

namespace App\Services\Inscricoes;

use App\Enums\SituacaoInscricao;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A historia da inscricao contada em marcos, para o participante ler.
 *
 * Nao existe tabela de auditoria e este servico nao cria uma: cada marco e
 * derivado de um carimbo de tempo que o dominio ja grava (created_at,
 * prazo_pagamento, pago_em, confirmada_em, expirada_em, cancelada_em,
 * estornado_em). Nada aqui escreve; nada aqui decide regra de negocio.
 *
 * Cada marco tem quatro estados possiveis:
 *   concluido — ja aconteceu
 *   atual     — o proximo passo esperado (existe no maximo um)
 *   futuro    — ainda vai acontecer e nao e o proximo passo
 *   encerrado — aconteceu e fechou o caminho (prazo vencido, cancelamento)
 */
class LinhaDoTempoDaInscricao
{
    public const CONCLUIDO = 'concluido';

    public const ATUAL = 'atual';

    public const FUTURO = 'futuro';

    public const ENCERRADO = 'encerrado';

    /**
     * @return list<array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}>
     */
    public function __invoke(Inscricao $inscricao): array
    {
        $pagamentos = $this->pagamentos($inscricao);
        $marcos = [];

        $marcos[] = $this->marco(
            'inscricao_feita',
            'Inscrição feita',
            'Recebemos sua inscrição e guardamos a sua vaga.',
            $inscricao->created_at,
            self::CONCLUIDO,
        );

        $primeiraCobranca = $pagamentos->sortBy('id')->first();

        if ($primeiraCobranca instanceof Pagamento) {
            $marcos[] = $this->marco(
                'cobranca_emitida',
                'Cobrança Pix emitida',
                'Geramos o código Pix para você pagar a inscrição.',
                $primeiraCobranca->created_at,
                self::CONCLUIDO,
            );
        }

        // O prazo so e um passo enquanto ainda ha o que pagar. Depois disso,
        // quem conta a historia e o marco de prazo vencido ou o de confirmacao.
        if ($inscricao->situacao === SituacaoInscricao::AguardandoPagamento) {
            $vencido = $inscricao->prazoVencido();

            $marcos[] = $this->marco(
                'prazo_pagamento',
                'Prazo para pagar',
                $vencido
                    ? 'O prazo para pagar terminou.'
                    : 'Pague o Pix até esta data para garantir a sua vaga.',
                $inscricao->prazo_pagamento,
                $vencido ? self::ENCERRADO : self::ATUAL,
            );
        }

        $pago = $pagamentos->whereNotNull('pago_em')->sortByDesc('pago_em')->first();

        if ($pago instanceof Pagamento) {
            $marcos[] = $this->marco(
                'pagamento_recebido',
                'Pagamento recebido',
                'Recebemos seu pagamento.',
                $pago->pago_em,
                self::CONCLUIDO,
            );
        }

        if ($inscricao->confirmada_em !== null) {
            $marcos[] = $this->marco(
                'inscricao_confirmada',
                'Inscrição confirmada',
                'Sua vaga está garantida. Guarde o código da inscrição para o dia do evento.',
                $inscricao->confirmada_em,
                self::CONCLUIDO,
            );
        }

        if ($inscricao->expirada_em !== null) {
            $marcos[] = $this->marco(
                'prazo_vencido',
                'Prazo vencido',
                'O prazo para pagar terminou e a vaga voltou para quem ainda quer se inscrever.',
                $inscricao->expirada_em,
                self::ENCERRADO,
            );
        }

        if ($inscricao->cancelada_em !== null) {
            $motivo = trim((string) $inscricao->motivo_cancelamento);

            $marcos[] = $this->marco(
                'inscricao_cancelada',
                'Inscrição cancelada',
                $motivo === '' ? 'Esta inscrição foi cancelada.' : 'Esta inscrição foi cancelada. Motivo: '.$motivo,
                $inscricao->cancelada_em,
                self::ENCERRADO,
            );
        }

        $estornado = $pagamentos->whereNotNull('estornado_em')->sortByDesc('estornado_em')->first();

        if ($estornado instanceof Pagamento) {
            $valor = $estornado->valor_estornado_centavos ?? $estornado->valor_centavos;

            $marcos[] = $this->marco(
                'valor_estornado',
                'Valor estornado',
                'Devolvemos '.$this->dinheiro((int) $valor, (string) ($inscricao->evento?->moeda ?? 'BRL')).' para a conta que fez o pagamento.',
                $estornado->estornado_em,
                self::ENCERRADO,
            );
        }

        return $this->ordenar($marcos);
    }

    /**
     * @return Collection<int, Pagamento>
     */
    private function pagamentos(Inscricao $inscricao): Collection
    {
        return $inscricao->relationLoaded('pagamentos')
            ? $inscricao->pagamentos
            : $inscricao->pagamentos()->get();
    }

    /**
     * @return array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}
     */
    private function marco(string $chave, string $titulo, string $descricao, ?Carbon $momento, string $estado): array
    {
        return [
            'chave' => $chave,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'momento' => $momento?->toIso8601String(),
            'estado' => $estado,
        ];
    }

    /**
     * Do mais antigo para o mais recente. Marco sem momento — o passo que
     * ainda nao tem data — vai para o fim, na ordem natural do fluxo.
     *
     * @param  list<array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}>  $marcos
     * @return list<array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}>
     */
    private function ordenar(array $marcos): array
    {
        // usort e estavel desde o PHP 8.0: empate mantem a ordem de montagem.
        usort($marcos, function (array $um, array $outro): int {
            if ($um['momento'] === $outro['momento']) {
                return 0;
            }

            if ($um['momento'] === null) {
                return 1;
            }

            if ($outro['momento'] === null) {
                return -1;
            }

            return $um['momento'] <=> $outro['momento'];
        });

        return $marcos;
    }

    /**
     * Dinheiro do jeito que se le no Brasil. Nao usa a extensao intl para nao
     * depender dela em maquina de desenvolvimento.
     */
    private function dinheiro(int $centavos, string $moeda): string
    {
        $valor = number_format($centavos / 100, 2, ',', '.');

        return $moeda === 'BRL' ? 'R$ '.$valor : $moeda.' '.$valor;
    }
}

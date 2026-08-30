<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Enums\AcaoAuditada;
use App\Enums\AmbientePagamento;
use App\Models\CredencialPagamento;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Troca qual ambiente do provedor de pagamento esta valendo.
 *
 * E a acao mais consequente da tela: a partir de "producao", toda cobranca
 * emitida cobra dinheiro de verdade de gente de verdade. A confirmacao
 * explicita e exigida antes daqui, na fronteira HTTP.
 *
 * A troca acontece em transacao e em dois passos — desliga todos, liga o
 * escolhido — porque o banco tem um indice unico parcial sobre "ativo = true"
 * (ver a migracao). Se o desligamento e a ativacao nao estiverem na mesma
 * transacao, uma falha no meio deixaria o sistema sem ambiente nenhum ativo, o
 * que na pratica derruba a emissao de cobranca.
 *
 * Esse indice e proposital e nao ha como contorna-lo: duas pessoas ativando
 * ambientes diferentes no mesmo instante nao produzem dois ativos. Quem
 * impede o segundo e o PostgreSQL, e nao uma verificacao em PHP que perderia
 * a corrida.
 */
class AtivarAmbientePagamento
{
    public function __construct(
        private readonly RegistrarAcao $registrar,
        private readonly ConfiguracaoEfi $configuracao,
    ) {}

    public function __invoke(
        AmbientePagamento $ambiente,
        ?User $responsavel = null,
        string $gateway = CredencialPagamento::GATEWAY_EFI,
    ): CredencialPagamento {
        $credencial = CredencialPagamento::query()
            ->where('gateway', $gateway)
            ->where('ambiente', $ambiente->value)
            ->first();

        if (! $credencial instanceof CredencialPagamento) {
            throw new RuntimeException(
                'Nao ha credencial cadastrada para o ambiente de '.$ambiente->rotulo().'.'
            );
        }

        if (! $credencial->estaCompleta()) {
            // Ativar um cadastro incompleto trocaria "o pagamento nao esta
            // configurado" por "o pagamento quebra na hora da inscricao".
            throw new RuntimeException(
                'O cadastro de '.$ambiente->rotulo().' esta incompleto e nao pode ser ativado. '.
                'Preencha a identificacao, a chave secreta, a chave Pix e o certificado.'
            );
        }

        $anterior = CredencialPagamento::ativaDe($gateway);

        if ($anterior?->getKey() === $credencial->getKey()) {
            return $credencial;
        }

        DB::transaction(function () use ($gateway, $credencial): void {
            CredencialPagamento::query()
                ->where('gateway', $gateway)
                ->where('ativo', true)
                ->update(['ativo' => false]);

            $credencial->ativo = true;
            $credencial->save();
        });

        // O ambiente mudou: o token guardado e do ambiente anterior e nao vale
        // mais nada aqui.
        $this->configuracao->recarregar();

        // O NOME do ambiente nao e segredo — e exatamente o que quem le a
        // auditoria precisa saber. Nenhum valor de credencial entra aqui.
        ($this->registrar)(
            AcaoAuditada::AlterouCredencialPagamento,
            'credencial-pagamento',
            (int) $credencial->getKey(),
            [
                'gateway' => $gateway,
                'ambiente_anterior' => $anterior?->ambiente->value,
                'ambiente_novo' => $ambiente->value,
            ],
            motivo: 'Troca do ambiente ativo do provedor de pagamento',
            responsavel: $responsavel,
        );

        return $credencial->refresh();
    }
}

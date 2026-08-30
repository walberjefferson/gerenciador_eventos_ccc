<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Enums\AcaoAuditada;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Database\Eloquent\Model;

/**
 * As tres linhas que todo cadastro administrativo repete.
 *
 * Existe para que acrescentar auditoria a uma tela nova seja uma linha, e nao
 * um bloco copiado. Quanto mais curto o gesto, menor a chance de alguem pular
 * o registro na pressa.
 *
 * Nenhum metodo daqui lanca excecao nem devolve nada: auditar e testemunhar, e
 * testemunha nao interrompe o que esta acontecendo (ver
 * App\Services\Auditoria\RegistrarAcao).
 */
trait RegistraAuditoria
{
    /**
     * @param  array<string, mixed>  $dados
     */
    protected function auditar(
        AcaoAuditada $acao,
        string $entidade,
        ?int $entidadeId = null,
        array $dados = [],
        ?string $motivo = null,
        ?User $responsavel = null,
    ): void {
        app(RegistrarAcao::class)($acao, $entidade, $entidadeId, $dados, $motivo, $responsavel);
    }

    protected function auditarCriacao(Model $modelo, string $entidade): void
    {
        $this->auditar(
            AcaoAuditada::Criou,
            $entidade,
            $modelo->getKey() === null ? null : (int) $modelo->getKey(),
            ['criado' => $modelo->getAttributes()],
        );
    }

    /**
     * O "antes" precisa ser capturado antes do update, com
     * `$modelo->getRawOriginal()`. Depois do save o Eloquent ja considera os
     * valores novos como originais, e a comparacao nao acharia diferenca
     * nenhuma.
     *
     * Quando nada mudou de fato, nenhum registro e gravado: auditoria cheia de
     * "alterou, mas nada mudou" e ruido que esconde o que importa.
     *
     * @param  array<string, mixed>  $antes
     */
    protected function auditarAlteracao(Model $modelo, array $antes, string $entidade): void
    {
        app(RegistrarAcao::class)->alteracao($modelo, $antes, $entidade);
    }

    /**
     * Guarda o que a linha continha antes de sumir. E o unico caso em que o
     * conteudo inteiro importa: depois do delete nao ha mais onde consultar.
     */
    protected function auditarRemocao(Model $modelo, string $entidade): void
    {
        $this->auditar(
            AcaoAuditada::Removeu,
            $entidade,
            $modelo->getKey() === null ? null : (int) $modelo->getKey(),
            ['removido' => $modelo->getAttributes()],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Presenca;

use App\Enums\AcaoAuditada;
use App\Models\Ingresso;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Support\Carbon;

/**
 * Apaga uma entrada registrada por engano.
 *
 * O engano acontece: o voluntario le o QR da pessoa errada, ou confere duas
 * vezes o mesmo ingresso porque a tela demorou. Sem este caminho, o conserto
 * seria alguem mexendo no banco de dados na hora do evento — que e pior de
 * todas as formas possiveis.
 *
 * ELE NAO E PARA A PORTARIA, e a permissao "presenca.desfazer" existe
 * exatamente para dizer isso: desfazer devolve validade a um ingresso ja
 * usado, ou seja, e o caminho pelo qual um ingresso vira carona para outra
 * pessoa. Quem esta no portao, com a fila olhando, nao deve poder.
 *
 * O ingresso NAO e apagado nem alterado de outra forma: so "usado_em" e
 * "usado_por" voltam a ficar vazios. O que aconteceu — a entrada e o desfazer
 * — fica na trilha de auditoria, que e onde a historia mora.
 */
class DesfazerPresenca
{
    public function __construct(private readonly RegistrarAcao $auditar) {}

    /**
     * @return bool false quando nao havia entrada nenhuma para desfazer — nao
     *              e erro: e alguem clicando duas vezes no mesmo botao
     */
    public function __invoke(Ingresso $ingresso, User $responsavel): bool
    {
        if (! $ingresso->estaUsado()) {
            return false;
        }

        $usadoEm = $ingresso->usado_em instanceof Carbon ? $ingresso->usado_em->toIso8601String() : null;
        $usadoPor = $ingresso->usado_por === null ? null : (int) $ingresso->usado_por;

        $ingresso->forceFill([
            'usado_em' => null,
            'usado_por' => null,
        ])->save();

        // O rastro guarda o que foi apagado — e so aqui que a hora da entrada
        // desfeita continua existindo depois desta linha.
        ($this->auditar)(
            AcaoAuditada::DesfezPresenca,
            'ingresso',
            (int) $ingresso->getKey(),
            [
                'inscricao_id' => (int) $ingresso->inscricao_id,
                'entrada_desfeita' => [
                    'usado_em' => $usadoEm,
                    'usado_por_id' => $usadoPor,
                ],
            ],
            responsavel: $responsavel,
        );

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Inscricao;
use App\Models\Pagamento;

/**
 * Uma linha da lista de inscricoes, no formato que a tela e o CSV leem.
 *
 * O que este arquivo **nao** tem e tao importante quanto o que ele tem: nem
 * `documento`, nem `documento_hash`. O CPF fica cifrado no banco e nenhuma tela
 * administrativa precisa dele — se um dia alguem precisar, que seja por uma
 * decisao consciente, e nao por um campo que vazou para dentro de um array.
 */
final class LinhaDaInscricaoResource
{
    /**
     * @return array<string, mixed>
     */
    public static function paraTela(Inscricao $inscricao): array
    {
        $pagamento = self::cobrancaMaisRecente($inscricao);

        return [
            'id' => $inscricao->id,
            'codigo_publico' => $inscricao->codigo_publico,
            'nome_completo' => $inscricao->nome_completo,
            'email' => $inscricao->email,
            'evento' => $inscricao->evento?->nome ?? '',
            'cidade' => self::cidade($inscricao),
            'grupo' => $inscricao->grupoParticipante?->nome ?? '',
            'situacao' => $inscricao->situacao->value,
            'situacao_rotulo' => $inscricao->situacao->rotulo(),
            'valor_centavos' => $inscricao->valor_centavos,
            'prazo_pagamento' => $inscricao->prazo_pagamento?->toIso8601String(),
            'criada_em' => $inscricao->created_at?->toIso8601String(),
            'situacao_pagamento' => $pagamento?->situacao->value,
            'situacao_pagamento_rotulo' => $pagamento?->situacao->rotulo(),
        ];
    }

    /**
     * A cidade vem pelo grupo de participantes: e o caminho que a propria
     * inscricao usa, e nao ha cidade solta na tabela.
     */
    public static function cidade(Inscricao $inscricao): string
    {
        $cidade = $inscricao->grupoParticipante?->cidade;

        return $cidade === null ? '' : "{$cidade->nome}/{$cidade->uf}";
    }

    /**
     * A cobranca mais recente e a que conta: se a primeira venceu e outra foi
     * emitida no lugar, e a nova que descreve a situacao de hoje.
     */
    public static function cobrancaMaisRecente(Inscricao $inscricao): ?Pagamento
    {
        return $inscricao->pagamentos->sortByDesc('id')->first();
    }
}

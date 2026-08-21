<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoComunicacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de que uma mensagem ja saiu.
 *
 * A linha e gravada ANTES do envio, e e ela — pela unicidade
 * (inscricao_id, tipo, canal) — que impede a segunda copia. O registro nao e
 * um historico decorativo: e a trava.
 */
class ComunicacaoEnviada extends Model
{
    /**
     * O unico canal existente hoje. A constante evita o texto solto espalhado
     * pelo codigo e deixa claro onde um segundo canal entraria.
     */
    public const CANAL_EMAIL = 'email';

    protected $table = 'comunicacoes_enviadas';

    protected $fillable = [
        'inscricao_id',
        'tipo',
        'canal',
        'destino',
        'enviada_em',
    ];

    /**
     * @return BelongsTo<Inscricao, $this>
     */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoComunicacao::class,
            'enviada_em' => 'datetime',
        ];
    }
}

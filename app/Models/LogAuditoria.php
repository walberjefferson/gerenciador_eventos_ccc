<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcaoAuditada;
use App\Exceptions\Auditoria\LogAuditoriaImutavelException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O rastro de uma acao administrativa.
 *
 * Este model tem uma regra que os outros nao tem: **ele so aceita nascer**.
 * Alterar ou apagar um registro pela aplicacao lanca excecao, sempre — nao ha
 * flag, nao ha modo administrador, nao ha excecao "so desta vez".
 *
 * A trava esta aqui, e nao numa combinacao com a equipe, porque combinado nao
 * sobrevive a quem escrever codigo novo sem ler esta decisao. Registro que
 * pode ser corrigido depois nao prova nada: bastaria alguem apagar a propria
 * linha para a auditoria virar enfeite.
 *
 * (Quem tiver acesso direto ao banco continua podendo mexer na tabela. Isso e
 * proposital e esta fora do alcance da aplicacao: a defesa nesse nivel e
 * permissao de banco, nao codigo PHP.)
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property AcaoAuditada $acao
 * @property string $entidade
 * @property int|null $entidade_id
 * @property string|null $motivo
 * @property array<string, mixed>|null $dados
 * @property string|null $ip
 * @property string|null $agente
 * @property Carbon $created_at
 */
class LogAuditoria extends Model
{
    protected $table = 'logs_auditoria';

    /**
     * So "created_at" existe. Deixar o Eloquent tentar gravar "updated_at"
     * quebraria a insercao — e, pior, sugeriria que o registro muda.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'acao',
        'entidade',
        'entidade_id',
        'motivo',
        'dados',
        'ip',
        'agente',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogAuditoriaImutavelException(
                'Registro de auditoria nao pode ser alterado.'
            );
        });

        static::deleting(function (): void {
            throw new LogAuditoriaImutavelException(
                'Registro de auditoria nao pode ser removido.'
            );
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Quem fez, pelo nome — ou "Sistema" quando a acao nao teve gente por tras
     * (rotina agendada, comando de terminal).
     */
    public function responsavel(): string
    {
        return $this->usuario?->name ?? 'Sistema';
    }

    /**
     * @param  Builder<LogAuditoria>  $consulta
     * @return Builder<LogAuditoria>
     */
    public function scopeDaEntidade(Builder $consulta, string $entidade, ?int $id = null): Builder
    {
        $consulta->where('entidade', $entidade);

        if ($id !== null) {
            $consulta->where('entidade_id', $id);
        }

        return $consulta;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acao' => AcaoAuditada::class,
            'dados' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

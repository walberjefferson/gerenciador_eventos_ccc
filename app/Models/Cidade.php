<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CidadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cidade do catalogo global de participantes — o "setor", no vocabulario que a
 * comunidade usa e que a tela mostra.
 *
 * **Por que a chave Pix daqui NAO e cifrada, e a da credencial e.**
 *
 * CredencialPagamento::chave_pix e cifrada porque e segredo de instituicao
 * financeira: ela diz para qual conta o dinheiro do evento vai, mora ao lado do
 * client_secret e do certificado, e nunca precisa voltar para tela nenhuma —
 * nem mascarada. Quem a le e o servidor, para falar com o provedor.
 *
 * Esta chave e o oposto disso, e a diferenca nao e de grau: ela existe PARA SER
 * MOSTRADA. Todo participante de um setor num evento que recebe pelo setor
 * precisa enxerga-la na tela para conseguir pagar. Cifrar em repouso o valor
 * que a propria aplicacao publica na pagina seguinte seria teatro: protegeria
 * contra um invasor com acesso ao banco e a nenhum outro, enquanto cobraria o
 * preco de tornar impossivel procurar, conferir e exportar a chave.
 *
 * O que esta chave exige e outra protecao, e essa e obrigatoria: ela so pode
 * aparecer para quem ja esta numa inscricao daquele setor. Nunca numa listagem
 * publica de setores, nunca no formulario de inscricao, nunca na pagina do
 * evento (RN-S3).
 */
class Cidade extends Model
{
    /** @use HasFactory<CidadeFactory> */
    use HasFactory;

    protected $table = 'cidades';

    protected $fillable = [
        'nome',
        'uf',
        'ativo',
        'responsavel_id',
        'chave_pix',
        'titular_chave_pix',
    ];

    /**
     * @return HasMany<GrupoParticipante, $this>
     */
    public function gruposParticipantes(): HasMany
    {
        return $this->hasMany(GrupoParticipante::class);
    }

    /**
     * A pessoa que responde por este setor.
     *
     * E quem recebe o Pix dos participantes daqui e quem confere os
     * comprovantes deles — vendo, no painel, apenas este setor (RN-S9).
     *
     * @return BelongsTo<User, $this>
     */
    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /**
     * Este setor consegue receber pagamento?
     *
     * Precisa das duas coisas ao mesmo tempo: a chave para onde o dinheiro vai
     * e a pessoa que vai conferir se ele chegou. Chave sem responsavel receberia
     * pagamento que ninguem confirma; responsavel sem chave nao teria o que
     * mostrar ao participante. Um evento so pode ser gravado no modo "setor"
     * quando todo setor ativo responde sim aqui (RN-S4).
     */
    public function estaPreparadaParaReceber(): bool
    {
        return $this->responsavel_id !== null
            && is_string($this->chave_pix)
            && trim($this->chave_pix) !== '';
    }

    /**
     * Os setores ativos que ainda nao conseguem receber, pelo nome.
     *
     * A lista existe para a mensagem de recusa NOMEAR quem falta: "ajuste os
     * setores" manda a pessoa procurar; "faltam Setor Norte e Setor Sul" manda
     * ela resolver.
     *
     * @return array<int, string>
     */
    public static function ativasDespreparadasParaReceber(): array
    {
        return static::query()
            ->ativos()
            ->where(fn (Builder $consulta) => $consulta
                ->whereNull('responsavel_id')
                ->orWhereNull('chave_pix')
                ->orWhere('chave_pix', '=', ''))
            ->orderBy('nome')
            ->pluck('nome')
            ->map(fn (mixed $nome): string => (string) $nome)
            ->all();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }
}

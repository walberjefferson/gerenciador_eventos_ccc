<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ResponsavelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quem recebe o Pix de um setor.
 *
 * **E cadastro proprio, e nao um campo do setor** (RN-R1). Os quatro campos que
 * moravam em `cidades` descreviam uma pessoa, nao um lugar: com mais de um
 * responsavel por setor eles nao tinham onde caber. Aqui a pessoa tem nome,
 * chave e telefone dela, e o setor apenas a aponta.
 *
 * `user_id` nulo quer dizer "recebe, mas nao confere": e o caso real do
 * tesoureiro que nao usa o sistema. Quem confere continua sendo quem tem conta
 * **e** o papel — ou o administrador, que alcanca tudo.
 *
 * **A chave Pix daqui NAO e cifrada, e a da credencial e.** A da credencial e
 * segredo de instituicao financeira: ela diz para qual conta o dinheiro do
 * evento vai e nunca precisa voltar para tela nenhuma. Esta e o oposto, e a
 * diferenca nao e de grau: ela existe PARA SER MOSTRADA. Todo participante de
 * um setor num evento que recebe pelo setor precisa enxerga-la na tela para
 * conseguir pagar. O que protege esta coluna e o escopo de quem a le (RN-S3),
 * nunca a criptografia.
 */
class Responsavel extends Model
{
    /** @use HasFactory<ResponsavelFactory> */
    use HasFactory;

    protected $table = 'responsaveis';

    protected $fillable = [
        'nome',
        'chave_pix',
        'telefone',
        'user_id',
        'ativo',
    ];

    /**
     * Os setores que esta pessoa atende (RN-R2).
     *
     * A chave Pix e DELA, e nao do vinculo: o mesmo responsavel usa a mesma
     * chave em todos os setores que atende. Chave por vinculo cobriria a
     * tesouraria que separa contas por setor ao preco de duplicar cadastro no
     * caso comum — se um dia for preciso, a coluna nasce na tabela de vinculo
     * sem desfazer nada disto.
     *
     * @return BelongsToMany<Cidade, $this>
     */
    public function setores(): BelongsToMany
    {
        return $this->belongsToMany(Cidade::class, 'responsaveis_setores', 'responsavel_id', 'cidade_id')
            ->orderBy('cidades.nome');
    }

    /**
     * A conta do painel desta pessoa, quando ela tem uma.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * As cobrancas que ja foram sorteadas para esta pessoa.
     *
     * E por causa desta relacao que ela nao se apaga (RN-R9): apagar quem ja
     * recebeu apagaria a resposta a pergunta "para quem foi este dinheiro".
     *
     * @return HasMany<Pagamento, $this>
     */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class, 'responsavel_id');
    }

    /**
     * Esta pessoa pode ser sorteada agora?
     *
     * Duas coisas, e as duas sao verificadas no instante do sorteio: ela
     * precisa estar ativa e precisa ter chave. Desativar ou limpar a chave tira
     * do sorteio na hora, sem tocar em cobranca nenhuma ja emitida (RN-R9).
     */
    public function estaApto(): bool
    {
        return $this->ativo === true && trim((string) $this->chave_pix) !== '';
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * Os que podem entrar no sorteio: ativos e com chave.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAptos(Builder $query): void
    {
        $query->ativos()
            ->whereNotNull('chave_pix')
            ->where('chave_pix', '<>', '');
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

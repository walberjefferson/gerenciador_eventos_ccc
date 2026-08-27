<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmbientePagamento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A credencial de um ambiente do provedor de pagamento.
 *
 * **Este e o model mais sensivel do sistema.** Cinco dos seus campos sao
 * segredo de instituicao financeira, e todos os cinco sao cifrados em repouso
 * pelo mesmo mecanismo que ja cifra o CPF do participante (D-08): a chave da
 * aplicacao, guardada fora do banco. A consequencia pratica e que a linha crua
 * nao tem nada legivel — nem para quem tem acesso direto ao banco, nem para
 * quem levar um backup embaixo do braco.
 *
 * Tres regras governam o uso deste model, e nenhuma delas e detalhe:
 *
 * 1. **Segredo nao volta para a tela.** Nao ha acessor de leitura para o
 *    navegador, e o metodo que monta o retrato da tela (paraTela) devolve
 *    apenas booleanos dizendo se cada segredo existe. Nem mascarado
 *    parcialmente: quatro digitos revelados ainda sao quatro digitos a menos
 *    para quem tenta adivinhar o resto.
 * 2. **Segredo nao vai para auditoria nem para log.** Os cinco campos estao
 *    em $hidden, o que os tira de toJson() e de qualquer despejo acidental.
 * 3. **O certificado mora no banco; o arquivo em disco e cache.** Ver
 *    materializarCertificado().
 *
 * @property int $id
 * @property string $gateway
 * @property AmbientePagamento $ambiente
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $certificado
 * @property string|null $certificado_nome
 * @property Carbon|null $certificado_expira_em
 * @property string|null $chave_pix
 * @property string|null $webhook_hmac
 * @property bool $ativo
 * @property int|null $atualizado_por_id
 * @property Carbon|null $updated_at
 */
class CredencialPagamento extends Model
{
    /** O unico provedor cadastravel hoje. A coluna existe para o futuro. */
    public const GATEWAY_EFI = 'efi';

    /**
     * Os campos que nunca podem sair daqui em direcao a tela, ao log ou a
     * auditoria. A lista e publica de proposito: as Actions a usam para
     * descrever *quais* deles mudaram sem tocar no conteudo.
     *
     * @var array<int, string>
     */
    public const CAMPOS_SIGILOSOS = [
        'client_id',
        'client_secret',
        'certificado',
        'chave_pix',
        'webhook_hmac',
    ];

    protected $table = 'credenciais_pagamento';

    protected $fillable = [
        'gateway',
        'ambiente',
        'client_id',
        'client_secret',
        'certificado',
        'certificado_nome',
        'certificado_expira_em',
        'chave_pix',
        'webhook_hmac',
        'ativo',
        'atualizado_por_id',
    ];

    /**
     * Rede de seguranca contra despejo acidental: toJson(), dd() de colecao e
     * qualquer resposta que serialize o model deixam os cinco de fora sem
     * depender de quem escreveu a chamada lembrar disso.
     *
     * @var array<int, string>
     */
    protected $hidden = self::CAMPOS_SIGILOSOS;

    /**
     * @return BelongsTo<User, $this>
     */
    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por_id');
    }

    /**
     * A credencial que o sistema usa de verdade, se houver alguma.
     *
     * Devolve nulo quando ninguem cadastrou nada — e nesse caso o sistema cai
     * para o arquivo de ambiente (DA-26), que e o que mantem a maquina de
     * desenvolvimento e a integracao continua rodando sem banco semeado.
     */
    public static function ativaDe(string $gateway = self::GATEWAY_EFI): ?self
    {
        return static::query()
            ->where('gateway', $gateway)
            ->where('ativo', true)
            ->first();
    }

    /**
     * @param  Builder<CredencialPagamento>  $consulta
     * @return Builder<CredencialPagamento>
     */
    public function scopeDoGateway(Builder $consulta, string $gateway = self::GATEWAY_EFI): Builder
    {
        return $consulta->where('gateway', $gateway);
    }

    public function temValorGuardado(string $campo): bool
    {
        if (! in_array($campo, self::CAMPOS_SIGILOSOS, true)) {
            return false;
        }

        $valor = $this->getAttribute($campo);

        return is_string($valor) && $valor !== '';
    }

    /**
     * Esta credencial da para operar?
     *
     * O certificado entra na conta porque a Efi exige mTLS: sem ele nao ha
     * nem token, quanto mais cobranca.
     */
    public function estaCompleta(): bool
    {
        return $this->temValorGuardado('client_id')
            && $this->temValorGuardado('client_secret')
            && $this->temValorGuardado('chave_pix')
            && $this->temValorGuardado('certificado');
    }

    /**
     * Escreve o certificado em disco e devolve o caminho.
     *
     * O SDK da Efi so aceita um caminho de arquivo — ele nao tem porta de
     * entrada para conteudo em memoria. Como a fonte da verdade e o banco
     * (DA-25), alguem precisa fazer a ponte, e e aqui.
     *
     * Tres cuidados, cada um por um motivo concreto:
     *
     * - **O arquivo nasce com permissao 0600, antes de ter conteudo.** Criar
     *   e depois restringir deixaria uma janela, curta mas real, em que a
     *   chave privada da conta bancaria fica legivel para qualquer processo
     *   da maquina. Por isso o arquivo temporario e criado vazio, restringido
     *   e so entao preenchido.
     * - **O nome carrega o resumo do conteudo.** Trocar o certificado gera um
     *   nome novo, e nao a sobrescrita de um arquivo que outro processo pode
     *   estar lendo naquele instante.
     * - **A troca final e um rename.** No mesmo sistema de arquivos ele e
     *   atomico: ninguem nunca le um certificado pela metade.
     *
     * O arquivo e cache, nao fonte da verdade: pode ser apagado a qualquer
     * momento e sera reescrito na proxima chamada. Fica em storage/, que o
     * .gitignore ja bloqueia — pasta e extensao.
     */
    public function materializarCertificado(): string
    {
        $conteudo = (string) $this->certificado;

        if ($conteudo === '') {
            return '';
        }

        $pasta = storage_path('certificados');

        if (! is_dir($pasta)) {
            @mkdir($pasta, 0700, true);
        }

        @chmod($pasta, 0700);

        $caminho = $pasta.'/'.$this->gateway.'-'.$this->ambiente->value
            .'-'.substr(hash('sha256', $conteudo), 0, 16).'.pem';

        if (is_file($caminho) && @file_get_contents($caminho) === $conteudo) {
            @chmod($caminho, 0600);

            return $caminho;
        }

        $temporario = $caminho.'.'.bin2hex(random_bytes(6)).'.tmp';

        // Nasce vazio e ja restrito. So depois recebe a chave privada.
        touch($temporario);
        chmod($temporario, 0600);
        file_put_contents($temporario, $conteudo);
        rename($temporario, $caminho);
        @chmod($caminho, 0600);

        return $caminho;
    }

    /**
     * O retrato que a tela recebe.
     *
     * **Nada sigiloso aparece aqui — nem cortado, nem mascarado.** O que a
     * pessoa precisa saber e se existe um valor guardado; qual e o valor, ela
     * ja sabia quando cadastrou, e o navegador nao tem por que saber de novo.
     *
     * @return array<string, mixed>
     */
    public function paraTela(): array
    {
        return [
            'id' => (int) $this->id,
            'gateway' => $this->gateway,
            'ambiente' => $this->ambiente->value,
            'ambiente_rotulo' => $this->ambiente->rotulo(),
            'ativo' => (bool) $this->ativo,
            'completa' => $this->estaCompleta(),
            'tem_client_id' => $this->temValorGuardado('client_id'),
            'tem_client_secret' => $this->temValorGuardado('client_secret'),
            'tem_chave_pix' => $this->temValorGuardado('chave_pix'),
            'tem_webhook_hmac' => $this->temValorGuardado('webhook_hmac'),
            'tem_certificado' => $this->temValorGuardado('certificado'),
            'certificado_nome' => $this->certificado_nome,
            'certificado_expira_em' => $this->certificado_expira_em?->format('d/m/Y'),
            'certificado_vencido' => $this->certificado_expira_em?->isPast() ?? false,
            'atualizado_em' => $this->updated_at?->format('d/m/Y H:i'),
            'atualizado_por' => $this->atualizadoPor?->name,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ambiente' => AmbientePagamento::class,
            // As cinco conversoes que fazem o valor chegar cifrado ao banco.
            // "encrypted" e o mesmo mecanismo usado em Inscricao::documento
            // (D-08): a chave e APP_KEY, que nao mora no banco.
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'certificado' => 'encrypted',
            'chave_pix' => 'encrypted',
            'webhook_hmac' => 'encrypted',
            'certificado_expira_em' => 'datetime',
            'ativo' => 'boolean',
        ];
    }
}

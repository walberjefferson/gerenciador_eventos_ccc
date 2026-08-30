<?php

declare(strict_types=1);

namespace App\Services\Auditoria;

use App\Enums\AcaoAuditada;
use App\Models\LogAuditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava o rastro de uma acao administrativa.
 *
 * Duas regras governam este arquivo, e as duas sao decisoes, nao detalhes:
 *
 * 1. **Auditoria e testemunha, nao porteiro.** Quando este servico e chamado,
 *    a acao administrativa ja aconteceu: a vaga ja voltou, o dinheiro ja foi
 *    reconhecido. Se a gravacao do rastro falhar — banco cheio, coluna
 *    mudada, seja o que for — o erro vai para o log da aplicacao e a vida
 *    segue. O contrario transformaria esta tabela no ponto unico de falha do
 *    painel inteiro: um problema no registro derrubaria toda acao
 *    administrativa do sistema.
 *
 * 2. **Nada sensivel entra em "dados".** CPF, hash de documento, senha, token
 *    e o Pix inteiro ficam de fora por construcao, e nao por cuidado de quem
 *    chama. Quando um campo desses muda, o registro guarda que ele mudou — o
 *    nome do campo, nunca o conteudo. Auditoria que vaza dado pessoal
 *    inverte o proprio proposito: vira um segundo lugar de onde vazar.
 */
class RegistrarAcao
{
    /**
     * Pedacos de nome de campo que nunca podem ter o conteudo gravado.
     *
     * A comparacao e por trecho, e nao por nome exato, de proposito: assim
     * "documento_hash", "hash_documento" e "documento_cifrado" caem todos na
     * mesma rede, sem que alguem precise lembrar de cadastrar cada variacao.
     *
     * @var array<int, string>
     */
    private const CAMPOS_SENSIVEIS = [
        'cpf',
        'documento',
        'hash',
        'senha',
        'password',
        'token',
        'secret',
        'segredo',
        'assinatura',
        'signature',
        'pix',
        'qr_code',
        'qrcode',
        'copia_e_cola',
        'payload',
        'api_key',
        'chave',
        'remember',
    ];

    /**
     * O que aparece no lugar do conteudo sensivel. Texto legivel de proposito:
     * quem le a auditoria precisa entender que o campo mudou e que o conteudo
     * foi omitido por decisao, e nao que a gravacao falhou.
     */
    public const OCULTO = '[omitido por seguranca]';

    /**
     * Registra a acao. Nunca lanca excecao.
     *
     * @param  string  $entidade  o nome curto do que foi afetado ("inscricao", "evento", "atividade")
     * @param  array<string, mixed>  $dados  o antes/depois do que mudou; passa pelo filtro de dado sensivel
     * @param  User|null  $responsavel  quem agiu; quando vem nulo, usamos quem esta autenticado
     * @return LogAuditoria|null o registro gravado, ou nulo se a gravacao falhou (a acao segue valendo)
     */
    public function __invoke(
        AcaoAuditada $acao,
        string $entidade,
        ?int $entidadeId = null,
        array $dados = [],
        ?string $motivo = null,
        ?User $responsavel = null,
    ): ?LogAuditoria {
        try {
            $usuario = $responsavel ?? $this->usuarioAutenticado();
            $pedido = request();

            $linha = [
                'usuario_id' => $usuario?->getKey(),
                'acao' => $acao->value,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'motivo' => $motivo === null ? null : mb_substr(trim($motivo), 0, 500),
                'dados' => $dados === [] ? null : $this->limpar($dados),
                'ip' => $pedido->ip(),
                'agente' => mb_substr((string) $pedido->userAgent(), 0, 255) ?: null,
            ];

            // O ponto de gravacao fica dentro de uma transacao propria, e isso
            // e o que faz a regra 1 ser verdade no PostgreSQL. Quando este
            // servico e chamado de dentro da transacao da acao, o Laravel abre
            // um ponto de retorno (SAVEPOINT) em vez de uma transacao nova: se
            // a insercao estourar, o banco volta so ate esse ponto e a
            // transacao da acao continua utilizavel.
            //
            // Sem isso, no PostgreSQL um unico comando com erro envenena a
            // transacao inteira — e a falha de auditoria desfaria exatamente a
            // acao que ela deveria apenas testemunhar.
            return DB::transaction(fn (): LogAuditoria => LogAuditoria::create($linha), 1);
        } catch (Throwable $erro) {
            // Aqui esta a regra 1 do cabecalho. O rastro se perdeu; a acao,
            // nao. O log da aplicacao guarda o suficiente para alguem
            // reconstruir o que aconteceu e descobrir por que a gravacao
            // falhou.
            Log::error('Falha ao registrar acao na auditoria.', [
                'acao' => $acao->value,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'erro' => $erro->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Atalho para os cadastros: registra o que mudou entre o antes e o depois
     * de um model, sem as colunas que nao dizem nada a quem le (as datas de
     * controle do proprio Eloquent).
     *
     * @param  array<string, mixed>  $antes
     */
    public function alteracao(Model $model, array $antes, string $entidade, ?User $responsavel = null): ?LogAuditoria
    {
        $depois = $model->getAttributes();
        $mudou = [];

        foreach ($depois as $campo => $valor) {
            if (in_array($campo, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $anterior = $antes[$campo] ?? null;

            if ($this->mesmoValor($anterior, $valor)) {
                continue;
            }

            $mudou[$campo] = ['antes' => $anterior, 'depois' => $valor];
        }

        if ($mudou === []) {
            return null;
        }

        return $this(
            AcaoAuditada::Alterou,
            $entidade,
            $model->getKey() === null ? null : (int) $model->getKey(),
            ['alteracoes' => $mudou],
            responsavel: $responsavel,
        );
    }

    /**
     * Tira do pacote tudo que nao pode ser guardado, em qualquer nivel.
     *
     * Campo sensivel nao some do registro: ele fica, com o conteudo trocado
     * pelo aviso de omissao. A diferenca importa — saber que o CPF mudou e
     * informacao de auditoria legitima; saber qual e o CPF nao e.
     *
     * @param  array<array-key, mixed>  $dados
     * @return array<array-key, mixed>
     */
    public function limpar(array $dados): array
    {
        $limpo = [];

        foreach ($dados as $campo => $valor) {
            if (is_string($campo) && $this->ehSensivel($campo)) {
                $limpo[$campo] = self::OCULTO;

                continue;
            }

            if (is_array($valor)) {
                $limpo[$campo] = $this->limpar($valor);

                continue;
            }

            if (is_object($valor)) {
                $limpo[$campo] = method_exists($valor, '__toString')
                    ? mb_substr((string) $valor, 0, 500)
                    : null;

                continue;
            }

            $limpo[$campo] = is_string($valor) ? mb_substr($valor, 0, 500) : $valor;
        }

        return $limpo;
    }

    private function ehSensivel(string $campo): bool
    {
        $campo = mb_strtolower($campo);

        foreach (self::CAMPOS_SENSIVEIS as $pedaco) {
            if (str_contains($campo, $pedaco)) {
                return true;
            }
        }

        return false;
    }

    private function mesmoValor(mixed $antes, mixed $depois): bool
    {
        if ($antes === null || $depois === null) {
            return $antes === $depois;
        }

        if (is_scalar($antes) && is_scalar($depois)) {
            return (string) $antes === (string) $depois;
        }

        return $antes == $depois;
    }

    private function usuarioAutenticado(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }
}

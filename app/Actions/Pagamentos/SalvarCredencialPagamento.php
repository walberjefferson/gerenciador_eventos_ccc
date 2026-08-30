<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Enums\AcaoAuditada;
use App\Enums\AmbientePagamento;
use App\Models\CredencialPagamento;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Grava a credencial de um ambiente do provedor de pagamento.
 *
 * Tres regras, e nenhuma e detalhe de implementacao:
 *
 * 1. **Campo em branco significa "mantem o que esta guardado".** Nunca
 *    "apaga". Isso e consequencia direta de o valor nunca voltar para a tela:
 *    se o campo aparece vazio porque nao mostramos o que ja existe, entao
 *    envia-lo vazio nao pode ser lido como um pedido de remocao. Quem quiser
 *    trocar, digita o novo.
 * 2. **A auditoria registra QUE mudou, nunca O QUE mudou.** Vai para o rastro
 *    o nome dos campos alterados e o ambiente; nao vai nenhum valor, nem
 *    antes, nem depois, nem cortado pela metade. Uma auditoria que guardasse
 *    a credencial seria um segundo lugar de onde vaza-la.
 * 3. **Trocar a credencial joga fora o token guardado.** Sem isso o sistema
 *    seguiria falando com a Efi usando o token emitido para a credencial
 *    antiga por ate uma hora — e o sintoma em producao (recusa intermitente
 *    que se cura sozinha) e dos mais dificeis de diagnosticar.
 *
 * A gravacao acontece em transacao; a auditoria vem depois e, se falhar, nao
 * desfaz nada (Fase 9): auditoria e testemunha, nao porteiro.
 */
class SalvarCredencialPagamento
{
    public function __construct(
        private readonly RegistrarAcao $registrar,
        private readonly ConfiguracaoEfi $configuracao,
    ) {}

    /**
     * @param  array<string, string|null>  $valores  os quatro campos digitados; vazio quer dizer "mantem"
     */
    public function __invoke(
        AmbientePagamento $ambiente,
        array $valores,
        ?UploadedFile $certificado = null,
        ?User $responsavel = null,
        string $gateway = CredencialPagamento::GATEWAY_EFI,
    ): CredencialPagamento {
        $credencial = CredencialPagamento::query()->firstOrNew([
            'gateway' => $gateway,
            'ambiente' => $ambiente,
        ]);

        $nascendo = ! $credencial->exists;

        /** @var list<string> $alterados */
        $alterados = [];

        foreach (['client_id', 'client_secret', 'chave_pix', 'webhook_hmac'] as $campo) {
            $novo = trim((string) ($valores[$campo] ?? ''));

            // Regra 1 do cabecalho: em branco nao apaga.
            if ($novo === '') {
                continue;
            }

            if ((string) $credencial->getAttribute($campo) === $novo) {
                continue;
            }

            $credencial->setAttribute($campo, $novo);
            $alterados[] = $campo;
        }

        if ($certificado instanceof UploadedFile) {
            $conteudo = (string) file_get_contents($certificado->getRealPath());
            $leitura = CredencialPagamento::lerCertificado($conteudo);

            $credencial->certificado = $conteudo;
            // Só o nome, e recortado: e ele que a tela mostra para a pessoa
            // reconhecer qual arquivo esta guardado.
            $credencial->certificado_nome = mb_substr($certificado->getClientOriginalName(), 0, 190);
            $credencial->certificado_expira_em = $leitura['expira_em'];

            $alterados[] = 'certificado';
        }

        if ($responsavel instanceof User) {
            $credencial->atualizado_por_id = (int) $responsavel->getKey();
        }

        DB::transaction(function () use ($credencial): void {
            $credencial->save();
        });

        // Regra 3: a credencial mudou, entao o token da anterior nao vale mais
        // e o retrato em memoria tambem nao.
        $this->configuracao->recarregar();

        // Regra 2: nomes de campo, nunca conteudo. Repare que nao ha nenhum
        // "antes"/"depois" aqui — de proposito.
        ($this->registrar)(
            AcaoAuditada::AlterouCredencialPagamento,
            'credencial-pagamento',
            $credencial->getKey() === null ? null : (int) $credencial->getKey(),
            [
                'gateway' => $gateway,
                'ambiente' => $ambiente->value,
                'cadastro' => $nascendo ? 'criado' : 'atualizado',
                'campos_alterados' => $alterados,
                'certificado_nome' => $credencial->certificado_nome,
            ],
            responsavel: $responsavel,
        );

        return $credencial;
    }
}

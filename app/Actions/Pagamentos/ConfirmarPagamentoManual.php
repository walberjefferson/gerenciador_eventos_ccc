<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Enums\AcaoAuditada;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Exceptions\Pagamentos\ConfirmacaoManualRecusadaException;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Reconhece, na mao, um pagamento que entrou por fora do sistema.
 *
 * Acontece de verdade: a pessoa paga em dinheiro na secretaria, ou faz uma
 * transferencia direta, e ninguem no provedor tem como saber disso. Alguem da
 * organizacao precisa declarar que o dinheiro entrou.
 *
 * E a unica acao do sistema que diz "entrou dinheiro" sem que uma fonte
 * externa tenha reconhecido nada. Por isso:
 *
 * - so o administrador alcanca (permissao "pagamentos.confirmar-manual");
 * - a observacao e obrigatoria — quem declarou precisa dizer o que aconteceu;
 * - fica gravado no pagamento que a origem foi manual e quem foi o
 *   responsavel. Nenhum identificador de provedor e inventado: se o provedor
 *   nao emitiu nada, o campo continua vazio.
 *
 * O destino e exatamente o mesmo de um pagamento reconhecido pelo provedor —
 * cobranca paga, inscricao confirmada, vaga presa virando vaga paga — e por
 * isso o caminho tambem e o mesmo: a Action delega para ConfirmarPagamento, em
 * vez de repetir a regra. O anuncio que sai e o de sempre, InscricaoConfirmada:
 * para o resto do sistema o fato e o mesmo, a pessoa esta confirmada.
 */
class ConfirmarPagamentoManual
{
    public function __construct(
        private readonly ConfirmarPagamento $confirmarPagamento,
        private readonly RegistrarAcao $registrarAcao,
    ) {}

    /**
     * @param  User  $responsavel  quem declarou que o dinheiro entrou
     * @param  MetodoPagamento  $metodo  como o dinheiro chegou (dinheiro, transferencia ou outro)
     * @param  string  $observacao  o que aconteceu, escrito por quem declarou
     * @return bool true se esta chamada foi quem confirmou; false se a inscricao
     *              ja estava confirmada antes
     *
     * @throws InvalidArgumentException quando a observacao vem vazia ou o metodo nao e declaravel na mao
     * @throws ConfirmacaoManualRecusadaException quando a inscricao nao pode mais ser confirmada
     */
    public function __invoke(
        Inscricao $inscricao,
        User $responsavel,
        MetodoPagamento $metodo,
        string $observacao,
        ?Carbon $momento = null,
    ): bool {
        $observacao = trim($observacao);

        if ($observacao === '') {
            throw new InvalidArgumentException('Descreva como o pagamento foi recebido.');
        }

        if (! $metodo->ehManual()) {
            throw new InvalidArgumentException(
                'Pagamento por Pix ou cartao e reconhecido pelo provedor, nao na mao.'
            );
        }

        $observacao = mb_substr($observacao, 0, 500);
        $momento ??= Carbon::now();

        $inscricao = $inscricao->fresh() ?? $inscricao;

        $this->recusarSeNaoPuderConfirmar($inscricao);

        if ($inscricao->situacao === SituacaoInscricao::Confirmada) {
            // Ja esta no destino: declarar de novo nao muda nada e nao gera
            // um segundo registro de dinheiro.
            return false;
        }

        $pagamento = $this->cobrancaEmAberto($inscricao, $metodo, $momento);

        // Daqui em diante a regra e a mesma de sempre. ConfirmarPagamento faz a
        // gravacao condicional da cobranca e da inscricao, converte a vaga presa
        // em vaga paga e anuncia depois que a transacao fecha.
        $confirmou = ($this->confirmarPagamento)($pagamento, $momento);

        if ($confirmou) {
            $this->registrarOrigemManual($pagamento, $responsavel, $metodo, $observacao);

            // E a unica acao do sistema que declara entrada de dinheiro sem
            // nenhuma fonte externa ter reconhecido nada. Por isso o rastro
            // guarda quem declarou, quanto, por qual meio e a explicacao
            // escrita — nada do payload do provedor, que aqui nem existe.
            ($this->registrarAcao)(
                AcaoAuditada::ConfirmouPagamentoManual,
                'inscricao',
                (int) $inscricao->getKey(),
                [
                    'codigo_publico' => $inscricao->codigo_publico,
                    'pagamento_id' => (int) $pagamento->getKey(),
                    'valor_centavos' => (int) $pagamento->valor_centavos,
                    'metodo' => $metodo->value,
                ],
                $observacao,
                $responsavel,
            );

            return true;
        }

        // Chegou aqui porque alguem mexeu na inscricao no mesmo instante: o
        // prazo venceu e a rotina de expiracao passou primeiro, ou outra aba
        // cancelou. A recusa e a mesma, e pelo mesmo motivo.
        $this->recusarSeNaoPuderConfirmar($inscricao->fresh() ?? $inscricao);

        return false;
    }

    /**
     * Recusa, em portugues, o que nao pode mais virar confirmacao.
     *
     * Inscricao expirada nao ressuscita por aqui: a vaga ja voltou para a fila
     * e pode ter dono novo. Confirmar por cima seria vender a mesma vaga duas
     * vezes. Se a pessoa pagou mesmo assim, o caminho e uma inscricao nova — e
     * a devolucao do valor e conversa com a organizacao, porque politica de
     * reembolso ainda nao existe neste sistema.
     */
    private function recusarSeNaoPuderConfirmar(Inscricao $inscricao): void
    {
        if ($inscricao->situacao === SituacaoInscricao::Expirada) {
            throw new ConfirmacaoManualRecusadaException(
                'Esta inscricao expirou e a vaga ja voltou para a fila. '
                .'Nao e possivel confirmar o pagamento por aqui: faca uma nova inscricao.'
            );
        }

        if ($inscricao->situacao === SituacaoInscricao::Cancelada) {
            throw new ConfirmacaoManualRecusadaException(
                'Esta inscricao foi cancelada. Nao e possivel confirmar o pagamento de uma inscricao cancelada.'
            );
        }

        if (! in_array($inscricao->situacao, [SituacaoInscricao::AguardandoPagamento, SituacaoInscricao::Confirmada], true)) {
            throw new ConfirmacaoManualRecusadaException(
                'Esta inscricao nao esta aguardando pagamento.'
            );
        }
    }

    /**
     * A cobranca que sera marcada como paga.
     *
     * Normalmente e a que ja estava aberta. Quando nao ha nenhuma — porque o
     * Pix venceu e ninguem pediu segunda via — abrimos uma cobranca propria,
     * sem provedor, para que o dinheiro recebido tenha onde ser registrado.
     */
    private function cobrancaEmAberto(Inscricao $inscricao, MetodoPagamento $metodo, Carbon $momento): Pagamento
    {
        $aberta = $inscricao->pagamentoPendente();

        if ($aberta instanceof Pagamento) {
            return $aberta;
        }

        return Pagamento::create([
            'inscricao_id' => $inscricao->getKey(),
            // Nao houve provedor nenhum, e o registro diz isso com todas as
            // letras. "id_externo" fica vazio: inventar um identificador de
            // provedor seria falsificar historico de dinheiro.
            'gateway' => 'manual',
            'id_externo' => null,
            'metodo' => $metodo,
            'valor_centavos' => (int) $inscricao->valor_centavos,
            'situacao' => SituacaoPagamento::Pendente,
            'expira_em' => null,
            'metadados' => ['origem' => 'manual', 'criado_em' => $momento->toIso8601String()],
        ]);
    }

    /**
     * Deixa gravado na cobranca que o dinheiro foi reconhecido na mao.
     *
     * Escreve depois da confirmacao de proposito: so quem de fato confirmou
     * escreve. Se um aviso do provedor tivesse chegado no mesmo instante e
     * vencido a corrida, o registro continuaria contando a verdade — que o
     * dinheiro veio pelo provedor, e nao pela declaracao de alguem.
     */
    private function registrarOrigemManual(
        Pagamento $pagamento,
        User $responsavel,
        MetodoPagamento $metodo,
        string $observacao,
    ): void {
        $pagamento->refresh();

        $metadados = is_array($pagamento->metadados) ? $pagamento->metadados : [];

        $metadados['origem'] = 'manual';
        $metadados['metodo_declarado'] = $metodo->value;
        $metadados['observacao'] = $observacao;
        $metadados['responsavel'] = [
            'id' => $responsavel->getKey(),
            'nome' => $responsavel->name,
            'email' => $responsavel->email,
        ];
        $metadados['confirmado_manualmente_em'] = Carbon::now()->toIso8601String();

        // A cobranca guardava o metodo com que foi emitida; agora ela guarda o
        // metodo com que foi realmente paga, sem perder o anterior.
        if ($pagamento->metodo !== $metodo) {
            $metadados['metodo_emitido'] = $pagamento->metodo->value;
        }

        Pagamento::query()
            ->whereKey($pagamento->getKey())
            ->where('situacao', SituacaoPagamento::Pago->value)
            ->update([
                'metodo' => $metodo->value,
                'metadados' => json_encode($metadados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => Carbon::now(),
            ]);
    }
}

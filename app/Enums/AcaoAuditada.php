<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * O verbo de cada acao administrativa que deixa rastro.
 *
 * Sao poucos de proposito. Tres deles — criou, alterou e removeu — servem a
 * qualquer cadastro, porque quem diz *o que* foi mexido e a coluna "entidade"
 * do registro, e nao o verbo. Se cada cadastro tivesse o seu proprio verbo,
 * esta lista cresceria a cada tela nova e a tela de auditoria viraria um menu
 * de trinta opcoes.
 *
 * Os quatro restantes existem separados porque nao sao cadastro: mexem em
 * vaga, em dinheiro ou em quem pode entrar no sistema, e quem le a auditoria
 * precisa encontrar esses casos sem depender de saber qual entidade filtrar.
 */
enum AcaoAuditada: string
{
    case Criou = 'criou';
    case Alterou = 'alterou';
    case Removeu = 'removeu';

    case CancelouInscricao = 'cancelou-inscricao';
    case ConfirmouPagamentoManual = 'confirmou-pagamento-manual';
    case PromoveuUsuario = 'promoveu-usuario';
    case CriouUsuarioAdministrativo = 'criou-usuario-administrativo';

    /**
     * Mexer na credencial do provedor de pagamento — ou trocar qual ambiente
     * esta valendo.
     *
     * Tem verbo proprio, e nao "alterou", porque nao e cadastro: quem faz isso
     * muda para qual conta bancaria o dinheiro do evento vai. Quem le a
     * auditoria precisa achar esses casos sem saber qual entidade filtrar.
     */
    case AlterouCredencialPagamento = 'alterou-credencial-pagamento';

    public function rotulo(): string
    {
        return match ($this) {
            self::Criou => 'Cadastrou',
            self::Alterou => 'Alterou',
            self::Removeu => 'Removeu',
            self::CancelouInscricao => 'Cancelou inscrição',
            self::ConfirmouPagamentoManual => 'Confirmou pagamento na mão',
            self::PromoveuUsuario => 'Mudou o papel de um usuário',
            self::CriouUsuarioAdministrativo => 'Criou conta administrativa',
            self::AlterouCredencialPagamento => 'Mexeu na credencial de pagamento',
        };
    }

    /**
     * As acoes que mexem em vaga, dinheiro ou acesso — as que alguem revisando
     * o sistema procura primeiro.
     *
     * @return array<int, self>
     */
    public static function sensiveis(): array
    {
        return [
            self::CancelouInscricao,
            self::ConfirmouPagamentoManual,
            self::PromoveuUsuario,
            self::CriouUsuarioAdministrativo,
            self::AlterouCredencialPagamento,
        ];
    }
}

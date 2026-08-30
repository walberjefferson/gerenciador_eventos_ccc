<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * O vocabulario de mensagens que o sistema envia ao participante.
 *
 * Cada valor aqui e uma linha possivel em "comunicacoes_enviadas". Junto com a
 * coluna "canal", ele forma a chave que garante que a mesma mensagem nunca
 * chegue duas vezes para a mesma inscricao.
 *
 * Todas sao transacionais: sao consequencia de um ato da propria pessoa
 * (inscrever-se, pagar, perder o prazo) ou de uma decisao da organizacao sobre
 * a inscricao dela. Nao ha comunicacao de divulgacao aqui — por isso nao ha
 * descadastro.
 */
enum TipoComunicacao: string
{
    case InscricaoRecebida = 'inscricao_recebida';
    case LembretePrazo = 'lembrete_prazo';
    case PagamentoConfirmado = 'pagamento_confirmado';
    case PrazoVencido = 'prazo_vencido';
    case InscricaoCancelada = 'inscricao_cancelada';

    /**
     * Nome legivel, para telas administrativas e relatorios de envio.
     */
    public function rotulo(): string
    {
        return match ($this) {
            self::InscricaoRecebida => 'Inscrição recebida',
            self::LembretePrazo => 'Lembrete de prazo',
            self::PagamentoConfirmado => 'Pagamento confirmado',
            self::PrazoVencido => 'Prazo vencido',
            self::InscricaoCancelada => 'Inscrição cancelada',
        };
    }

    /**
     * O assunto do e-mail correspondente.
     *
     * Fica no Enum porque o assunto e a unica parte da mensagem que a pessoa le
     * antes de decidir abrir: precisa dizer o que e sem depender do corpo.
     */
    public function assunto(): string
    {
        return match ($this) {
            self::InscricaoRecebida => 'Recebemos a sua inscrição — falta o pagamento',
            self::LembretePrazo => 'O prazo para pagar a sua inscrição está acabando',
            self::PagamentoConfirmado => 'Pagamento confirmado: sua inscrição está garantida',
            self::PrazoVencido => 'O prazo da sua inscrição venceu',
            self::InscricaoCancelada => 'Sua inscrição foi cancelada',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Sobre o lembrete de prazo
    |--------------------------------------------------------------------------
    |
    | LembretePrazo e enviado UMA UNICA VEZ por inscricao, porque a chave de
    | unicidade e (inscricao, tipo, canal). Isso e correto enquanto o prazo de
    | pagamento nao puder ser esticado — e hoje ele nao pode.
    |
    | No dia em que existir prorrogacao de prazo, esta regra precisa mudar
    | junto: um prazo novo pede um lembrete novo, e a chave passaria a incluir
    | alguma marca do prazo vigente. Nao ha codigo aqui antecipando esse dia:
    | codigo escrito para uma regra que nao existe envelhece antes de ser usado.
    |
    */
}

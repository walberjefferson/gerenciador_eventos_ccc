<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoInscricao;
use App\Models\Cidade;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Pagamentos\GeradorQrCodePix;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cobranca Pix de uma inscricao.
 *
 * As duas rotas sao assinadas: o codigo publico sozinho nunca serve de senha.
 * Quem chega sem assinatura valida — ou com o link vencido — recebe 403 do
 * proprio middleware, antes de o controller existir.
 *
 * O controller nao decide nada sobre dinheiro. Ele le a situacao que o
 * dominio ja gravou (mudada so por aviso do provedor ou por reconciliacao) e
 * a entrega para a tela. Nenhum parametro vindo do navegador muda situacao.
 */
class PagamentoController extends Controller
{
    /**
     * A tela da cobranca: valor, QR Code, copia e cola, prazo e o estado atual.
     */
    public function show(string $codigoPublico, GeradorQrCodePix $gerador): Response
    {
        $inscricao = $this->inscricao($codigoPublico);
        $pagamento = $this->pagamento($inscricao);
        $estado = $this->estado($inscricao, $pagamento);
        $peloSetor = $inscricao->evento?->recebePeloSetor() ?? false;

        return Inertia::render('Inscricoes/Pagamento', [
            'codigo_publico' => $inscricao->codigo_publico,
            'nome_completo' => $inscricao->nome_completo,
            'evento' => [
                'nome' => $inscricao->evento?->nome,
                'slug' => $inscricao->evento?->slug,
            ],
            // Como este evento recebe. A tela desenha duas coisas bem
            // diferentes conforme a resposta, e por isso ela precisa saber.
            'recebe_pelo_setor' => $peloSetor,
            // A chave, o titular e o setor. Vao para a tela SO quando o evento
            // recebe pelo setor — e mesmo assim, so nesta tela, que e assinada
            // e pertence a uma inscricao. A chave nao e segredo (RN-S3), mas
            // ela tambem nao e informacao publica: quem a ve aqui e quem
            // precisa pagar para este setor.
            'setor' => $peloSetor ? $this->setorDaCobranca($inscricao) : null,
            // O comprovante mais recente, se houver, e a URL para mandar outro.
            // A tela le daqui o que dizer ao participante — "em conferência",
            // "recusado, veja o motivo", "aceito". Nada disso e situacao de
            // inscricao (RN-S7): a inscricao segue exatamente onde estava ate
            // uma pessoa conferir.
            'comprovante' => $peloSetor
                ? $inscricao->comprovanteMaisRecente()?->paraTela()
                : null,
            'url_comprovante' => $peloSetor
                ? $this->urlAssinada($inscricao, 'inscricoes.comprovante')
                : null,
            'limites_do_comprovante' => $peloSetor ? [
                'tamanho_maximo_mb' => 5,
                'tipos' => ['JPG', 'PNG', 'WebP', 'PDF'],
            ] : null,
            'estado' => $estado,
            'situacao' => $inscricao->situacao->value,
            'situacao_rotulo' => $inscricao->situacao->rotulo(),
            'valor_centavos' => $inscricao->valor_centavos,
            'moeda' => $inscricao->evento?->moeda ?? 'BRL',
            'prazo_pagamento' => $inscricao->prazo_pagamento?->toIso8601String(),
            'confirmada_em' => $inscricao->confirmada_em?->toIso8601String(),
            'pagamento' => $pagamento === null ? null : [
                'situacao' => $pagamento->situacao->value,
                'situacao_rotulo' => $pagamento->situacao->rotulo(),
                // O copia e cola so faz sentido enquanto ainda da para pagar.
                'pix_copia_e_cola' => $estado === 'aguardando' ? $pagamento->pix_copia_e_cola : null,
                'qr_code_svg' => $estado === 'aguardando' ? $gerador->svg($pagamento->pix_copia_e_cola) : null,
                'expira_em' => $pagamento->expira_em?->toIso8601String(),
                'pago_em' => $pagamento->pago_em?->toIso8601String(),
            ],
            // A tela pergunta por aqui, de tempos em tempos, se o dinheiro
            // chegou. Assinada tambem: consultar situacao e ler dado de
            // inscricao alheia.
            'url_situacao' => $this->urlAssinada($inscricao, 'inscricoes.situacao'),
            // A pagina do participante: linha do tempo e historico da
            // cobranca. Assinada tambem, pelo mesmo motivo.
            'url_acompanhamento' => $this->urlAssinada($inscricao, 'inscricoes.acompanhar'),
            'sucesso' => session('sucesso'),
        ]);
    }

    /**
     * O setor da inscricao, do jeito que a tela de pagamento precisa dele.
     *
     * Devolve nulo quando a inscricao nao tem setor ou o setor perdeu a chave
     * entre o cadastro do evento e agora. Nesse caso a tela nao inventa um Pix:
     * ela diz que a organizacao precisa ser procurada, que e a unica coisa
     * verdadeira que ela pode dizer.
     *
     * @return array<string, mixed>|null
     */
    private function setorDaCobranca(Inscricao $inscricao): ?array
    {
        $setor = $inscricao->setor();

        if (! $setor instanceof Cidade || ! $setor->estaPreparadaParaReceber()) {
            return null;
        }

        return [
            'nome' => $setor->nome,
            'chave_pix' => $setor->chave_pix,
            'titular' => $setor->titular_chave_pix,
            'responsavel' => $setor->responsavel?->name,
            // O telefone de quem responde pelo setor, para a duvida que aparece
            // com o dinheiro ja fora da conta. Nulo quando ninguem cadastrou —
            // a tela simplesmente nao oferece o contato, em vez de mostrar um
            // convite para ligar para lugar nenhum.
            'telefone' => $setor->telefone_responsavel,
        ];
    }

    /**
     * Resposta curta para a tela saber se algo mudou, sem recarregar a pagina.
     */
    public function situacao(string $codigoPublico): JsonResponse
    {
        $inscricao = $this->inscricao($codigoPublico);
        $pagamento = $this->pagamento($inscricao);

        return response()->json([
            'situacao' => $inscricao->situacao->value,
            'situacao_rotulo' => $inscricao->situacao->rotulo(),
            'estado' => $this->estado($inscricao, $pagamento),
            'pago_em' => $pagamento?->pago_em?->toIso8601String(),
        ]);
    }

    private function inscricao(string $codigoPublico): Inscricao
    {
        return Inscricao::query()
            ->with('evento')
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();
    }

    /**
     * A cobranca que interessa: a que ainda pode ser paga; na falta dela, a
     * ultima emitida — e ela que explica por que nao da mais para pagar.
     */
    private function pagamento(Inscricao $inscricao): ?Pagamento
    {
        return $inscricao->pagamentoPendente()
            ?? $inscricao->pagamentos()->orderByDesc('id')->first();
    }

    /**
     * Qual das tres telas o participante vai ver.
     *
     * A confirmacao vem sempre do dominio: a inscricao so fica confirmada
     * quando o pagamento e reconhecido por fonte confiavel. Aqui apenas
     * lemos o que ja esta gravado.
     */
    private function estado(Inscricao $inscricao, ?Pagamento $pagamento): string
    {
        if ($inscricao->situacao === SituacaoInscricao::Confirmada) {
            return 'confirmada';
        }

        if ($inscricao->situacao !== SituacaoInscricao::AguardandoPagamento) {
            return 'expirada';
        }

        if ($pagamento === null || ! $pagamento->estaAberto()) {
            return 'expirada';
        }

        // O prazo pode ter vencido sem que a rotina de expiracao tenha passado
        // ainda. Para quem esta olhando a tela, ja acabou.
        if ($inscricao->prazoVencido() || ($pagamento->expira_em !== null && $pagamento->expira_em < Carbon::now())) {
            return 'expirada';
        }

        return 'aguardando';
    }

    /**
     * Link assinado para outra tela do participante.
     *
     * A validade acompanha a da tela: prazo de pagamento com 24 horas de
     * folga, para o link nao morrer antes da pagina que o usa.
     */
    private function urlAssinada(Inscricao $inscricao, string $rota): string
    {
        $prazo = $inscricao->prazo_pagamento ?? Carbon::now()->addDay();

        return URL::temporarySignedRoute(
            $rota,
            $prazo->copy()->addDay(),
            ['codigo_publico' => $inscricao->codigo_publico],
        );
    }
}

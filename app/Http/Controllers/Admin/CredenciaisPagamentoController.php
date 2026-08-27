<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Pagamentos\AtivarAmbientePagamento;
use App\Actions\Pagamentos\SalvarCredencialPagamento;
use App\Enums\AmbientePagamento;
use App\Exceptions\Payments\EfiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SalvarCredencialPagamentoRequest;
use App\Models\CredencialPagamento;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use App\Services\Payments\Efi\EfiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * A tela que guarda a credencial do provedor de pagamento.
 *
 * **E a tela mais perigosa do sistema**, e o codigo aqui e escrito com isso em
 * mente. Duas regras o governam:
 *
 * 1. **Nada sigiloso sai daqui em direcao ao navegador.** Nem inteiro, nem
 *    mascarado pela metade. O que a tela recebe e a existencia de cada valor
 *    (CredencialPagamento::paraTela()), e mais nada. O corolario e a regra do
 *    campo em branco: como o valor guardado nunca aparece, enviar o campo
 *    vazio significa "mantem", jamais "apaga".
 * 2. **Nenhuma mensagem de erro repete o que foi digitado.** Erro de tela vira
 *    captura de tela, e captura de tela vira anexo de chamado.
 *
 * A permissao "pagamentos.credenciais" e exclusiva do administrador (D-55).
 * Quem organiza o evento no dia a dia nao precisa dela nem uma vez, e recebe
 * 403 — nao uma tela vazia.
 */
class CredenciaisPagamentoController extends Controller
{
    public function index(Request $pedido, ConfiguracaoEfi $configuracao): Response
    {
        $this->exigirPermissao($pedido);

        $cadastros = CredencialPagamento::query()
            ->doGateway()
            ->with('atualizadoPor:id,name')
            ->get()
            ->keyBy(fn (CredencialPagamento $linha): string => $linha->ambiente->value);

        return inertia('Admin/Pagamentos/Credenciais/Index', [
            'ambientes' => array_map(
                fn (AmbientePagamento $ambiente): array => [
                    'valor' => $ambiente->value,
                    'rotulo' => $ambiente->rotulo(),
                    'eh_producao' => $ambiente->ehProducao(),
                    'cadastro' => $cadastros->get($ambiente->value)?->paraTela(),
                ],
                AmbientePagamento::cases(),
            ),
            // De onde a configuracao em uso esta vindo. Descobrir tarde que o
            // sistema estava lendo o arquivo de ambiente e uma hora perdida.
            'origem' => $configuracao->origem(),
            'ambiente_em_uso' => $configuracao->ambiente(),
            // O endereco do aviso automatico, SEM o valor do webhook. A tela
            // monta a URL completa com o que a pessoa acabou de digitar ou de
            // gerar; o valor ja guardado nunca volta do servidor, nem para
            // compor uma URL. Ver o comentario na propria tela.
            'webhook' => [
                'base' => rtrim((string) config('app.url'), '/').'/'.ltrim((string) config('payments.webhook.path', 'webhooks/pagamentos'), '/'),
                'parametro_assinatura' => 'hmac',
            ],
            'sucesso' => $pedido->session()->get('sucesso'),
            'erro' => $pedido->session()->get('erro'),
            'teste' => $pedido->session()->get('teste'),
        ]);
    }

    public function salvar(
        SalvarCredencialPagamentoRequest $pedido,
        string $ambiente,
        SalvarCredencialPagamento $salvar,
    ): RedirectResponse {
        $escolhido = $this->ambiente($ambiente);

        $salvar(
            $escolhido,
            $pedido->valoresDigitados(),
            $pedido->certificadoEnviado(),
            $pedido->user(),
        );

        return back()->with(
            'sucesso',
            'Credenciais de '.$escolhido->rotulo().' guardadas. '.
            'Use "Testar conexao" antes de ativar este ambiente.'
        );
    }

    /**
     * Troca qual ambiente esta valendo.
     *
     * A confirmacao explicita e exigida AQUI, e nao apenas na tela: uma
     * confirmacao que vive so no navegador cai com um clique no lugar errado,
     * com um formulario reenviado ou com uma chamada feita fora da tela. A
     * partir de "producao" comeca a sair cobranca de verdade.
     */
    public function ativar(
        Request $pedido,
        string $ambiente,
        AtivarAmbientePagamento $ativar,
    ): RedirectResponse {
        $this->exigirPermissao($pedido);

        $escolhido = $this->ambiente($ambiente);

        if ($escolhido->ehProducao() && $pedido->boolean('confirmacao') !== true) {
            return back()->with(
                'erro',
                'Ativar producao exige confirmacao: a partir dai as cobrancas passam a ser reais.'
            );
        }

        try {
            $ativar($escolhido, $pedido->user());
        } catch (RuntimeException $erro) {
            // Mensagem escrita por nos, em portugues, sobre o que falta —
            // nunca sobre o que foi digitado.
            return back()->with('erro', $erro->getMessage());
        }

        return back()->with(
            'sucesso',
            'O sistema passou a usar o ambiente de '.$escolhido->rotulo().'.'
        );
    }

    /**
     * Testa a credencial de um ambiente contra a Efi de verdade.
     *
     * Percorre os mesmos passos, na mesma ordem, que o comando
     * "php artisan efi:diagnostico" da Fase 8a — configuracao completa,
     * certificado que abre e nao venceu, e token aceito pela Efi. A ordem
     * importa: e a ordem em que essas coisas falham na vida real, e dizer em
     * qual passo parou e metade do diagnostico.
     *
     * O teste NAO emite cobranca. O comando de terminal emite porque roda
     * travado em ambiente local; esta tela roda em producao, e uma cobranca de
     * teste ali seria dinheiro de verdade.
     *
     * Testa o ambiente escolhido, mesmo que ele nao seja o ativo: quem vai
     * virar a chave para producao precisa provar a credencial ANTES.
     */
    public function testar(Request $pedido, string $ambiente, ConfiguracaoEfi $configuracao): RedirectResponse
    {
        $this->exigirPermissao($pedido);

        $escolhido = $this->ambiente($ambiente);

        $credencial = CredencialPagamento::query()
            ->doGateway()
            ->where('ambiente', $escolhido->value)
            ->first();

        if (! $credencial instanceof CredencialPagamento) {
            return back()->with('teste', [
                'ambiente' => $escolhido->value,
                'sucesso' => false,
                'mensagem' => 'Nao ha credencial cadastrada para '.$escolhido->rotulo().'.',
            ]);
        }

        $daCredencial = $configuracao->paraCredencial($credencial);

        try {
            $daCredencial->exigirCompleta();
        } catch (EfiException $erro) {
            return back()->with('teste', [
                'ambiente' => $escolhido->value,
                'sucesso' => false,
                'mensagem' => $erro->getMessage(),
            ]);
        }

        $validade = CredencialPagamento::lerCertificado((string) $credencial->certificado)['expira_em'];

        if ($validade !== null && $validade->isPast()) {
            return back()->with('teste', [
                'ambiente' => $escolhido->value,
                'sucesso' => false,
                'mensagem' => 'O certificado venceu em '.$validade->format('d/m/Y').
                    '. Baixe um novo no painel da Efi e envie aqui.',
            ]);
        }

        try {
            // O token e pedido e descartado: o que interessa e saber se a Efi
            // aceitou o certificado e a credencial. O valor dele nao aparece
            // na tela, no log nem na sessao, em hipotese nenhuma.
            $cliente = new EfiClient($daCredencial, Cache::store());
            $cliente->esquecerToken();
            $cliente->token();
            $cliente->esquecerToken();
        } catch (EfiException $erro) {
            // A mensagem do EfiException ja passou pelo filtro que remove
            // qualquer valor nosso que a Efi tenha repetido de volta.
            return back()->with('teste', [
                'ambiente' => $escolhido->value,
                'sucesso' => false,
                'mensagem' => $erro->getMessage(),
            ]);
        } catch (Throwable) {
            // De proposito, a mensagem original NAO entra na resposta: falha
            // inesperada costuma trazer caminho de arquivo, e o caminho leva
            // ao certificado.
            return back()->with('teste', [
                'ambiente' => $escolhido->value,
                'sucesso' => false,
                'mensagem' => 'Nao foi possivel falar com a Efi agora. Tente novamente em instantes.',
            ]);
        }

        return back()->with('teste', [
            'ambiente' => $escolhido->value,
            'sucesso' => true,
            'mensagem' => 'A Efi aceitou o certificado e a credencial de '.$escolhido->rotulo().'.',
        ]);
    }

    private function exigirPermissao(Request $pedido): void
    {
        abort_unless($pedido->user()?->can('pagamentos.credenciais') === true, 403);
    }

    /**
     * Le o ambiente da URL sem nunca devolver producao por engano: um valor
     * desconhecido e 404, e nao "vai que e homologacao".
     */
    private function ambiente(string $valor): AmbientePagamento
    {
        $ambiente = AmbientePagamento::tryFrom($valor);

        abort_unless($ambiente instanceof AmbientePagamento, 404);

        return $ambiente;
    }
}

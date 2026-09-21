<?php

declare(strict_types=1);

use App\Actions\Comunicacao\ReenviarComunicacao;
use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Enums\AcaoAuditada;
use App\Enums\TipoComunicacao;
use App\Mail\InscricaoRecebidaMail;
use App\Mail\LinkDeAcessoInscricao;
use App\Mail\PagamentoConfirmadoMail;
use App\Models\Cidade;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use App\Models\LogAuditoria;
use App\Models\Responsavel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * As acoes da ficha: reenviar uma mensagem e entregar o ingresso.
 *
 * O reenvio existe porque "eu nunca recebi nada" e a frase mais comum de quem
 * atende. Ele e deliberadamente diferente do envio automatico: passa por cima
 * da trava de copia unica, porque a segunda copia e exatamente o que se esta
 * pedindo — e por isso quem registra o reenvio e a auditoria, nao a tabela de
 * comunicacoes enviadas.
 *
 * Cada mensagem so vale na situacao dela. Mandar "falta o pagamento" para quem
 * ja pagou e pior do que nao mandar nada: a pessoa acredita no que esta
 * escrito.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
    Mail::fake();
});

/** Confirma o pagamento pelo caminho real, que e o que emite o ingresso. */
function confirmarParaAcoes(Inscricao $inscricao): Inscricao
{
    app(ConfirmarPagamento::class)($inscricao->pagamentoPendente());

    return $inscricao->fresh();
}

it('reenvia o comprovante de quem ja pagou e registra na auditoria', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = confirmarParaAcoes($cenario->inscrever());

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::CONFIRMACAO])
        ->assertRedirect()
        ->assertSessionHas('sucesso', fn (string $mensagem): bool => str_contains($mensagem, 'Confirmação com o ingresso')
            && str_contains($mensagem, (string) $inscricao->email));

    Mail::assertQueued(PagamentoConfirmadoMail::class, fn (PagamentoConfirmadoMail $email): bool => $email->hasTo((string) $inscricao->email)
        // O ingresso vai junto: e a razao de existir deste reenvio.
        && $email->codigoIngresso === $inscricao->ingresso->codigo);

    $registro = LogAuditoria::query()->where('acao', AcaoAuditada::ReenviouComunicacao->value)->first();

    expect($registro)->not->toBeNull()
        ->and($registro->entidade)->toBe('inscricao')
        ->and($registro->entidade_id)->toBe($inscricao->id)
        ->and($registro->dados['mensagem'])->toBe('Confirmação com o ingresso')
        // O endereco importa: trocar o e-mail e reenviar o link sao duas acoes
        // legitimas que, em sequencia, entregam o acesso a outra caixa.
        ->and($registro->dados['destino'])->toBe($inscricao->email);
});

it('reenvia as instrucoes de pagamento para quem ainda deve', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::INSTRUCOES_DE_PAGAMENTO])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Mail::assertQueued(InscricaoRecebidaMail::class, fn (InscricaoRecebidaMail $email): bool => $email->hasTo((string) $inscricao->email));
});

it('envia o link de acesso de uma inscricao ativa', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::LINK_DE_ACESSO])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Este e o unico e-mail sincrono do sistema, e continua sendo (D-49).
    Mail::assertSent(LinkDeAcessoInscricao::class, function (LinkDeAcessoInscricao $email) use ($inscricao): bool {
        expect($email->inscricoes)->toHaveCount(1)
            ->and($email->inscricoes[0]['link'])->toContain('signature=');

        return $email->hasTo((string) $inscricao->email);
    });
});

it('recusa a mensagem que nao vale para a situacao da inscricao', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // A criacao da inscricao ja enfileirou o e-mail automatico. O que se quer
    // provar aqui e o que a ACAO fez, entao a caixa de saida recomeca vazia.
    Mail::fake();

    // Ela ainda nao pagou: nao ha comprovante nenhum a reenviar.
    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::CONFIRMACAO])
        ->assertSessionHasErrors('tipo');

    Mail::assertNothingQueued();
});

it('reenvia mesmo quando a mensagem ja tinha saido uma vez', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // O envio automatico ja aconteceu e deixou o registro que impede a segunda
    // copia. O reenvio administrativo passa por cima disso de proposito.
    $jaEnviadas = ComunicacaoEnviada::query()
        ->where('inscricao_id', $inscricao->id)
        ->where('tipo', TipoComunicacao::InscricaoRecebida->value)
        ->count();

    expect($jaEnviadas)->toBe(1);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::INSTRUCOES_DE_PAGAMENTO])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Mail::assertQueued(InscricaoRecebidaMail::class);

    // E a tabela do envio automatico continua com UMA linha: o reenvio nao
    // escreve nela, senao o historico de envios automaticos passaria a mentir.
    expect(ComunicacaoEnviada::query()
        ->where('inscricao_id', $inscricao->id)
        ->where('tipo', TipoComunicacao::InscricaoRecebida->value)
        ->count())->toBe(1);
});

it('nao oferece reenvio nenhum quando a inscricao nao tem e-mail', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // O unico jeito de a coluna ficar vazia e uma inscricao feita no balcao por
    // quem nao tinha e-mail para dar.
    DB::table('inscricoes')->where('id', $inscricao->id)->update(['email' => '']);

    Mail::fake();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertInertia(fn (Assert $pagina) => $pagina->where('reenvios', [])->etc());

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::INSTRUCOES_DE_PAGAMENTO])
        ->assertSessionHasErrors('tipo');

    Mail::assertNothingQueued();
});

it('recusa quem nao tem a permissao de reenviar', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    Mail::fake();

    $this->actingAs(Cenario::usuarioCom('portaria'))
        ->post("/admin/inscricoes/{$inscricao->id}/reenviar", ['tipo' => ReenviarComunicacao::INSTRUCOES_DE_PAGAMENTO])
        ->assertForbidden();

    Mail::assertNothingQueued();
});

it('leva o QR e o caminho do PDF ate a ficha de quem esta confirmado', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = confirmarParaAcoes($cenario->inscrever());

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('ingresso.codigo_formatado', $inscricao->ingresso->codigoFormatado())
            ->where('ingresso.url_pdf', route('admin.inscricoes.ingresso', ['inscricao' => $inscricao->id]))
            ->has('ingresso.qr')
            ->etc());
});

it('nao mostra ingresso nenhum na ficha de quem ainda nao pagou', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertInertia(fn (Assert $pagina) => $pagina->where('ingresso', null)->etc());
});

it('entrega o ingresso em PDF pelo painel', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = confirmarParaAcoes($cenario->inscrever());

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}/ingresso");

    $resposta->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename="ingresso-'.$inscricao->codigo_publico.'.pdf"')
        ->assertOk();

    // O ingresso e de uma pessoa so: nenhum intermediario guarda copia.
    expect($resposta->headers->get('cache-control'))->toContain('no-store')
        ->and($resposta->headers->get('cache-control'))->toContain('private')
        ->and($resposta->getContent())->toStartWith('%PDF');
});

it('recusa o ingresso de quem ainda nao pagou', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // A mesma dupla tranca do controller do participante: sem confirmacao nao
    // ha ingresso, e um PDF com codigo que a portaria recusaria seria pior do
    // que nenhum PDF.
    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}/ingresso")
        ->assertForbidden();
});

it('nao entrega o ingresso de inscricao de outro setor', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = confirmarParaAcoes($cenario->inscrever());

    $usuario = Cenario::usuarioCom('responsavel-setor');

    $outroSetor = Cidade::factory()->create(['uf' => 'SP']);
    $responsavel = Responsavel::factory()->create(['user_id' => $usuario->id]);
    $responsavel->setores()->attach($outroSetor->id);

    $this->actingAs($usuario)
        ->get("/admin/inscricoes/{$inscricao->id}/ingresso")
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Actions\Presenca\DesfazerPresenca;
use App\Actions\Presenca\RegistrarPresenca;
use App\Enums\AcaoAuditada;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Presenca\IngressoRecusado;
use App\Models\Evento;
use App\Models\Ingresso;
use App\Models\Inscricao;
use App\Models\LogAuditoria;
use App\Services\Admin\NumerosDePresenca;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Cenario;

/*
|--------------------------------------------------------------------------
| O portao: quem entra, quem nao entra, e por que
|--------------------------------------------------------------------------
|
| As quatro recusas sao testadas UMA A UMA, e cada uma pela MENSAGEM que a
| pessoa do portao vai ler em voz alta — nao so pelo fato de ter sido recusada.
| A diferenca importa: "codigo nao encontrado" e "entrada ja registrada as
| 14h02" levam a conversas completamente diferentes com quem esta na fila, e um
| teste que so contasse recusas deixaria as duas trocarem de lugar sem avisar.
|
| A ORDEM das recusas tambem e testada, porque ela e a regra: um ingresso de
| outro evento cuja inscricao foi cancelada precisa dizer "e de outro evento",
| que e o problema que a pessoa consegue resolver.
|
*/

/** Um ingresso pronto para ser conferido, com a inscricao ja confirmada. */
function ingressoPara(Evento $evento, string $nome = 'Joana Ferreira da Silva'): Ingresso
{
    $inscricao = Inscricao::factory()->confirmada()->create([
        'evento_id' => $evento->id,
        'nome_completo' => $nome,
    ]);

    return Ingresso::factory()->create(['inscricao_id' => $inscricao->id]);
}

beforeEach(function (): void {
    Cenario::semearPapeis();

    $this->evento = Evento::factory()->create(['nome' => 'Copa de Agosto']);
    $this->porteiro = Cenario::usuarioCom('portaria');
});

it('aceita o ingresso valido e devolve quem entrou', function (): void {
    $ingresso = ingressoPara($this->evento, 'Marcia Aparecida Lopes');

    $resultado = app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);

    expect($resultado['aceito'])->toBeTrue()
        ->and($resultado['participante']['nome'])->toBe('Marcia Aparecida Lopes')
        ->and($resultado['usado_por'])->toBe($this->porteiro->name)
        ->and($resultado['codigo_formatado'])->toBe($ingresso->codigoFormatado());

    $ingresso->refresh();

    expect($ingresso->usado_em)->not->toBeNull()
        ->and($ingresso->usado_por)->toBe($this->porteiro->id);
});

it('aceita o codigo digitado com hifen, em minuscula e com espaco', function (): void {
    $ingresso = ingressoPara($this->evento);

    // O mesmo codigo, escrito como quem le um papel amassado o escreveria.
    $digitado = strtolower(implode('-', str_split($ingresso->codigo, 4)));

    $resultado = app(RegistrarPresenca::class)(' '.$digitado.' ', $this->evento, $this->porteiro);

    expect($resultado['aceito'])->toBeTrue();
});

it('recusa 1: codigo que nao existe', function (): void {
    $recusa = null;

    try {
        app(RegistrarPresenca::class)('ZZZZZZZZZZZZ', $this->evento, $this->porteiro);
    } catch (IngressoRecusado $erro) {
        $recusa = $erro;
    }

    expect($recusa)->not->toBeNull()
        ->and($recusa->motivo)->toBe(IngressoRecusado::NAO_ENCONTRADO)
        ->and($recusa->getMessage())->toContain('Código não encontrado');
});

it('recusa 2: ingresso de outro evento, dizendo de qual', function (): void {
    $outro = Evento::factory()->create(['nome' => 'Retiro do Ano Passado']);
    $ingresso = ingressoPara($outro);

    $recusa = null;

    try {
        app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);
    } catch (IngressoRecusado $erro) {
        $recusa = $erro;
    }

    expect($recusa->motivo)->toBe(IngressoRecusado::OUTRO_EVENTO)
        ->and($recusa->getMessage())->toBe('Este ingresso é do evento Retiro do Ano Passado.');

    // E nada foi gravado: recusar nao pode marcar entrada.
    expect($ingresso->fresh()->usado_em)->toBeNull();
});

it('recusa 3: inscricao cancelada depois de paga, com a data do cancelamento', function (): void {
    $ingresso = ingressoPara($this->evento);

    $momento = Carbon::create(2026, 8, 14, 9, 30);

    $ingresso->inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => $momento,
    ]);

    $recusa = null;

    try {
        app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);
    } catch (IngressoRecusado $erro) {
        $recusa = $erro;
    }

    expect($recusa->motivo)->toBe(IngressoRecusado::INSCRICAO_NAO_CONFIRMADA)
        ->and($recusa->getMessage())->toBe('A inscrição foi cancelada em 14/08/2026 09:30.');
});

it('recusa 4: segunda leitura, com a hora e o nome de quem registrou a primeira', function (): void {
    $ingresso = ingressoPara($this->evento);

    $primeira = Carbon::create(2026, 8, 15, 14, 2);

    app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro, $primeira);

    $recusa = null;

    try {
        app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);
    } catch (IngressoRecusado $erro) {
        $recusa = $erro;
    }

    expect($recusa->motivo)->toBe(IngressoRecusado::JA_UTILIZADO)
        ->and($recusa->getMessage())->toBe(
            sprintf('Entrada já registrada em 15/08/2026 14:02, por %s.', $this->porteiro->name)
        )
        ->and($recusa->dados['usado_em'])->toBe('15/08/2026 14:02')
        ->and($recusa->dados['usado_por'])->toBe($this->porteiro->name);

    // A entrada original continua exatamente onde estava: a segunda leitura
    // nao pode reescrever a hora nem o responsavel da primeira.
    expect($ingresso->fresh()->usado_em->format('d/m/Y H:i'))->toBe('15/08/2026 14:02');
});

it('diz que o ingresso e de outro evento antes de falar da inscricao cancelada', function (): void {
    // A ordem e a regra: quem esta na fila com o ingresso do ano passado
    // precisa ouvir "isso e de outro evento", que e o problema que ele
    // consegue entender — e nao "sua inscricao foi cancelada", que o mandaria
    // procurar o erro no lugar errado.
    $outro = Evento::factory()->create(['nome' => 'Encontro de 2025']);
    $ingresso = ingressoPara($outro);

    $ingresso->inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => Carbon::now(),
    ]);

    $recusa = null;

    try {
        app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);
    } catch (IngressoRecusado $erro) {
        $recusa = $erro;
    }

    expect($recusa->motivo)->toBe(IngressoRecusado::OUTRO_EVENTO);
});

it('grava auditoria da entrada, com responsavel e momento', function (): void {
    $ingresso = ingressoPara($this->evento);

    app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);

    $registro = LogAuditoria::query()->where('acao', AcaoAuditada::RegistrouPresenca->value)->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->entidade)->toBe('ingresso')
        ->and($registro->entidade_id)->toBe($ingresso->id)
        ->and($registro->usuario_id)->toBe($this->porteiro->id)
        ->and($registro->dados['inscricao_id'])->toBe($ingresso->inscricao_id);
});

it('nao deixa duas leituras simultaneas registrarem a mesma entrada duas vezes', function (): void {
    $ingresso = ingressoPara($this->evento);

    // A segunda conferencia comeca com o MESMO retrato do ingresso que a
    // primeira teve — que e o que acontece com dois voluntarios lendo o mesmo
    // papel ao mesmo tempo. A trava dentro da transacao e o que precisa segurar.
    $copia = Ingresso::query()->find($ingresso->id);

    app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);

    expect(fn () => app(RegistrarPresenca::class)($copia->codigo, $this->evento, $this->porteiro))
        ->toThrow(IngressoRecusado::class);

    expect(DB::table('ingressos')->where('id', $ingresso->id)->whereNotNull('usado_em')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Desfazer
|--------------------------------------------------------------------------
*/

it('desfaz a entrada e o ingresso volta a valer', function (): void {
    $ingresso = ingressoPara($this->evento);
    $organizador = Cenario::usuarioCom('organizador');

    app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);

    $desfez = app(DesfazerPresenca::class)($ingresso->fresh(), $organizador);

    expect($desfez)->toBeTrue();

    $ingresso->refresh();

    expect($ingresso->usado_em)->toBeNull()
        ->and($ingresso->usado_por)->toBeNull();

    // E o mesmo ingresso e aceito de novo — que e o ponto de desfazer.
    $resultado = app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);

    expect($resultado['aceito'])->toBeTrue();
});

it('desfazer duas vezes nao e erro: a segunda apenas nao faz nada', function (): void {
    $ingresso = ingressoPara($this->evento);
    $organizador = Cenario::usuarioCom('organizador');

    app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);

    expect(app(DesfazerPresenca::class)($ingresso->fresh(), $organizador))->toBeTrue()
        ->and(app(DesfazerPresenca::class)($ingresso->fresh(), $organizador))->toBeFalse();
});

it('grava auditoria do desfazer, guardando a entrada que foi apagada', function (): void {
    $ingresso = ingressoPara($this->evento);
    $organizador = Cenario::usuarioCom('organizador');

    app(RegistrarPresenca::class)($ingresso->codigo, $this->evento, $this->porteiro);
    app(DesfazerPresenca::class)($ingresso->fresh(), $organizador);

    $registro = LogAuditoria::query()->where('acao', AcaoAuditada::DesfezPresenca->value)->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->usuario_id)->toBe($organizador->id)
        ->and($registro->entidade_id)->toBe($ingresso->id)
        // A hora da entrada apagada so continua existindo aqui.
        ->and($registro->dados['entrada_desfeita']['usado_em'])->not->toBeNull()
        ->and($registro->dados['entrada_desfeita']['usado_por_id'])->toBe($this->porteiro->id);
});

it('o desfazer e uma acao sensivel da auditoria, e o registrar nao e', function (): void {
    // Um evento de mil pessoas gera mil "registrou presenca" num dia. Se ele
    // entrasse no filtro de acoes sensiveis, afogaria as oito que existem para
    // ser encontradas.
    expect(AcaoAuditada::sensiveis())->toContain(AcaoAuditada::DesfezPresenca)
        ->and(AcaoAuditada::sensiveis())->not->toContain(AcaoAuditada::RegistrouPresenca);
});

/*
|--------------------------------------------------------------------------
| Presentes x faltantes
|--------------------------------------------------------------------------
*/

it('conta presentes e faltantes, e os dois sempre fecham com os esperados', function (): void {
    $primeiro = ingressoPara($this->evento);
    ingressoPara($this->evento);
    ingressoPara($this->evento);

    $numeros = app(NumerosDePresenca::class)->paraEvento($this->evento);

    expect($numeros)->toBe(['presentes' => 0, 'faltantes' => 3, 'confirmadas' => 3]);

    app(RegistrarPresenca::class)($primeiro->codigo, $this->evento, $this->porteiro);

    $numeros = app(NumerosDePresenca::class)->paraEvento($this->evento);

    expect($numeros)->toBe(['presentes' => 1, 'faltantes' => 2, 'confirmadas' => 3])
        ->and($numeros['presentes'] + $numeros['faltantes'])->toBe($numeros['confirmadas']);
});

it('nao conta quem nao esta confirmado, nem de nenhum dos dois lados', function (): void {
    ingressoPara($this->evento);

    // Aguardando pagamento e cancelada: nenhuma das duas e esperada no portao.
    Inscricao::factory()->create(['evento_id' => $this->evento->id]);
    Inscricao::factory()->cancelada()->create(['evento_id' => $this->evento->id]);

    // E o evento do lado nao entra na conta de jeito nenhum.
    $outro = Evento::factory()->create();
    $doOutro = ingressoPara($outro);
    app(RegistrarPresenca::class)($doOutro->codigo, $outro, $this->porteiro);

    expect(app(NumerosDePresenca::class)->paraEvento($this->evento))
        ->toBe(['presentes' => 0, 'faltantes' => 1, 'confirmadas' => 1]);
});

it('conta como faltante a inscricao confirmada que ainda nao tem ingresso', function (): void {
    // O caso de quem pagou antes desta entrega e espera o comando de backfill.
    // Sem o "left join" ela sumiria dos dois lados e o total nao fecharia.
    Inscricao::factory()->confirmada()->create(['evento_id' => $this->evento->id]);

    expect(app(NumerosDePresenca::class)->paraEvento($this->evento))
        ->toBe(['presentes' => 0, 'faltantes' => 1, 'confirmadas' => 1]);
});

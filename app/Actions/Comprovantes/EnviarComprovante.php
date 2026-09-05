<?php

declare(strict_types=1);

namespace App\Actions\Comprovantes;

use App\Enums\SituacaoComprovante;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Pagamentos\ComprovanteRecusadoException;
use App\Models\ComprovantePagamento;
use App\Models\Evento;
use App\Models\Inscricao;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda o comprovante que o participante mandou pela tela dele.
 *
 * **Isto nao confirma nada** (RN-S7). A inscricao continua exatamente onde
 * estava — aguardando pagamento, com o mesmo prazo — e nenhuma vaga muda de
 * lugar. O que acontece aqui e o registro de uma AFIRMACAO: "eu paguei, olha o
 * arquivo". Quem transforma isso em dinheiro reconhecido e uma pessoa, no
 * caminho de ConferirComprovante.
 *
 * Tres cuidados com o arquivo, cada um por um motivo concreto:
 *
 * 1. **Disco privado.** Um comprovante de Pix traz nome, valor, banco e conta
 *    de gente de verdade. Ele nao entra em storage/app/public, que o
 *    `storage:link` espelha para a web, e o disco escolhido nao tem URL: nao
 *    existe endereco para adivinhar (RN-S11).
 * 2. **O nome do arquivo e gerado aqui.** O nome que a pessoa deu vai para a
 *    coluna `nome_original`, nunca para o caminho: nome vindo de terceiro em
 *    caminho de servidor e como se escreve travessia de diretorio por acidente.
 * 3. **A extensao sai do tipo conferido, nao do nome enviado.** O Request ja
 *    conferiu o conteudo com `mimetypes`; e desse conteudo que a extensao vem.
 */
class EnviarComprovante
{
    /**
     * A extensao que cada tipo aceito ganha em disco.
     *
     * @var array<string, string>
     */
    private const EXTENSOES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * @throws ComprovanteRecusadoException quando esta inscricao nao pode receber comprovante
     */
    public function __invoke(Inscricao $inscricao, UploadedFile $arquivo): ComprovantePagamento
    {
        $this->recusarSeNaoPuderReceber($inscricao);

        $caminho = $this->guardar($inscricao, $arquivo);

        // Uma transacao por causa da RN-S6: o comprovante anterior em aberto e
        // SUBSTITUIDO, e substituir sao duas escritas — a linha e o arquivo
        // velho. Se a segunda falhasse sozinha, sobraria um arquivo orfao em
        // disco; se a primeira falhasse, existiriam dois comprovantes em aberto
        // — e o indice unico parcial do banco recusaria, o que ja e a ultima
        // linha de defesa desta mesma regra.
        return DB::transaction(function () use ($inscricao, $arquivo, $caminho): ComprovantePagamento {
            $emAberto = ComprovantePagamento::query()
                ->where('inscricao_id', $inscricao->getKey())
                ->emAberto()
                ->lockForUpdate()
                ->first();

            $atributos = [
                'caminho' => $caminho,
                // mb_substr e nao substr: nome de arquivo com acento e comum, e
                // cortar no meio de um caractere de dois bytes geraria texto
                // invalido na coluna.
                'nome_original' => mb_substr($arquivo->getClientOriginalName(), 0, 180),
                'mime' => (string) $arquivo->getMimeType(),
                'tamanho_bytes' => (int) $arquivo->getSize(),
                'situacao' => SituacaoComprovante::Enviado,
                'enviado_em' => Carbon::now(),
                'conferido_por_id' => null,
                'conferido_em' => null,
                'motivo_recusa' => null,
            ];

            if ($emAberto instanceof ComprovantePagamento) {
                $anterior = $emAberto->caminho;

                $emAberto->update($atributos);

                // O arquivo velho sai do disco: ninguem vai conferi-lo, e
                // guardar comprovante que nao aponta para linha nenhuma so
                // acumula dado pessoal sem dono.
                Storage::disk('comprovantes')->delete($anterior);

                return $emAberto->refresh();
            }

            // Depois de uma RECUSA, o envio novo cria linha nova: o historico
            // da recusa — e o motivo escrito nela — nao se apaga.
            return ComprovantePagamento::create($atributos + ['inscricao_id' => $inscricao->getKey()]);
        });
    }

    /**
     * Quem nao esta aguardando pagamento nao tem o que comprovar.
     *
     * Inscricao confirmada ja teve o dinheiro reconhecido; expirada ja devolveu
     * a vaga; cancelada nao existe mais. Aceitar comprovante nesses tres casos
     * criaria uma fila de conferencia sobre coisas que nao viram nada.
     */
    private function recusarSeNaoPuderReceber(Inscricao $inscricao): void
    {
        $evento = $inscricao->relationLoaded('evento')
            ? $inscricao->evento
            : $inscricao->evento()->first();

        if (! $evento instanceof Evento || ! $evento->recebePeloSetor()) {
            throw new ComprovanteRecusadoException(
                'Este evento recebe pelo provedor de pagamento: o Pix é reconhecido automaticamente '
                .'e não há comprovante a enviar.'
            );
        }

        if ($inscricao->situacao === SituacaoInscricao::Confirmada) {
            throw new ComprovanteRecusadoException('Esta inscrição já está confirmada.');
        }

        if ($inscricao->situacao !== SituacaoInscricao::AguardandoPagamento) {
            throw new ComprovanteRecusadoException(
                'Esta inscrição não está mais aguardando pagamento, então não há comprovante a enviar.'
            );
        }
    }

    /**
     * Escreve o arquivo no disco privado e devolve o caminho gravado.
     *
     * A pasta e organizada por ano e por codigo publico da inscricao: o ano
     * mantem o diretorio navegavel com o tempo, e o codigo agrupa as tentativas
     * de uma mesma pessoa. Nem um nem outro e segredo — o que protege o arquivo
     * e nao existir rota que o sirva sem conferir quem pede (RN-S11).
     */
    private function guardar(Inscricao $inscricao, UploadedFile $arquivo): string
    {
        $extensao = self::EXTENSOES[(string) $arquivo->getMimeType()] ?? 'bin';

        $pasta = 'comprovantes/'.Carbon::now()->year.'/'.$inscricao->codigo_publico;

        return (string) $arquivo->storeAs(
            $pasta,
            Str::lower((string) Str::ulid()).'.'.$extensao,
            ['disk' => 'comprovantes'],
        );
    }
}

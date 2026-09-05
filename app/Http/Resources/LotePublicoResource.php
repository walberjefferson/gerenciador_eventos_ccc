<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Lote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Um lote de inscricao como ele aparece nas telas publicas.
 *
 * TODOS OS LOTES CHEGAM A TELA, e nao so o que vale agora (RN-L4): o encerrado
 * mostra de onde o preco veio e o futuro mostra para onde ele vai — e essa e a
 * unica razao de existir de um lote. So o vigente chega marcado como
 * selecionavel, e mesmo assim quem decide continua sendo o servidor: o
 * lote_id do envio e conferido de novo na hora de gravar (RN-L5).
 *
 * A situacao vem escrita em palavras porque a tela nao pode ser o unico lugar
 * onde ela existe: quem le por leitor de tela, ou quem nao distingue cinza de
 * preto, precisa da mesma informacao que a cor daria.
 *
 * @mixin Lote
 */
class LotePublicoResource extends JsonResource
{
    /**
     * Sem o envelope "data": os props do Inertia chegam direto como lotes[].
     *
     * @var string|null
     */
    public static $wrap = null;

    private ?int $loteVigenteId = null;

    private ?Carbon $momento = null;

    /**
     * A lista inteira, ja sabendo qual deles e o vigente.
     *
     * Quem resolve o vigente e App\Actions\Inscricoes\ResolverLoteVigente
     * (RN-L3): este Resource nao decide nada, so escreve o resultado.
     *
     * @param  Collection<int, Lote>  $lotes
     * @return array<int, array<string, mixed>>
     */
    public static function lista(Collection $lotes, ?int $loteVigenteId, ?Carbon $momento = null): array
    {
        return $lotes
            ->map(fn (Lote $lote): array => (new self($lote))->doLoteVigente($loteVigenteId, $momento)->resolve())
            ->all();
    }

    public function doLoteVigente(?int $loteVigenteId, ?Carbon $momento = null): self
    {
        $this->loteVigenteId = $loteVigenteId;
        $this->momento = $momento;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $situacao = $this->situacaoEm($this->momento, $this->loteVigenteId);

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'posicao' => $this->posicao,
            'valor_centavos' => $this->valor_centavos,
            'disponivel_ate' => $this->disponivel_ate?->toIso8601String(),
            'quantidade' => $this->quantidade,
            // Quantas ainda cabem, nunca quantas foram tomadas: a tela publica
            // nao precisa do contador interno para dizer o que falta.
            'vagas_restantes' => $this->vagasRestantes(),
            'situacao' => $situacao,
            'situacao_rotulo' => self::situacaoEmPalavras($situacao),
            'limite_rotulo' => $this->limiteEmPalavras($situacao),
            // A tela obedece; o servidor decide de novo no envio.
            'selecionavel' => $situacao === 'vigente',
        ];
    }

    /**
     * @param  'encerrado'|'vigente'|'futuro'  $situacao
     */
    private static function situacaoEmPalavras(string $situacao): string
    {
        return match ($situacao) {
            'vigente' => 'Lote atual',
            'futuro' => 'Em breve',
            default => 'Encerrado',
        };
    }

    /**
     * Ate quando, ou ate quantos, este lote vale — em uma frase.
     *
     * O lote encerrado diz POR QUE encerrou, e nao apenas que encerrou: quem
     * chegou tarde entende se perdeu a data ou se perdeu a vaga, e isso muda a
     * pressa com que ela olha para o lote seguinte.
     *
     * @param  'encerrado'|'vigente'|'futuro'  $situacao
     */
    private function limiteEmPalavras(string $situacao): string
    {
        $semVaga = $this->quantidade !== null && $this->vagasRestantes() === 0;

        if ($situacao === 'encerrado') {
            return $semVaga ? 'Vagas esgotadas' : 'Prazo encerrado em '.$this->disponivel_ate?->format('d/m/Y');
        }

        $partes = [];

        if ($this->disponivel_ate !== null) {
            $partes[] = 'até '.$this->disponivel_ate->format('d/m/Y');
        }

        if ($this->quantidade !== null) {
            $restantes = (int) $this->vagasRestantes();
            $partes[] = $restantes === 1 ? 'resta 1 vaga' : "restam {$restantes} vagas";
        }

        return $partes === [] ? '' : ucfirst(implode(' · ', $partes));
    }
}

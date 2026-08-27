<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * O evento como ele aparece na porta da rua.
 *
 * E de proposito muito menor que o EventoPublicoResource: a home apresenta e
 * encaminha, entao ela precisa de nome, quando e, um resumo e o endereco da
 * vitrine. Nao ha aqui id interno, contagem de inscritos nem vaga restante —
 * numero de vaga na entrada vira pressao sem contexto, e fica errado no segundo
 * seguinte. O identificador publico e o slug.
 *
 * @mixin Evento
 */
class EventoEmDestaqueResource extends JsonResource
{
    /**
     * Quantos caracteres da descricao cabem num resumo de entrada.
     */
    private const LIMITE_DO_RESUMO = 180;

    /**
     * Sem o envelope "data": os props do Inertia chegam direto.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'nome' => $this->nome,
            'slug' => $this->slug,
            'resumo' => $this->resumo(),
            'data_inicio' => $this->data_inicio->toDateString(),
            'data_fim' => $this->data_fim->toDateString(),
            'periodo_rotulo' => $this->periodoEmPalavras(),
            'situacao' => $this->situacao->value,
            'situacao_rotulo' => $this->situacao->rotulo(),
            // Quem decide se da para se inscrever e o servidor, sempre. A tela
            // so obedece: sem isto verdadeiro, nao existe botao de inscricao.
            'inscricoes_abertas' => $this->inscricoesEstaoAbertas(),
            'abre_em_rotulo' => $this->aberturaEmPalavras(),
        ];
    }

    /**
     * A descricao encurtada. Nao existe coluna de resumo, e criar uma seria
     * mudanca de dominio — nesta fase, mostra-se menos.
     */
    private function resumo(): ?string
    {
        $descricao = $this->descricao;

        if ($descricao === null || trim($descricao) === '') {
            return null;
        }

        return Str::limit(trim($descricao), self::LIMITE_DO_RESUMO);
    }

    private function periodoEmPalavras(): string
    {
        if ($this->data_inicio->isSameDay($this->data_fim)) {
            return 'Dia '.$this->data_inicio->format('d/m/Y');
        }

        return 'De '.$this->data_inicio->format('d/m/Y').' a '.$this->data_fim->format('d/m/Y');
    }

    /**
     * Quando as inscricoes abrem. So faz sentido para quem ainda vai abrir:
     * para o evento ja aberto, a frase seria sobre o passado.
     */
    private function aberturaEmPalavras(): ?string
    {
        if ($this->inscricoesEstaoAbertas()) {
            return null;
        }

        return 'As inscrições abrem em '
            .$this->inscricoes_abrem_em->format('d/m/Y').' às '
            .$this->inscricoes_abrem_em->format('H:i').'.';
    }
}

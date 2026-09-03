<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\CuidaDaEstruturaDoEvento;
use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoteRequest;
use App\Models\Evento;
use App\Models\Lote;
use Illuminate\Http\RedirectResponse;

/**
 * Os lotes de inscricao de um evento.
 *
 * Cada lote e um degrau de preco com prazo de validade: vale ate uma data, ate
 * acabarem as vagas dele, ou ate o que vier primeiro. A posicao ordena a
 * sucessao e nao se repete dentro do evento — o banco cobra isso e o formulario
 * avisa antes.
 *
 * Nada aqui apaga em silencio: lote de onde alguem ja se inscreveu nao e
 * excluido nem tem a quantidade reduzida abaixo do que ja saiu (RN-L12). Seria
 * apagar de onde aquelas pessoas vieram e por qual valor entraram.
 */
class LoteController extends Controller
{
    use CuidaDaEstruturaDoEvento;
    use RegistraAuditoria;

    public function store(LoteRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $lote = Lote::create($request->dadosDoLote());

        $this->auditarCriacao($lote, 'lote');

        return back()->with('sucesso', 'Lote acrescentado.');
    }

    public function update(LoteRequest $request, Evento $evento, Lote $lote): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $lote->evento_id);

        $antes = $lote->getRawOriginal();

        $lote->update($request->dadosDoLote());

        $this->auditarAlteracao($lote, $antes, 'lote');

        // RN-L7 — o aviso existe porque a pergunta e inevitavel: quem acabou de
        // mudar o valor precisa saber que ele NAO alcanca quem ja se inscreveu.
        return back()->with(
            'sucesso',
            'Lote atualizado. O novo valor vale para quem se inscrever daqui em diante: '
            .'quem já se inscreveu continua devendo o valor que viu.'
        );
    }

    public function destroy(Evento $evento, Lote $lote): RedirectResponse
    {
        $this->authorize('update', $evento);
        $this->confirmarQueEDoEvento($evento, $lote->evento_id);

        $inscricoes = $lote->inscricoes()->count();

        if ($inscricoes > 0) {
            return back()->withErrors([
                'exclusao' => "Não é possível excluir este lote: {$inscricoes} inscrição(ões) vieram dele. "
                    .'Apagá-lo apagaria o registro de por qual degrau essas pessoas entraram. '
                    .'Encerre o lote pela data ou pela quantidade em vez de excluir.',
            ]);
        }

        $lote->delete();

        $this->auditarRemocao($lote, 'lote');

        return back()->with('sucesso', 'Lote excluído.');
    }
}

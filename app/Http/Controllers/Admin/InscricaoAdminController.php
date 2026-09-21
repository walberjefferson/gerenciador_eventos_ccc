<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Comunicacao\ReenviarComunicacao;
use App\Actions\Inscricoes\AtualizarInscricaoAdministrativa;
use App\Enums\AcaoAuditada;
use App\Enums\MetodoPagamento;
use App\Enums\Sexo;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Exceptions\Inscricoes\InscricaoInvalidaException;
use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AtualizarInscricaoRequest;
use App\Http\Resources\Admin\LinhaDaInscricaoResource;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Admin\FiltroDeInscricoes;
use App\Services\Ingressos\GeradorQrCodeIngresso;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * A lista de inscricoes: onde o organizador acha uma pessoa.
 *
 * Os filtros se combinam — evento, situacao, setor, grupo, atividade
 * escolhida, situacao da cobranca e periodo — e a busca por texto olha nome,
 * e-mail e codigo publico. **CPF nao entra**: esta cifrado e a impressao
 * digital so serve para comparar o numero inteiro, nunca um pedaco.
 *
 * A paginacao carrega os filtros junto, para que a segunda pagina seja mesmo a
 * continuacao do que a pessoa estava vendo.
 */
class InscricaoAdminController extends Controller
{
    use RegistraAuditoria;

    public function index(Request $pedido): Response
    {
        $this->authorize('viewAny', Inscricao::class);

        $filtro = FiltroDeInscricoes::doPedido($pedido);

        $pagina = $filtro->consulta()
            ->paginate(25)
            ->withQueryString();

        return inertia('Admin/Inscricoes/Index', [
            'inscricoes' => [
                'dados' => collect($pagina->items())
                    ->map(fn (Inscricao $inscricao): array => LinhaDaInscricaoResource::paraTela($inscricao))
                    ->all(),
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'por_pagina' => $pagina->perPage(),
                'links' => [
                    'anterior' => $pagina->previousPageUrl(),
                    'proxima' => $pagina->nextPageUrl(),
                ],
            ],
            'filtros' => $filtro->valores(),
            'opcoes' => $this->opcoes($filtro),
            'pode_exportar' => $pedido->user()?->can('inscricoes.exportar') ?? false,
            'sucesso' => session('sucesso'),
        ]);
    }

    /**
     * A ficha de uma inscricao, com o historico da cobranca.
     *
     * O historico e a parte que importa: cada cobranca emitida, com a situacao
     * em que parou e — quando o pagamento foi reconhecido na mao — quem
     * declarou isso e o que escreveu. Sem esse registro, "esta pago" e so uma
     * afirmacao sem dono.
     *
     * O CPF nao aparece. Nem cifrado, nem em pedaco, nem a impressao digital.
     */
    public function show(Request $pedido, Inscricao $inscricao): Response
    {
        $this->authorize('view', $inscricao);

        $inscricao->load([
            'evento:id,nome,slug',
            'grupoParticipante:id,nome,cidade_id',
            'grupoParticipante.cidade:id,nome,uf',
            'atividades:id,nome,comeca_em,termina_em',
            'pagamentos',
            'ingresso',
        ]);

        $usuario = $pedido->user();

        // O ingresso so existe para quem esta confirmado, e as duas condicoes
        // sao conferidas juntas: a situacao pode ter mudado depois de o
        // ingresso ter sido emitido (um cancelamento, por exemplo), e ai nao ha
        // mais o que entregar.
        $temIngresso = $inscricao->situacao === SituacaoInscricao::Confirmada
            && $inscricao->ingresso !== null;

        return inertia('Admin/Inscricoes/Show', [
            'inscricao' => [
                'id' => $inscricao->id,
                'codigo_publico' => $inscricao->codigo_publico,
                'nome_completo' => $inscricao->nome_completo,
                'email' => $inscricao->email,
                'telefone' => $inscricao->telefone,
                'evento' => $inscricao->evento?->nome ?? '',
                'cidade' => LinhaDaInscricaoResource::cidade($inscricao),
                'grupo' => $inscricao->grupoParticipante?->nome ?? '',
                // Nulos quando a inscricao e anterior a existencia do campo: a
                // ficha desenha travessao em vez de inventar um valor (RN-X2).
                'sexo' => $inscricao->sexo?->value,
                'sexo_rotulo' => $inscricao->sexo?->rotulo(),
                'situacao' => $inscricao->situacao->value,
                'situacao_rotulo' => $inscricao->situacao->rotulo(),
                'valor_centavos' => $inscricao->valor_centavos,
                'prazo_pagamento' => $inscricao->prazo_pagamento?->toIso8601String(),
                'criada_em' => $inscricao->created_at?->toIso8601String(),
                'confirmada_em' => $inscricao->confirmada_em?->toIso8601String(),
                'expirada_em' => $inscricao->expirada_em?->toIso8601String(),
                'cancelada_em' => $inscricao->cancelada_em?->toIso8601String(),
                'motivo_cancelamento' => $inscricao->motivo_cancelamento,
                'atividades' => $inscricao->atividades
                    ->map(fn ($atividade): array => [
                        'id' => (int) $atividade->id,
                        'nome' => $atividade->nome,
                        // Nulos quando a atividade não tem hora marcada.
                        'comeca_em' => $atividade->comeca_em?->toIso8601String(),
                        'termina_em' => $atividade->termina_em?->toIso8601String(),
                    ])
                    ->all(),
                'esta_ativa' => $inscricao->situacao->estaAtiva(),
                'foi_paga' => $inscricao->pagamentos->contains(
                    fn (Pagamento $pagamento): bool => $pagamento->situacao === SituacaoPagamento::Pago
                ),
            ],
            'cobrancas' => $this->historicoDeCobrancas($inscricao),
            'metodos_manuais' => array_map(
                fn (MetodoPagamento $metodo): array => ['valor' => $metodo->value, 'rotulo' => $metodo->rotulo()],
                MetodoPagamento::manuais(),
            ),
            'pode_cancelar' => $usuario?->can('inscricoes.cancelar') ?? false,
            'pode_confirmar_manualmente' => $usuario?->can('pagamentos.confirmar-manual') ?? false,
            'pode_editar' => $usuario !== null && $usuario->can('editar', $inscricao),
            'pode_reenviar' => $usuario !== null && $usuario->can('reenviarComunicacao', $inscricao),
            // O que faz sentido reenviar AGORA, nesta situacao (RN-A2). A lista
            // vem do servidor e nao da tela: a tela pode estar aberta desde
            // antes de o pagamento ser reconhecido.
            'reenvios' => array_map(
                fn (string $tipo): array => ['valor' => $tipo, 'rotulo' => ReenviarComunicacao::rotulo($tipo)],
                ReenviarComunicacao::disponiveisPara($inscricao),
            ),
            'ingresso' => $temIngresso ? [
                'codigo_formatado' => $inscricao->ingresso?->codigoFormatado(),
                // O desenho vem pronto do servidor, em SVG, como ja acontece na
                // tela do participante: aparece mesmo com a rede ruim e nao
                // depende de biblioteca nenhuma no navegador.
                'qr' => app(GeradorQrCodeIngresso::class)->svg((string) $inscricao->ingresso?->codigo),
                'url_pdf' => route('admin.inscricoes.ingresso', ['inscricao' => $inscricao->id]),
            ] : null,
            'sucesso' => session('sucesso'),
        ]);
    }

    /**
     * O formulario de correcao de uma inscricao.
     *
     * **O CPF NAO VEM PARA CA.** Nem cifrado, nem em pedaco, nem a impressao
     * digital — como na ficha, e pela mesma razao. Evento, lote e valor tambem
     * nao: eles nao sao corrigiveis, e um campo desabilitado na tela so
     * convidaria alguem a perguntar por que.
     */
    public function edit(Request $pedido, Inscricao $inscricao): Response
    {
        $this->authorize('editar', $inscricao);

        $inscricao->load(['evento:id,nome,slug', 'grupoParticipante:id,nome,cidade_id', 'atividades:id']);

        $escolhidas = $inscricao->atividades->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return inertia('Admin/Inscricoes/Editar', [
            'inscricao' => [
                'id' => $inscricao->id,
                'codigo_publico' => $inscricao->codigo_publico,
                'nome_completo' => $inscricao->nome_completo,
                'email' => $inscricao->email,
                'telefone' => $inscricao->telefone,
                'data_nascimento' => $inscricao->data_nascimento?->toDateString(),
                'sexo' => $inscricao->sexo?->value,
                'grupo_participante_id' => $inscricao->grupo_participante_id,
                'evento' => $inscricao->evento?->nome ?? '',
                'situacao' => $inscricao->situacao->value,
                'situacao_rotulo' => $inscricao->situacao->rotulo(),
                // Quem nao tem vaga presa nao move contador nenhum ao trocar de
                // atividade (RN-E3). A tela avisa isso antes, para que ninguem
                // procure depois a vaga que "sumiu".
                'move_vagas' => $inscricao->situacao->estaAtiva(),
                'atividades' => $escolhidas,
            ],
            'grupos' => $this->gruposParaEscolha($inscricao),
            // Os setores que tem ao menos um grupo na lista acima. Eles saem
            // dos proprios grupos, e nao de uma consulta separada, para que as
            // duas listas nunca discordem: setor oferecido sem grupo nenhum
            // seria um beco sem saida no formulario.
            'setores' => $this->setoresDosGrupos($inscricao),
            'sexos' => Sexo::opcoes(),
            'dias' => $this->programacaoDoEvento($inscricao, $escolhidas),
        ]);
    }

    /**
     * Grava a correcao.
     *
     * As recusas de negocio — conflito de horario, idade minima, atividade
     * lotada, e-mail ja usado por outra inscricao ativa — chegam da Action como
     * InscricaoInvalidaException, ja com as frases prontas e agrupadas pelo
     * campo do formulario a que pertencem. Aqui elas so voltam para a tela.
     */
    public function update(
        AtualizarInscricaoRequest $pedido,
        Inscricao $inscricao,
        AtualizarInscricaoAdministrativa $atualizar,
    ): RedirectResponse {
        $this->authorize('editar', $inscricao);

        // O "antes" precisa ser capturado agora: depois do save o Eloquent ja
        // considera os valores novos como originais, e a comparacao nao acharia
        // diferenca nenhuma.
        $antes = $inscricao->getRawOriginal();

        try {
            $mudancas = $atualizar($inscricao, $pedido->dados());
        } catch (InscricaoInvalidaException $recusa) {
            return back()->withErrors($this->primeiraPorCampo($recusa));
        }

        // O diff dos campos de cadastro. O CPF nao entra porque nao foi
        // alterado — e, ainda que estivesse aqui, o filtro da auditoria o
        // omitiria por construcao.
        $this->auditarAlteracao($inscricao, $antes, 'inscricao');

        // As atividades nao aparecem no diff do Eloquent: elas moram na tabela
        // de ligacao. Sem este registro, a auditoria diria que a inscricao
        // mudou sem dizer que a pessoa trocou de atividade.
        if ($this->houveMudancaDeAtividade($mudancas)) {
            $this->auditar(
                AcaoAuditada::Alterou,
                'inscricao',
                (int) $inscricao->getKey(),
                ['atividades' => $mudancas],
            );
        }

        return to_route('admin.inscricoes.show', ['inscricao' => $inscricao->id])
            ->with('sucesso', $this->resumoDaEdicao($mudancas));
    }

    /**
     * @param  array{atividades_adicionadas: array<int, string>, atividades_removidas: array<int, string>, lotacao_excedida: array<int, array{atividade: string, capacidade_antes: int, capacidade_depois: int}>}  $mudancas
     */
    private function houveMudancaDeAtividade(array $mudancas): bool
    {
        return $mudancas['atividades_adicionadas'] !== [] || $mudancas['atividades_removidas'] !== [];
    }

    /**
     * O que a tela diz depois de gravar.
     *
     * A lotacao excedida e dita por extenso, com o nome da atividade: quem
     * autorizou precisa ler de volta o que autorizou, e quem abrir a ficha
     * depois precisa entender por que aquela atividade passou do limite.
     *
     * @param  array{atividades_adicionadas: array<int, string>, atividades_removidas: array<int, string>, lotacao_excedida: array<int, array{atividade: string, capacidade_antes: int, capacidade_depois: int}>}  $mudancas
     */
    private function resumoDaEdicao(array $mudancas): string
    {
        if ($mudancas['lotacao_excedida'] === []) {
            return 'Inscrição atualizada.';
        }

        // Dito por extenso, com o numero de antes e o de depois: quem autorizou
        // precisa ler de volta o que autorizou, e quem abrir a programacao
        // depois precisa entender por que aquele limite mudou.
        $frases = array_map(
            fn (array $excesso): string => $excesso['atividade']
                .' (de '.$excesso['capacidade_antes'].' para '.$excesso['capacidade_depois'].')',
            $mudancas['lotacao_excedida'],
        );

        return 'Inscrição atualizada. A lotação foi excedida em: '.implode(', ', $frases).'.';
    }

    /**
     * A primeira frase de cada campo recusado.
     *
     * A excecao carrega uma lista por campo, para que o participante corrija
     * tudo de uma vez. O formulario do painel mostra uma frase por campo, entao
     * as demais viram uma linha so, separadas por espaco — nenhuma recusa se
     * perde pelo caminho.
     *
     * @return array<string, string>
     */
    private function primeiraPorCampo(InscricaoInvalidaException $recusa): array
    {
        $erros = [];

        foreach ($recusa->erros() as $campo => $mensagens) {
            $erros[$campo] = implode(' ', $mensagens);
        }

        return $erros;
    }

    /**
     * Os grupos que o formulario oferece.
     *
     * Sao os ATIVOS, mais o da propria inscricao quando ele ja foi desativado:
     * sem essa excecao, abrir a tela de quem esta num grupo antigo mudaria a
     * pessoa de grupo em silencio, so por ela ter sido salva.
     *
     * Cada linha leva o `cidade_id` junto porque a tela escolhe o SETOR
     * primeiro e so entao o grupo. O rotulo e so o nome do grupo: repetir o
     * setor dentro dele, depois de ele ja ter sido escolhido no campo de cima,
     * so faria a lista ficar mais longa para dizer a mesma coisa duas vezes.
     *
     * @return array<int, array{id: int, nome: string, cidade_id: int|null}>
     */
    private function gruposParaEscolha(Inscricao $inscricao): array
    {
        return $this->gruposDisponiveis($inscricao)
            ->map(fn (GrupoParticipante $grupo): array => [
                'id' => (int) $grupo->id,
                'nome' => (string) $grupo->nome,
                'cidade_id' => $grupo->cidade_id === null ? null : (int) $grupo->cidade_id,
            ])
            ->all();
    }

    /**
     * Os setores que aparecem no seletor de cima.
     *
     * Sao exatamente os setores dos grupos oferecidos — inclusive o do grupo
     * atual da inscricao, ainda que ele tenha sido desativado. A razao e a
     * mesma de la: abrir a ficha de alguem nao pode mudar o setor dele em
     * silencio, so por ela ter sido salva.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    private function setoresDosGrupos(Inscricao $inscricao): array
    {
        return $this->gruposDisponiveis($inscricao)
            ->map(fn (GrupoParticipante $grupo): ?Cidade => $grupo->cidade)
            ->filter()
            ->unique('id')
            ->sortBy('nome')
            ->values()
            ->map(fn (Cidade $cidade): array => ['id' => (int) $cidade->id, 'nome' => (string) $cidade->nome])
            ->all();
    }

    /**
     * A consulta unica de onde saem as duas listas — grupos e setores.
     *
     * @return Collection<int, GrupoParticipante>
     */
    private function gruposDisponiveis(Inscricao $inscricao): Collection
    {
        return GrupoParticipante::query()
            ->with('cidade:id,nome')
            ->where(fn ($consulta) => $consulta->where('ativo', true)
                ->orWhere('id', $inscricao->grupo_participante_id))
            ->orderBy('nome')
            ->get();
    }

    /**
     * A programacao do evento, por dia e por grupo — a mesma leitura que o
     * participante teve quando escolheu.
     *
     * ATIVIDADE DESATIVADA CONTINUA APARECENDO QUANDO JA FOI ESCOLHIDA. A
     * tentacao seria esconde-la, e o efeito seria pior do que o problema:
     * escondida, ela sairia da lista enviada e a pessoa perderia a atividade
     * sem que ninguem tivesse decidido isso. Visivel e marcada, quem esta
     * corrigindo enxerga o que ha para resolver.
     *
     * @param  array<int, int>  $escolhidas
     * @return array<int, array<string, mixed>>
     */
    private function programacaoDoEvento(Inscricao $inscricao, array $escolhidas): array
    {
        $evento = $inscricao->evento;

        if ($evento === null) {
            return [];
        }

        $evento->load(['diasEvento.gruposAtividades.atividades' => fn ($consulta) => $consulta->orderBy('posicao')->orderBy('id')]);

        return $evento->diasEvento
            ->sortBy(['posicao', 'id'])
            ->values()
            ->map(fn (DiaEvento $dia): array => [
                'id' => (int) $dia->id,
                'nome' => (string) $dia->nome,
                'data' => $dia->data->toDateString(),
                'ativo' => (bool) $dia->ativo,
                'grupos' => $dia->gruposAtividades
                    ->sortBy(['posicao', 'id'])
                    ->values()
                    ->map(fn (GrupoAtividade $grupo): array => [
                        'id' => (int) $grupo->id,
                        'nome' => (string) $grupo->nome,
                        'obrigatorio' => (bool) $grupo->obrigatorio,
                        'min_selecoes' => (int) $grupo->min_selecoes,
                        'max_selecoes' => $grupo->max_selecoes,
                        'atividades' => $grupo->atividades
                            ->map(fn (Atividade $atividade): array => [
                                'id' => (int) $atividade->id,
                                'nome' => (string) $atividade->nome,
                                'comeca_em' => $atividade->comeca_em?->toIso8601String(),
                                'termina_em' => $atividade->termina_em?->toIso8601String(),
                                'capacidade' => $atividade->capacidade,
                                'vagas_ocupadas' => $atividade->vagasOcupadas(),
                                'idade_minima' => $atividade->idade_minima,
                                'idade_maxima' => $atividade->idade_maxima,
                                // Ativa de verdade: a atividade, o grupo dela e
                                // o dia precisam estar os tres de pe, que e o
                                // que o validador cobra na hora de gravar.
                                'ativa' => (bool) $atividade->ativo && (bool) $grupo->ativo && (bool) $dia->ativo,
                                'escolhida' => in_array((int) $atividade->id, $escolhidas, true),
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * O historico da cobranca, da mais recente para a mais antiga.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historicoDeCobrancas(Inscricao $inscricao): array
    {
        return $inscricao->pagamentos
            ->sortByDesc('id')
            ->values()
            ->map(function (Pagamento $pagamento): array {
                $metadados = is_array($pagamento->metadados) ? $pagamento->metadados : [];
                $responsavel = is_array($metadados['responsavel'] ?? null) ? $metadados['responsavel'] : null;

                return [
                    'id' => $pagamento->id,
                    'codigo_publico' => $pagamento->codigo_publico,
                    // O identificador da cobranca no provedor — o txid, na Efi.
                    // Vai para a tela porque e por ele que se procura a
                    // cobranca no painel da instituicao financeira; o
                    // `codigo_publico` acima nao existe do lado de la. Fica
                    // nulo quando o pagamento foi reconhecido na mao, e a tela
                    // desenha isso como vazio.
                    'id_externo' => $pagamento->id_externo,
                    'gateway' => $pagamento->gateway,
                    'metodo' => $pagamento->metodo->value,
                    'metodo_rotulo' => $pagamento->metodo->rotulo(),
                    'situacao' => $pagamento->situacao->value,
                    'situacao_rotulo' => $pagamento->situacao->rotulo(),
                    'valor_centavos' => $pagamento->valor_centavos,
                    'criada_em' => $pagamento->created_at?->toIso8601String(),
                    'expira_em' => $pagamento->expira_em?->toIso8601String(),
                    'pago_em' => $pagamento->pago_em?->toIso8601String(),
                    'cancelado_em' => $pagamento->cancelado_em?->toIso8601String(),
                    // Quem pagou, quando o aviso do provedor tiver dito. Vem
                    // do mesmo jsonb da observacao, e ja chega com o CPF
                    // mascarado de quando o aviso foi gravado.
                    'pagador' => is_array($metadados['pagador'] ?? null) ? $metadados['pagador'] : null,
                    'origem_manual' => ($metadados['origem'] ?? null) === 'manual',
                    'observacao' => is_string($metadados['observacao'] ?? null) ? $metadados['observacao'] : null,
                    'responsavel' => is_string($responsavel['nome'] ?? null) ? $responsavel['nome'] : null,
                ];
            })
            ->all();
    }

    /**
     * As listas que alimentam os seletores de filtro.
     *
     * As atividades so aparecem quando ha um evento escolhido: sem isso, a
     * lista misturaria a programacao de todos os eventos e nao ajudaria
     * ninguem.
     *
     * @return array<string, mixed>
     */
    private function opcoes(FiltroDeInscricoes $filtro): array
    {
        $eventoId = $filtro->valores()['evento_id'];

        return [
            'eventos' => Evento::query()
                ->orderByDesc('data_inicio')
                ->get(['id', 'nome'])
                ->map(fn (Evento $evento): array => ['id' => $evento->id, 'nome' => $evento->nome])
                ->all(),
            // Os setores do filtro. O rotulo e so o nome: a UF servia para
            // separar cidades homonimas de estados diferentes, e os setores da
            // comunidade sao todos da mesma regiao. A chave da prop continua
            // sendo `cidades`, como a coluna.
            'cidades' => Cidade::query()
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (Cidade $cidade): array => ['id' => $cidade->id, 'nome' => $cidade->nome])
                ->all(),
            'grupos' => GrupoParticipante::query()
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (GrupoParticipante $grupo): array => ['id' => $grupo->id, 'nome' => $grupo->nome])
                ->all(),
            'atividades' => $eventoId === null ? [] : $this->atividadesDoEvento((int) $eventoId),
            'situacoes' => array_map(
                fn (SituacaoInscricao $situacao): array => ['valor' => $situacao->value, 'rotulo' => $situacao->rotulo()],
                SituacaoInscricao::cases(),
            ),
            // Duas opcoes, e so (RN-X3). Elas vem do enum pelo mesmo caminho
            // das situacoes: nenhum rotulo e escrito na tela.
            'sexos' => Sexo::opcoes(),
            'situacoes_pagamento' => array_map(
                fn (SituacaoPagamento $situacao): array => ['valor' => $situacao->value, 'rotulo' => $situacao->rotulo()],
                SituacaoPagamento::cases(),
            ),
        ];
    }

    /**
     * @return array<int, array{id: int, nome: string}>
     */
    private function atividadesDoEvento(int $eventoId): array
    {
        return Atividade::query()
            ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
            ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
            ->where('dias_evento.evento_id', $eventoId)
            ->orderBy('dias_evento.posicao')
            ->orderBy('atividades.posicao')
            ->orderBy('atividades.id')
            ->get(['atividades.id', 'atividades.nome', 'dias_evento.nome as dia'])
            ->map(fn (Atividade $atividade): array => [
                'id' => (int) $atividade->id,
                'nome' => $atividade->getAttribute('dia').' · '.$atividade->nome,
            ])
            ->all();
    }
}

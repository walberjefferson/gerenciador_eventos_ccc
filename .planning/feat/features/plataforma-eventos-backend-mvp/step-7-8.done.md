# Execution Report — Etapas 7 e 8 (Inscrição: domínio, regras e testes)

> **Plan:** plataforma-eventos-backend-mvp — Step 8 (fechamento da Fase 3)
> **Executed:** 2026-08-20
> **Status:** ✅ COMPLETE

## What Was Done

| File | Action | Description |
|------|--------|-------------|
| `tests/Feature/Inscricoes/ConcorrenciaTest.php` | criado | 5 testes: 3 deterministas de gravação condicional (CAS), 1 de varredura sob demanda vista pelo CAS, 1 de disputa real entre 6 processos de SO |
| `tests/Feature/Inscricoes/scripts/disputar-vaga.php` | mantido + Pint | processo independente que tenta UMA inscrição; contrato de saída: `ok` / `esgotado` / `erro: ...` |
| `tests/Feature/Inscricoes/Cenario.php` | mantido | cenário compartilhado (evento, dia, 2 grupos, 5 atividades, cidade, grupo de participantes) |
| `tests/Feature/Inscricoes/{Inscricao,SelecaoAtividades,ConflitoAtividade,CapacidadeAtividade,InscricaoDuplicada}Test.php` | mantidos | vindos da execução anterior da Etapa 8 |
| `config/database.php` | mantido | `'timezone' => env('DB_TIMEZONE', env('APP_TIMEZONE', 'UTC'))` na conexão `pgsql`. Inspecionado e considerado correto e necessário: sem ele o PostgreSQL interpretava o horário enviado pelo Laravel como se fosse de Londres, deslocando em 3 horas toda comparação feita no PHP (inclusive a que decide se as inscrições já abriram). Não é uma segunda conexão |
| `docs/PROGRESS.md` | atualizado | Etapa 8 concluída, Fase 3 fechada, lista do que a Fase 4 (Pagamento) ainda precisa |

## Como o teste paralelo funciona (o executor da Fase 4 precisa saber)

O `RefreshDatabase` mantém tudo dentro de uma transação, e processos externos
não enxergam transação alheia. Por isso o teste de disputa usa duas peças:

1. `cenarioVisivelParaOutrosProcessos()` — troca `database.default` por uma
   conexão clonada (`disputa`) enquanto monta o cenário, de modo que as linhas
   sejam confirmadas no banco; depois devolve o padrão. As asserções seguem
   usando a conexão do teste: em `READ COMMITTED` elas enxergam o que os
   processos filhos confirmaram.
2. `limparCenarioCommitado()` — apaga, no `finally`, as linhas que a transação
   do teste não alcança. **Única exceção à regra de nunca apagar registro**:
   é sujeira de teste, não domínio. Sem ela, `Inscricao::count()` dos outros
   arquivos passaria a contar sobras (o Pest roda os arquivos em ordem
   alfabética e `ConcorrenciaTest` vem cedo).

Os processos filhos recebem `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`
explicitamente e uma largada comum (`microtime(true) + 2s`), para que nenhum
termine antes de o último nascer.

## Quality Criteria

| Criterion | Status | Evidence |
|-----------|--------|----------|
| CAS determinista: `affectedRows = 0` com `reservadas + confirmadas >= capacidade` | ✅ | `expect(reservarComGravacaoCondicional(...))->toBe(0)` passa; contador permanece inalterado |
| CAS determinista: recusa vira `VagasEsgotadasException` | ✅ | teste "recusa a inscricao justamente quando a gravacao condicional devolve zero" |
| Disputa real entre processos de SO, exatamente 1 vencedor | ✅ | 6 processos: 1 `ok`, 5 `esgotado`; `vagas_reservadas + vagas_confirmadas (1) <= capacidade (1)` |
| Estabilidade do harness paralelo | ✅ | 4 execuções seguidas do arquivo, 5/5 verdes em todas |
| Varredura sob demanda sem esperar o agendador | ✅ | CAS devolve 0 → `ExpirarInscricoesVencidas` limitada ao evento → única retentativa concede; primeira vira `expirada` com `expirada_em` preenchido |
| Bordas que se encostam são aceitas | ✅ | `ConflitoAtividadeTest.php:67` "permite atividades cujos horarios apenas se encostam" (Futebol 09–11, Vôlei 11–13) |
| Ordem dos contadores: evento, depois atividades por id ASC | ✅ | `ReservarVagas::emOrdemCanonica()`; teste "nao prende vaga de atividade quando o evento ja esta lotado" |
| Nenhum registro apagado no domínio | ✅ | `CapacidadeAtividadeTest` "nao apaga nenhum registro ao expirar"; `ConcorrenciaTest` confirma `Inscricao::count() === 2` após expiração |
| Dinheiro em centavos, identificadores sem acento | ✅ | `valor_centavos`; nenhum identificador acentuado nas migrações |

## Verification

| Command | Result |
|---------|--------|
| `vendor/bin/pint` | 1 arquivo corrigido (`scripts/disputar-vaga.php`: imports) |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `php artisan test tests/Feature/Inscricoes/ConcorrenciaTest.php` (×4) | 5 passed (26 assertions) em todas |
| `php artisan test` (suíte completa) | **140 passed (333 assertions)** — inclui os 29 testes de domínio e os testes do pacote inicial de autenticação |

## Deviations from Plan

- O item "varredura sob demanda" já estava coberto em `CapacidadeAtividadeTest`
  (4 testes, incluindo o caso da atividade e o caso sem reserva vencida). Em
  `ConcorrenciaTest` foi acrescentada a versão que agrega valor novo: a que
  afirma explicitamente que o CAS devolveu `0` **antes** de a retentativa
  conceder a vaga.
- Nenhum código de produção foi alterado. Nenhum defeito de produção foi
  encontrado. A única correção foi no próprio teste (`whereKey()` não existe no
  construtor de consultas do banco, só no Eloquent → trocado por `where('id', …)`).

## Commit

- `test(inscricoes): cover selection rules, capacity and concurrency`
- `config/database.php`, `docs/PROGRESS.md`, `tests/Feature/Inscricoes/` (7 arquivos + script)

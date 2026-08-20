# Execution Report — Fase 5a, steps 4, 5 e 6

> **Plan:** fase-5a-site-publico-inscricao (execução parcial: steps 4, 5 e 6)
> **Executed:** 2026-08-20
> **Status:** ⚠️ WITH CAVEATS

## O que foi feito

### Step 4 — `e82029a` feat(inscricao): add activity selection rules composable
| Arquivo | Ação | Descrição |
|---|---|---|
| `resources/js/types/inscricao.ts` | create | `CidadePublica`, `GrupoParticipantePublico`, `ConflitoDeAtividades`, `PassoDaInscricao`, `FormularioInscricao`, `SituacaoDaAtividade` |
| `resources/js/composables/useSelecaoAtividades.ts` | create | espelho de RN-03..RN-09 |
| `resources/js/composables/useGruposDaCidade.ts` | create | grupos filtrados pela cidade + limpeza do grupo ao trocar de cidade |
| `tsconfig.json` | modify | remoção de `"vue/tsx"` e `"./resources/js/types"` de `compilerOptions.types` |

Regras espelhadas (conforto, nunca autoridade):
- `haChoqueDeHorario` → `comecaA < terminaB && terminaA > comecaB`. **Limites que se tocam continuam permitidos** (comparação estrita).
- conflito declarado indexado nos **dois sentidos** (o banco guarda o par normalizado, menor id primeiro).
- faixa etária calculada **na data em que a atividade começa** (`idadeNaData`), com a mesma conta de `Atividade::idadeNaData()`. Usa só o pedaço `AAAA-MM-DD` das datas, sem passar por fuso horário.
- esgotado, mínimo/máximo por bloco e bloco obrigatório (`minimoDoGrupo` = `max(min_selecoes, obrigatorio ? 1 : 0)`).
- ordem dos motivos: `Esgotado` → faixa etária → choque de horário → conflito declarado → máximo do bloco.
- atividade já marcada **sempre** pode ser desmarcada (senão a pessoa trava).

### Step 5 — `91d5197` feat(inscricao): add personal data and participation steps
| Arquivo | Ação | Descrição |
|---|---|---|
| `app/Http/Controllers/InscricaoPublicaController.php` | create | `create`; inscrições fechadas → redirect para a vitrine; rascunho/cancelado → 404 |
| `app/Http/Resources/CidadeResource.php` | create | `id`, `nome`, `uf`, `rotulo` ("Sabará (MG)") |
| `app/Http/Resources/GrupoParticipanteResource.php` | create | `id`, `cidade_id`, `nome` |
| `routes/web.php` | modify | `GET eventos/{slug}/inscricao` → `inscricoes.criar` |
| `resources/js/pages/Inscricoes/Criar.vue` | create | casca das etapas, navegação no cliente, anúncio de troca de passo |
| `resources/js/components/inscricao/IndicadorDePassos.vue` | create | `Progress` + lista com `aria-current="step"` |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | create | 7 campos, `<label for>`, `aria-describedby`, `role="alert"` |
| `resources/js/components/inscricao/PassoParticipacao.vue` | create | dias → blocos |
| `resources/js/components/inscricao/GrupoDeAtividades.vue` | create | `fieldset`/`legend`, regra do bloco e contador |
| `resources/js/components/inscricao/CartaoDeAtividade.vue` | create | horário, vagas, `Esgotado`, motivo do bloqueio |
| `tests/Feature/Publico/FormularioInscricaoTest.php` | create | 5 testes |

### Step 6 — `c7c109e` feat(inscricao): add review step and signed redirect to payment
| Arquivo | Ação | Descrição |
|---|---|---|
| `resources/js/components/inscricao/PassoRevisao.vue` | create | resumo completo, valor, regulamento e aceite |
| `resources/js/pages/Inscricoes/Criar.vue` | modify | envio, `chave_idempotencia`, mapeamento do 422 de volta ao passo |
| `app/Http/Controllers/InscricaoController.php` | modify | `$request->inertia()` → redirect assinado; demais → JSON idêntico ao de hoje |
| `app/Http/Controllers/PagamentoController.php` | create | **stub mínimo** (ver desvio 2) |
| `app/Http/Controllers/InscricaoPublicaController.php` | modify | prop `evento_id` |
| `routes/web.php` | modify | `GET inscricoes/{codigo_publico}/pagamento` → `inscricoes.pagamento`, middleware `signed` |
| `tests/Feature/Inscricoes/RespostaDaInscricaoTest.php` | create | 4 testes |

## Critérios de qualidade

| Critério | Status | Evidência |
|---|---|---|
| Suíte Pest verde | ✅ | `php artisan test` → **196 passed (717 assertions)** — 187 anteriores + 5 + 4 novos |
| `vendor/bin/pint --test` | ✅ | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | ✅ | eslint sem saída |
| `npm run build` | ✅ | `✓ built in 1.49s` |
| `npx vue-tsc --noEmit` | ⚠️ | **zero erro em qualquer arquivo da Fase 5a**; 12 erros pré-existentes do starter kit ficaram visíveis (ver desvio 1) |
| Conflito de horário nomeia a atividade | ✅ | `Indisponível — conflito de horário com ${choque.nome}` |
| Contador `2 de 2 selecionadas` desabilita o resto | ✅ | `rotuloDeContagem` + `grupoAtingiuMaximo` em `situacaoDe` |
| Limites que se tocam permitidos | ✅ | comparação estrita em `haChoqueDeHorario`; o `Cenario` de teste tem Vôlei encostando no Futebol e a suíte de domínio segue verde |
| 422 volta ao passo do campo | ✅ | `passoDoCampo` + `tratarRecusa` (foco no campo, `mostrarProblemas` ligado quando volta para a participação) |
| `chave_idempotencia` no cliente | ✅ | `crypto.randomUUID()` na criação do `formulario`, reenviada em toda tentativa |
| JSON preservado para quem não é a tela | ✅ | teste `continua respondendo o mesmo JSON para quem nao e a tela` |
| URL da cobrança sem assinatura → 403 | ✅ | teste `recusa a cobranca quando a URL vem sem assinatura` |
| Sem dado sensível nos props | ✅ | teste `nao vaza contadores internos das atividades no formulario` |
| Cor só por token semântico | ✅ | `bg-acao`, `text-informacao-texto`, `text-sucesso-texto`, `text-atencao-texto`, `variant="sucesso\|informacao\|atencao"`; nenhum hex |
| Sem regra de negócio nova no Vue | ✅ | o composable só reordena o que o domínio já decide; nenhuma Action/Service/Enum/migration tocada |
| Sem dependência nova | ✅ | nada instalado |
| Alvos de toque ≥ 44 px | ✅ | `h-11` nos campos e `h-12` nos botões; cartão de atividade é bem maior |

## Verificação

| Comando | Resultado |
|---|---|
| `vendor/bin/pint --test` | passed |
| `npm run lint` | limpo |
| `npx vue-tsc --noEmit` | 12 erros pré-existentes, 0 na Fase 5a |
| `npm run build` | ✓ built in 1.49s |
| `php artisan test` | 196 passed (717 assertions) |

## Desvios do plano

1. **`tsconfig.json` — a correção funcionou, e revelou o que estava escondido.**
   `compilerOptions.types` aceita nome de pacote `@types`, não caminho. As duas entradas inválidas (`vue/tsx` e `./resources/js/types`) faziam o TypeScript **abortar o programa antes de checar qualquer arquivo**: o `vue-tsc` mostrava só os 2 `TS2688` e nada mais — verificação de tipo nenhuma acontecia no projeto inteiro.
   Depois de removê-las, o `vue-tsc` passou a checar de verdade e apareceram **12 erros pré-existentes, todos em arquivos do starter kit** (`NavMain.vue`, `NavUser.vue`, `TextLink.vue`, `UserInfo.vue`, `AuthSplitLayout.vue`, `auth/Login.vue`, `auth/Register.vue`, `settings/Profile.vue`, `Welcome.vue`). Nenhum deles está na Fase 5a e nenhum está na tabela de Output Format deste plano — por isso **não** foram corrigidos.
   Optei por **manter a correção** em vez de reverter: revertê-la devolveria um `vue-tsc` que não checa nada, ou seja, um verde falso que deixaria todo o código desta fase sem verificação de tipo. Filtrando pelos arquivos da fase, a saída é limpa.
   **Pendência para o step 10:** decidir com o dono do produto se os 12 erros do starter kit entram nesta fase ou viram tarefa própria.

2. **`PagamentoController` nasceu como stub, por necessidade do step 6.**
   O redirect assinado exige uma rota nomeada existente (`URL::temporarySignedRoute`). Registrei `inscricoes.pagamento` com o middleware `signed` e um controller de 20 linhas que só carrega a inscrição e faz `Inertia::render('Inscricoes/Pagamento', ['codigo_publico' => ...])`.
   **A página `resources/js/pages/Inscricoes/Pagamento.vue` NÃO foi criada** — é do step 7. Entre este commit e o step 7, quem concluir uma inscrição é redirecionado para uma rota que o servidor responde 200 mas que o Inertia não consegue montar no navegador. É um estado intermediário conhecido e resolvido pelo próximo step.

3. **Prop `evento_id` separada.** `EventoPublicoResource` não expõe `id` (e não quis mexer nele, que também serve à vitrine). O `InscricaoPublicaController` manda `evento_id` como prop própria, que é o que `StoreInscricaoRequest` exige.

4. **Coleções entregues com `->resolve()`.** `CidadeResource::collection($cidades)->resolve()` evita o envelope `data` do Inertia sem depender de `$wrap`.

5. **Inscrições fechadas → redirect para `/eventos/{slug}`** em vez de 403/404: a vitrine já sabe explicar o motivo em uma frase, e é lá que a explicação deve aparecer.

6. **Conferência de formato no cliente** (nome, e-mail, telefone, 11 dígitos de CPF, data, cidade, grupo) é só espelho do `StoreInscricaoRequest` — os dígitos verificadores do CPF continuam sendo conferidos apenas pelo servidor, para a mensagem vir dele.

7. **Sem Vitest** (§3.7): as regras do composable ficam para os cenários do Playwright dos steps 8 e 9.

## O que o próximo executor precisa saber

- **Rota da cobrança:** `inscricoes.pagamento` → `GET /inscricoes/{codigo_publico}/pagamento`, middleware `signed`. Assinada por `InscricaoController::urlDaCobranca()` com validade `prazo_pagamento + 24h`.
- **`app/Http/Controllers/PagamentoController.php` já existe como stub** — o step 7 deve substituir o `show` (QR Code, copia e cola, prazo) e acrescentar `situacao`. **Falta criar `resources/js/pages/Inscricoes/Pagamento.vue`.**
- `Toaster` já está montado no `PublicoLayout`; use `toast({ titulo, descricao, tom })` de `@/components/ui/toast` para o "código Pix copiado".
- Props já disponíveis no formulário: `evento`, `evento_id`, `cidades`, `grupos_participantes`, `conflitos`.
- `npx vue-tsc --noEmit` mostra 12 erros do starter kit — **são pré-existentes e não vieram desta fase**. Filtre pelo caminho do seu arquivo antes de se assustar.
- Playwright (steps 8 e 9): o pacote e o script `test:e2e` existem, mas `npx playwright install` ainda não foi rodado.

## Commits
- `e82029a` feat(inscricao): add activity selection rules composable
- `91d5197` feat(inscricao): add personal data and participation steps
- `c7c109e` feat(inscricao): add review step and signed redirect to payment

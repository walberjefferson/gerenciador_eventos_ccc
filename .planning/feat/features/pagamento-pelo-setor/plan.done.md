# Execution Report — Pagamento pela chave Pix do responsável do setor

> **Plan:** pagamento-pelo-setor
> **Executed:** 2026-09-03
> **Status:** ✅ COMPLETE

## What Was Done

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_09_03_100001_add_forma_recebimento_to_eventos_table.php` | create | Coluna `forma_recebimento` + CHECK `IN ('gateway','setor')`, padrão `gateway` |
| `database/migrations/2026_09_03_100002_add_responsavel_to_cidades_table.php` | create | `responsavel_id` (nullOnDelete), `chave_pix(140)`, `titular_chave_pix(120)` |
| `database/migrations/2026_09_03_100003_create_comprovantes_pagamento_table.php` | create | Tabela, único parcial `WHERE situacao='enviado'`, 3 CHECK, 2 índices |
| `database/factories/ComprovantePagamentoFactory.php` | create | States `enviado`, `aceito`, `recusado` |
| `database/factories/EventoFactory.php` | modify | `forma_recebimento` padrão + state `recebendoPeloSetor()` |
| `app/Enums/FormaRecebimento.php` | create | `Gateway`/`Setor`, `rotulo()`, `explicacao()`, `exigeSetorPreparado()`, prazos mín./sugerido |
| `app/Enums/SituacaoComprovante.php` | create | `Enviado`/`Aceito`/`Recusado`, `rotulo()`, `estaEmAberto()` |
| `app/Models/ComprovantePagamento.php` | create | Casts, relações, scope `emAberto()`, `paraTela()`, `caminho` em `$hidden` |
| `app/Models/Cidade.php` | modify | `responsavel()`, `estaPreparadaParaReceber()`, `ativasDespreparadasParaReceber()`, RN-S3 por extenso |
| `app/Models/Evento.php` | modify | Cast `FormaRecebimento`, `recebePeloSetor()` |
| `app/Models/Inscricao.php` | modify | `comprovantes()`, `comprovanteEmAberto()`, `comprovanteMaisRecente()`, `setor()` |
| `app/Services/Pagamentos/MontadorDeBrCodePix.php` | create | BR Code extraído do `FakePaymentGateway`; descrição `26-02` opcional; valor por recorte de inteiro |
| `app/Services/Payments/Fake/FakePaymentGateway.php` | modify | Passa a usar o montador; `campo/emv26/crc16` privados removidos |
| `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` | modify | Bifurcação RN-S2 antes de tocar no gateway; `gateway='setor'`, `id_externo=null` |
| `app/Actions/Comprovantes/EnviarComprovante.php` | create | RN-S5/RN-S6: disco privado, nome gerado, substituição do em aberto apagando o arquivo antigo |
| `app/Actions/Comprovantes/ConferirComprovante.php` | create | RN-S10: `aceitar()` delega a `ConfirmarPagamentoManual`; `recusar()` exige motivo |
| `app/Exceptions/Pagamentos/SetorSemChavePixException.php` | create | *(fora da tabela do plano — ver Deviations)* |
| `app/Exceptions/Pagamentos/ComprovanteRecusadoException.php` | create | *(fora da tabela do plano — ver Deviations)* |
| `app/Http/Requests/EnviarComprovanteRequest.php` | create | `mimetypes` (conteúdo), 5 MB |
| `app/Http/Controllers/ComprovanteController.php` | create | `store` do participante, volta para a tela assinada |
| `app/Http/Controllers/Admin/ConferenciaComprovanteController.php` | create | `index` (ordenado por prazo, urgência), `show` (download), `aceitar`, `recusar` |
| `app/Http/Requests/Admin/ConferirComprovanteRequest.php` | create | Observação/motivo obrigatórios conforme a rota |
| `app/Policies/ComprovantePagamentoPolicy.php` | create | Lugar único do escopo RN-S9 |
| `app/Policies/InscricaoPolicy.php` | modify | *(fora da tabela do plano — ver Deviations)* `view()` passou a exigir o escopo |
| `app/Services/Admin/FiltroDeInscricoes.php` | modify | Escopo obrigatório por setor antes de qualquer filtro |
| `app/Http/Controllers/PagamentoController.php` | modify | `recebe_pelo_setor`, `setor`, `comprovante`, `url_comprovante`, `limites`, `sucesso` |
| `app/Http/Requests/Admin/EventoRequest.php` | modify | RN-S4 (nomeia setores) e RN-S8 (mínimo condicional) |
| `app/Http/Controllers/Admin/EventoController.php` | modify | `formas_recebimento` e `setores_despreparados` |
| `app/Http/Controllers/Admin/CidadeController.php` | modify | Responsável, chave, titular e `responsaveis` disponíveis |
| `app/Http/Requests/Admin/CidadeRequest.php` | modify | Validação dos três campos, responsável ativo |
| `database/seeders/PapeisSeeder.php` | modify | Papel `responsavel-setor` + `pagamentos.conferir-comprovante`, com a distinção escrita |
| `routes/web.php` | modify | 1 rota pública assinada com throttle + 4 administrativas |
| `config/filesystems.php` | modify | Disco privado `comprovantes` (sem `url`, fora de `app/public`) |
| `config/inscricoes.php` | modify | *(fora da tabela do plano — ver Deviations)* limite `comprovante` = 6/min |
| `resources/js/types/pagamento.ts` | modify | Comprovante, setor, limites |
| `resources/js/types/admin.ts` | modify | Forma de recebimento, setor, fila de comprovantes |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modify | Bloco do Pix do setor + envio do comprovante |
| `resources/js/components/participante/EnvioDeComprovante.vue` | create | Upload com quatro estados, rótulo, `aria-describedby` e erro anunciado |
| `resources/js/components/AppSidebar.vue` | modify | *(fora da tabela do plano — ver Deviations)* item "Comprovantes" |
| `resources/js/pages/Admin/Eventos/Formulario.vue` | modify | Escolha da forma + prazo sugerido + aviso de setores despreparados |
| `resources/js/pages/Admin/Catalogo/Setores.vue` | modify | Responsável, chave e titular; colunas "Responsável" e "Recebe Pix" |
| `resources/js/pages/Admin/Comprovantes/Index.vue` | create | Fila ordenada por prazo, urgentes em vermelho, aceitar/recusar |
| `tests/Feature/Pagamentos/RecebimentoPeloSetorTest.php` | create | 30 testes: RN-S1..S4, S8, S12, S13 |
| `tests/Feature/Comprovantes/EnvioDeComprovanteTest.php` | create | 13 testes: RN-S5, S6, S7, S11 |
| `tests/Feature/Comprovantes/ConferenciaTest.php` | create | 17 testes: RN-S9, S10 |
| `tests/Feature/Admin/AutorizacaoTest.php` | modify | *(fora da tabela do plano — ver Deviations)* 3→4 papéis, 13→14 permissões |
| `tests/Feature/Admin/UsuariosTest.php` | modify | *(fora da tabela do plano — ver Deviations)* mesma contagem |
| `tests/e2e/pagamento-pelo-setor.spec.ts` | create | 4 cenários Playwright |
| `docs/BUSINESS_RULES.md` | modify | RN-S1 a RN-S13 + mapeamento de testes + índice de mensagens |
| `docs/DATABASE.md` | modify | §3.1 (cidades), §3.3 (eventos), §3.14 (comprovantes_pagamento), enums |
| `docs/PAYMENTS.md` | modify | §11 completa: por que este caminho não fala com provedor |
| `docs/PROGRESS.md` | modify | O entregue, o risco da RN-S7 e o defeito de float corrigido |

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|-----------|--------|------------------------|
| Nenhuma chamada de rede no modo `setor` | ✅ | `ProvedorQueNaoPodeSerChamado` (lança em todos os métodos) registrado via `app()->instance()`; `it emite a cobranca do setor sem tocar no provedor` passa |
| Cobrança do setor não é consultada nem cancelada no provedor | ✅ | `it nao consulta o provedor ... na reconciliacao` → `Cobrancas consultadas: 0`; `it encerra a cobranca do setor sem avisar provedor nenhum` passa com `avisarProvedor: true` |
| BR Code byte a byte igual ao de antes (sem descrição) | ✅ | `it produz byte a byte o mesmo payload de antes` × 10 valores, comparando com `payloadComoEraAntes()` (cópia do algoritmo antigo, `number_format` incluído) — todos passam |
| Descrição carrega o código inteiro (RN-S13) | ✅ | `it leva o codigo da inscricao inteiro na descricao e os ultimos 25 no identificador` (`0236INSCRICAO <26 chars>` e `62290525<25 chars>`); `it fecha o crc16 mesmo depois do campo novo`; `it reencontra a inscricao pelo codigo lido do payload` |
| Nenhum ponto flutuante no valor | ✅ | `it converte centavos por recorte de inteiro` com 1_00, 10_05, 999_99, 1_234_56 e 7 → `0.07` |
| Comprovante nunca em disco público; download 403 para quem não é do setor | ✅ | `it nao guarda o comprovante em disco publico` (root = `storage/app/comprovantes`, sem `url`); `it recusa com 403 o comprovante de outro setor pedido pela url direta` |
| Escopo por setor é do servidor | ✅ | `it recusa com 403 a ficha da inscricao de outro setor pedida pela url direta`; `it nao amplia o recorte trocando o setor na url` → total 0 |
| RN-S12 provada por ausência | ✅ | `php artisan test --parallel` → **801 passed**. Nenhuma expectativa de teste de evento por gateway foi editada; as duas edições foram contagens de papéis/permissões |
| `mimetypes` e nunca `mimes` | ✅ | `EnviarComprovanteRequest::rules()` usa `mimetypes:`; `it aceita pdf e recusa arquivo cujo conteudo nao e imagem nem pdf` (arquivo `.jpg` com conteúdo `x-msdownload` recusado) |
| PSR-12 / Pint limpo; Vue `<script setup>` + TS strict, sem `any` | ✅ | `./vendor/bin/pint --dirty` → `{"tool":"pint","result":"passed"}`; `npx vue-tsc --noEmit` → sem saída; `npx eslint resources/js tests/e2e` → sem saída |
| Comentários em português explicando o porquê; RN-S3 por extenso em `Cidade` | ✅ | Docblock de `Cidade` com o contraste ponto a ponto contra `CredencialPagamento` |
| Pest — os três arquivos cobrindo cada RN-S | ✅ | 30 + 13 + 17 = **60 testes novos**, incluindo substituição do em aberto, recusa sem motivo negada, aceite caindo em `ConfirmarPagamentoManual` com auditoria, e evento recusado por setor despreparado |
| Playwright E2E, quatro cenários | ✅ | `npx playwright test pagamento-pelo-setor` → **4 passed** |
| Acessibilidade do upload | ✅ | `<label for>` visível, `aria-describedby` com formatos e tamanho, `aria-invalid`/`aria-errormessage` e `role="alert" aria-live="assertive"` no erro |
| A suíte inteira continua verde | ✅ | `php artisan test --parallel` → 801 passed (5611 assertions); `npx playwright test` → **102 passed** |

## Verification

| Command | Result |
|---------|--------|
| `./vendor/bin/pint --dirty` | `{"tool":"pint","result":"passed"}` |
| `php artisan test --parallel` | **801 passed** (5611 assertions), 16.53s |
| `npx vue-tsc --noEmit` | sem saída (limpo) |
| `npx eslint resources/js tests/e2e` | sem saída (limpo) |
| `npm run build` | `✓ built in 1.75s` |
| `npx playwright test pagamento-pelo-setor` | **4 passed** |
| `npx playwright test` (suíte inteira) | **102 passed** (1.9m), 0 falhas |
| `psql \d comprovantes_pagamento` | único parcial + 3 CHECK + 2 índices + 2 FK confirmados |

## Deviations from Plan

Sete arquivos fora da tabela do Output Format. Nenhum deles acrescenta
funcionalidade: todos existem para que uma regra do plano seja de fato
cumprida.

- **`app/Exceptions/Pagamentos/SetorSemChavePixException.php`** e
  **`ComprovanteRecusadoException.php`** (novos) — o plano exige que a
  emissão e o envio recusem em português, mas não nomeia as classes. Seguem o
  molde de `ConfirmacaoManualRecusadaException`.
- **`app/Policies/InscricaoPolicy.php`** (`view()`) — sem isto, o critério
  "responsável do Setor A pede a inscrição do Setor B pela URL direta e recebe
  403" falharia: o recorte de `FiltroDeInscricoes` só cobre a lista. A regra
  não foi duplicada — a policy pergunta a `ComprovantePagamentoPolicy`.
- **`config/inscricoes.php`** — a rota do comprovante precisa de `throttle`
  (RN-S5) e o projeto declara todos os limites nesse arquivo, com o motivo
  escrito. Chave `comprovante` = `6,1`.
- **`database/factories/EventoFactory.php`** — `forma_recebimento` padrão
  (`gateway`) e o state `recebendoPeloSetor()`, sem os quais os testes novos
  não têm como montar o cenário.
- **`resources/js/components/AppSidebar.vue`** — um item de menu para
  `pagamentos.conferir-comprovante`. Sem ele, o responsável de setor não teria
  como chegar à única tela que o papel dele alcança.
- **`tests/Feature/Admin/AutorizacaoTest.php`** e
  **`tests/Feature/Admin/UsuariosTest.php`** — as únicas expectativas
  editadas na suíte existente: 3→4 papéis e 13→14 permissões, com os
  comentários do projeto atualizados para dizer por quê. Não são testes de
  evento por gateway, então a RN-S12 continua provada por ausência.

Duas escolhas de conteúdo que valem registro:

- **A descrição do `26-02` sai em CAIXA ALTA** (`INSCRICAO <ULID>`). O plano
  escreve `Inscricao <codigo_publico>`, mas o saneamento EMV extraído é o
  mesmo do resto do payload e ele já fazia `Str::upper` — e é justamente essa
  mesmice que sustenta o teste de igualdade byte a byte. Preferi manter o
  saneamento intacto e documentar a consequência a abrir uma exceção nele.
- **`alcancaTodosOsSetores()` é definida por negação** (`estaRecortadoPorSetor`
  = tem `conferir-comprovante` **e não** tem `confirmar-manual`). A definição
  direta pela permissão de confirmação manual teria recortado o **organizador**
  a zero setores, quebrando a RN-S12.

Não commitados de propósito, por serem de outro agente:
`tests/e2e/admin-avisos-pagamento.spec.ts`, `tests/e2e/admin-usuarios.spec.ts`
e `tests/e2e/ambiente.ts` (as 5 falhas pré-existentes já não aparecem: a suíte
Playwright fechou 102/102).

## Commit

- **Hash:** `84b2360`
- **Message:** `feat(pagamentos): recebimento pela chave pix do responsavel do setor`
- **Files:** 53 (49 da tabela do plano + desvios acima) + os 4 docs do Passo 10

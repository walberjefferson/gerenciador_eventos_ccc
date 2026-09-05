# Action Plan — Inscrições por lote

> **Type:** feature
> **Created:** 2026-09-02 22:01
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro Sênior PHP 8.4 + Laravel 12 + Inertia 2 + Vue 3 (Composition API,
`<script setup>`) + TypeScript strict + Tailwind v4/Reka UI, com domínio de PostgreSQL
(CHECK constraints, UPDATE condicional para contador concorrente) e testes Pest +
Playwright.

**Scope:** Introduzir o **lote de inscrição** — um degrau de preço com limite de data,
de quantidade ou dos dois — entre o evento e a inscrição. Quatro frentes, sempre nesta
ordem: banco/domínio → HTTP (requests, resources, controllers, rotas) → telas (admin e
público) → testes.

Fora de escopo:
- mexer em pagamentos, ingressos, portaria ou e-mails (o valor já chega fotografado na
  inscrição, e nada nesse caminho precisa saber o que é um lote);
- desconto por grupo, cupom, cortesia ou valor diferente por atividade;
- prorrogar, reabrir ou remanejar lote pela tela administrativa;
- mudar a hierarquia `evento → dia → grupo → atividade`.

**Stack:** PHP 8.4, Laravel 12, PostgreSQL, Inertia 2, Vue 3.5, TypeScript, Tailwind v4,
Reka UI, Pest (paralelo com paratest), Playwright.

## 2. Direct Objective

Permitir que um evento tenha uma sequência de lotes, cada um com o seu valor e com
limite de data, de quantidade ou de ambos, de modo que a página do evento e o formulário
de inscrição mostrem **todos** os lotes — com preço e situação — mas só deixem inscrever
pelo **lote vigente no instante do envio**, com o valor daquele lote fotografado na
inscrição. Evento sem lote nenhum continua funcionando exatamente como hoje.

## 3. Minimum Inputs

### Entities / Data

**`lotes`** (nova tabela)

| Coluna | Tipo | Nulo | Padrão | Observação |
|---|---|---|---|---|
| `id` | bigserial | não | — | |
| `evento_id` | bigint FK → `eventos` | não | — | `restrictOnDelete`, no molde de `dias_evento` |
| `nome` | string(80) | não | — | "1º lote", "Lote promocional" |
| `posicao` | integer | não | — | ordena a sucessão; único dentro do evento |
| `valor_centavos` | bigint | não | — | dinheiro sempre inteiro (D-06) |
| `disponivel_ate` | timestamptz | sim | `null` | limite por data |
| `quantidade` | integer | sim | `null` | limite por vagas |
| `vagas_ocupadas` | integer | não | `0` | contador atômico, **só cresce** (RN-L6) |
| `created_at` / `updated_at` | timestamptz | não | — | `timestampsTz()` |

Índices e restrições:

```sql
UNIQUE (evento_id, posicao)
INDEX  (evento_id, posicao)

CHECK lotes_quantidade_check
  quantidade IS NULL OR (quantidade > 0 AND vagas_ocupadas <= quantidade)

CHECK lotes_vagas_nao_negativas_check
  vagas_ocupadas >= 0

CHECK lotes_valor_check
  valor_centavos >= 0

CHECK lotes_tem_limite_check
  disponivel_ate IS NOT NULL OR quantidade IS NOT NULL
```

O último CHECK é a regra RN-L1 escrita no banco: lote sem limite nenhum nunca encerraria,
e um lote que não encerra não é um lote — é o preço do evento.

**`inscricoes`** (alterar)

| Coluna | Tipo | Nulo | Observação |
|---|---|---|---|
| `lote_id` | bigint FK → `lotes` | sim | `nullOnDelete` **não**: `restrictOnDelete` — lote com inscrição não se apaga |

`valor_centavos` **permanece exatamente como está**: é a fotografia do preço no instante
da inscrição, e continua sendo a única fonte de verdade do que aquela pessoa deve pagar.
`lote_id` responde outra pergunta — *de qual degrau ela veio* —, e serve a relatório e
conferência, nunca a cálculo de cobrança.

**Conceito novo — lote vigente.** O primeiro lote do evento, em ordem de `posicao`, que
ainda está disponível:

```
(disponivel_ate IS NULL OR disponivel_ate > agora)
AND (quantidade IS NULL OR vagas_ocupadas < quantidade)
```

Há no máximo um. Pode não haver nenhum (todos vencidos ou esgotados).

### Business Rules

- **RN-L1 — Todo lote encerra.** Pelo menos um dos dois limites (`disponivel_ate` ou
  `quantidade`) precisa estar preenchido. Cobrado no `FormRequest` com mensagem em
  português e no banco pelo CHECK — o formulário avisa antes, o banco recusa depois.

- **RN-L2 — A ordem é a `posicao`.** Ela decide qual lote sucede qual. Não se repete
  dentro do evento (índice único), no mesmo molde de `dias_evento`.

- **RN-L3 — O lote vigente é calculado, nunca gravado.** Não existe coluna "ativo".
  Situação derivada de data e contador é sempre verdadeira; situação gravada envelhece
  no primeiro minuto em que ninguém roda a rotina que a atualizaria.

- **RN-L4 — Todos aparecem, só o vigente é selecionável.** A página do evento e o
  formulário listam os lotes na ordem, cada um com nome, valor e situação (`encerrado`,
  `vigente`, `futuro`). Os não vigentes chegam à tela já marcados como não selecionáveis,
  e **o servidor decide de novo no envio**: `lote_id` que não seja o vigente é recusado,
  venha de onde vier.

- **RN-L5 — O lote virou enquanto a pessoa preenchia.** O formulário envia o `lote_id`
  que ela viu. Se, no instante do envio, esse não for mais o vigente, a inscrição é
  **recusada** com 422 e uma mensagem que diz o que mudou e qual é o novo valor — e a
  tela recarrega os lotes. Aceitar em silêncio pelo lote novo cobraria um preço que a
  pessoa não viu; aceitar pelo lote velho venderia abaixo do combinado.

- **RN-L6 — O contador do lote nunca volta.** A vaga é presa no lote no mesmo instante em
  que é presa no evento, com o mesmo UPDATE condicional. Quando a inscrição expira ou é
  cancelada, a vaga volta para o evento e para as atividades, **mas não para o lote**:
  `lotes.vagas_ocupadas` só cresce, e lote esgotado não reabre. Decisão do dono do
  produto. Ver o risco em §6.

- **RN-L7 — O valor é fotografado, e a fotografia não se refaz.** Na criação da
  inscrição, `valor_centavos` recebe o valor do lote vigente e `lote_id` guarda o lote.
  Alterar o valor de um lote depois **não muda inscrição nenhuma**, nem cobrança já
  emitida, nem segunda via de Pix — que continua lendo `inscricao->valor_centavos`.

- **RN-L8 — Evento sem lote continua como hoje.** Nenhum lote cadastrado → `lote_id`
  nulo e `valor_centavos` copiado de `eventos.valor_centavos`. Nenhuma tela muda de
  comportamento, nenhum teste existente muda de resultado.

- **RN-L9 — Lotes esgotados fecham a inscrição.** Evento **com** lotes e **sem** lote
  vigente tem as inscrições fechadas, com motivo em palavras ("Os lotes de inscrição se
  esgotaram."), pelo mesmo caminho que já explica capacidade cheia e janela fechada.
  Evento **sem** lotes nunca cai nesta regra.

- **RN-L10 — Lote e capacidade são tetos independentes.** `eventos.capacidade` continua
  sendo o teto físico e manda em último caso; os lotes são degraus de preço dentro dela.
  A soma das quantidades pode ser menor que a capacidade (sobra vaga sem lote — e aí a
  inscrição fecha por RN-L9) ou maior (o evento lota antes do último lote). **Nenhuma
  validação cruzada entre os dois**; a tela administrativa apenas informa a soma ao lado
  da capacidade, para quem cadastra enxergar a diferença.

- **RN-L11 — Ordem canônica das travas.** `evento → lote → atividades (id crescente)`.
  O lote entra logo depois do evento, antes das atividades. Cada inscrição toca um único
  lote, então não há ordem a arbitrar entre lotes.

- **RN-L12 — Lote com inscrição não some nem encolhe.** Excluir lote que já tem
  inscrição é recusado (`restrictOnDelete`, com mensagem explicando o caminho). Reduzir
  `quantidade` abaixo de `vagas_ocupadas` é recusado no `FormRequest` e no CHECK.

### Existing Files to Read

Antes de escrever qualquer linha, ler:

- `app/Actions/Inscricoes/CriarInscricao.php` — a transação, a retentativa e onde o valor
  é fotografado hoje (`prazo_pagamento` e `valor_centavos` no mesmo `create`)
- `app/Actions/Inscricoes/ReservarVagas.php` — o UPDATE condicional e a ordem canônica
- `app/Actions/Inscricoes/LiberarVagas.php` — o que devolve vaga hoje (e o que **não**
  vai devolver, por RN-L6)
- `app/DTOs/Inscricoes/DadosNovaInscricao.php`
- `app/Models/Evento.php` — `inscricoesEstaoAbertas()`, `temVagaDisponivel()`,
  `vagasDisponiveis()`, `motivoEmPalavras()`
- `app/Http/Resources/EventoPublicoResource.php`
- `app/Http/Controllers/Admin/DiaEventoController.php` +
  `app/Http/Controllers/Admin/Concerns/CuidaDaEstruturaDoEvento.php` — o molde exato de
  um CRUD de estrutura (authorize, confirmação de dono, auditoria, `back()->with`)
- `app/Http/Requests/Admin/EventoRequest.php` — o estilo das mensagens em português
- `database/migrations/2026_08_20_100003_create_eventos_table.php` — o estilo dos CHECK
- `resources/js/pages/Admin/Eventos/Estrutura.vue` — os modais de cadastro
- `resources/js/pages/Inscricoes/Criar.vue` + `components/inscricao/PassoRevisao.vue`
- `tests/Feature/Inscricoes/Cenario.php` — o helper que monta evento e inscreve

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/{ts}_create_lotes_table.php` | create | Tabela, índice único, quatro CHECK |
| `database/migrations/{ts}_add_lote_id_to_inscricoes_table.php` | create | FK `restrictOnDelete` + índice |
| `database/factories/LoteFactory.php` | create | Factory com states `porData`, `porQuantidade`, `esgotado` |
| `app/Models/Lote.php` | create | Casts, relações, `estaDisponivel()`, `situacaoEm()`, scope `emOrdem()` |
| `app/Models/Evento.php` | modify | `lotes()`, `temLotes()`, `loteVigente()`, RN-L9 no motivo e na abertura |
| `app/Models/Inscricao.php` | modify | Relação `lote()` |
| `app/Actions/Inscricoes/ResolverLoteVigente.php` | create | Regra RN-L3 num lugar só, usada por Action, Resource e testes |
| `app/Actions/Inscricoes/ReservarVagas.php` | modify | `reservarNoLote()` com UPDATE condicional; ordem RN-L11 |
| `app/Actions/Inscricoes/CriarInscricao.php` | modify | Confere RN-L5, fotografa valor e `lote_id` (RN-L7) |
| `app/Actions/Inscricoes/LiberarVagas.php` | modify | **Comentário** dizendo por que o lote não é devolvido (RN-L6) |
| `app/DTOs/Inscricoes/DadosNovaInscricao.php` | modify | Campo `?int $loteId` |
| `app/Exceptions/Inscricoes/LoteIndisponivelException.php` | create | Três mensagens: virou, esgotou, não existe mais |
| `app/Http/Requests/StoreInscricaoRequest.php` | modify | `lote_id` nullable/integer/exists |
| `app/Http/Resources/LotePublicoResource.php` | create | Nome, valor, situação, `selecionavel`, rótulos prontos |
| `app/Http/Resources/EventoPublicoResource.php` | modify | `lotes`, `lote_vigente_id`, `valor_centavos` efetivo |
| `app/Http/Controllers/EventoPublicoController.php` | modify | Carregar `lotes` |
| `app/Http/Controllers/InscricaoPublicaController.php` | modify | Carregar `lotes` |
| `app/Http/Controllers/Admin/LoteController.php` | create | `store` / `update` / `destroy` no molde de `DiaEventoController` |
| `app/Http/Requests/Admin/LoteRequest.php` | create | RN-L1, RN-L2, RN-L12 com mensagens em português |
| `app/Http/Controllers/Admin/EventoController.php` | modify | Enviar lotes + soma para a tela de estrutura |
| `routes/web.php` | modify | Três rotas dentro do grupo `eventos.gerenciar` |
| `resources/js/types/evento.ts` | modify | `LotePublico`, `lotes`, `lote_vigente_id` |
| `resources/js/types/inscricao.ts` | modify | `lote_id` no formulário |
| `resources/js/types/admin.ts` | modify | `LoteDaEstrutura` |
| `resources/js/components/eventos/ListaDeLotes.vue` | create | A tabela de lotes, usada nas duas telas públicas |
| `resources/js/pages/Eventos/Show.vue` | modify | Mostrar a lista de lotes |
| `resources/js/pages/Inscricoes/Criar.vue` | modify | `lote_id` no formulário; recarga quando o lote vira |
| `resources/js/components/inscricao/PassoParticipacao.vue` | modify | A lista de lotes no topo da etapa |
| `resources/js/components/inscricao/PassoRevisao.vue` | modify | Valor do lote, não do evento |
| `resources/js/components/inscricao/ResumoDaInscricao.vue` | modify | Idem |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | Seção de lotes com modal de cadastro |
| `tests/Feature/Inscricoes/Cenario.php` | modify | `comLotes()` opcional, sem quebrar chamada existente |
| `tests/Feature/Inscricoes/LotesTest.php` | create | Domínio: vigência, corrida, fotografia, RN-L6 |
| `tests/Feature/Admin/LotesAdminTest.php` | create | CRUD, RN-L1, RN-L12, auditoria |
| `tests/e2e/inscricao-por-lote.spec.ts` | create | Playwright — ver §5 |

## 5. Quality Criteria

- [ ] **Vaga do lote presa por UPDATE condicional**, jamais por leitura seguida de
      gravação. Zero linhas afetadas = lote esgotou entre a consulta e a gravação, e a
      inscrição é recusada. Mesmo padrão de `ReservarVagas::reservarNoEvento()`.
- [ ] **O CHECK do banco é a última linha de defesa**: se algum caminho de código errar a
      contabilidade, o PostgreSQL recusa a gravação.
- [ ] **RN-L3 num lugar só.** A regra do lote vigente vive em `ResolverLoteVigente` e é
      consumida pela Action, pelo Resource e pelos testes. Nenhuma cópia da condição em
      Blade, Vue ou controller.
- [ ] Comentários em português explicando **por quê**, não o quê — no tom dos arquivos
      vizinhos (`ReservarVagas`, `ExpirarInscricoesVencidas`). Em especial: um comentário
      em `LiberarVagas` dizendo que o lote **não** é devolvido, e por decisão de quem.
- [ ] PSR-12 / Pint limpo (`./vendor/bin/pint --dirty`).
- [ ] Vue 3 `<script setup>` + TypeScript strict; nenhum `any`.
- [ ] **Pest — domínio** (`LotesTest.php`):
      - o lote vigente é o primeiro não vencido e não esgotado, na ordem de `posicao`;
      - lote vencido por data é pulado; lote esgotado por quantidade é pulado;
      - inscrição grava `lote_id` e o `valor_centavos` **do lote**, não o do evento;
      - alterar o valor do lote depois **não muda** a inscrição já criada (RN-L7);
      - enviar um `lote_id` que não é o vigente devolve 422 com a mensagem de RN-L5;
      - duas inscrições simultâneas na última vaga do lote: uma entra, a outra é
        recusada, e `vagas_ocupadas` nunca passa de `quantidade`;
      - inscrição expirada **não** devolve vaga ao lote, mas devolve ao evento (RN-L6);
      - evento sem lotes continua cobrando `eventos.valor_centavos` (RN-L8);
      - evento com todos os lotes esgotados fecha as inscrições com o motivo certo
        (RN-L9).
- [ ] **Pest — admin** (`LotesAdminTest.php`): criar, editar e excluir; recusa de lote sem
      limite nenhum (RN-L1); recusa de `posicao` repetida (RN-L2); recusa de exclusão e de
      redução de quantidade quando já há inscrição (RN-L12); auditoria registrada, no
      molde de `DiaEventoController`.
- [ ] **Playwright E2E** (`inscricao-por-lote.spec.ts`), quatro cenários:
      1. **Caminho feliz** — evento com três lotes; a página mostra os três com preço e
         situação; a inscrição usa o vigente e a tela de cobrança mostra o valor dele.
      2. **Só o vigente é selecionável** — os encerrados e os futuros aparecem, e não são
         clicáveis nem enviáveis (`aria-disabled` / ausência de `input` habilitado).
      3. **Lote esgota durante o preenchimento** — a última vaga é tomada por fora e o
         envio devolve a mensagem de RN-L5, com os lotes recarregados.
      4. **Todos os lotes esgotados** — a página do evento explica que os lotes acabaram e
         não oferece o botão de inscrição (estado vazio).
- [ ] Acessibilidade: a lista de lotes é uma tabela ou lista semântica, cada lote diz a
      sua situação em texto (não só por cor), e o lote não selecionável é anunciado como
      tal — não apenas pintado de cinza.
- [ ] A suíte inteira continua verde: `php artisan test --parallel`.

## 6. Ambiguity Handling

**Decisões do dono do produto (entrevista de 2026-09-02):**

- A quantidade do lote conta **reservadas + confirmadas** — quem aguarda pagamento já
  ocupa a vaga do lote.
- Vaga devolvida por expiração ou cancelamento **volta só para a capacidade do evento**;
  o lote fica esgotado para sempre.
- Lote e capacidade são **tetos independentes**, sem validação cruzada.
- Lote é **opcional**: evento sem lote continua como hoje.
- O valor fica **na inscrição**, não só no lote: mudar o valor do lote depois não altera
  inscrição nenhuma.

**Assumptions made:**

- *O projeto ainda não está em produção* (informado pelo dono do produto), então as
  migrações são diretas: sem backfill, sem migração de dados, sem período de convivência
  entre dois modelos de preço.
- *Pelo menos um limite por lote* (RN-L1). O último lote de uma sequência normalmente
  vale "até fechar as inscrições" — e nesse caso o organizador põe em `disponivel_ate` a
  mesma data de `inscricoes_fecham_em`. Permitir lote sem limite algum criaria um lote
  que nunca encerra, indistinguível do preço do evento.
- *A situação do lote não é gravada* (RN-L3): é derivada de data e contador a cada
  leitura.
- *A lista de lotes aparece nas duas telas públicas* — na página do evento (é o apelo
  comercial: "o preço sobe dia tal") e no topo da etapa de participação do formulário
  (é onde a pessoa decide). Um componente só, usado nos dois lugares.
- *`eventos.valor_centavos` continua existindo e obrigatório*, como valor do evento sem
  lotes e como padrão sugerido no cadastro do primeiro lote.

**⚠️ Risco que o dono do produto precisa enxergar (RN-L6):**

Como o contador do lote nunca volta, **quem reserva e não paga queima a vaga do lote
barato**. Com o prazo padrão de 24 horas, um evento popular pode ver o 1º lote esgotar em
minutos e, no dia seguinte, ter metade daquelas inscrições expiradas — vagas que voltam
para o evento, mas são vendidas ao preço do 2º lote, sem que ninguém tenha pago o 1º.

Isso é consequência direta da decisão tomada, não um defeito da implementação, e o plano
a executa como decidida. Duas mitigações existem e **não** estão neste escopo:
prazo de pagamento mais curto nos eventos com lote, ou devolver a vaga ao lote de origem
enquanto ele ainda estiver na data. Se o dono do produto quiser qualquer uma delas, é
outro plano.

**If unsure during execution:**

- Faltou informação essencial (nome de coluna, texto de mensagem, comportamento de tela)
  → **pare e pergunte**. Não invente regra de negócio.
- A regra existente e a nova se contradizem (por exemplo: `temVagaDisponivel()` diz que
  há vaga, mas não há lote vigente) → **RN-L9 vence**: sem lote vigente, num evento que
  tem lotes, não há inscrição. Explique a escolha no comentário.
- Um teste existente quebrar → **leia o teste antes de mexer nele**. Se ele quebrou
  porque a RN-L8 não foi respeitada, o defeito é do código novo, não do teste.

## 7. Prohibitions

- ❌ **Nunca** ler o contador do lote e depois gravá-lo. Só UPDATE condicional.
- ❌ **Nunca** gravar situação de lote em coluna ("ativo", "encerrado"): ela é derivada.
- ❌ **Nunca** calcular o valor da cobrança a partir do lote no momento do pagamento. A
  cobrança lê `inscricoes.valor_centavos`, sempre — inclusive na segunda via.
- ❌ **Nunca** devolver vaga ao lote em expiração, cancelamento ou estorno (RN-L6).
- ❌ **Nunca** aceitar `lote_id` diferente do vigente, nem "corrigir" em silêncio para o
  vigente (RN-L5).
- ❌ **Nunca** confiar na tela: o servidor recalcula o lote vigente no envio.
- ❌ **Nunca** apagar lote com inscrição vinculada, nem por cascade.
- ❌ **Nunca** alterar o comportamento de evento sem lotes (RN-L8).
- ❌ **Nunca** mexer em pagamentos, ingressos, portaria, e-mails ou no fuso da aplicação.
- ❌ **Nunca** pular os testes Playwright: há UI nova em três telas.
- ❌ **Nunca** usar float em dinheiro. Centavos inteiros do começo ao fim (D-06).

---

## Execution Steps

1. **Banco.** Criar as duas migrações (`lotes` e `inscricoes.lote_id`) com os quatro CHECK
   e o índice único de §3, no estilo de `create_eventos_table`. Criar `LoteFactory` com os
   states `porData`, `porQuantidade` e `esgotado`. Rodar `migrate:fresh` e conferir as
   restrições no banco.

2. **Domínio — o lote e a vigência.** Criar `app/Models/Lote.php` (casts, relação com
   evento e inscrições, `estaDisponivel()`, `situacaoEm()`, scope `emOrdem()`) e
   `app/Actions/Inscricoes/ResolverLoteVigente.php` com a regra RN-L3. Acrescentar em
   `Evento` as relações e os atalhos (`temLotes()`, `loteVigente()`) e em `Inscricao` a
   relação `lote()`. Nenhuma tela ainda.

3. **Domínio — prender a vaga e fotografar o preço.** Acrescentar `reservarNoLote()` em
   `ReservarVagas` (UPDATE condicional por quantidade **e** por data), respeitando a ordem
   RN-L11. Em `CriarInscricao`: resolver o lote vigente, conferir contra o `lote_id`
   enviado (RN-L5), gravar `lote_id` e o `valor_centavos` do lote (RN-L7). Criar
   `LoteIndisponivelException` com as três mensagens. Acrescentar `?int $loteId` ao DTO.
   Comentar em `LiberarVagas` por que o lote não é devolvido (RN-L6).

4. **Domínio — inscrições fechadas por falta de lote.** Ajustar `inscricoesEstaoAbertas()`
   e `motivoEmPalavras()` em `Evento` para a RN-L9, sem tocar no comportamento de evento
   sem lotes (RN-L8).

5. **HTTP.** `StoreInscricaoRequest` aceita `lote_id`. Criar `LotePublicoResource` e
   acrescentar `lotes` / `lote_vigente_id` ao `EventoPublicoResource`. Carregar a relação
   nos dois controllers públicos. Criar `Admin/LoteController` + `Admin/LoteRequest` no
   molde de `DiaEventoController` (authorize, dono, auditoria) e registrar as três rotas
   em `routes/web.php` dentro do grupo `eventos.gerenciar`.

6. **Testes de domínio e de admin (Pest).** Escrever `LotesTest.php` e
   `LotesAdminTest.php` com todos os casos de §5, incluindo a corrida pela última vaga.
   Ajustar `Cenario` com um `comLotes()` opcional que **não** muda nenhuma chamada
   existente. A suíte inteira precisa estar verde antes de abrir qualquer arquivo `.vue`.

7. **Tela pública.** Criar `components/eventos/ListaDeLotes.vue` e usá-lo em
   `Eventos/Show.vue` e no topo de `PassoParticipacao.vue`. Levar `lote_id` no formulário
   de `Criar.vue` e tratar a recusa de RN-L5 recarregando os lotes com a mensagem do
   servidor. Trocar o valor exibido em `PassoRevisao.vue` e `ResumoDaInscricao.vue` pelo
   valor do lote vigente. Atualizar os três arquivos de tipos.

8. **Tela administrativa.** Acrescentar a seção de lotes em `Admin/Eventos/Estrutura.vue`,
   com o modal de cadastro no mesmo padrão dos dias e grupos: cada linha mostra valor,
   limite, quantas vagas já foram ocupadas e a situação; sem botão de excluir quando já
   há inscrição, com a explicação do caminho certo. Mostrar a soma das quantidades ao
   lado da capacidade do evento (RN-L10), como informação — nunca como bloqueio.

9. **Playwright.** Escrever `tests/e2e/inscricao-por-lote.spec.ts` com os quatro cenários
   de §5, no molde de `capacidade-esgotada.spec.ts`. Rodar a suíte E2E inteira.

10. **Fechamento.** `./vendor/bin/pint --dirty`, `php artisan test --parallel` e a suíte
    Playwright, todos verdes. Atualizar `docs/BUSINESS_RULES.md` (as RN-L),
    `docs/DATABASE.md` (a tabela `lotes` e a coluna nova) e `docs/PROGRESS.md` (o que foi
    entregue e a decisão da RN-L6, com o risco registrado).

## Done

Um evento pode ter uma sequência de lotes com valor e limite de data e/ou quantidade; a
página do evento e o formulário mostram todos os lotes com preço e situação, mas só
deixam inscrever pelo vigente no instante do envio; a inscrição guarda de qual lote veio e
o valor daquele lote, imune a alterações posteriores; evento sem lote continua idêntico ao
de hoje; e tudo isso está coberto por Pest e por Playwright, com a suíte inteira verde.

## Commit

`feat(inscricoes): inscricoes por lote com limite de data e quantidade`

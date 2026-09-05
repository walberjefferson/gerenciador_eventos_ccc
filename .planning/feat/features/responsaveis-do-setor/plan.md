# Action Plan — Cadastro de responsáveis e sorteio de quem recebe

> **Type:** feature
> **Created:** 2026-09-03 14:59
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro Sênior PHP 8.4 + Laravel 12 + Inertia 2 + Vue 3 (Composition API,
`<script setup>`) + TypeScript strict + Tailwind v4/Reka UI, com domínio de PostgreSQL
(migração com backfill, tabela de vínculo, consulta de agregação) e testes Pest +
Playwright.

**Scope:** Trocar o responsável único do setor por um **cadastro próprio de responsáveis**,
com vínculo N:N, e sortear entre eles — pelo menos carregado — no momento de emitir a
cobrança. Quatro frentes, nesta ordem: banco (com backfill) → domínio e sorteio → HTTP e
acesso → telas → testes.

Fora de escopo:
- mexer no provedor de pagamento, credenciais, webhook ou reconciliação;
- mudar o fluxo do comprovante (envio, aceite, recusa) — só quem aparece nele muda;
- mexer em lotes, ingresso, portaria ou no fuso;
- prestação de contas, fechamento por responsável, relatório de repasse.

**Stack:** PHP 8.4, Laravel 12, PostgreSQL, Inertia 2, Vue 3.5, TypeScript, Tailwind v4,
Reka UI, spatie/permission, Pest, Playwright.

## 2. Direct Objective

Permitir cadastrar responsáveis de forma independente do setor — cada um com o seu nome,
chave Pix e telefone —, vincular vários deles a um mesmo setor (e o mesmo responsável a
vários setores), e fazer com que **cada cobrança emitida no modo setor sorteie um deles**,
entre os que estiverem menos carregados naquele evento, gravando na própria cobrança quem
foi o escolhido.

## 3. Minimum Inputs

### O que existe hoje, e por que precisa mudar

`cidades` guarda `responsavel_id` (FK → `users`), `chave_pix`, `titular_chave_pix` e
`telefone_responsavel`. É 1:1 e mistura duas coisas: *quem responde* e *para onde o
dinheiro vai*. Com mais de um responsável por setor, esses quatro campos deixam de fazer
sentido no setor — eles descrevem uma pessoa, não um lugar.

**27 arquivos dependem desse vínculo.** Os que decidem alguma coisa:

| Arquivo | O que ele faz hoje |
|---|---|
| `app/Models/Cidade.php` | `responsavel()`, `estaPreparadaParaReceber()`, `ativasDespreparadasParaReceber()` |
| `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` | lê `setor->chave_pix` e `titular_chave_pix` para montar o BR Code |
| `app/Policies/ComprovantePagamentoPolicy.php` | `setoresDe()` consulta `cidades.responsavel_id` |
| `app/Http/Controllers/PagamentoController.php` | monta o bloco `setor` da tela com chave, titular, responsável e telefone |
| `app/Http/Requests/Admin/CidadeRequest.php` | valida os quatro campos |

### Entities / Data

**`responsaveis`** (nova tabela)

| Coluna | Tipo | Nulo | Observação |
|---|---|---|---|
| `id` | bigserial | não | |
| `nome` | string(120) | não | o nome do titular — é o que aparece no aplicativo de quem paga |
| `chave_pix` | string(140) | não | **em claro**, pelo mesmo motivo da RN-S3: ela existe para ser mostrada |
| `telefone` | string(40) | sim | contato para dúvidas |
| `user_id` | bigint FK → `users` | sim | `nullOnDelete` — a conta do painel, **quando existe** |
| `ativo` | boolean | não | `default true` |
| `created_at` / `updated_at` | timestamptz | não | |

```sql
INDEX (ativo)
CREATE UNIQUE INDEX responsaveis_user_id_unique ON responsaveis (user_id) WHERE user_id IS NOT NULL
```

O único parcial em `user_id` diz que uma conta do painel corresponde a **um** cadastro de
responsável: duas fichas para a mesma pessoa fariam o escopo de conferência responder
duas coisas diferentes para o mesmo login.

**`responsaveis_setores`** (nova tabela de vínculo)

| Coluna | Tipo | Nulo | Observação |
|---|---|---|---|
| `cidade_id` | bigint FK → `cidades` | não | `cascadeOnDelete` — o vínculo morre com o setor, e só ele |
| `responsavel_id` | bigint FK → `responsaveis` | não | `cascadeOnDelete` |

```sql
PRIMARY KEY (cidade_id, responsavel_id)
INDEX (responsavel_id)
```

Sem `id` próprio e sem timestamps: é vínculo puro, no molde de `inscricoes_atividades`.

**`pagamentos`** (alterar)

| Coluna | Tipo | Nulo | Observação |
|---|---|---|---|
| `responsavel_id` | bigint FK → `responsaveis` | sim | `restrictOnDelete` — responsável que já recebeu não se apaga |

**A coluna fica no PAGAMENTO, e não na inscrição.** É a consequência direta da decisão de
sortear de novo a cada cobrança: o escolhido pertence àquela cobrança, não à pessoa. Assim
uma cobrança vencida guarda para sempre quem era o responsável dela, e a cobrança nova
guarda o seu — o histórico conta a verdade em vez de ser reescrito.

**`cidades`** (remover)

`responsavel_id`, `chave_pix`, `titular_chave_pix` e `telefone_responsavel` saem. A
migração faz **backfill antes de dropar**: cada setor que hoje tem chave vira um registro
em `responsaveis` (aproveitando `user_id`, `titular_chave_pix` como nome e o telefone) e
ganha o vínculo correspondente. Ninguém precisa recadastrar o que já cadastrou.

### Business Rules

- **RN-R1 — Responsável é cadastro próprio, e pode existir sem conta no painel.**
  `user_id` nulo significa "recebe, mas não confere". É o caso real do tesoureiro que não
  usa o sistema. Quem confere continua sendo quem tem conta **e** o papel — ou o
  administrador, que alcança tudo.

- **RN-R2 — Um setor tem N responsáveis; um responsável atende N setores.** A chave Pix é
  **da pessoa**, não do vínculo: o mesmo responsável usa a mesma chave em todos os setores
  que atende. Chave por vínculo foi considerada e recusada em §6.

- **RN-R3 — Setor preparado é setor com pelo menos um responsável ativo e com chave.**
  Substitui a regra antiga (`responsavel_id` + `chave_pix` no próprio setor). A RN-S4
  continua valendo com a redação nova: evento no modo `setor` só é salvo quando **todo**
  setor ativo responde sim a isto, e a mensagem continua nomeando quem falta.

- **RN-R4 — O sorteio acontece ao emitir a cobrança, e escolhe entre os menos carregados.**
  Candidatos: responsáveis **ativos**, **com chave**, vinculados ao setor da inscrição.
  Carga: quantas inscrições **ativas** (aguardando pagamento ou confirmadas) daquele
  **evento** já apontam para cada candidato, via `pagamentos.responsavel_id`. Toma-se o
  menor valor de carga e sorteia-se **entre os empatados** nele. Assim ninguém recebe o
  dobro do outro por azar, e a escolha continua imprevisível.

- **RN-R5 — Cada cobrança sorteia de novo.** `CriarPagamentoDaInscricao` continua
  idempotente: havendo cobrança pendente, ela é devolvida como está, **sem sortear**.
  O sorteio só acontece quando uma cobrança nova precisa nascer — primeira emissão, ou
  reemissão depois de a anterior vencer. Ver o risco em §6.

- **RN-R6 — Qualquer responsável do setor confere, não só o sorteado.** O escopo da RN-S9
  passa a ser "os setores que esta conta atende", pela cadeia
  `users → responsaveis → responsaveis_setores → cidades`. Responsável ausente não trava
  a fila do setor.

- **RN-R7 — Quem confere precisa ver para quem o dinheiro foi.** A tela de conferência
  mostra, em cada linha, o responsável **daquela cobrança** — nome e chave. Sem isso, com
  a RN-R5, alguém aceitaria um comprovante de um Pix que caiu na conta de outra pessoa sem
  perceber. É a peça que torna a RN-R5 segura.

- **RN-R8 — A carga é balanceamento, nunca capacidade.** Duas inscrições simultâneas podem
  sortear o mesmo responsável, e isso é aceitável: nada estoura, nada é vendido duas vezes.
  Por isso o sorteio **não** usa trava nem `SELECT ... FOR UPDATE` — o custo da trava não
  se paga para corrigir um desequilíbrio de uma unidade.

- **RN-R9 — Responsável que já recebeu não se apaga, e sem chave não entra no sorteio.**
  Excluir responsável com cobrança é recusado (`restrictOnDelete`), com a mensagem
  indicando o caminho: desativar. Desativado ou sem chave, ele sai do sorteio na hora,
  sem tocar em cobrança nenhuma já emitida.

- **RN-R10 — Setor sem responsável apto recusa a inscrição com a mensagem de sempre.**
  Reaproveita `SetorSemChavePixException`, com o texto ajustado para o plural.

### Existing Files to Read

- `app/Models/Cidade.php` — os quatro campos que saem e os dois métodos que mudam
- `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` — a bifurcação do modo setor e o
  `brCode()`; é onde o sorteio entra
- `app/Policies/ComprovantePagamentoPolicy.php` — `setoresDe()`, o lugar único do escopo
- `app/Http/Controllers/PagamentoController.php` — `setorDaCobranca()`
- `app/Http/Controllers/Admin/ConferenciaComprovanteController.php` — a fila que ganha a
  coluna da RN-R7
- `app/Http/Requests/Admin/CidadeRequest.php` + `app/Http/Controllers/Admin/CidadeController.php`
- `database/migrations/2026_09_03_100002_add_responsavel_to_cidades_table.php` e
  `..._110001_add_telefone_responsavel_to_cidades_table.php` — o que a nova desfaz
- `resources/js/pages/Admin/Catalogo/Setores.vue` e `GruposParticipantes.vue` — o molde da
  tela de catálogo (modal, tabela, estado vazio)
- `database/seeders/CobrancaDemoSeeder.php` — `prepararSetores()` muda de forma
- `tests/Feature/Pagamentos/RecebimentoPeloSetorTest.php`,
  `tests/Feature/Comprovantes/{EnvioDeComprovanteTest,ConferenciaTest}.php`,
  `tests/Feature/Admin/CatalogoTest.php` — os testes que falam do vínculo antigo

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/{ts}_create_responsaveis_table.php` | create | Tabela + único parcial em `user_id` |
| `database/migrations/{ts}_create_responsaveis_setores_table.php` | create | Vínculo, PK composta |
| `database/migrations/{ts}_add_responsavel_id_to_pagamentos_table.php` | create | FK `restrictOnDelete` + índice |
| `database/migrations/{ts}_mover_responsavel_do_setor_para_responsaveis.php` | create | **Backfill e depois drop** dos quatro campos de `cidades` |
| `database/factories/ResponsavelFactory.php` | create | States `semConta`, `semChave`, `inativo` |
| `app/Models/Responsavel.php` | create | Casts, `setores()`, `user()`, `pagamentos()`, scopes `ativos()`/`aptos()` |
| `app/Models/Cidade.php` | modify | `responsaveis()`, RN-R3; remove `responsavel()` e os campos |
| `app/Models/Pagamento.php` | modify | Relação `responsavel()` |
| `app/Actions/Pagamentos/SortearResponsavel.php` | create | RN-R4 num lugar só |
| `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` | modify | Sorteia, grava `responsavel_id`, monta o BR Code com a chave dele |
| `app/Exceptions/Pagamentos/SetorSemChavePixException.php` | modify | Texto no plural (RN-R10) |
| `app/Policies/ComprovantePagamentoPolicy.php` | modify | `setoresDe()` pela cadeia nova (RN-R6) |
| `app/Http/Controllers/PagamentoController.php` | modify | O bloco `setor` passa a ler o responsável **da cobrança** |
| `app/Http/Controllers/Admin/ConferenciaComprovanteController.php` | modify | RN-R7: quem recebeu, em cada linha |
| `app/Http/Controllers/Admin/ResponsavelController.php` | create | CRUD no molde de `CidadeController` |
| `app/Http/Requests/Admin/ResponsavelRequest.php` | create | Nome, chave, telefone, conta, setores |
| `app/Http/Requests/Admin/CidadeRequest.php` | modify | Perde os quatro campos; ganha `responsaveis[]` |
| `app/Http/Controllers/Admin/CidadeController.php` | modify | Sincroniza o vínculo; expõe os responsáveis |
| `app/Http/Requests/Admin/EventoRequest.php` | modify | RN-S4 com a redação da RN-R3 |
| `database/seeders/PapeisSeeder.php` | modify | `catalogo.gerenciar` passa a cobrir responsáveis (comentar a decisão) |
| `routes/web.php` | modify | Rotas do CRUD de responsáveis |
| `resources/js/types/admin.ts` | modify | `ResponsavelDoCatalogo`, vínculo no setor |
| `resources/js/types/pagamento.ts` | modify | O bloco do responsável vem da cobrança |
| `resources/js/pages/Admin/Catalogo/Responsaveis.vue` | create | A tela do cadastro novo |
| `resources/js/pages/Admin/Catalogo/Setores.vue` | modify | Troca os 4 campos por seleção de responsáveis |
| `resources/js/pages/Admin/Comprovantes/Index.vue` | modify | Coluna "recebeu" (RN-R7) |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modify | Ajuste de origem dos dados (visual quase igual) |
| `resources/js/components/AppSidebar.vue` | modify | Item de menu do cadastro novo |
| `database/seeders/CobrancaDemoSeeder.php` | modify | Dois responsáveis por setor, para o sorteio ter o que sortear |
| `tests/Feature/Pagamentos/SorteioDeResponsavelTest.php` | create | RN-R4, RN-R5, RN-R8, RN-R9 |
| `tests/Feature/Admin/ResponsaveisTest.php` | create | CRUD, vínculo, RN-R9 |
| `tests/Feature/Pagamentos/RecebimentoPeloSetorTest.php` | modify | Adaptar ao cadastro novo |
| `tests/Feature/Comprovantes/EnvioDeComprovanteTest.php` | modify | Idem |
| `tests/Feature/Comprovantes/ConferenciaTest.php` | modify | RN-R6 e RN-R7 |
| `tests/Feature/Admin/CatalogoTest.php` | modify | Os campos saíram do setor |
| `tests/e2e/pagamento-pelo-setor.spec.ts` | modify | Cenário com dois responsáveis |
| `docs/{BUSINESS_RULES,DATABASE,PAYMENTS,PROGRESS}.md` | modify | RN-R*, esquema, e o porquê do sorteio |

## 5. Quality Criteria

- [ ] **A migração não perde nada.** Teste que cria setores com chave no formato antigo,
      roda a migração e prova que cada um virou responsável com o mesmo nome, chave,
      telefone e conta, vinculado ao mesmo setor. Backfill sem prova é backfill que
      ninguém conferiu.
- [ ] **O sorteio equilibra.** Teste com 2 responsáveis e 10 inscrições: cada um fica com
      5. Com 3 responsáveis e 9 inscrições: 3 cada. É a promessa da RN-R4, e é
      determinística mesmo com sorteio — porque o empate só existe entre os menos
      carregados.
- [ ] **O sorteio continua imprevisível dentro do empate.** Teste com 2 responsáveis
      empatados em zero, repetido muitas vezes, em que os dois aparecem. Um sorteio que
      sempre devolve o de menor id não é sorteio — é ordenação.
- [ ] **Só entram os aptos.** Responsável inativo, sem chave ou de outro setor nunca é
      sorteado — um teste por exclusão.
- [ ] **Cobrança pendente não sorteia de novo** (RN-R5): chamar a Action duas vezes devolve
      a mesma cobrança, com o mesmo responsável. A reemissão depois do vencimento sorteia.
- [ ] **A cobrança guarda quem recebeu.** Depois de reemitir, a cobrança antiga continua
      apontando para o responsável antigo — o histórico não é reescrito.
- [ ] **O escopo de conferência segue a cadeia nova** (RN-R6): responsável de dois setores
      alcança os dois; responsável sem conta não alcança nada; conta sem responsável não
      alcança nada; e a URL direta de outro setor devolve 403, não lista filtrada.
- [ ] **A fila mostra quem recebeu** (RN-R7): teste da tela afirmando que a linha traz o
      nome e a chave do responsável **daquela cobrança**.
- [ ] **RN-R9 provada dos dois lados:** excluir responsável com cobrança é recusado;
      desativar tira do sorteio sem tocar em cobrança emitida.
- [ ] **Nada mudou no modo gateway.** A suíte inteira de hoje continua verde sem alteração
      de expectativa em teste de evento por provedor.
- [ ] PSR-12 / Pint limpo; Vue `<script setup>` + TypeScript strict, sem `any`;
      `npx vue-tsc --noEmit` e `npm run build` limpos.
- [ ] Comentários em português explicando **por quê**, no tom de `CriarPagamentoDaInscricao`
      e `ComprovantePagamentoPolicy`. Em especial: por que a coluna do responsável mora no
      **pagamento** e não na inscrição, e por que o sorteio não usa trava (RN-R8).
- [ ] **Playwright E2E**, quatro cenários no `pagamento-pelo-setor.spec.ts`:
      1. setor com dois responsáveis: a tela mostra a chave de **um** deles, e é o mesmo
         ao recarregar;
      2. o outro responsável do setor confere e aceita o comprovante (RN-R6);
      3. a fila de conferência mostra quem recebeu (RN-R7);
      4. isolamento entre setores continua valendo.
- [ ] A suíte inteira verde: `php artisan test --parallel` e `npx playwright test`.

## 6. Ambiguity Handling

**Decisões do dono do produto (entrevista de 2026-09-03):**

- **Tabela própria de responsáveis**, podendo existir sem conta no painel.
- **Sorteia de novo a cada cobrança emitida** — o escolhido não fica preso à inscrição.
- **Qualquer responsável do setor confere**, não só o sorteado.
- **Aleatório entre os menos carregados.**

**Assumptions made:**

- *A chave Pix é da pessoa, não do vínculo* (RN-R2). A opção "chave por vínculo" foi
  oferecida e recusada; ela cobriria a tesouraria que separa contas por setor, ao preço de
  duplicar cadastro no caso comum. Se um dia isso for preciso, a coluna nasce na tabela de
  vínculo sem desfazer nada do que está aqui.
- *A coluna do responsável mora em `pagamentos`* — é a leitura direta de "sorteia a cada
  cobrança". Se um dia a decisão virar "fica preso à inscrição", a coluna migra para
  `inscricoes` e o sorteio passa a ser condicional; nada mais muda.
- *`titular_chave_pix` vira `responsaveis.nome`.* Hoje são dois campos porque o setor não
  é uma pessoa; com pessoa de verdade, um nome basta — é o que aparece no aplicativo de
  quem paga e é como a organização chama a pessoa.
- *`catalogo.gerenciar` cobre o cadastro novo.* Ele já é a permissão de "cadastrar setores
  e grupos", e responsável é catálogo. Permissão nova só se o dono do produto quiser
  separar quem cadastra chave Pix de quem cadastra setor — o que é uma decisão de
  segurança, não de organização de menu.
- *O sorteio conta inscrições ativas do evento, não de todos os eventos.* Equilibrar entre
  eventos faria um responsável novo herdar a carga histórica de outro e receber tudo do
  evento seguinte.

**⚠️ Risco que o dono do produto precisa enxergar (RN-R5):**

Sortear de novo a cada cobrança significa que **a chave pode mudar entre uma visita e
outra**. O caso concreto: a pessoa abre a tela, anota a chave do responsável A, não paga,
o prazo vence; ela pede segunda via, a cobrança nova sorteia o responsável B — e ela paga
para A, pela chave anotada. O dinheiro está na conta de A e a cobrança vigente é de B.

Três coisas reduzem isso, e as três estão no plano:
1. enquanto a cobrança está pendente **não há novo sorteio** (RN-R5) — a chave só muda
   depois de o Pix vencer, quando ele já não valia mesmo;
2. **qualquer responsável do setor confere** (RN-R6), então A pode aceitar o comprovante
   que recebeu, sem depender de B;
3. a fila mostra **quem era o responsável daquela cobrança** (RN-R7), então quem aceita vê
   a divergência em vez de tropeçar nela.

O que elimina de vez é prender o sorteado à inscrição — foi oferecido, e a decisão foi
outra. Fica registrado para o dia em que a organização reclamar de dinheiro na conta
errada: a mudança é a coluna sair de `pagamentos` para `inscricoes`.

**If unsure during execution:**

- O backfill não souber preencher algum campo (setor com chave mas sem
  `titular_chave_pix`, por exemplo) → use o **nome do setor** como nome do responsável e
  registre no comentário. Nunca descarte a chave: perder chave de recebimento no meio de
  uma migração é o pior desfecho possível.
- Faltar texto de mensagem, rótulo ou nome de coluna → **pare e pergunte**.
- Um teste existente quebrar → **leia o teste antes de mexer nele**. Vários falam do
  vínculo antigo e precisam mudar de verdade; mas se quebrou algo de evento por provedor,
  o defeito é do código novo.

## 7. Prohibitions

- ❌ **Nunca** dropar as colunas de `cidades` antes de o backfill ter rodado e conferido.
- ❌ **Nunca** sortear quando já existe cobrança pendente (RN-R5).
- ❌ **Nunca** sortear responsável inativo, sem chave, ou de outro setor.
- ❌ **Nunca** deixar o escopo de conferência depender de parâmetro vindo do navegador.
- ❌ **Nunca** apagar responsável que já recebeu cobrança, nem por cascade.
- ❌ **Nunca** cifrar a chave Pix do responsável: ela é publicada por desenho (RN-S3).
- ❌ **Nunca** usar trava de banco no sorteio (RN-R8).
- ❌ **Nunca** alterar o comportamento de evento no modo `gateway`.
- ❌ **Nunca** mexer em lotes, ingresso, portaria, e-mails ou no fuso.
- ❌ **Nunca** pular os testes Playwright.
- ❌ **Nunca** usar float em dinheiro (D-06).

---

## Execution Steps

1. **Banco, com o backfill provado antes de qualquer perda.** As quatro migrações, na
   ordem: `responsaveis`, `responsaveis_setores`, `pagamentos.responsavel_id`, e por
   último a que **move e depois dropa**. Escrever junto o teste de migração de §5 e
   rodá-lo antes de seguir — depois do drop não há volta.

2. **Domínio.** `Responsavel` (relações, scopes `ativos()` e `aptos()`), `Cidade` com
   `responsaveis()` e a RN-R3 no lugar da regra antiga, `Pagamento::responsavel()`.
   `ResponsavelFactory` com os três states.

3. **O sorteio.** `SortearResponsavel` com a RN-R4 inteira: candidatos aptos do setor,
   carga por inscrições ativas do evento via `pagamentos.responsavel_id`, empate no menor
   e sorteio entre os empatados. Sem trava (RN-R8), com o porquê comentado.

4. **A emissão.** `CriarPagamentoDaInscricao` passa a sortear **só quando nasce cobrança
   nova** (RN-R5), grava `responsavel_id` e monta o BR Code com a chave e o nome dele.
   Ajustar `SetorSemChavePixException` para o plural.

5. **Acesso e telas de leitura.** `ComprovantePagamentoPolicy::setoresDe()` pela cadeia
   nova (RN-R6); `PagamentoController` lendo o responsável **da cobrança**;
   `ConferenciaComprovanteController` levando quem recebeu para a fila (RN-R7).

6. **Cadastro.** `ResponsavelController` + `ResponsavelRequest` no molde de
   `CidadeController`; `CidadeRequest`/`CidadeController` perdendo os quatro campos e
   ganhando a sincronização do vínculo; `EventoRequest` com a RN-R3; as rotas; o
   `PapeisSeeder` comentando a decisão sobre `catalogo.gerenciar`.

7. **Testes de servidor.** `SorteioDeResponsavelTest` e `ResponsaveisTest` novos, mais a
   adaptação dos quatro arquivos que falam do vínculo antigo. A suíte Pest inteira verde
   antes de abrir qualquer `.vue`.

8. **Telas administrativas.** `Catalogo/Responsaveis.vue` (molde de `Setores.vue`),
   `Setores.vue` trocando os quatro campos pela seleção de responsáveis com aviso quando
   nenhum estiver apto, `Comprovantes/Index.vue` com a coluna de quem recebeu, e o item no
   `AppSidebar`.

9. **Tela do participante e seeder.** `Pagamento.vue` e `types/pagamento.ts` acompanhando a
   origem nova dos dados; `CobrancaDemoSeeder` criando **dois** responsáveis por setor,
   para o sorteio ter o que sortear na demonstração.

10. **Fechamento.** `pint --dirty`, `php artisan test --parallel`, `vue-tsc --noEmit`,
    `npm run build`, Playwright (com o spec do modo setor ajustado para dois
    responsáveis), e os quatro docs — incluindo o risco da RN-R5 registrado em
    `PROGRESS.md`.

## Done

Responsáveis são cadastrados por si, com nome, chave Pix e telefone, podendo ou não ter
conta no painel; um setor tem vários e um responsável atende vários; cada cobrança emitida
no modo setor sorteia entre os menos carregados e grava quem foi; qualquer responsável do
setor confere, vendo na fila quem recebeu aquele Pix; o que já estava cadastrado no
formato antigo foi migrado sem perda; evento por provedor continua idêntico; e tudo está
coberto por Pest e Playwright, com a suíte inteira verde.

## Commit

`feat(pagamentos): cadastro de responsaveis do setor com sorteio de quem recebe`

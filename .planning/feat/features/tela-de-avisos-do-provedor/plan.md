# Action Plan — A tela que mostra os avisos do provedor de pagamento

> **Type:** feature
> **Created:** 2026-08-30
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Pessoa desenvolvedora full-stack sênior em Laravel 12 (PHP 8.2+,
PSR-12, Pest) + Vue 3.5 com `<script setup>` + TypeScript estrito + Inertia 2 +
Tailwind CSS 4 + Reka UI 2. Escreve comentário em português explicando o
**porquê**, nunca o **o quê**. Domínio em português (tabelas, Models, Enums),
infraestrutura Laravel em inglês.

**Escopo:** uma tela administrativa somente-leitura em
`/admin/pagamentos/avisos`, que lista os registros da tabela
`webhooks_pagamento`; a permissão nova que a protege; o item de menu que leva
até ela; e um cartão no painel dizendo quando chegou o último aviso.

**Fora do escopo:** nada do fluxo de pagamento muda. O
`PaymentWebhookController`, o job `ProcessarWebhookPagamento`, o
`EfiPaymentGateway` e a reconciliação **não são tocados** — esta feature só
**lê** o que eles já gravam. Não há botão de reprocessar (decisão do dono do
produto: primeiro enxergar, depois agir), não há exclusão e não há edição.

**Stack:** Laravel 12 · PHP 8.2 · PostgreSQL · Pest · Vue 3.5 · Inertia ·
Tailwind 4 · Reka UI 2 · Playwright.

## 2. Direct Objective

Tornar visível, para quem administra, o que hoje só existe no banco: todo aviso
que a Efí (ou qualquer provedor) mandou, com data, tipo, situação, se a
assinatura era válida e o motivo quando foi ignorado ou falhou. E responder, no
painel, a pergunta que hoje ninguém consegue fazer sem abrir o PostgreSQL:
**"o provedor ainda está chamando?"**

## 3. Minimum Inputs

### Entidades / Dados

Nenhuma tabela nova, nenhuma migration. Tudo já é gravado por
`PaymentWebhookController` desde a Fase 8a. A tabela `webhooks_pagamento`
(migration `2026_08_20_100011`) e o model `App\Models\WebhookPagamento`:

| Coluna | Tipo | O que a tela faz com ela |
|---|---|---|
| `id` | bigint | chave da linha expandida |
| `gateway` | string(40) | coluna e filtro |
| `id_evento_externo` | string(190), nulo | coluna (o identificador do provedor) |
| `tipo_evento` | string(80), nulo | coluna e filtro |
| `payload` | jsonb | mostrado **sob demanda**, ao expandir a linha |
| `assinatura_valida` | bool | coluna, com destaque quando falsa |
| `recebido_em` | timestamptz | coluna, ordenação e filtro de período |
| `processado_em` | timestamptz, nulo | coluna |
| `situacao` | string(20) | coluna e filtro — enum `SituacaoWebhook` |
| `erro` | text, nulo | coluna, quando existe |

O enum `App\Enums\SituacaoWebhook` já tem `rotulo()` e os quatro casos:
`Recebido`, `Processado`, `Ignorado`, `Falhou`. **Use o `rotulo()` existente,
não escreva os textos de novo na tela.**

Índice já existente: `['situacao', 'recebido_em']` — a ordenação e o filtro
principais desta tela caem nele, e nenhum índice novo é necessário.

### Regras de negócio

1. **A tela é somente leitura, e isso é regra, não escopo reduzido.** Não
   existe rota de criar, alterar, apagar nem reprocessar. Aviso de provedor é
   registro de algo que aconteceu fora daqui.
2. **`ignorado` não é erro, e a tela precisa dizer isso.** É o aviso sem
   assinatura válida, o que fala de cobrança que não existe aqui, ou o que
   repete algo já resolvido. Pintar de vermelho ensinaria a ignorar o vermelho.
   Quem merece destaque de alarme é `falhou`, e `assinatura_valida = false`
   merece destaque **de segurança**, que é outra coisa: alguém bateu na porta
   com uma chave errada.
3. **O payload já chega limpo do banco** — `semDadoSensivel()` trocou `secret`,
   `token`, `hmac`, `card`, `chave` e afins por `[removido]` antes de gravar.
   A tela **não precisa mascarar nada de novo**; e não deve reintroduzir
   nenhum campo cru. Se algum dia aparecer segredo ali, o defeito é do
   controller, não desta tela.
4. **Permissão nova: `pagamentos.avisos-ver`**, concedida **só ao
   administrador** — como `auditoria.ver` e `pagamentos.credenciais`. Quem
   organiza o evento não precisa disso.
5. **O cartão do painel é global, não por evento.** Aviso de provedor não
   pertence a um evento: ele fala de uma cobrança, que pertence a uma
   inscrição. O cartão responde "o provedor está chamando?", e essa pergunta
   não muda quando se troca o evento do seletor.
6. **Sem avisos nenhum, o cartão não pode parecer defeito.** Sistema recém
   publicado, ou rodando com `PAYMENT_GATEWAY=fake`, nunca recebeu aviso — e
   isso é normal. O texto para lista vazia diz isso, em vez de mostrar um
   "há — dias" que assusta à toa.

### Arquivos a ler antes de começar

- `app/Http/Controllers/Admin/AuditoriaController.php` — **é o molde desta
  tela**: lista paginada, somente leitura, filtros preservados por
  `withQueryString()`, dupla tranca de permissão (middleware na rota + `abort_unless`
  no controller). Siga a mesma forma, inclusive nos comentários.
- `resources/js/pages/Admin/Auditoria/Index.vue` — o molde da página.
- `app/Http/Controllers/Webhooks/PaymentWebhookController.php` — para entender
  o que cada situação significa antes de escrever o texto que a explica.
- `app/Jobs/ProcessarWebhookPagamento.php` — os três motivos de `Ignorado` e o
  caminho de `Falhou` saem daqui.
- `app/Enums/SituacaoWebhook.php`, `app/Models/WebhookPagamento.php`
- `database/seeders/PapeisSeeder.php` — a lista de permissões e a concessão
  por papel.
- `routes/web.php` — o grupo `/admin`, linhas ~186–200.
- `resources/js/components/AppSidebar.vue` — como a Auditoria some do menu para
  quem não tem a permissão (linhas ~34–45).
- `app/Http/Controllers/Admin/PainelController.php` e
  `resources/js/pages/Admin/Painel.vue`
- `tests/Feature/Admin/AutorizacaoTest.php` — **a linha 29 afirma que o
  administrador tem 10 permissões e vai passar a 11.**
- `docs/PROGRESS.md` (Decisões — a numeração corrente vai até **DA-79**) e
  `docs/ARCHITECTURE.md`.

## 4. Output Format

| Arquivo | Ação | O quê |
|---|---|---|
| `database/seeders/PapeisSeeder.php` | modificar | Permissão `pagamentos.avisos-ver` e concessão ao papel administrador |
| `app/Http/Controllers/Admin/AvisosPagamentoController.php` | criar | `index` paginado com filtros, espelhando o `AuditoriaController` |
| `routes/web.php` | modificar | `GET admin/pagamentos/avisos`, com `permission:pagamentos.avisos-ver` |
| `app/Http/Controllers/Admin/PainelController.php` | modificar | Um agregado a mais: o último aviso recebido |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | criar | A lista, os filtros e o payload sob demanda |
| `resources/js/pages/Admin/Painel.vue` | modificar | O cartão "último aviso do provedor" |
| `resources/js/components/AppSidebar.vue` | modificar | Item de menu, condicionado à permissão |
| `resources/js/types/admin.ts` | modificar | Tipos do aviso, da página e do cartão |
| `tests/Feature/Admin/AutorizacaoTest.php` | modificar | 10 → 11 permissões; organizador recebe 403 na rota nova |
| `tests/Feature/Pagamentos/AvisosDoProvedorTest.php` | criar | Listagem, filtros, permissão e o cartão do painel |
| `tests/e2e/admin-avisos-pagamento.spec.ts` | criar | A tela em 1280×800, com dados semeados |
| `docs/PROGRESS.md` | modificar | Decisões novas a partir da **DA-80** |

## 5. Quality Criteria

### Backend

- [ ] A rota exige `permission:pagamentos.avisos-ver` **e** o controller repete
      a conferência com `abort_unless` — a mesma dupla tranca do
      `AuditoriaController`, pelo mesmo motivo escrito lá.
- [ ] Paginação de 25 por página, `withQueryString()`, ordenação
      `recebido_em desc, id desc`.
- [ ] Filtros combináveis: período (`de`/`ate`), `situacao`, `gateway` e
      `assinatura_valida`. Filtro ausente não entra na consulta.
- [ ] **Nenhuma consulta N+1**: a lista não carrega relação nenhuma; se algum
      dado do pagamento for exibido, entra por `join` ou `with`, nunca por
      laço. Prove com `DB::listen` contando as consultas no teste, como o
      projeto já faz na home.
- [ ] O cartão do painel é **uma** consulta agregada (o `max(recebido_em)`, ou
      o registro mais recente), **não** uma listagem. O painel hoje faz três
      consultas; passa a fazer quatro, e o teste que vigia esse número é
      atualizado com o motivo escrito.
- [ ] `SituacaoWebhook::rotulo()` é a fonte dos textos de situação. Nenhum
      rótulo reescrito à mão na tela.
- [ ] `./vendor/bin/pint --test` limpo.

### Frontend

- [ ] A página segue o `Admin/Auditoria/Index.vue`: mesma moldura, mesma
      paginação, mesmos componentes de filtro. **Nenhum componente novo em
      `components/ui/`** — o que for preciso já existe.
- [ ] O payload aparece **só quando a linha é expandida**, formatado e com
      rolagem própria. Um jsonb inteiro por linha tornaria a lista ilegível.
- [ ] `falhou` tem destaque de alarme; `assinatura_valida = false` tem destaque
      de segurança; `ignorado` é neutro, **com a explicação do porquê ao lado
      do filtro**, não escondida num tooltip.
- [ ] O item do menu **some** para quem não tem a permissão, como já acontece
      com a Auditoria — 403 ao clicar num link visível é defeito de navegação.
- [ ] Lista vazia diz o que fazer, e não só "nada aqui": sem aviso nenhum, o
      texto lembra que em `PAYMENT_GATEWAY=fake` isso é o esperado, e que em
      produção pode significar endereço de aviso não registrado na Efí.
- [ ] O cartão do painel some, ou diz "nenhum aviso ainda", quando não há
      registro — nunca mostra um intervalo calculado sobre nada.

### Acessibilidade (DA-42)

- [ ] Tabela com `<th scope>` de verdade e legenda; o estado de cada aviso
      **não pode ser comunicado só por cor** (WCAG 1.4.1) — a palavra da
      situação aparece escrita.
- [ ] O botão que expande o payload diz o que faz, tem `aria-expanded` e alvo
      de 44px.
- [ ] Contraste AA em todo texto novo, inclusive nos destaques de alarme.
- [ ] Filtros com `<label>` de verdade, ligados por `for`.

### Provas

- [ ] **Pest** (`AvisosDoProvedorTest.php`): administrador vê a lista;
      organizador recebe **403**; cada filtro devolve o que promete e a
      combinação de dois filtros também; a paginação preserva o filtro; o
      payload devolvido **não contém** chave sensível; o cartão do painel traz
      o aviso mais recente e se comporta com a tabela vazia.
- [ ] **`AutorizacaoTest`**: a contagem sobe para 11 e o organizador continua
      sem a permissão nova. **Atualize o número com o motivo escrito ao lado**
      — esse teste existe para que permissão nova nunca entre despercebida.
- [ ] **Playwright** (`admin-avisos-pagamento.spec.ts`, 1280×800, declarando o
      próprio viewport como faz `admin-barra-lateral.spec.ts`): a tela abre
      pelo menu, mostra os avisos semeados, filtra por situação, expande um
      payload e o item não aparece no menu para o organizador.
- [ ] A suíte Pest inteira passa; `npx vue-tsc --noEmit`, `npm run lint` e
      `npm run build` limpos.
- [ ] **`git diff` sobre `app/Services/`, `app/Jobs/` e
      `app/Http/Controllers/Webhooks/` volta vazio** — esta feature lê, não
      mexe no fluxo de pagamento.

## 6. Ambiguity Handling

**Decisões tomadas com o dono do produto:**

- **Permissão nova `pagamentos.avisos-ver`, só administrador.** Reusar
  `pagamentos.credenciais` faria uma permissão significar duas coisas, e
  reusar `auditoria.ver` misturaria rastro de gente com aviso de máquina. O
  custo de deploy que eu havia previsto **não existe**: o `entrypoint.sh` já
  roda `db:seed --class=PapeisSeeder --force` a cada subida (`DEPLOY.md` §202).
- **Somente leitura nesta versão.** Sem botão de reprocessar. Primeiro
  enxergar, depois agir.
- **O cartão do painel entra.**

**Premissas a conferir na execução:**

- O nome da rota segue o padrão do grupo: provavelmente
  `admin.pagamentos.avisos`. Confira como o grupo `pagamentos.credenciais`
  nomeia as suas e mantenha a coerência.
- O `PapeisSeeder` é idempotente (D-50). Confirme antes de mexer: ele precisa
  continuar podendo rodar duas vezes sem duplicar permissão.
- O `AutorizacaoTest` tem um caso que varre as rotas `/admin` exigindo que cada
  uma declare `permission:` ou `role:`. A rota nova já nasce coberta por ele —
  **se esse teste falhar, é porque o middleware ficou faltando**, e a correção
  é a rota, não o teste.

**Se travar:**

- Se aparecer chave sensível dentro de algum `payload` gravado, **pare e
  relate**: o defeito é do `semDadoSensivel()` no controller do webhook, é
  anterior a esta tela, e esconder na exibição mascararia um problema de dado
  em repouso.
- Se o teste de contagem de consultas do painel não existir, **não o invente
  agora**; registre no relatório.

## 7. Prohibitions

- ❌ **Não alterar `PaymentWebhookController`, `ProcessarWebhookPagamento`,
  `EfiPaymentGateway` nem a reconciliação.** Esta tela lê.
- ❌ **Nenhuma migration.** A tabela e o índice já existem.
- ❌ Nada de rota de escrita: sem `POST`, `PUT`, `PATCH`, `DELETE` e sem
  reprocessar.
- ❌ Não exibir `payload` cru sem passar pelo que já foi gravado limpo, e não
  acrescentar campo nenhum que o `semDadoSensivel()` remove.
- ❌ Não conceder a permissão nova ao papel `organizador`.
- ❌ Não rodar o gerador do shadcn-vue (**DA-44**); os componentes de interface
  são adaptados à mão.
- ❌ Não usar `classe-[--variavel]` (Tailwind 3); na versão 4 é
  `classe-(--variavel)` (**D-86**).
- ❌ Não combinar classe estática com condicional para a mesma propriedade —
  use um ternário que decide fundo, borda e texto de uma vez (**DA-68**).
- ❌ Não comunicar situação só por cor.

---

## Execution Steps

1. **A permissão.** `PapeisSeeder`: acrescentar `pagamentos.avisos-ver` com a
   descrição em português e concedê-la **só** ao administrador. Conferir que o
   seeder continua idempotente rodando-o duas vezes seguidas.

2. **A rota e o controller.** `AvisosPagamentoController::index` espelhando o
   `AuditoriaController`: paginação de 25, `withQueryString()`, ordenação por
   `recebido_em desc`, filtros de período, situação, gateway e validade da
   assinatura, e a dupla tranca de permissão. Rota `GET
   admin/pagamentos/avisos` dentro do grupo `/admin`.

3. **A página.** `Admin/Pagamentos/Avisos/Index.vue` no molde da Auditoria:
   tabela com data, tipo, gateway, situação escrita, validade da assinatura,
   erro; payload sob demanda ao expandir, com `aria-expanded`; barra de filtros
   com rótulos de verdade; e o texto de lista vazia que explica o que a
   ausência de avisos significa em cada ambiente.

4. **O menu.** `AppSidebar.vue`: item novo condicionado a
   `pagamentos.avisos-ver`, seguindo exatamente o que já é feito com a
   Auditoria — e pelo mesmo motivo escrito lá.

5. **O cartão do painel.** `PainelController`: uma consulta agregada a mais,
   global e não por evento, trazendo o aviso mais recente. `Painel.vue` mostra
   "último aviso recebido há X" e, sem registro, diz que ainda não chegou
   nenhum em vez de calcular sobre o vazio.

6. **Os tipos.** `resources/js/types/admin.ts` recebe o formato do aviso, o da
   página paginada e o do cartão. Nada de `any`, nada de `@ts-ignore`.

7. **As provas de servidor.** `AvisosDoProvedorTest.php` com os casos da seção
   5, mais a atualização do `AutorizacaoTest` de 10 para 11 permissões, com o
   motivo escrito ao lado do número.

8. **A prova de navegador.** `admin-avisos-pagamento.spec.ts` em 1280×800,
   declarando o próprio viewport (não crie projeto novo no
   `playwright.config.ts`). Rodar Pest inteiro, `vue-tsc`, lint e build, e
   conferir que `app/Services/`, `app/Jobs/` e
   `app/Http/Controllers/Webhooks/` ficaram intocados.

9. **Registro.** `docs/PROGRESS.md`, decisões a partir da **DA-80**: por que a
   permissão é nova e não reusada, por que a tela é somente leitura, por que
   `ignorado` não é alarme e `assinatura inválida` é, e por que o cartão do
   painel é global. Some a linha às "Próximas tarefas": a tela é o que permite
   conferir, no dia da virada, se a Efí está mesmo chamando — o que hoje o
   `DEPLOY.md` só consegue pedir que se olhe no banco.

## Done

Um administrador abre `/admin/pagamentos/avisos` pelo menu e vê, do mais
recente para o mais antigo, todo aviso que o provedor mandou — com situação
escrita, assinatura conferida, motivo quando ignorado ou falhou, e o payload a
um clique. O organizador não vê o item no menu e recebe 403 na rota. O painel
diz há quanto tempo chegou o último aviso. A suíte Pest inteira passa, e o
fluxo de pagamento não teve uma linha alterada.

## Commit

`feat(admin): mostrar os avisos recebidos do provedor de pagamento`

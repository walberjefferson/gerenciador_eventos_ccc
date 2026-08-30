# Action Plan — Fase 6a: controle de acesso administrativo e painel de números

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending
> **Ordem:** primeiro plano do lote. **6b, 7 e 9 dependem deste.**

---

## 1. Persona & Scope

**Persona:** Senior Fullstack Engineer **Laravel 12 + PHP 8.4** e **Vue 3.5 + TypeScript strict + Inertia 2 + Tailwind + shadcn-vue**, com prática em autorização (Gates, Policies, spatie/laravel-permission) e em consulta agregada sobre PostgreSQL. Escreve tela administrativa densa e legível — não é vitrine, é ferramenta de trabalho.

**Scope — Fase 6a:** a fundação do lado de dentro. Quem entra, o que pode, e a primeira tela que responde "como está o evento".

| Entrega | Nesta fase |
|---------|:----------:|
| Papéis e permissões com `spatie/laravel-permission` | ✅ |
| Cadastro público fechado; conta administrativa por comando | ✅ |
| Layout administrativo e navegação | ✅ |
| Correção dos 20 erros de tipo da pendência **P-09** | ✅ |
| Painel: inscrições por situação, vagas por atividade, receita | ✅ |
| CRUDs de cadastro | ❌ **plano 6b** |
| Lista de inscrições, exportação e ações administrativas | ❌ **plano 6b** |
| E-mails | ❌ Fase 7 |
| Auditoria | ❌ Fase 9 |

**Stack:** PHP 8.4 · Laravel 12 · Vue 3.5 · TypeScript strict · Inertia 2 · Tailwind · shadcn-vue sobre **`radix-vue`** · Playwright · Pest 4 · PostgreSQL 18.

---

## 2. Direct Objective

Fechar a porta e acender a luz: instalar papéis e permissões, desativar o cadastro público que hoje deixa qualquer visitante criar conta, e entregar um painel onde o organizador vê, num relance e por evento, quantas pessoas se inscreveram, em que situação estão, quantas vagas restam em cada atividade e quanto dinheiro já entrou.

Nenhuma regra de inscrição ou de pagamento é alterada. O painel **lê**.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| Autorização (**DA-10**) | **`spatie/laravel-permission`**. Assumido o custo: é a primeira dependência pesada do projeto e traz tabelas em inglês (`roles`, `permissions`, `model_has_roles`) no meio de um domínio em português. Isso é coerente com **D-02** — estrutura de framework fica em inglês; o que é do negócio fica em português. **Os nomes dos papéis e das permissões são em português** (§3.3) |
| Cadastro público (**DA-11**) | **Fechado.** As rotas de registro saem do ar; conta administrativa nasce por comando artisan. Consequência conhecida: `tests/Feature/Auth/RegistrationTest.php` **vai quebrar** e precisa ser reescrito para provar o oposto (§3.5) |
| Pendência P-09 (**DA-12**) | Os **20 erros de tipo** são corrigidos **aqui**. Eles vivem exatamente nas telas do pacote inicial que esta fase passa a usar de verdade; adiar de novo seria construir o painel sobre código que a verificação de tipos nunca leu |
| Ações sobre inscrição alheia | **Não nesta fase.** Cancelamento administrativo e confirmação manual de pagamento são do plano **6b** |

### 3.2 O que já existe (verificado — não reimplementar)

- **241 testes Pest / 1048 asserções** e **21 cenários Playwright**, verdes. Fases 0 a 5b concluídas.
- `User` — model do pacote inicial, sem papel nenhum, sem Policy nenhuma (`app/Policies/` não existe).
- Autenticação completa do pacote inicial: login, registro, recuperação de senha, verificação de e-mail, ajustes de perfil (`routes/auth.php`, `routes/settings.php`).
- `AppLayout`, `AppSidebarLayout`, `AppHeaderLayout`, `AppSidebar`, `NavMain`, `NavUser` — o esqueleto administrativo do pacote inicial, **hoje sem uso real** e com os 20 erros de tipo da P-09.
- Domínio completo para consultar: `Evento`, `DiaEvento`, `GrupoAtividade`, `Atividade`, `ConflitoAtividade`, `Cidade`, `GrupoParticipante`, `Inscricao`, `InscricaoAtividade`, `Pagamento`, `WebhookPagamento`.
- Contadores de vaga **já materializados** nas tabelas (`vagas_reservadas`, `vagas_confirmadas` ou equivalente — **confira os nomes reais na migration antes de escrever consulta**).
- Enums com `rotulo()`: `SituacaoEvento`, `SituacaoInscricao`, `SituacaoPagamento`, `MetodoPagamento`.
- Tokens semânticos de cor (`--cor-acao`, `--cor-sucesso`, `--cor-informacao`, `--cor-atencao`), claro e escuro, contraste AA medido (D-40, D-41).

### 3.3 Papéis e permissões

**Dois papéis.** Mais do que isso, sem alguém pedindo, é complexidade sem dono.

| Papel | Quem é |
|-------|--------|
| `administrador` | Responsável pelo sistema. Tudo, inclusive dinheiro |
| `organizador` | Quem toca o evento no dia a dia. Cadastra, acompanha e cancela — **não** confirma pagamento manualmente |

**Permissões** (nomes em português, no plural do recurso + verbo):

| Permissão | `administrador` | `organizador` |
|-----------|:---:|:---:|
| `painel.ver` | ✅ | ✅ |
| `catalogo.gerenciar` (cidades, grupos de participantes) | ✅ | ✅ |
| `eventos.gerenciar` (evento, dias, grupos, atividades, conflitos) | ✅ | ✅ |
| `inscricoes.ver` | ✅ | ✅ |
| `inscricoes.exportar` | ✅ | ✅ |
| `inscricoes.cancelar` | ✅ | ✅ |
| `pagamentos.confirmar-manual` | ✅ | ❌ |
| `usuarios.gerenciar` | ✅ | ❌ |
| `auditoria.ver` (usada só na Fase 9) | ✅ | ❌ |

**Por que `pagamentos.confirmar-manual` fica só com o administrador:** é a única ação do sistema que declara "entrou dinheiro" sem que dinheiro tenha sido reconhecido por fonte externa. Quanto menos gente puder, melhor.

As permissões da tabela acima são criadas por seeder **idempotente** (`php artisan db:seed --class=PapeisSeeder` roda duas vezes sem duplicar nada). `auditoria.ver` já nasce aqui, sem tela, para a Fase 9 só amarrar.

### 3.4 O painel

Uma tela, com o evento selecionável (padrão: o mais recente que não seja rascunho). Três blocos:

**Bloco 1 — Inscrições por situação.** Contagem de `inscricoes` agrupada por `situacao`, com o rótulo do Enum: aguardando pagamento, confirmadas, expiradas, canceladas. Mais o total.

**Bloco 2 — Vagas por atividade.** Por atividade: capacidade, reservadas, confirmadas e **restantes**. Lê os contadores materializados — **não** recalcule contando linhas de `inscricoes_atividades`: o contador é a fonte da verdade do domínio, e divergir dele na tela criaria duas versões do mesmo número.

**Bloco 3 — Dinheiro.** Recebido (soma de `pagamentos.valor_centavos` com situação paga) e pendente (soma das cobranças ainda abertas). Estornado, quando houver. Sempre em centavos no backend, formatado só na tela.

**Desempenho:** todos os números saem de **consultas agregadas** (`selectRaw` com `count`/`sum` e `group by`), nunca de carregar coleção e contar em PHP. Um evento com 5.000 inscrições não pode virar 5.000 objetos Eloquent para mostrar quatro números. Ajuste fino de índice é da Fase 9; a consulta nascer certa é desta.

### 3.5 Fechar o cadastro público — o que quebra e o que fazer

Remover as rotas `register` (GET e POST) de `routes/auth.php`, a tela `pages/auth/Register.vue` e o link "cadastre-se" da tela de login.

**`tests/Feature/Auth/RegistrationTest.php` vai quebrar** — ele existe justamente para provar que o registro funciona. Reescreva-o para provar o contrário: `GET /register` e `POST /register` respondem **404**. Não apague o arquivo: um teste que prova que a porta está fechada vale mais do que a ausência de teste.

Conta administrativa passa a nascer por **comando artisan**: `usuario:criar-administrador {email} {--nome=} {--papel=administrador}`. Ele pede a senha de forma escondida (`secret()`), recusa e-mail já existente, valida o papel contra os que existem e diz em português o que fez. Um `AdminDemoSeeder` cria uma conta de desenvolvimento — **só** em `local`, com trava de ambiente igual à de `routes/dev.php`.

### 3.6 Armadilhas conhecidas deste projeto

- **`radix-vue`, não `reka-ui`.** Confira os imports contra `resources/js/components/ui/`.
- **Os 20 erros da P-09** estão em: `AppHeader`, `AppSidebar`, `AppSidebarHeader`, `NavMain`, `NavUser`, `UserInfo`, `TextLink`, `Welcome`, `AuthSplitLayout`, telas de `pages/auth/` e `app.ts`. Corrigir é tipar de verdade, **não** silenciar com `any` nem `@ts-ignore`.
- **`php artisan test` roda no HOST, não dentro do Sail** — `phpunit.xml` fixa `127.0.0.1:55432`, que só resolve fora do contêiner. Dentro do Sail a conexão é recusada e os 241 testes falham em bloco por motivo nenhum.
- **Nunca** rodar `php artisan test` e `npm run test:e2e` ao mesmo tempo — dividem o banco `testing` (D-42).
- **Executores morrem em ~60 chamadas de ferramenta**, sem aviso. Commite ao fim de **cada** step. Se sentir o fim chegando, pare num commit limpo, escreva o `plan.done.md` e diga qual é o próximo passo.
- O `spatie/laravel-permission` **cacheia** permissões. Depois de semear, limpe o cache (`app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions()`), senão o teste seguinte enxerga o estado velho e falha sem explicação.

### 3.7 Arquivos a ler antes de começar

- `routes/auth.php` e `routes/web.php` — o que sai do ar e onde o grupo administrativo entra
- `app/Models/User.php` — onde entra o trait `HasRoles`
- `resources/js/layouts/AppLayout.vue` e `resources/js/components/AppSidebar.vue` — o esqueleto a aproveitar
- `database/migrations/*eventos*`, `*atividades*`, `*inscricoes*`, `*pagamentos*` — os **nomes reais** das colunas de contador e de dinheiro
- `docs/PROGRESS.md` — decisões D-01..D-49 e pendências P-01..P-09
- `tests/Feature/Publico/EventoPublicoTest.php` — o padrão de teste de props do Inertia

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `composer.json` | modify | `spatie/laravel-permission` |
| `config/permission.php` | create | publicado pelo pacote |
| `database/migrations/*_create_permission_tables.php` | create | publicado pelo pacote |
| `database/seeders/PapeisSeeder.php` | create | papéis e permissões de §3.3, idempotente |
| `database/seeders/AdminDemoSeeder.php` | create | conta de desenvolvimento, só em `local` |
| `app/Console/Commands/CriarAdministrador.php` | create | `usuario:criar-administrador` |
| `app/Models/User.php` | modify | trait `HasRoles` |
| `routes/auth.php` | modify | remover as rotas de registro |
| `routes/web.php` | modify | grupo `admin` com `auth` + `verified` + permissão |
| `app/Http/Middleware/` | modify/create | amarrar a permissão na rota (use o middleware do pacote) |
| `app/Http/Controllers/Admin/PainelController.php` | create | `index` do painel |
| `app/Services/Admin/NumerosDoEvento.php` | create | as consultas agregadas de §3.4 |
| `resources/js/layouts/AdminLayout.vue` | create | layout administrativo sobre o esqueleto existente |
| `resources/js/pages/Admin/Painel.vue` | create | a tela do painel |
| `resources/js/components/admin/CartaoDeNumero.vue` | create | número com rótulo e significado |
| `resources/js/components/admin/TabelaDeVagas.vue` | create | vagas por atividade |
| `resources/js/components/AppSidebar.vue` | modify | navegação administrativa real |
| `resources/js/pages/auth/Register.vue` | delete | cadastro público fechado |
| `resources/js/components/{AppHeader,AppSidebarHeader,NavMain,NavUser,UserInfo,TextLink}.vue` | modify | correção da P-09 |
| `resources/js/pages/{Welcome.vue,auth/*.vue}`, `resources/js/layouts/auth/AuthSplitLayout.vue`, `resources/js/app.ts` | modify | correção da P-09 |
| `tests/Feature/Auth/RegistrationTest.php` | modify | prova que `/register` responde 404 |
| `tests/Feature/Admin/AutorizacaoTest.php` | create | sem papel → 403; cada permissão abre só o que deve |
| `tests/Feature/Admin/PainelTest.php` | create | os números batem com o banco semeado |
| `tests/Feature/Admin/CriarAdministradorTest.php` | create | o comando cria, recusa e-mail repetido, atribui papel |
| `tests/e2e/admin-acesso.spec.ts` | create | visitante não entra; administrador vê o painel |
| `docs/PROGRESS.md`, `docs/IMPLEMENTATION_PLAN.md` | modify | fechamento da fase |

---

## 5. Quality Criteria

### Funcional
- [ ] `GET /register` e `POST /register` respondem **404**
- [ ] Usuário autenticado **sem papel** recebe **403** em toda rota administrativa
- [ ] `organizador` **não** consegue alcançar nada que exija `pagamentos.confirmar-manual` ou `usuarios.gerenciar`
- [ ] `administrador` alcança tudo o que existe nesta fase
- [ ] `usuario:criar-administrador` cria a conta com o papel, recusa e-mail repetido e nunca ecoa a senha
- [ ] `PapeisSeeder` roda duas vezes sem duplicar papel nem permissão
- [ ] O painel mostra inscrições por situação, vagas por atividade e dinheiro recebido/pendente, por evento
- [ ] Os números do painel **batem** com o que o banco semeado tem — provado por teste, não por conferência visual
- [ ] O painel de um evento sem inscrição nenhuma mostra zeros com explicação, não tela vazia nem erro

### Qualidade
- [ ] `vendor/bin/pint --test` limpo · `npm run lint` limpo
- [ ] **`vue-tsc --noEmit` com ZERO erros** — a pendência P-09 fecha nesta fase
- [ ] Os **241 testes Pest** continuam verdes (menos `RegistrationTest`, que muda de propósito de propósito)
- [ ] Os **21 cenários Playwright** continuam verdes, **sem serem editados**
- [ ] Nenhuma Action, Enum, Model ou migration **de domínio** alterada — o painel só lê
- [ ] Números do painel vêm de consulta agregada; nenhuma contagem em PHP sobre coleção carregada
- [ ] Vaga restante lida do contador materializado, nunca recontada a partir de `inscricoes_atividades`
- [ ] Cor só via token semântico
- [ ] Nenhuma dependência além de `spatie/laravel-permission`

### Acessibilidade
- [ ] Navegação administrativa por teclado, com foco visível
- [ ] Tabela de vagas com `<th scope>` e legenda; número nunca comunicado só por cor
- [ ] Contraste AA nos modos claro e escuro
- [ ] O painel é utilizável em tela de tablet (768 px) sem rolagem horizontal

### Playwright E2E
- [ ] Visitante não autenticado é mandado para o login ao tentar o painel
- [ ] Usuário autenticado sem papel vê a recusa, não o painel
- [ ] Administrador vê os três blocos de números
- [ ] `/register` não existe mais

---

## 6. Ambiguity Handling

**Assumptions made:**
- **Dois papéis, não mais.** Tesoureiro, credenciador e "só leitura" são fáceis de acrescentar depois — as permissões já são granulares. Criar perfil sem alguém para ocupá-lo é inventar requisito.
- **O painel é por evento**, com seletor, e não um agregado de todos os eventos. O sistema nasceu para um evento por vez; somar dois eventos diferentes num número só não responde pergunta nenhuma.
- **Sem cache dos números nesta fase.** Otimizar antes de medir é adivinhação; a Fase 9 mede com volume e decide. As consultas nascem agregadas, que é o que evita o problema de verdade.
- **`auditoria.ver` já nasce como permissão**, sem tela. Custa uma linha no seeder e evita que a Fase 9 precise mexer em papéis.
- **A conta de demonstração só existe em `local`**, com a mesma dupla trava de `routes/dev.php` (D-29). Conta administrativa previsível em produção é porta aberta.

**If unsure during execution:**
- Nome real de coluna de contador ou de dinheiro → **leia a migration**, nunca deduza pelo nome que faria sentido.
- Dúvida de layout administrativo → siga o esqueleto do pacote inicial (`AppSidebarLayout`) em vez de inventar outro.
- Um número do painel que exigiria regra nova para ser calculado → **PARE**. O painel só lê o que o domínio já decidiu.
- Erro de tipo da P-09 que pareça exigir mudança de comportamento → corrija o **tipo**, não o comportamento, e registre no PROGRESS.

---

## 7. Prohibitions

- ❌ **Nunca** alterar Action, Enum, Model, migration ou evento **de domínio** — esta fase só lê
- ❌ **Nunca** recalcular vaga a partir de `inscricoes_atividades`; o contador materializado é a fonte da verdade
- ❌ **Nunca** carregar coleção para contar em PHP o que o banco agrega
- ❌ **Nunca** implementar cancelamento administrativo ou confirmação manual de pagamento (plano 6b)
- ❌ **Nunca** registrar ouvinte de evento de domínio nem enviar e-mail (Fase 7)
- ❌ **Nunca** criar tabela de auditoria (Fase 9)
- ❌ **Nunca** silenciar erro de tipo com `any`, `as unknown as` ou `@ts-ignore`
- ❌ **Nunca** deixar rota administrativa protegida só por `auth` — permissão é obrigatória
- ❌ **Nunca** criar conta administrativa fixa em ambiente que não seja `local`
- ❌ **Nunca** editar os 21 cenários Playwright existentes
- ❌ **Nunca** escrever cor direto no componente
- ❌ **Nunca** instalar componente que importe `reka-ui`
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Papéis e permissões.** Instalar `spatie/laravel-permission`, publicar configuração e migração, trait `HasRoles` no `User`, `PapeisSeeder` idempotente com os dois papéis e as nove permissões de §3.3. `tests/Feature/Admin/AutorizacaoTest.php` cobrindo: sem papel → 403, `organizador` barrado no que é do administrador, seeder rodando duas vezes sem duplicar. → commit `feat(admin): add roles and permissions`

2. **Fechar o cadastro público.** Remover as rotas de registro, a tela `Register.vue` e o link no login; reescrever `RegistrationTest` para provar o 404; comando `usuario:criar-administrador` com senha escondida e `AdminDemoSeeder` travado em `local`; `CriarAdministradorTest`. → commit `feat(admin): close public registration and add admin account command`

3. **Fechar a pendência P-09.** Corrigir os 20 erros de tipo nas telas do pacote inicial, tipando de verdade. `vue-tsc --noEmit` tem de terminar com **zero**. → commit `fix(types): resolve starter kit type errors (P-09)`

4. **Layout e navegação administrativa.** `AdminLayout.vue` sobre o esqueleto existente, `AppSidebar` com a navegação real, grupo de rotas `admin` protegido por `auth` + `verified` + permissão. → commit `feat(admin): add admin layout and protected route group`

5. **Os números.** `NumerosDoEvento` com as consultas agregadas de §3.4 e `PainelController@index`. `tests/Feature/Admin/PainelTest.php` provando que cada número bate com o banco semeado, inclusive o caso do evento sem inscrição. → commit `feat(admin): add event metrics service and dashboard endpoint`

6. **A tela do painel.** `Admin/Painel.vue` com `CartaoDeNumero` e `TabelaDeVagas`, seletor de evento, estados de carregamento e de vazio. → commit `feat(admin): add dashboard page`

7. **Playwright e fechamento.** `tests/e2e/admin-acesso.spec.ts` (visitante barrado, sem papel barrado, administrador vê os números, `/register` sumiu). Rodar `pint`, `lint`, `vue-tsc`, Pest e Playwright completos. Atualizar `docs/PROGRESS.md` (Etapa 13, decisões DA-10..DA-12 promovidas, **P-09 fechada**) e `docs/IMPLEMENTATION_PLAN.md`. → commit `feat(admin): polish access control and close phase 6a`

## Done

Ninguém entra sem ser convidado: o cadastro público não existe mais, conta administrativa nasce por comando, e cada rota administrativa exige a permissão certa. O organizador abre o painel e vê, por evento, quantas pessoas se inscreveram e em que situação, quantas vagas restam em cada atividade e quanto dinheiro entrou e falta entrar. A verificação de tipos passa a rodar limpa pela primeira vez no projeto.

## Commit

```
feat(admin): add roles and permissions
feat(admin): close public registration and add admin account command
fix(types): resolve starter kit type errors (P-09)
feat(admin): add admin layout and protected route group
feat(admin): add event metrics service and dashboard endpoint
feat(admin): add dashboard page
feat(admin): polish access control and close phase 6a
```

# Execution Report — A tela de usuários: quem entra, com que papel, e até quando

> **Plan:** usuarios-e-papeis
> **Executed:** 2026-08-30
> **Status:** ⚠️ WITH CAVEATS

O motivo do CAVEATS não é defeito no que foi entregue: é que **o Playwright não
foi executado**, por ordem explícita do dono do produto, e que **`pint --test`
continua reprovando um arquivo do próprio dono do produto** que eu não podia
tocar. Tudo o que é regra de segurança desta feature está provado no Pest.

---

## What Was Done

| File | Action | Description |
|---|---|---|
| `database/migrations/2026_08_30_100001_add_ativo_to_users_table.php` | criar | Coluna `ativo`, booleana, `default(true)`, NOT NULL, com índice |
| `app/Models/User.php` | modificar | `ativo` no `casts`, escopo `ativos()` e `$attributes = ['ativo' => true]` |
| `app/Enums/AcaoAuditada.php` | modificar | Caso `MudouSituacaoDoUsuario`, rótulo e entrada em `sensiveis()` |
| `app/Actions/Usuarios/GovernarConta.php` | criar | **(fora da tabela do plano — ver Deviations)** As duas operações com as três travas e a auditoria |
| `app/Http/Controllers/Admin/UsuarioController.php` | criar | `index` paginado com filtros, `atualizarPapel`, `atualizarSituacao` |
| `app/Http/Requests/Admin/UsuarioPapelRequest.php` | criar | Valida o papel contra os que existem na tabela |
| `app/Http/Controllers/Admin/PapelController.php` | criar | `index` somente-leitura: matriz papel × permissão, com o texto do seeder |
| `app/Http/Requests/Auth/LoginRequest.php` | modificar | Recusa a conta desativada com a mesma frase de credencial inválida |
| `app/Http/Middleware/GarantirContaAtiva.php` | criar | Estende o `Authenticate`; derruba a sessão de quem foi desativado |
| `bootstrap/app.php` | modificar | O apelido `auth` passa a ser o `GarantirContaAtiva` |
| `routes/web.php` | modificar | `/admin/usuarios` (3 rotas) e `/admin/papeis`, com `permission:usuarios.gerenciar` |
| `resources/js/pages/Admin/Usuarios/Index.vue` | criar | Lista, filtro recolhível, troca de papel e de situação |
| `resources/js/pages/Admin/Papeis/Index.vue` | criar | Matriz papel × permissão, só leitura |
| `resources/js/components/AppSidebar.vue` | modificar | Item "Usuários", condicionado a `usuarios.gerenciar` |
| `resources/js/types/admin.ts` | modificar | Tipos das duas telas |
| `tests/Feature/Admin/UsuariosTest.php` | criar | 18 casos: papel, situação, as três travas, auditoria e a disputa real |
| `tests/Feature/Admin/scripts/governar-conta.php` | criar | **(fora da tabela do plano — ver Deviations)** O processo da disputa |
| `tests/Feature/Auth/AcessoDesativadoTest.php` | criar | Login recusado, sessão derrubada, conta reativada |
| `tests/Feature/Admin/AutorizacaoTest.php` | modificar | 3 casos novos; a contagem de permissões **não** foi tocada |
| `tests/e2e/admin-usuarios.spec.ts` | criar | 6 cenários em 1280×800 — **escritos, não executados** |
| `docs/PROGRESS.md` | modificar | Bloco de checklist, linha nas "Próximas tarefas" e decisões **DA-90 a DA-96** |

---

## Quality Criteria

### Backend

| Criterion | Status | Evidence (real output) |
|---|---|---|
| Migration aditiva, `default(true)` | ✅ | `$tabela->boolean('ativo')->default(true)->after('password')`; as 577 provas anteriores continuam verdes, nenhuma conta existente deixou de entrar |
| As rotas exigem `permission:usuarios.gerenciar` **e** o controller repete com `abort_unless` | ✅ | `✓ it exige a permissao de gerenciar usuarios nas quatro rotas novas`; `abort_unless(...->can('usuarios.gerenciar'))` nos dois controllers |
| **Trava do último administrador provada com concorrência de verdade** | ✅ | `✓ disputa real entre processos → it nao deixa o sistema chegar a zero… 2.84s` — dois `proc_open` largando no mesmo instante (um rebaixa, o outro desativa); o teste exige `ok = 1`, `recusado = 1` e **`administradoresAtivos = 1`** lidos da conexão commitada |
| Auto-rebaixamento e auto-desativação recusados no servidor | ✅ | `✓ it recusa que a pessoa rebaixe a si mesma, no servidor`; `✓ it recusa que a pessoa desative a propria conta, no servidor` |
| Auditoria com antes/depois, sem senha nem hash | ✅ | `✓ it promove quem organiza a administrador e registra o antes e o depois`; `✓ it nunca guarda senha nem hash no rastro` (afirma que o JSON não contém `password`, `senha`, nem o hash real da conta) |
| Recusa de login indistinguível da senha errada | ✅ | `✓ it recusa o login de conta desativada com a mesma frase de senha errada` — compara mensagem, campo e **código de status** com o da senha errada |
| Sessão do desativado cai na requisição seguinte | ✅ | `✓ it derruba na requisicao seguinte a sessao de quem foi desativado com a tela aberta`; `✓ it derruba a sessao tambem fora do painel, em qualquer rota autenticada` |
| `./vendor/bin/pint --test` limpo | ⚠️ | Reprova **um único arquivo, que não é meu**: `{"path":"database/seeders/DatabaseSeeder.php","fixers":["statement_indentation"]}` — conteúdo do dono do produto, commitado em `6031db3`. Zero arquivos meus reprovam |

### Frontend

| Criterion | Status | Evidence |
|---|---|---|
| Segue o molde da Auditoria e usa o `PainelDeFiltros`; nenhum componente novo em `components/ui/` | ✅ | `git diff --name-only HEAD~1 -- resources/js/components/ui/` vem vazio; a tela importa `@/components/admin/PainelDeFiltros.vue` |
| Nome, e-mail, papel, situação e quando entrou; papel em `<select>` `w-full`; situação com confirmação | ✅ | Seis colunas na tabela; `class="… h-11 w-full …"` no seletor de papel; `confirmandoDesativacao` exige o segundo clique |
| A linha da própria pessoa é visivelmente diferente, com o motivo escrito | ✅ | `data-testid="linha-de-voce"`, fundo `bg-muted/40`, etiqueta "você", nenhuma ação e a frase "Esta é a sua conta. Ninguém muda o próprio papel…" |
| A matriz mostra o texto em português de cada permissão, lido do `PapeisSeeder` | ✅ | `✓ it mostra a matriz de papeis com o texto em portugues de cada permissao` — o teste compara com `PapeisSeeder::PERMISSOES['painel.ver']` |
| O item some para quem não tem a permissão | ✅ | `if (permissoes.includes('usuarios.gerenciar'))` no `AppSidebar.vue`, no mesmo padrão de Auditoria/Credenciais/Avisos |
| Situação não é comunicada só por cor (WCAG 1.4.1) | ✅ | "Ativo"/"Desativado" e "Alcança"/"Não alcança" escritos por extenso, com os tokens `sucesso-suave`/`sucesso-suave-foreground`, que já têm razão de contraste medida (DA-42) |
| Alvos de 44px; `<th scope>` de verdade nas duas tabelas | ⚠️ | Escrito: `h-11`/`min-h-11` em todos os botões, seletores e links das duas telas; `scope="col"` e `scope="row"` nas duas. **Medido no navegador: não** — ver "Não verificado" |

### Provas

| Criterion | Status | Evidence |
|---|---|---|
| Pest `UsuariosTest` | ✅ | 18 casos, todos verdes |
| Pest `AcessoDesativadoTest` | ✅ | 6 casos, todos verdes |
| Pest `AutorizacaoTest` — a contagem de permissões **NÃO** muda | ✅ | `git diff HEAD~1 -- tests/Feature/Admin/AutorizacaoTest.php \| grep TOTAL_DE_PERMISSOES` → **nenhuma linha**; a constante segue `= 11`; `✓ it cria os dois papeis e as onze permissoes` e `✓ it nao cria permissao nenhuma ao ganhar a tela de usuarios` |
| Playwright `admin-usuarios.spec.ts` | ⚠️ | **Escrito, não executado** — ordem do dono do produto |
| Suíte Pest inteira, `vue-tsc`, `lint`, `build` | ✅ | Ver a tabela abaixo |

---

## Verification

| Command | Result |
|---|---|
| `./vendor/bin/pest` (inteiro) | **`Tests: 604 passed (4343 assertions)` — `Duration: 63.48s`, exit 0**. Baseline antes desta feature: 577 |
| `./vendor/bin/pest --filter="disputa real entre processos"` | `✓ it nao deixa o sistema chegar a zero… 2.84s` · `✓ it recusa tambem quando a mesma acao… 0.08s` — `2 passed` |
| `./vendor/bin/pint --test` | `{"result":"fail","files":[{"path":"database/seeders/DatabaseSeeder.php","fixers":["statement_indentation"]}]}` — **único arquivo, e é do dono do produto** |
| `npx vue-tsc --noEmit` | `vue-tsc exit: 0` (zero erros) |
| `npm run lint` | `lint exit: 0` |
| `npm run build` | `build exit: 0` — `✓ built in 1.73s` |
| `npx playwright test` | **NÃO EXECUTADO**, por ordem do dono do produto |

---

## Não verificado

Tudo aqui depende de uma rodada do Playwright, que eu não podia fazer. **Nada
disto está sendo afirmado como "passou"** — está escrito, e não medido:

1. **A tela abrindo pelo menu.** O item "Usuários" foi acrescentado ao
   `AppSidebar.vue` e o cenário que clica nele existe; ninguém clicou.
2. **A própria linha marcada como "você", sem ações.** Provado no Pest pelo
   dado que o servidor manda (`sou_eu = true`); **não** provado na tela.
3. **Os alvos de 44px.** As classes `h-11`/`min-h-11` estão lá; a medida no
   navegador, que é o que pega o caso do `Editar` da DA-69, não foi feita.
4. **O contraste em AA das duas telas.** Foram usados apenas tokens que já têm
   a razão calculada e escrita no `app.css` (`sucesso-suave`, `muted`,
   `destructive`), então não há tom novo para medir — mas a conferência à vista
   não aconteceu.
5. **O item sumindo do menu para o organizador.** Provado no servidor (403 nas
   quatro rotas); a ausência do link na barra, não.
6. **A troca de papel e a desativação pela tela, de ponta a ponta.** Provadas
   por HTTP no Pest; pelo navegador, não.

**Como fechar:** `npx playwright test tests/e2e/admin-usuarios.spec.ts`. Os
cenários criam as contas por `artisan tinker`, como o `admin-barra-lateral.spec.ts`
já faz, e devolvem o cenário ao estado inicial no fim de cada um.

---

## Deviations from Plan

1. **Dois arquivos a mais do que a tabela de Output Format**, e os dois existem
   por causa de um critério de qualidade do próprio plano:
   - `app/Actions/Usuarios/GovernarConta.php` — o critério exige que a trava do
     último administrador seja provada **com processos concorrentes de
     verdade**. Não há como duas conexões disputarem a mesma linha através de um
     controller: o processo precisa de um ponto de entrada chamável fora do
     HTTP. É exatamente o formato que o projeto já usa (`CancelarInscricaoAdministrativa`
     é chamada assim pelo `cancelar-ou-expirar.php`). Como efeito colateral
     bom, as três travas passaram a valer para qualquer chamador, e não só para
     o formulário.
   - `tests/Feature/Admin/scripts/governar-conta.php` — o processo em si, irmão
     do `cancelar-ou-expirar.php` e do `disputar-vaga.php`.
2. **As decisões novas são DA-90 a DA-96, e não "a partir da DA-88".** O plano
   dizia que a numeração corrente ia até DA-87; ao abrir o `docs/PROGRESS.md`,
   **DA-88 e DA-89 já existiam** (são da feature do catálogo real de setores, já
   commitada). Numerar por cima delas apagaria duas decisões registradas.
3. **`User.php` ganhou `protected $attributes = ['ativo' => true]`**, que o
   plano não previa. Sem isso, um objeto recém-criado não tem o atributo até
   ser relido do banco, `$usuario->ativo` vem nulo e o middleware lê a conta
   como desativada — seis testes do pacote inicial (`AuthenticationTest`,
   `EmailVerificationTest`, `PasswordConfirmationTest`) ficaram vermelhos e
   apontaram isso. Está registrado na **DA-92**.
4. **O middleware substitui o apelido `auth` em vez de entrar num grupo.** O
   plano pedia "o grupo autenticado, e não o grupo `web`"; não existe um grupo
   `auth` no `bootstrap/app.php`, e amarrá-lo à mão em cada grupo de rotas
   deixaria de fora `routes/settings.php` e as que nascerem depois. Trocar o
   apelido (com a classe estendendo o `Authenticate` do framework) entrega
   exatamente o alcance pedido. Está registrado na **DA-96**.
5. **Uma função de teste precisou de nome próprio.** `naConexaoCommitada` já
   existia no `CancelamentoAdministrativoTest`, e função declarada em arquivo de
   teste vive no escopo global: a suíte inteira morria com "cannot redeclare".
   A minha virou `naConexaoCommitadaDeContas`, com o porquê escrito ao lado.
6. **A tela de papéis não ganhou item próprio no menu**, e o plano também não
   pedia um — a decisão e o motivo estão na **DA-91** e no comentário do
   `AppSidebar.vue`: ela responde a uma pergunta que só nasce olhando a lista de
   contas, e é de lá que se chega a ela.

---

## ⚠️ Commit alheio ao plano, para o dono do produto decidir

O commit **`6031db3` — "fix(seeders): ajustar senha do AdminDemoSeeder e
comentar exemplo no DatabaseSeeder"** contém os dois arquivos que a ordem de
execução dizia expressamente que **não podiam entrar em commit nenhum**:
`database/seeders/AdminDemoSeeder.php` e `database/seeders/DatabaseSeeder.php`.

- **O conteúdo é do dono do produto**, não meu: senha do seeder de demonstração
  e o bloco `User::factory()` de exemplo comentado.
- **Não foi desfeito**, conforme instruído. Ele está **antes** do meu commit, e
  o meu (`530f154`) não toca nesses arquivos: `git show 530f154 --stat` não
  lista nenhum dos dois.
- **Efeito colateral herdado:** é esse commit que faz o `./vendor/bin/pint --test`
  reprovar hoje (`statement_indentation` nas quatro linhas comentadas do
  `DatabaseSeeder`). Um `./vendor/bin/pint database/seeders/DatabaseSeeder.php`
  resolve em um commit separado — mas quem decide se aquele conteúdo fica ou é
  revertido é o dono do produto, e por isso não mexi.

---

## ⚠️ Alteração de terceiro aparecida no `AcaoAuditada.php` depois do commit

Ao conferir a árvore no fim, o `app/Enums/AcaoAuditada.php` apareceu **modificado
de novo**, com dois casos que **não são meus** e que não estão no meu commit:
`AlterouDadosDoUsuario` e `RedefiniuSenhaDeUsuario` (com rótulo e entrada em
`sensiveis()`). Eles descrevem trabalho que o plano coloca **fora do escopo**
(editar nome/e-mail e redefinir senha pela tela).

- **Não commitei nada disso.** Prova: `git show 530f154:app/Enums/AcaoAuditada.php
  | grep -c AlterouDadosDoUsuario` → `0`.
- **Não desfiz e não toquei.** Ficam na árvore de trabalho, para quem os escreveu.
- **Não quebram nada hoje:** nenhum outro arquivo os referencia
  (`grep -rn` em `app/`, `resources/` e `tests/` só encontra o próprio enum), e
  `tests/Feature/Auditoria/` mais o `UsuariosTest` continuam verdes com a árvore
  como está — `Tests: 42 passed (264 assertions)`.

---

## Commit

- **Hash:** `530f154`
- **Mensagem:** `feat(admin): governar contas e papeis pela tela`
- **Arquivos:** 21 (`2255 insertions(+), 5 deletions(-)`) — os 19 da tabela de
  Output Format mais os dois justificados na seção Deviations.
- **Fora do commit, como mandado:** `database/seeders/AdminDemoSeeder.php`,
  `database/seeders/DatabaseSeeder.php`, `ccc-redesign.html`, o `.md` de prompt
  na raiz e os diretórios em `.planning/`.

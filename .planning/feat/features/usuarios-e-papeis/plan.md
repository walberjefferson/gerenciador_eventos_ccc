# Action Plan — A tela de usuários: quem entra, com que papel, e até quando

> **Type:** feature
> **Created:** 2026-08-30
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Pessoa desenvolvedora full-stack sênior em Laravel 12 (PHP 8.2+,
PSR-12, Pest) + Vue 3.5 `<script setup>` + TypeScript estrito + Inertia 2 +
Tailwind CSS 4 + Reka UI 2, com `spatie/laravel-permission` já instalado.
Comentário em português explicando o **porquê**.

**Escopo:** uma tela `/admin/usuarios` que lista as contas administrativas,
troca o papel de cada uma e desativa quem saiu da organização; uma tela
`/admin/papeis` somente-leitura mostrando o que cada papel alcança; a coluna
`ativo` em `users` e o bloqueio de entrada de quem está desativado.

**Fora do escopo — por decisão do dono do produto:**

- **Criar conta pela tela não entra.** A conta administrativa continua nascendo
  por `php artisan usuario:criar-administrador` (**D-51**). Esta tela **governa**
  contas; não as cria.
- **Criar ou editar papéis pela tela não entra.** Os dois papéis e as 11
  permissões continuam nascendo no `PapeisSeeder`, que é versionado, revisável
  em code review e roda igual em todo ambiente. A tela de papéis é uma janela
  para ele, não um editor.

**Stack:** Laravel 12 · PostgreSQL · spatie/laravel-permission · Pest · Vue 3.5
· Inertia · Tailwind 4 · Playwright.

## 2. Direct Objective

Tirar do terminal a única coisa que ainda só acontece lá: dizer quem entra no
painel e com que papel. Hoje, promover alguém de organizador a administrador —
ou tirar o acesso de quem saiu — exige alguém com acesso ao container.

## 3. Minimum Inputs

### O que já existe e será finalmente usado

| Peça | Onde | Situação hoje |
|---|---|---|
| Permissão `usuarios.gerenciar` | `PapeisSeeder:43` | **Órfã** — nenhuma rota a exige |
| `AcaoAuditada::PromoveuUsuario` | `AcaoAuditada:28` | Enum com rótulo pronto, **nunca usado** |
| `AcaoAuditada::CriouUsuarioAdministrativo` | `AcaoAuditada:29` | Usado só pelo comando |
| `AcaoAuditada::sensiveis()` | `AcaoAuditada:62` | Já lista as duas acima |
| `HasRoles` no `User` | `User:15` | Ativo |
| Papéis `administrador`/`organizador` | `PapeisSeeder:25,28` | Ativos |

**O desenho previa esta tela.** Os dois casos de auditoria existem, com rótulo
escrito ("Mudou o papel de um usuário", "Criou conta administrativa"), e estão
no grupo das ações sensíveis. Ela só nunca foi construída.

### Entidades / Dados

**Uma migration, e só uma:** `users.ativo`, booleano, `default(true)`,
`NOT NULL`, com índice se a lista vier a filtrar por ele.

Nada mais muda no banco. Papel e permissão continuam nas tabelas do
`spatie/laravel-permission`.

### Regras de negócio

1. **Desativado não entra.** A conferência acontece **no login**
   (`LoginRequest::authenticate`) e também **em cada requisição** — sessão já
   aberta precisa cair, senão desativar alguém que está com a tela aberta não
   surte efeito até ele sair sozinho. A mensagem de recusa é a **mesma** de
   credencial inválida: dizer "sua conta foi desativada" conta a quem tenta
   adivinhar senha que aquele e-mail existe.
2. **Ninguém se rebaixa nem se desativa.** O usuário da sessão não pode tirar o
   próprio papel de administrador nem desativar a própria conta. A tela não
   oferece a ação, e o servidor recusa de novo se alguém insistir.
3. **Nunca zero administradores ativos.** Qualquer ação que deixaria o sistema
   sem nenhum administrador ativo é recusada, com frase explicando por quê.
   Sem essa trava, o sistema pode chegar a um estado em que ninguém cadastra
   credencial de pagamento nem confirma pagamento manual — e a saída seria
   rodar comando no servidor.
4. **A trava do item 3 é conferida com bloqueio no banco.** Duas pessoas
   rebaixando o penúltimo e o último administrador ao mesmo tempo passariam
   pelas duas conferências e chegariam a zero. Use `lockForUpdate()` na
   contagem, dentro da mesma transação da escrita — é o mesmo cuidado que a
   reserva de vaga já toma (D-04).
5. **Toda mudança vai para a auditoria:** papel trocado usa
   `AcaoAuditada::PromoveuUsuario`; situação alterada usa o caso novo
   `MudouSituacaoDoUsuario`, acrescentado ao enum e ao grupo `sensiveis()`.
   O registro guarda o antes e o depois; **nunca a senha, nem o hash**.
6. **A lista mostra o papel, não as permissões.** Quem administra pensa em
   "organizador" e "administrador"; a matriz de permissões vive na tela de
   consulta, para quem quiser saber o que isso quer dizer.

### Arquivos a ler antes de começar

- `database/seeders/PapeisSeeder.php` — os dois papéis, as 11 permissões e o
  `FORA_DO_ORGANIZADOR`, que é a fonte da matriz da tela de consulta.
- `app/Console/Commands/CriarAdministrador.php` — como o papel é atribuído e
  como a auditoria já é registrada ali (linhas 85–96). **Siga o mesmo formato.**
- `app/Enums/AcaoAuditada.php` — os casos e o `sensiveis()`.
- `app/Services/Auditoria/RegistrarAcao.php` — como se grava, e o que ele
  recusa gravar (nada sensível em `dados`).
- `app/Http/Controllers/Admin/AuditoriaController.php` — **o molde da tela**:
  lista paginada, dupla tranca de permissão, filtros com `withQueryString()`.
- `resources/js/pages/Admin/Auditoria/Index.vue` e o
  `resources/js/components/admin/PainelDeFiltros.vue` (filtro recolhível).
- `app/Http/Requests/Auth/LoginRequest.php` — onde a recusa de login entra.
- `app/Models/User.php`, `routes/web.php` (grupo `/admin`),
  `resources/js/components/AppSidebar.vue`.
- `tests/Feature/Admin/AutorizacaoTest.php` — a contagem de permissões e a
  varredura que exige `permission:` em toda rota `/admin`.
- `docs/PROGRESS.md` — Decisões; a numeração corrente vai até **DA-87**.

## 4. Output Format

| Arquivo | Ação | O quê |
|---|---|---|
| `database/migrations/*_add_ativo_to_users_table.php` | criar | Coluna `ativo`, booleana, default `true` |
| `app/Models/User.php` | modificar | `ativo` no `casts`, escopo `ativos()` |
| `app/Enums/AcaoAuditada.php` | modificar | Caso `MudouSituacaoDoUsuario` + rótulo + `sensiveis()` |
| `app/Http/Controllers/Admin/UsuarioController.php` | criar | `index`, `atualizarPapel`, `atualizarSituacao` |
| `app/Http/Requests/Admin/UsuarioPapelRequest.php` | criar | Valida o papel contra os que existem |
| `app/Http/Controllers/Admin/PapelController.php` | criar | `index` somente-leitura: matriz papel × permissão |
| `app/Http/Requests/Auth/LoginRequest.php` | modificar | Recusa quem está desativado, com a mesma frase de sempre |
| `app/Http/Middleware/*` | criar | Derruba a sessão de quem foi desativado depois de entrar |
| `bootstrap/app.php` | modificar | Registra o middleware no grupo autenticado |
| `routes/web.php` | modificar | `/admin/usuarios` e `/admin/papeis`, com `permission:usuarios.gerenciar` |
| `resources/js/pages/Admin/Usuarios/Index.vue` | criar | Lista, troca de papel e de situação |
| `resources/js/pages/Admin/Papeis/Index.vue` | criar | Matriz papel × permissão, só leitura |
| `resources/js/components/AppSidebar.vue` | modificar | Item "Usuários", condicionado à permissão |
| `resources/js/types/admin.ts` | modificar | Tipos das duas telas |
| `tests/Feature/Admin/UsuariosTest.php` | criar | Papel, situação, as três travas e a auditoria |
| `tests/Feature/Auth/AcessoDesativadoTest.php` | criar | Login recusado e sessão derrubada |
| `tests/Feature/Admin/AutorizacaoTest.php` | modificar | As rotas novas exigem a permissão; organizador recebe 403 |
| `tests/e2e/admin-usuarios.spec.ts` | criar | A tela em 1280×800 |
| `docs/PROGRESS.md` | modificar | Decisões a partir da **DA-88** |

## 5. Quality Criteria

### Backend

- [ ] A migration é **aditiva**: coluna com `default(true)`, para que toda conta
      existente continue entrando sem ninguém precisar rodar nada.
- [ ] As três rotas exigem `permission:usuarios.gerenciar` **e** o controller
      repete a conferência com `abort_unless` — a dupla tranca do
      `AuditoriaController`, pelo mesmo motivo escrito lá.
- [ ] **A trava dos administradores é provada com concorrência de verdade**, e
      não só com duas chamadas em sequência: dois processos rebaixando o
      penúltimo e o último administrador ao mesmo tempo, no formato que
      `tests/Feature/Inscricoes/Disputa.php` já usa (D-84). Ao final,
      **pelo menos um administrador ativo continua existindo**.
- [ ] Auto-rebaixamento e auto-desativação recusados **no servidor**, não só
      escondidos na tela.
- [ ] Cada mudança grava um registro de auditoria com o antes e o depois. O
      teste afirma que o registro **não contém** `password` nem hash.
- [ ] A recusa de login de conta desativada é **indistinguível** da recusa por
      senha errada — mesma mensagem, mesmo código, mesmo tempo de resposta
      dentro do que o `RateLimiter` já faz.
- [ ] Sessão de quem foi desativado cai na requisição seguinte.
- [ ] `./vendor/bin/pint --test` limpo.

### Frontend

- [ ] A tela de usuários segue o molde da Auditoria e usa o `PainelDeFiltros`
      recolhível já existente. **Nenhum componente novo em `components/ui/`.**
- [ ] A lista mostra nome, e-mail, papel, situação e quando entrou. A troca de
      papel é um `<select>` com `w-full`; a de situação, uma ação explícita com
      confirmação — desativar alguém não pode acontecer por clique errado.
- [ ] **A linha da própria pessoa é visivelmente diferente**: marcada como
      "você", com as ações desabilitadas e o motivo escrito ao lado. Ação
      desabilitada sem explicação é pior do que ação ausente.
- [ ] A tela de papéis mostra a matriz papel × permissão com o **texto em
      português de cada permissão**, lido do `PapeisSeeder` — quem lê a tela
      precisa entender o que "catalogo.gerenciar" alcança sem abrir código.
- [ ] O item do menu some para quem não tem a permissão, como já ocorre com
      Auditoria, Credenciais e Avisos.
- [ ] Situação **não é comunicada só por cor** (WCAG 1.4.1): a palavra
      "Ativo"/"Desativado" aparece escrita.
- [ ] Alvos de 44px; contraste AA; `<th scope>` de verdade nas duas tabelas.

### Provas

- [ ] **Pest** (`UsuariosTest`): administrador troca papel e desativa;
      organizador recebe 403 nas três rotas; auto-rebaixamento recusado;
      auto-desativação recusada; a trava do último administrador recusa nos
      dois caminhos (rebaixar e desativar); a auditoria registra as duas ações
      com o antes e o depois.
- [ ] **Pest** (`AcessoDesativadoTest`): login de conta desativada é recusado
      com a mesma mensagem de credencial inválida; sessão aberta cai na
      requisição seguinte; conta reativada volta a entrar.
- [ ] **Pest** (`AutorizacaoTest`): a varredura que exige `permission:` em toda
      rota `/admin` continua verde com as três rotas novas. **A contagem de
      permissões do administrador NÃO muda** — `usuarios.gerenciar` já existe;
      esta feature apenas passa a usá-la. Se esse número mudar, algo saiu errado.
- [ ] **Playwright** (`admin-usuarios.spec.ts`, 1280×800, declarando o próprio
      viewport como faz `admin-barra-lateral.spec.ts`): a tela abre pelo menu;
      trocar o papel de outra pessoa funciona; a própria linha aparece marcada
      e sem ações; o item não aparece no menu para o organizador.
- [ ] Suíte Pest inteira verde; `vue-tsc`, `npm run lint` e `npm run build`
      limpos.

## 6. Ambiguity Handling

**Decisões do dono do produto nesta entrevista:**

- **Só atribuir os papéis existentes**, mais uma tela de consulta da matriz.
  Criar papéis pela tela faria o conjunto de permissões deixar de ser o mesmo
  em todo ambiente, e um papel mal montado viraria brecha sem revisão.
- **Conta continua nascendo pelo comando** (D-51). A tela governa, não cria.
- **Desativar, com migration nova** — é o único caminho que barra o login de
  verdade.
- **As três proteções entram:** não rebaixar a si mesmo, nunca ficar sem
  administrador, e registrar tudo na auditoria.

**Premissas de quem escreveu o plano:**

- O caso `MudouSituacaoDoUsuario` é **novo** no enum. Reusar o `Alterou`
  genérico deixaria uma ação de segurança com o mesmo rótulo de uma edição de
  cadastro qualquer, e ela não apareceria no filtro de ações sensíveis.
- **A tela é `/admin/usuarios`, no plural**, seguindo `/admin/inscricoes` e
  `/admin/eventos`.
- O middleware que derruba a sessão do desativado entra no grupo autenticado,
  e **não** no grupo `web` inteiro: a rota do webhook fica fora do grupo `web`
  de propósito (D-28), e o participante não tem sessão administrativa.

**Se travar:**

- Se a trava do último administrador não segurar no teste de concorrência,
  **pare e relate**: significa que a contagem está fora da transação, e a
  correção é de arquitetura, não de ajuste no teste.
- Se a contagem de permissões do `AutorizacaoTest` mudar, **pare**: nenhuma
  permissão nova deveria nascer aqui.

## 7. Prohibitions

- ❌ **Não criar conta pela tela.** O comando continua sendo o único caminho.
- ❌ **Não criar, editar nem apagar papéis e permissões pela aplicação.** O
  `PapeisSeeder` continua sendo a fonte.
- ❌ **Não excluir usuário.** A auditoria guarda `usuario_id`, e apagar deixaria
  o histórico apontando para o vazio — justamente o rastro que a Fase 9 existiu
  para tornar inapagável.
- ❌ Não dizer, na tela de login, que a conta está desativada.
- ❌ Não gravar senha, hash de senha ou token em `dados` da auditoria.
- ❌ Não conceder `usuarios.gerenciar` ao papel `organizador`.
- ❌ Não usar `classe-[--variavel]` (Tailwind 3): na versão 4 é
  `classe-(--variavel)` (**D-86**).
- ❌ Não combinar classe estática com condicional para a mesma propriedade
  (**DA-68**).
- ❌ Não mexer em `app/Services/Payments/`, `app/Jobs/` nem no fluxo de
  inscrição.

---

## Execution Steps

1. **A coluna.** Migration aditiva com `ativo` default `true`; `casts` e escopo
   `ativos()` no `User`. Rodar a suíte inteira depois: nenhuma conta existente
   pode deixar de entrar.

2. **O enum.** Caso `MudouSituacaoDoUsuario` com rótulo em português, incluído
   em `sensiveis()`.

3. **O controller de usuários.** `index` paginado no molde do
   `AuditoriaController`; `atualizarPapel` e `atualizarSituacao`, cada um numa
   transação com `lockForUpdate()` na contagem de administradores ativos, as
   três travas e o registro de auditoria com antes e depois.

4. **As rotas.** `/admin/usuarios` e `/admin/papeis` com
   `permission:usuarios.gerenciar` — a permissão órfã finalmente exigida.

5. **O bloqueio de acesso.** Recusa no `LoginRequest` com a mesma frase de
   credencial inválida, e middleware que derruba a sessão de quem foi
   desativado com a tela aberta.

6. **A tela de usuários.** Lista, filtro recolhível, troca de papel e
   confirmação para desativar. A própria linha marcada como "você", com as
   ações desabilitadas e o motivo à vista.

7. **A tela de papéis.** Matriz papel × permissão, somente leitura, com o texto
   em português de cada permissão lido do seeder.

8. **O menu.** Item "Usuários" condicionado a `usuarios.gerenciar`.

9. **As provas.** Os dois arquivos Pest novos, o `AutorizacaoTest` atualizado,
   o cenário Playwright, e o teste de concorrência da trava do último
   administrador. Rodar Pest inteiro, pint, `vue-tsc`, lint e build.

10. **Registro.** `docs/PROGRESS.md`, decisões a partir da **DA-88**: por que a
    conta continua nascendo por comando, por que papéis não se editam pela
    tela, por que desativar em vez de excluir, por que a recusa de login é
    indistinguível, e por que a trava do último administrador precisa de
    bloqueio no banco. Some também a linha às "Próximas tarefas": a partir daqui,
    promover alguém deixa de exigir acesso ao servidor.

## Done

Um administrador abre `/admin/usuarios` pelo menu, vê as contas com papel e
situação, promove alguém de organizador a administrador e desativa quem saiu —
tudo com rastro na auditoria. Ele não consegue se rebaixar, não consegue se
desativar, e o sistema recusa qualquer ação que o deixaria sem nenhum
administrador ativo, inclusive quando duas pessoas tentam ao mesmo tempo. Quem
foi desativado não entra, e cai na requisição seguinte se já estava dentro. A
suíte Pest inteira passa e a contagem de permissões do administrador continua
a mesma.

## Commit

`feat(admin): governar contas e papeis pela tela`

# Execution Report — Cadastro de responsáveis e sorteio de quem recebe

> **Plan:** responsaveis-do-setor
> **Executed:** 2026-09-03
> **Status:** ✅ COMPLETE

## What Was Done

### Banco (Passo 1)

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_09_03_120001_create_responsaveis_table.php` | create | Tabela + único parcial `responsaveis_user_id_unique` em `user_id` + `index(ativo)` |
| `database/migrations/2026_09_03_120002_create_responsaveis_setores_table.php` | create | Vínculo N:N, PK composta `(cidade_id, responsavel_id)`, `index(responsavel_id)`, sem `id` e sem timestamps |
| `database/migrations/2026_09_03_120003_add_responsavel_id_to_pagamentos_table.php` | create | FK `restrictOnDelete` + `index(responsavel_id)` |
| `database/migrations/2026_09_03_120004_mover_responsavel_do_setor_para_responsaveis.php` | create | **Backfill e depois drop** dos quatro campos de `cidades`; `down()` devolve as colunas e o primeiro responsável de cada setor |

### Domínio e sorteio (Passos 2-4)

| File | Action | Description |
|------|--------|-------------|
| `app/Models/Responsavel.php` | create | Casts, `setores()`, `user()`, `pagamentos()`, `estaApto()`, scopes `ativos()`/`aptos()` |
| `database/factories/ResponsavelFactory.php` | create | States `semConta`, `semChave`, `inativo` |
| `app/Models/Cidade.php` | modify | `responsaveis()` N:N; RN-R3 em `estaPreparadaParaReceber()` e `ativasDespreparadasParaReceber()`; removidos `responsavel()` e os quatro campos do `$fillable` |
| `app/Models/Pagamento.php` | modify | `responsavel()` + `responsavel_id` no `$fillable` |
| `app/Actions/Pagamentos/SortearResponsavel.php` | create | RN-R4 inteira num lugar só: candidatos aptos do setor, carga por inscrições ativas do evento, empate no menor e `array_rand` entre os empatados. Sem trava (RN-R8), com o porquê comentado |
| `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` | modify | Sorteia só quando nasce cobrança nova (RN-R5), grava `responsavel_id`, monta o BR Code com a chave e o nome do sorteado |
| `app/Exceptions/Pagamentos/SetorSemChavePixException.php` | modify | Docblock no plural (RN-R10) |

### Acesso e telas de leitura (Passo 5)

| File | Action | Description |
|------|--------|-------------|
| `app/Policies/ComprovantePagamentoPolicy.php` | modify | `setoresDe()` pela cadeia `users → responsaveis → responsaveis_setores → cidades` (RN-R6) |
| `app/Http/Controllers/PagamentoController.php` | modify | O bloco `setor` lê chave, titular e telefone do responsável **da cobrança** |
| `app/Http/Controllers/Admin/ConferenciaComprovanteController.php` | modify | RN-R7: campo `recebedor` (nome + chave) em cada linha, com eager loading das cobranças |

### Cadastro (Passo 6)

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Admin/ResponsavelController.php` | create | CRUD no molde de `CidadeController`, com auditoria e a recusa da RN-R9 em português |
| `app/Http/Requests/Admin/ResponsavelRequest.php` | create | Nome, chave (obrigatória), telefone, conta (única, ativa) e setores |
| `app/Policies/ResponsavelPolicy.php` | create | **Não estava na tabela do plano** — ver "Deviations" |
| `app/Http/Requests/Admin/CidadeRequest.php` | modify | Perdeu os quatro campos; ganhou `responsaveis[]` e `responsaveis()` |
| `app/Http/Controllers/Admin/CidadeController.php` | modify | Sincroniza o vínculo (só quando o formulário fala dele); expõe os responsáveis com `apto` |
| `app/Http/Requests/Admin/EventoRequest.php` | modify | RN-S4 com a redação da RN-R3, mensagem apontando as duas telas |
| `database/seeders/PapeisSeeder.php` | modify | `catalogo.gerenciar` passou a cobrir responsáveis, com a decisão comentada |
| `routes/web.php` | modify | Quatro rotas do CRUD sob `catalogo.gerenciar` |

### Telas (Passos 8-9)

| File | Action | Description |
|------|--------|-------------|
| `resources/js/pages/Admin/Catalogo/Responsaveis.vue` | create | Tela do cadastro novo, molde de `Setores.vue` |
| `resources/js/pages/Admin/Catalogo/Setores.vue` | modify | Os 4 campos viraram seleção de responsáveis, com aviso quando nenhum está apto |
| `resources/js/pages/Admin/Comprovantes/Index.vue` | modify | Coluna "Recebeu" (RN-R7) e o nome de quem recebeu no modal de aceite |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modify | Origem nova dos dados (visual quase igual) |
| `resources/js/components/AppSidebar.vue` | modify | Item "Responsáveis" no catálogo |
| `resources/js/types/admin.ts` | modify | `ResponsavelDoCatalogo`, `ResponsavelVinculado`, `ResponsavelDisponivel`, `ContaDoPainel`, `SetorParaVinculo`, `recebedor` na linha da fila |
| `resources/js/types/pagamento.ts` | modify | O bloco do responsável vem da cobrança |
| `database/seeders/CobrancaDemoSeeder.php` | modify | Dois responsáveis por setor (um com conta, um sem) |

### Testes (Passo 7 e 10)

| File | Action | Description |
|------|--------|-------------|
| `tests/Feature/Pagamentos/SorteioDeResponsavelTest.php` | create | 15 testes: RN-R4, RN-R5, RN-R8, RN-R9 |
| `tests/Feature/Admin/ResponsaveisTest.php` | create | 12 testes: CRUD, vínculo N:N, RN-R9 pelos dois lados |
| `tests/Feature/Pagamentos/MigracaoDoResponsavelDoSetorTest.php` | create | **Não estava na tabela do plano** — exigido por §5 e pelo Passo 1; ver "Deviations" |
| `tests/Feature/Pagamentos/RecebimentoPeloSetorTest.php` | modify | `cenarioDoSetor()` e `setorPreparado()` no cadastro novo |
| `tests/Feature/Comprovantes/EnvioDeComprovanteTest.php` | modify | Idem; telefone lido do responsável da cobrança |
| `tests/Feature/Comprovantes/ConferenciaTest.php` | modify | +7 testes de RN-R6 e RN-R7 |
| `tests/Feature/Admin/CatalogoTest.php` | modify | Os 3 testes de telefone viraram 3 testes de vínculo |
| `tests/e2e/pagamento-pelo-setor.spec.ts` | modify | Setor Batalha com dois responsáveis; os quatro cenários de §5 |
| `tests/e2e/admin-usuarios.spec.ts` | modify | **Não estava na tabela do plano** — ver "Deviations" |
| `docs/BUSINESS_RULES.md` | modify | Seção RN-R1 a RN-R10, RN-S3/S4/S9 reescritas, tabela de testes e índice de mensagens |
| `docs/DATABASE.md` | modify | ERD, §3.1 sem os 4 campos, §3.1.1 `responsaveis`, §3.1.2 `responsaveis_setores`, §3.10 com `responsavel_id` |
| `docs/PAYMENTS.md` | modify | §11 reescrita para o plural e §11.5 nova: o sorteio, o "não conversa com provedor nenhum", a ausência de trava e o risco da RN-R5 |
| `docs/PROGRESS.md` | modify | Entrega registrada e o **risco da RN-R5** com as três mitigações |

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|---|---|---|
| A migração não perde nada | ✅ | `./vendor/bin/pest tests/Feature/Pagamentos/MigracaoDoResponsavelDoSetorTest.php` → `Tests: 6 passed (22 assertions)`, rodado **antes** de qualquer outro passo. Cobre: campo a campo, titular vazio virando nome do setor, setor sem chave, mesma pessoa em dois setores, mesma conta com chaves diferentes, e as 4 colunas ausentes no fim |
| O sorteio equilibra | ✅ | `it divide dez inscricoes igualmente entre dois responsaveis` → `['Ana' => 5, 'Bruno' => 5]`; `it divide nove inscricoes igualmente entre tres responsaveis` → `3` cada |
| Imprevisível dentro do empate | ✅ | `it continua imprevisivel dentro do empate` — 60 sorteios com dois empatados em zero; `array_unique` tem 2 elementos |
| Só entram os aptos | ✅ | Três testes por exclusão (inativo, sem chave, de outro setor), 20 sorteios cada, todos devolvendo o único apto |
| Cobrança pendente não sorteia de novo | ✅ | `it nao sorteia de novo enquanto a cobranca esta pendente` — 20 chamadas devolvem a mesma cobrança e o mesmo `responsavel_id`; total de cobranças = 1 |
| A cobrança guarda quem recebeu | ✅ | `it sorteia de novo na reemissao, e a cobranca antiga guarda quem recebeu` |
| Escopo de conferência pela cadeia nova | ✅ | 4 testes em `ConferenciaTest`: dois setores pela mesma ficha, conta sem ficha não alcança nada (+403 na URL direta), responsável sem conta não dá alcance, e o outro responsável do setor confere |
| A fila mostra quem recebeu | ✅ | `it mostra na linha o nome e a chave do responsavel daquela cobranca` → `['nome' => 'Titular do Setor A', 'chave_pix' => 'setor-a@example.com']`; + o caso gateway com `recebedor` nulo |
| RN-R9 dos dois lados | ✅ | `it nao deixa o banco apagar responsavel que ja recebeu` (QueryException) e `it recusa excluir responsavel que ja recebeu, oferecendo desativar` (mensagem em português); `it desativa sem tocar na cobranca ja emitida` |
| Nada mudou no modo gateway | ✅ | Nenhum teste de evento por provedor foi editado. As 3 falhas encontradas na suíte eram todas do vínculo 1:1 (CatalogoTest, ConferenciaTest, EnvioDeComprovanteTest) e estão nomeadas no plano |
| PSR-12 / Pint | ✅ | `./vendor/bin/pint --dirty` → `{"tool":"pint","result":"passed"}` |
| Vue strict, sem `any`; vue-tsc e build limpos | ✅ | `npx vue-tsc --noEmit` sem saída (exit 0); `npm run build` → `✓ built in 1.82s`; `npx eslint` sem saída nos 8 arquivos tocados; `npx prettier --check` limpo |
| Comentários em português explicando o porquê | ✅ | Em especial: por que `responsavel_id` mora no **pagamento** (migração 120003, `Pagamento::responsavel()`, DATABASE §3.10) e por que o sorteio não usa trava (`SortearResponsavel`, docblock da classe) |
| Playwright, quatro cenários | ✅ | `pagamento-pelo-setor.spec.ts`: (1) a tela mostra a chave de um dos dois e ela não muda ao recarregar; (2) o **outro** responsável confere e aceita, vendo o recebedor na linha; (3) recusa; (4) isolamento entre setores |
| A suíte inteira verde | ✅ | `php artisan test --parallel` → **846 passed (5876 assertions)**; `npx playwright test` → **102 passed (2.0m)** |

## Verification

| Command | Result |
|---|---|
| `./vendor/bin/pest tests/Feature/Pagamentos/MigracaoDoResponsavelDoSetorTest.php` (antes do drop) | 6 passed, 22 assertions |
| `php artisan test --parallel` | **846 passed** (5876 assertions) — baseline era 806 |
| `npx playwright test` | **102 passed** — baseline era 102 |
| `./vendor/bin/pint --dirty` | passed |
| `npx vue-tsc --noEmit` | sem erros (exit 0) |
| `npm run build` | ✓ built |
| `npx eslint` (8 arquivos tocados) | sem saída |
| `npx prettier --check` (8 arquivos tocados) | All matched files use Prettier code style |

## Deviations from Plan

1. **`app/Policies/ResponsavelPolicy.php` — arquivo criado fora da tabela do Output Format.**
   O plano manda escrever `ResponsavelController` "no molde de `CidadeController`", e esse
   molde chama `$this->authorize()` nos quatro métodos. Sem uma policy descoberta por
   convenção (`Responsavel` → `ResponsavelPolicy`), o Gate negaria tudo e o CRUD responderia
   403 sempre. As alternativas eram deixar o controller sem `authorize` — confiando apenas no
   middleware da rota, ou seja, uma checagem a menos que os cadastros irmãos — ou criar o
   arquivo. Escolhi criar: 4 métodos, todos `catalogo.gerenciar`, idênticos a `CidadePolicy`,
   com a decisão de permissão comentada. **Entra no commit.**

2. **`tests/Feature/Pagamentos/MigracaoDoResponsavelDoSetorTest.php` — arquivo criado fora da
   tabela do Output Format.** A tabela não o lista, mas §5 exige "teste que cria setores com
   chave no formato antigo, roda a migração e prova que cada um virou responsável" e o Passo 1
   manda escrevê-lo e rodá-lo **antes** do drop. Ele exercita a migração real (`down()` para
   devolver as colunas, escrita no formato antigo, `up()`), e não uma cópia dela.
   **Entra no commit.**

3. **`tests/e2e/admin-usuarios.spec.ts` — arquivo modificado fora da tabela.** O plano manda
   alterar o texto da permissão `catalogo.gerenciar` no `PapeisSeeder`, e a matriz de papéis
   mostra esse texto na tela: o cenário afirmava `'Cadastrar setores e grupos de participantes'`
   e quebrou. A alternativa era deixar o rótulo antigo e só comentar a decisão — mas aí a
   matriz mentiria para quem administra sobre o que a permissão alcança. Uma linha alterada,
   só a string esperada. **Entra no commit.**

4. **`responsaveis_setores` não seguiu o "molde de `inscricoes_atividades`".** O plano diz
   "sem `id` e sem timestamps: é vínculo puro, no molde de `inscricoes_atividades`", mas
   `inscricoes_atividades` **tem** `id()` e `timestampsTz()` no projeto. Segui a especificação
   explícita de colunas do §3 (PK composta, sem `id`, sem timestamps), que é inequívoca, e
   ignorei a referência ao molde, que estava incorreta.

5. **`ComprovantePagamentoPolicy::setoresDe()` não filtra por `responsaveis.ativo`.** A RN-R6
   descreve a cadeia sem filtro, e a RN-R9 restringe o efeito de desativar ao **sorteio**
   ("sem tocar em cobrança nenhuma já emitida"). Quem foi desativado precisa continuar podendo
   resolver o comprovante do dinheiro que já caiu na conta dele; quem não pode mais entrar no
   painel já é barrado no login. A decisão está escrita no docblock do método.

6. **`CidadeController` e `ResponsavelController` só sincronizam o vínculo quando o formulário
   envia a lista** (`$request->has(...)`). O plano não fala do caso omisso; sincronizar por
   omissão esvaziaria o setor — tirando-o do ar — sem ninguém pedir. Há um teste para cada
   lado disso.

## Observation (não é desvio, e não foi tocado)

`npx playwright test pagamento-pelo-setor` **isolado** falha nos cenários 3 e 4 (o upload do
comprovante não conclui), enquanto `npx playwright test` completo passa 102/102. Os dois
cenários não foram alterados por esta entrega (só o `beforeAll` compartilhado mudou), e a
suíte é declaradamente `fullyParallel: false, workers: 1` sobre um banco semeado uma vez —
ou seja, é feita para rodar inteira. Fica registrado como dependência de ordem pré-existente
do arquivo, não consertada aqui por estar fora do escopo do plano.

## Commit

- **Message:** `feat(pagamentos): cadastro de responsaveis do setor com sorteio de quem recebe`
- **Files:** os 37 da tabela do Output Format (com os nomes reais das migrações) + os 3 do
  desvio 1-3. Ficaram **fora** do commit, como pedido: `ccc-redesign.html`,
  `Prompt para Claude Code — ....md` e os diretórios `.planning/`.

# Action Plan — Fase 9: auditoria, desempenho com volume e endurecimento de segurança

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending
> **Ordem:** último plano do lote. **Depende da 6a, 6b e 7.**

---

## 1. Persona & Scope

**Persona:** Senior Backend Engineer **Laravel 12 + PHP 8.4 + PostgreSQL 18**, com prática em auditoria de ações sensíveis, análise de plano de execução (`EXPLAIN ANALYZE`) e endurecimento de aplicação exposta à internet. Mede antes de otimizar e prova a melhoria com número, não com impressão.

**Scope — Fase 9:** deixar o sistema pronto para operar com gente de verdade acessando ao mesmo tempo.

| Entrega | Nesta fase |
|---------|:----------:|
| Tabela `logs_auditoria` e registro das ações administrativas | ✅ |
| Tela de auditoria (somente leitura) no painel | ✅ |
| Medição de desempenho com volume real e correção do que doer | ✅ |
| Endurecimento de segurança: limites, cabeçalhos, dependências | ✅ |
| Teste de carga no caminho de inscrição | ✅ |
| Retenção e anonimização (LGPD) | ❌ **fora do escopo** (§3.1) |
| Credenciamento (check-in) e lista de espera | ❌ fora do escopo |
| Provedor de pagamento real | ❌ Fase 8 |

**Stack:** PHP 8.4 · Laravel 12 · PostgreSQL 18 · Redis · Vue 3.5 + Inertia (uma tela) · Pest 4 · Playwright.

---

## 2. Direct Objective

Três garantias que hoje não existem: **quem fez o quê** fica registrado de forma que ninguém possa apagar discretamente; **os números do painel e a lista de inscrições continuam rápidos** quando o evento tiver milhares de inscritos, provado com volume de verdade; e **as portas expostas à internet** — inscrição, webhook, recuperação de acesso, login — resistem a quem insiste.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| LGPD (**DA-15**) | **Fora desta fase.** Retenção e anonimização dependem das pendências **P-04** (prazo de descarte) e **P-03** (pagamento reconhecido após o prazo), que continuam sem decisão do dono do produto. Implementar sob prazo inventado seria decisão jurídica tomada por software. Vira plano próprio quando P-03 e P-04 forem respondidas |
| Auditoria | Registro **append-only** de ação administrativa sensível (§3.3) |
| Permissão `auditoria.ver` | **Já existe** desde a 6a, só do `administrador`. Esta fase amarra a tela nela |

### 3.2 O que já existe (verificado — não reimplementar)

- Fases 0 a 7 concluídas. **Leia `docs/PROGRESS.md` para o estado exato no momento em que começar.**
- Papéis e permissões (`spatie/laravel-permission`), incluindo `auditoria.ver` sem tela.
- Ações administrativas que precisam de auditoria: `CancelarInscricaoAdministrativa` e `ConfirmarPagamentoManual` (6b), mais os CRUDs de evento e catálogo.
- `AdminLayout` e o padrão de tela administrativa (6a/6b).
- Índices já criados nas migrations: `(situacao, prazo_pagamento)`, `(evento_id, situacao)` em `inscricoes`; `(inscricao_id, situacao)` e `(situacao, expira_em)` em `pagamentos`. **Existem, mas nunca foram medidos com volume.**
- `NumerosDoEvento` (6a) e `FiltroDeInscricoes` (6b) — as duas consultas que mais vão doer.
- `tests/Feature/Inscricoes/ConcorrenciaTest.php` e `scripts/disputar-vaga.php` — concorrência real com processos de sistema operacional. **É a base do teste de carga**, não recomece do zero.
- Limite de tentativas já existente em `POST /acesso` (D-48), contado dentro do controller para preservar a resposta neutra.

### 3.3 Auditoria

Tabela **`logs_auditoria`**, append-only:

| Coluna | Papel |
|--------|-------|
| `id` | — |
| `usuario_id` | quem fez; FK `restrict`, **nullable** (ação de sistema não tem gente) |
| `acao` | verbo do que foi feito (Enum `AcaoAuditada`) |
| `entidade` / `entidade_id` | o que foi afetado |
| `motivo` | a justificativa que o organizador escreveu, quando houver |
| `dados` | `jsonb` com o antes/depois **do que mudou**, sem dado sensível |
| `ip` / `agente` | de onde veio |
| `created_at` | quando (sem `updated_at`: registro não se altera) |

**Ações auditadas nesta entrega:** cancelamento administrativo, confirmação manual de pagamento, criação/alteração/remoção em qualquer CRUD do evento e do catálogo, promoção de usuário e criação de conta administrativa.

**Regras que dão valor ao registro:**
- **Append-only de verdade:** sem `update` e sem `delete` no model — bloqueie nos eventos do Eloquent (`updating`/`deleting` lançam exceção). Registro que pode ser corrigido depois não serve de auditoria.
- **`dados` nunca guarda CPF, hash de documento, senha, token nem Pix completo.** Guarde o nome do campo e o fato de ter mudado, não o conteúdo sensível.
- **Auditar não pode derrubar a ação.** Se a gravação do log falhar, a ação administrativa já aconteceu — registre o erro no log da aplicação e siga. Auditoria é testemunha, não porteiro.
- A gravação acontece **na mesma transação** da ação quando isso for possível sem risco; onde não for, imediatamente depois.

### 3.4 Desempenho — medir, corrigir, provar

**Primeiro medir.** Um seeder de volume (`VolumeSeeder`, só em `local`/`testing`) cria um evento com **10.000 inscrições** distribuídas entre as situações, com atividades e pagamentos coerentes. Use inserção em lote; gerar 10.000 inscrições pelo caminho normal levaria tempo demais e não é o objetivo.

Com o volume no banco, meça com `EXPLAIN ANALYZE` e registre o tempo **antes**:
1. `NumerosDoEvento` — os três blocos do painel
2. `FiltroDeInscricoes` — lista sem filtro, e com os filtros mais pesados combinados (atividade + situação + período)
3. A exportação CSV completa
4. A página pública do evento (`/eventos/{slug}`) com a programação inteira
5. `ExpirarInscricoesVencidas` com muitas vencidas ao mesmo tempo

**Depois corrigir só o que doeu**, na ordem: índice que falta → consulta reescrita → cache. Cada correção precisa do número **antes e depois** no relatório. **Não** adicione índice "por precaução": todo índice custa em escrita, e escrita é justamente o caminho da inscrição.

**Cache só se a medição pedir.** Se pedir, os números do painel podem ir para cache curto (60 s) com invalidação por evento — e a tela precisa dizer que o número é de até um minuto atrás. Número velho sem aviso é pior que número lento.

### 3.5 Endurecimento de segurança

**Limite de requisições** onde ainda não há:
- `POST /inscricoes` — por IP; generoso o bastante para não punir família compartilhando conexão, apertado o bastante para conter script. Ao estourar, responda em português, não com página crua do framework.
- `POST /webhooks/pagamentos` — limite alto e por IP, **sem** alterar a regra de responder 200 mesmo a assinatura inválida (D-18).
- Login administrativo — o Laravel já traz `throttle`; **confirme** que está ativo e cubra com teste.
- `POST /acesso` — já tem (D-48); **não mexa** sem rodar o teste da resposta neutra.

**Cabeçalhos de segurança** por middleware global: `X-Content-Type-Options`, `X-Frame-Options` (ou `frame-ancestors`), `Referrer-Policy`, `Strict-Transport-Security` (só quando servindo HTTPS) e uma `Content-Security-Policy`. **Cuidado com a CSP:** o QR Code Pix é SVG embutido e o Inertia injeta dados na página — uma CSP mal calibrada quebra a tela de pagamento em produção sem quebrar nada em desenvolvimento. Prove com Playwright que as telas públicas continuam funcionando com a CSP ligada.

**Dependências:** `composer audit` e `npm audit`. Registre o resultado. Corrija o que for corrigível sem trocar versão maior de framework; o que não for, vira pendência com o motivo.

**Revisão de superfície:** confirme que `routes/dev.php` continua com a dupla trava (D-29), que `APP_DEBUG=false` está documentado para produção, e que nenhuma rota administrativa depende só de `auth`.

### 3.6 Teste de carga do caminho de inscrição

Estenda o que já existe em `scripts/disputar-vaga.php`: **50 processos** disputando as últimas vagas de uma atividade ao mesmo tempo, cada um com sua conexão.

O que precisa ficar provado:
- A soma de reservadas + confirmadas **nunca** passa da capacidade — nem por uma;
- ninguém trava esperando outro (sem impasse);
- o tempo de resposta do caminho de inscrição sob disputa fica registrado no relatório.

É a mesma garantia que `ConcorrenciaTest` já dá com 6 processos, agora sob pressão de verdade. **Se falhar, o defeito é real e anterior a esta fase** — pare e relate; não conserte contador em cima do laço.

### 3.7 Armadilhas conhecidas deste projeto

- **`php artisan test` roda no HOST** (`phpunit.xml` fixa `127.0.0.1:55432`); dentro do Sail a conexão é recusada e a suíte falha em bloco.
- **Nunca** rodar Pest e Playwright ao mesmo tempo (D-42).
- **`lockForUpdate()` é proibido**; concorrência é por gravação condicional.
- **CSP quebra em produção o que não quebra em desenvolvimento** — teste com Playwright, não no olho.
- O seeder de volume é pesado: **só** em `local`/`testing`, com trava de ambiente.
- **Executores morrem em ~60 chamadas de ferramenta.** Commite ao fim de cada step.

### 3.8 Arquivos a ler antes de começar

- `app/Services/Admin/NumerosDoEvento.php` e `app/Services/Admin/FiltroDeInscricoes.php` — as consultas a medir
- `tests/Feature/Inscricoes/ConcorrenciaTest.php` e `tests/Feature/Inscricoes/scripts/disputar-vaga.php` — a base do teste de carga
- `app/Actions/Inscricoes/{ReservarVagas,LiberarVagas}.php` — o que o teste de carga põe à prova
- `routes/web.php`, `routes/dev.php` e `bootstrap/app.php` — onde entram limites e cabeçalhos
- `docs/DATABASE.md` — os índices que já existem e por quê
- `docs/PROGRESS.md` — decisões e pendências

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_create_logs_auditoria_table.php` | create | §3.3 |
| `app/Enums/AcaoAuditada.php` | create | as ações auditadas, com `rotulo()` |
| `app/Models/LogAuditoria.php` | create | append-only, bloqueando `update`/`delete` |
| `app/Services/Auditoria/RegistrarAcao.php` | create | grava sem nunca derrubar a ação |
| `app/Actions/Inscricoes/CancelarInscricaoAdministrativa.php` | modify | registra auditoria |
| `app/Actions/Pagamentos/ConfirmarPagamentoManual.php` | modify | registra auditoria |
| `app/Http/Controllers/Admin/*.php` | modify | CRUDs registram auditoria |
| `app/Http/Controllers/Admin/AuditoriaController.php` | create | lista somente leitura |
| `resources/js/pages/Admin/Auditoria/Index.vue` | create | a tela, com filtros por período, usuário e ação |
| `database/seeders/VolumeSeeder.php` | create | 10.000 inscrições, só em `local`/`testing` |
| `app/Http/Middleware/CabecalhosDeSeguranca.php` | create | §3.5 |
| `bootstrap/app.php` | modify | middleware global e limites |
| `routes/web.php` | modify | `throttle` nas rotas públicas de escrita |
| `app/Services/Admin/{NumerosDoEvento,FiltroDeInscricoes}.php` | modify | só o que a medição mandar mudar |
| `database/migrations/*_add_performance_indexes.php` | create | **só** se a medição provar necessidade |
| `tests/Feature/Auditoria/AuditoriaTest.php` | create | registra, é imutável, não vaza dado sensível, não derruba a ação |
| `tests/Feature/Seguranca/LimitesTest.php` | create | limites respondem em português e não quebram o webhook |
| `tests/Feature/Seguranca/CabecalhosTest.php` | create | cabeçalhos presentes |
| `tests/Feature/Desempenho/ConsultasDoPainelTest.php` | create | consulta agregada, sem N+1, dentro do teto de tempo |
| `tests/Feature/Inscricoes/CargaTest.php` | create | 50 processos, capacidade nunca estourada |
| `tests/e2e/seguranca-csp.spec.ts` | create | telas públicas funcionam com CSP ligada |
| `docs/PERFORMANCE.md` | create | o relatório de medição, antes e depois |
| `docs/ARCHITECTURE.md`, `docs/PROGRESS.md`, `docs/IMPLEMENTATION_PLAN.md` | modify | fechamento da fase |

---

## 5. Quality Criteria

### Auditoria
- [ ] Cancelamento administrativo e confirmação manual geram registro com usuário, motivo, entidade e momento
- [ ] Todo CRUD administrativo gera registro
- [ ] `update` e `delete` em `logs_auditoria` são **impossíveis** pelo model — provado por teste que espera exceção
- [ ] Nenhum registro contém CPF, hash de documento, senha, token ou Pix completo
- [ ] Falha ao gravar auditoria **não** desfaz nem impede a ação administrativa — provado por teste
- [ ] A tela de auditoria exige `auditoria.ver`; `organizador` recebe 403
- [ ] A tela filtra por período, usuário e ação, e pagina

### Desempenho
- [ ] `VolumeSeeder` cria 10.000 inscrições coerentes e só roda em `local`/`testing`
- [ ] `docs/PERFORMANCE.md` traz, para cada uma das cinco consultas de §3.4, o tempo **antes**, o que foi feito e o tempo **depois**
- [ ] Nenhum N+1 nas telas do painel e da lista — provado por contagem de consultas no teste
- [ ] Consultas do painel continuam agregadas (nada de contar em PHP)
- [ ] Índice novo só existe se a medição justificou, e a justificativa está escrita
- [ ] Se houver cache, a tela diz que o número pode estar até 60 s atrasado

### Segurança
- [ ] `POST /inscricoes` tem limite por IP, com resposta em português
- [ ] `POST /webhooks/pagamentos` tem limite **sem** deixar de responder 200 a assinatura inválida (D-18 intacta)
- [ ] Login administrativo tem limite ativo, com teste
- [ ] O limite de `POST /acesso` continua preservando a resposta neutra (D-48) — teste continua verde
- [ ] Cabeçalhos de segurança presentes em toda resposta HTML
- [ ] **Com a CSP ligada**, a tela de pagamento mostra o QR Code e o formulário de inscrição funciona — provado por Playwright
- [ ] `composer audit` e `npm audit` rodados e o resultado registrado
- [ ] `routes/dev.php` continua com dupla trava; nenhuma rota administrativa depende só de `auth`

### Carga
- [ ] 50 processos disputando as últimas vagas: reservadas + confirmadas **nunca** passa da capacidade
- [ ] Nenhum impasse entre transações
- [ ] Tempo de resposta sob disputa registrado em `docs/PERFORMANCE.md`

### Qualidade
- [ ] `vendor/bin/pint --test` limpo · `npm run lint` limpo · `vue-tsc --noEmit` com zero erros
- [ ] Toda a suíte Pest continua verde
- [ ] Todos os cenários Playwright continuam verdes, **sem serem editados**
- [ ] Nenhuma regra de inscrição ou de pagamento alterada
- [ ] Nenhuma dependência nova sem justificativa registrada

---

## 6. Ambiguity Handling

**Assumptions made:**
- **Auditoria é testemunha, não porteiro.** Falha ao registrar não pode impedir a ação; o contrário transformaria o log em ponto único de falha do painel.
- **Append-only no model, não só por convenção.** Bloquear `updating`/`deleting` no Eloquent é a trava que sobrevive a quem escrever código novo sem ler esta decisão.
- **10.000 inscrições é o volume de referência** — uma ordem de grandeza acima do evento real esperado. Medir com 100 não provaria nada.
- **Otimização só depois da medição.** Índice preventivo custa em escrita, e escrita é o caminho da inscrição, que é o que não pode ficar lento.
- **CSP entra medida por Playwright**, porque é o tipo de mudança que só falha em produção.
- **Sem LGPD nesta fase** (§3.1) — e o `PROGRESS.md` precisa dizer isso explicitamente, para ninguém supor que retenção foi resolvida.

**If unsure during execution:**
- Consulta lenta cuja correção exigiria mudar regra de domínio → **PARE**. Desempenho não paga o preço de correção.
- Índice que "parece útil" mas a medição não pediu → **não crie**; anote em `PERFORMANCE.md` como hipótese não confirmada.
- CSP que exigiria `unsafe-inline` para tudo funcionar → **pare e relate**, com a lista exata do que quebrou. Aceitar `unsafe-inline` em silêncio anula o objetivo.
- O teste de carga falhar → o defeito é real e anterior a esta fase. **Relate, não conserte por cima.**

---

## 7. Prohibitions

- ❌ **Nunca** alterar regra de inscrição ou de pagamento em nome de desempenho
- ❌ **Nunca** usar `lockForUpdate()`
- ❌ **Nunca** permitir `update` ou `delete` em `logs_auditoria`
- ❌ **Nunca** gravar CPF, hash, senha, token ou Pix completo na auditoria
- ❌ **Nunca** deixar falha de auditoria derrubar ação administrativa
- ❌ **Nunca** alterar a regra do webhook de responder 200 a assinatura inválida (D-18)
- ❌ **Nunca** afrouxar o limite de `POST /acesso` sem rodar o teste da resposta neutra (D-48)
- ❌ **Nunca** criar índice sem medição que o justifique
- ❌ **Nunca** ligar cache sem avisar na tela que o número pode estar atrasado
- ❌ **Nunca** rodar `VolumeSeeder` fora de `local`/`testing`
- ❌ **Nunca** implementar retenção, anonimização, check-in ou lista de espera
- ❌ **Nunca** editar os cenários Playwright existentes
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Base da auditoria.** Migration `logs_auditoria`, Enum `AcaoAuditada`, model append-only (bloqueando `updating`/`deleting`), serviço `RegistrarAcao` que nunca derruba a ação. `AuditoriaTest`: grava, é imutável, não vaza dado sensível, falha de gravação não desfaz a ação. → commit `feat(auditoria): add append-only audit log`

2. **Amarrar a auditoria às ações.** `CancelarInscricaoAdministrativa`, `ConfirmarPagamentoManual` e os CRUDs administrativos passam a registrar. Teste cobrindo cada ponto. → commit `feat(auditoria): record administrative actions`

3. **Tela de auditoria.** `AuditoriaController` e `Admin/Auditoria/Index.vue`, somente leitura, exigindo `auditoria.ver`, com filtros por período, usuário e ação, e paginação. → commit `feat(auditoria): add audit log screen`

4. **Medir com volume.** `VolumeSeeder` com 10.000 inscrições (inserção em lote, travado em `local`/`testing`), medição com `EXPLAIN ANALYZE` das cinco consultas de §3.4 e `docs/PERFORMANCE.md` com os tempos **antes**. Nenhuma otimização ainda. → commit `test(desempenho): add volume seeder and baseline measurements`

5. **Corrigir o que doeu.** Só o que a medição apontou: índice que falta, consulta reescrita ou cache com aviso na tela. `ConsultasDoPainelTest` provando ausência de N+1 e o teto de tempo. `PERFORMANCE.md` ganha a coluna **depois**. → commit `perf(admin): optimize dashboard and listing queries`

6. **Limites e cabeçalhos.** `throttle` em `POST /inscricoes`, webhook e login; `CabecalhosDeSeguranca` com CSP calibrada; `LimitesTest` e `CabecalhosTest`; `tests/e2e/seguranca-csp.spec.ts` provando que as telas públicas seguem funcionando com a CSP ligada. → commit `feat(seguranca): add rate limits and security headers`

7. **Carga e fechamento.** `CargaTest` com 50 processos disputando as últimas vagas, no molde de `disputar-vaga.php`; `composer audit` e `npm audit` com resultado registrado; `pint`, `lint`, `vue-tsc`, Pest e Playwright completos. Atualizar `docs/PERFORMANCE.md`, `docs/ARCHITECTURE.md`, `docs/PROGRESS.md` (Etapa 16, **Fase 9 concluída**, decisão DA-15 promovida, **P-03 e P-04 seguem abertas e a LGPD continua pendente**) e `docs/IMPLEMENTATION_PLAN.md`. → commit `feat(seguranca): add load test and close phase 9`

## Done

Toda ação administrativa que mexe em vaga, dinheiro ou cadastro deixa rastro que ninguém consegue apagar pela aplicação. O painel e a lista de inscrições foram medidos com 10.000 inscrições no banco, e o que doía foi corrigido com número antes e depois registrado. As portas expostas à internet têm limite de tentativas e cabeçalhos de segurança, com prova em navegador de que nada quebrou. E 50 processos disputando as últimas vagas ao mesmo tempo não conseguem furar a capacidade — nem por uma.

## Commit

```
feat(auditoria): add append-only audit log
feat(auditoria): record administrative actions
feat(auditoria): add audit log screen
test(desempenho): add volume seeder and baseline measurements
perf(admin): optimize dashboard and listing queries
feat(seguranca): add rate limits and security headers
feat(seguranca): add load test and close phase 9
```

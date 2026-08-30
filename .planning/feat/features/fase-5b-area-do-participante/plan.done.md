# Execution Report — Fase 5b: área do participante, linha do tempo, histórico da cobrança e recuperação de acesso

> **Plan:** fase-5b-area-do-participante
> **Executed:** 2026-08-20
> **Status:** ✅ COMPLETE

---

## Histórico da execução (leia antes de tudo)

O plano foi executado em **três corridas**, não em uma:

| Corrida | Steps | Como terminou |
|---------|-------|---------------|
| 1ª | 1, 2, 3, **5**, 6 | Morreu por esgotamento de contexto. **Pulou o step 4** (ligação entre as telas) e **não escreveu relatório** |
| 2ª | 7 e a recuperação do step 4 | Morreu por esgotamento de contexto. **Não escreveu relatório** |
| 3ª (esta) | 8 — varredura de acessibilidade já feita no working tree, documentação e fechamento | Concluída |

Por isso o commit `faea248` (step 4) aparece no histórico **depois** de `ed26198` (step 6): ele foi recuperado fora de ordem. O conteúdo entregue é o mesmo do plano; só a ordem cronológica dos commits difere.

**Lição para a próxima fase:** o próprio plano avisava (§3.7) que executores morrem por volta de 60 chamadas de ferramenta. Commitar ao fim de cada step salvou o trabalho, mas o relatório ficou para o fim e se perdeu duas vezes. Escrever o relatório **antes** de acabar o contexto, ou por corrida, é mais seguro.

---

## What Was Done

### Step 1 — `6ba7e9c` `feat(participante): add registration tracking endpoint`

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Inscricoes/LinhaDoTempoDaInscricao.php` | create | Monta os oito marcos de §3.3 a partir dos carimbos de tempo já gravados; ordena por momento, marcos futuros no fim, um único marco `atual` |
| `app/Http/Resources/InscricaoAcompanhamentoResource.php` | create | Props do participante, sem dado sensível |
| `app/Http/Resources/PagamentoHistoricoResource.php` | create | Um pagamento do histórico (§3.4) |
| `app/Http/Controllers/AcompanhamentoController.php` | create | `show` atrás do middleware `signed` |
| `routes/web.php` | modify | `GET inscricoes/{codigo_publico}/acompanhar` |
| `tests/Feature/Participante/AcompanhamentoTest.php` | create | Props corretos, sem assinatura → 403, sem vazamento |
| `tests/Feature/Participante/LinhaDoTempoTest.php` | create | Oito marcos, ordenação, marco `atual` único, quatro finais possíveis |

### Step 2 — `13d99a3` `feat(participante): add registration tracking page`

| File | Action | Description |
|------|--------|-------------|
| `resources/js/pages/Inscricoes/Acompanhar.vue` | create | A página do participante, sobre `PublicoLayout` |
| `resources/js/components/participante/ResumoDaInscricao.vue` | create | Evento, situação, valor, atividades escolhidas |
| `resources/js/components/participante/LinhaDoTempo.vue` | create | `<ol>` semântica dos marcos |
| `resources/js/components/participante/MarcoDaLinhaDoTempo.vue` | create | Um marco, com estado por texto **e** ícone |
| `resources/js/components/participante/HistoricoDaCobranca.vue` | create | Lista dos pagamentos |
| `resources/js/types/participante.ts` | create | Tipos dos props vindos do Inertia |

### Step 3 — `833c7a9` `feat(pagamentos): add pix second copy on demand`

| File | Action | Description |
|------|--------|-------------|
| `config/inscricoes.php` | create | `validade_link_acesso`, `limites` e `tempo_minimo_resposta_ms` |
| `app/Http/Controllers/SegundaViaPagamentoController.php` | create | `store` com as guardas de §3.6; chama `CriarPagamentoDaInscricao` |
| `app/Http/Controllers/AcompanhamentoController.php` | modify | Expõe `url_segunda_via` quando cabe |
| `resources/js/pages/Inscricoes/Acompanhar.vue` | modify | Botão da segunda via / explicação quando o prazo venceu |
| `resources/js/types/participante.ts` | modify | Prop nova |
| `routes/web.php` | modify | `POST inscricoes/{codigo_publico}/segunda-via` |
| `tests/Feature/Participante/SegundaViaTest.php` | create | Reaproveita a pendente, emite quando não há, recusa fora do prazo e inscrição não-pendente |

### Step 5 — `232cecf` `feat(participante): add access link recovery by email`

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Inscricoes/GeradorLinkDeAcesso.php` | create | URL assinada com validade de 7 dias por configuração |
| `app/Http/Requests/EnviarLinkAcessoRequest.php` | create | Valida o formato do e-mail e nada mais |
| `app/Http/Controllers/AcessoInscricaoController.php` | create | `create` e `store` com resposta neutra, limite por IP e por e-mail e piso de tempo de resposta |
| `app/Mail/LinkDeAcessoInscricao.php` | create | O único e-mail da fase |
| `resources/views/emails/link-de-acesso.blade.php` | create | Corpo do e-mail: evento, situação e link — nada mais |
| `config/inscricoes.php` | modify | Limites de acesso e piso de tempo |
| `routes/web.php` | modify | `GET /acesso` e `POST /acesso` |
| `tests/Feature/Participante/RecuperarAcessoTest.php` | create | `Mail::fake()`, resposta idêntica nos dois casos, cancelada fora, limite mantendo a resposta neutra |

### Step 6 — `ed26198` `feat(participante): add access recovery page`

| File | Action | Description |
|------|--------|-------------|
| `resources/js/pages/Inscricoes/RecuperarAcesso.vue` | create | Um campo, `role="alert"` no erro, `role="status"` na mensagem neutra, estados de carregamento e de erro |

### Step 4 (recuperado fora de ordem) — `faea248` `feat(participante): link payment and tracking screens`

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/PagamentoController.php` | modify | Expõe `url_acompanhamento` nos props |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modify | Link "acompanhar minha inscrição"; no estado expirado, link para pedir novo acesso |
| `resources/js/pages/Eventos/Show.vue` | modify | Link discreto "já me inscrevi" |
| `resources/js/types/pagamento.ts` | modify | Prop nova |
| `tests/Feature/Participante/AcompanhamentoTest.php` | modify | Cobre a prop nova |

### Step 7 — `8a65bfd` `test(e2e): add participant area scenarios`

| File | Action | Description |
|------|--------|-------------|
| `tests/e2e/acompanhamento.spec.ts` | create | 3 cenários: prazo como passo de agora, confirmação na linha do tempo, acesso sem assinatura → recusa |
| `tests/e2e/recuperar-acesso.spec.ts` | create | 2 cenários: mesma resposta com e sem inscrição; e-mail malformado sem revelar existência |
| `tests/e2e/segunda-via-do-pix.spec.ts` | create | 1 cenário: devolve a tela de pagamento com QR Code, sem criar outra cobrança |
| `tests/e2e/apoio.ts` | modify | Helper devolve `urlDoAcompanhamento` (arquivo de apoio, **não** é um dos 12 `.spec.ts` da 5a) |

### Step 8 — commit de fechamento `feat(participante): polish accessibility and close phase 5b`

| File | Action | Description |
|------|--------|-------------|
| `resources/js/components/participante/LinhaDoTempo.vue` | modify | Formatação do `v-for` (eslint/prettier) |
| `resources/js/components/participante/ResumoDaInscricao.vue` | modify | `CardTitle` (que desenha `<h3>`) trocado por `<h2>` explícito, para os títulos descerem um nível de cada vez |
| `resources/js/pages/Inscricoes/Acompanhar.vue` | modify | Mesma troca nos dois cartões ("O que já aconteceu" e "Histórico da cobrança") + formatação |
| `resources/js/pages/Inscricoes/RecuperarAcesso.vue` | modify | `aria-describedby` passa a apontar para **ajuda e erro juntos** (`"ajuda-email erro-email"`), em vez de a dica sumir quando aparece o erro |
| `tests/e2e/recuperar-acesso.spec.ts` | modify | Acompanha o `aria-describedby` novo (arquivo desta fase, criado no step 7) |
| `tests/e2e/acessibilidade-do-participante.spec.ts` | create | 3 cenários: 320 px e alvos de toque; teclado com foco visível; linha do tempo legível sem cor + hierarquia de títulos |
| `docs/PROGRESS.md` | modify | Etapa 12, decisões D-44 a D-49, Fase 5b concluída, checklist atualizado |
| `docs/IMPLEMENTATION_PLAN.md` | modify | Fase 5b marcada como ✅, com a tabela de entregas |
| `.planning/feat/features/fase-5b-area-do-participante/` | create | Plano e este relatório |

---

## Quality Criteria

### Funcional

| Critério | Status | Evidência |
|----------|--------|-----------|
| `/acompanhar` sem assinatura → 403 | ✅ | `AcompanhamentoTest` + cenário Playwright "sem assinatura → recusa" |
| Página mostra evento, situação, valor, atividades, linha do tempo e histórico | ✅ | `AcompanhamentoTest`; cenários de acompanhamento verdes |
| Oito marcos, ordenados, com um único `atual` | ✅ | `LinhaDoTempoTest` |
| Confirmada e expirada sem marco `atual` | ✅ | `LinhaDoTempoTest` |
| Caminho de volta ao Pix dentro do prazo; explicação depois dele | ✅ | `SegundaViaTest` + `Acompanhar.vue` |
| Segunda via devolve a mesma cobrança pendente | ✅ | `SegundaViaTest` |
| Segunda via emite nova com `expira_em` = `prazo_pagamento` | ✅ | `SegundaViaTest` |
| Fora do prazo / não-pendente não cria nada e explica | ✅ | `SegundaViaTest` |
| `POST /acesso` responde igual com e sem inscrição | ✅ | `RecuperarAcessoTest` + cenário Playwright "mesma resposta" |
| E-mail com link assinado que abre o acompanhamento | ✅ | `RecuperarAcessoTest` com `Mail::fake()` |
| Limite estourado mantém a resposta neutra | ✅ | `RecuperarAcessoTest` |
| Telas ligadas por link | ✅ | commit `faea248` |

### Qualidade

| Critério | Status | Evidência |
|----------|--------|-----------|
| `vendor/bin/pint --test` | ✅ | **PASS, 165 files** |
| `npm run lint` | ✅ | limpo, sem alteração produzida |
| `vue-tsc` sem erro novo | ✅ | **exatamente 20 erros**, os mesmos da P-09, todos em telas do pacote inicial. **Zero erro novo** |
| Testes Pest existentes verdes | ✅ | 241 passando; a base era 205 |
| 12 cenários da 5a verdes e **não editados** | ✅ | 21 cenários passando; nenhum dos 12 `.spec.ts` da 5a aparece nos commits desta fase |
| Nenhum dado sensível nos props | ✅ | `AcompanhamentoTest` verifica ausência de `documento`, `documento_hash`, `id_externo`, `gateway`, `metadados` |
| Nenhuma Action, Enum, Model ou migração de domínio | ✅ | Nenhum arquivo de `app/Actions`, `app/Enums`, `app/Models` ou `database/migrations` aparece no diff da fase |
| Nenhum ouvinte de evento de domínio | ✅ | Nada em `app/Listeners`; D-12 continua de pé |
| Cor só por token semântico | ✅ | `bg-acao`, `text-acao-foreground`, variantes `sucesso`/`atencao` do `Alert` |
| Nenhuma dependência nova | ✅ | `composer.json` e `package.json` intocados entre `01ae2b8` e o fechamento |

### Acessibilidade e mobile

| Critério | Status | Evidência |
|----------|--------|-----------|
| `<ol>` de verdade, estado legível sem cor | ✅ | cenário "a linha do tempo se lê sem depender da cor" |
| `<label>` associado, erro por `aria-describedby` + `role="alert"` | ✅ | `RecuperarAcesso.vue`; cenário do e-mail malformado confere `aria-describedby="ajuda-email erro-email"` |
| Mensagem neutra com `role="status"` | ✅ | cenário de teclado confere o atributo |
| Foco sempre visível, navegação por teclado | ✅ | cenário "só com o teclado, com o foco sempre visível" |
| Contraste AA nos dois modos | ✅ | herdado dos tokens medidos na 5a (D-40, D-41); nenhuma cor nova foi introduzida |
| Alvos de 44×44 px | ✅ | cenário de 320 px, lista de alvos pequenos vazia |
| Sem rolagem horizontal a 320 px | ✅ | mesmo cenário, `scrollWidth - clientWidth <= 0` |

---

## Verification

Comandos rodados sobre exatamente esta árvore, com a saída registrada:

| Command | Result |
|---------|--------|
| `vendor/bin/pint --test` | **PASS, 165 files** |
| `npm run lint` (`eslint --fix`) | **limpo**, nenhuma alteração produzida |
| `npx vue-tsc --noEmit` | **exatamente 20 erros**, os conhecidos da pendência **P-09**, todos em telas do pacote inicial (território da Fase 6). **Zero erro novo** |
| `php artisan test` | **241 passed (1048 assertions)** — base antes da fase: 205/795, logo **+36 testes / +253 asserções** |
| `npm run test:e2e` | **21 passed (30.9s)** — os 12 da Fase 5a intactos e verdes + 9 novos |

**Nota de operação:** `php artisan test` foi rodado **na máquina hospedeira, fora do Sail**. O `phpunit.xml` fixa `127.0.0.1:55432` (decisões D-19 e D-20), endereço que só resolve fora do contêiner. Dentro do Sail, a suíte não conecta.

Os 9 cenários Playwright novos:

- `acompanhamento.spec.ts` → 3 (prazo como passo de agora; confirmação na linha do tempo; sem assinatura → recusa)
- `recuperar-acesso.spec.ts` → 2 (mesma resposta com e sem inscrição; e-mail malformado sem revelar existência)
- `segunda-via-do-pix.spec.ts` → 1 (devolve a tela de pagamento com QR Code, sem criar outra cobrança)
- `acessibilidade-do-participante.spec.ts` → 3 (320 px e alvos de toque; teclado com foco visível; linha do tempo legível sem cor)

---

## Deviations from Plan

1. **Três corridas, não uma.** Duas execuções anteriores morreram por esgotamento de contexto e nenhuma escreveu relatório. Ver o quadro no topo.
2. **Step 4 executado fora de ordem.** Pulado pela 1ª corrida, recuperado pela 2ª (`faea248`). Conteúdo idêntico ao previsto; só a posição no histórico difere.
3. **Um arquivo de teste a mais que o plano previa:** `tests/e2e/acessibilidade-do-participante.spec.ts`. O plano pedia a varredura de acessibilidade no step 8 sem nomear o arquivo; ela virou cenário automatizado em vez de conferência manual. Melhor assim: a verificação passa a rodar em toda execução da suíte.
4. **`tests/e2e/apoio.ts` foi modificado** para devolver `urlDoAcompanhamento`. É arquivo de apoio compartilhado, **não** um dos 12 `.spec.ts` da Fase 5a — a proibição do plano foi respeitada e os 12 continuam byte a byte como estavam.
5. **Duas decisões que o plano não antecipou**, registradas no PROGRESS como **D-48** e **D-49**:
   - **D-48** — a resposta neutra do `POST /acesso` exigiu três medidas juntas, não só o `throttle` sugerido em §3.5: o limite é contado **dentro do controller** (um 429 de middleware confirmaria o alvo a quem varre endereços), o e-mail vira `sha256` antes de virar chave do limitador, e há um **piso de tempo de resposta de 400 ms** (`inscricoes.tempo_minimo_resposta_ms`) porque enviar e-mail demora mais que não enviar. Falha de envio é registrada em log e engolida — um 500 contaria que a inscrição existe.
   - **D-49** — nas telas do participante, os títulos de cartão são `<h2>` escritos à mão em vez do `CardTitle` do pacote inicial, que desenha `<h3>` e pularia um nível depois do `<h1>` da página. Na mesma linha, o campo de e-mail aponta para ajuda **e** erro ao mesmo tempo, em vez de a dica sumir quando o erro aparece.
6. **Nenhuma dependência nova** foi adicionada — registrado no PROGRESS.
7. **P-09 continua aberta** de propósito: os 20 erros de tipo são das telas do pacote inicial, que a Fase 6 reescreve.

Nada mais: nenhuma Action, Enum, Model, migração ou evento de domínio foi criado; nenhum ouvinte foi registrado; nenhum e-mail além do link de acesso; cancelamento pelo participante segue fora do escopo (D-45).

---

## Commit

- **Mensagem:** `feat(participante): polish accessibility and close phase 5b`
- **Arquivos:** os seis do step 8 (quatro Vue, um `.spec.ts` modificado e o `.spec.ts` novo de acessibilidade), `docs/PROGRESS.md`, `docs/IMPLEMENTATION_PLAN.md` e `.planning/feat/features/fase-5b-area-do-participante/`
- **Não commitado de propósito:** `Prompt para Claude Code — Plataforma de Inscrições e Gestão de Eventos.md`, na raiz — arquivo solto que antecede esta fase
- **Sem `git push`.** O trabalho fica em `main` local, aguardando decisão de quem conduz o projeto
- **Commits anteriores da fase:** `6ba7e9c`, `13d99a3`, `833c7a9`, `232cecf`, `ed26198`, `faea248`, `8a65bfd`

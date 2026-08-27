# Action Plan — Página inicial pública que leva ao evento

> **Type:** feature
> **Created:** 2026-08-27
> **Status:** pending
> **Ordem:** independente das fases de pagamento e deploy. Pode ser executada a qualquer
> momento — mas **antes de o site ir ao ar**, porque hoje a raiz do domínio mostra a tela
> de exemplo do Laravel.

---

## 1. Persona & Scope

**Persona:** Frontend Engineer **Vue 3.5 + Inertia + TypeScript + Tailwind**, com olho para
página de entrada: sabe que quem chega ali veio de um link no WhatsApp, está no celular e
decide em segundos se aquilo é o lugar certo. Escreve HTML semântico e acessível porque a
página vai ser lida em voz alta por alguém.

**Scope:** trocar a tela de exemplo do starter kit por uma página inicial que apresente o
evento e leve à inscrição.

| Entrega | Nesta fase |
|---------|:----------:|
| `HomeController` com a consulta do evento em destaque | ✅ |
| Página `Home.vue` — evento, datas, chamada para inscrição | ✅ |
| Estado **sem evento aberto**, com o próximo evento quando houver | ✅ |
| Estado **mais de um evento aberto** (§3.4) | ✅ |
| Link "já fiz minha inscrição" para `/acesso` | ✅ |
| Remoção do `Welcome.vue` do starter kit | ✅ |
| Testes Pest e cenários Playwright | ✅ |
| Identidade visual nova (logo, cores, marca) | ❌ **fora do escopo** (DA-37) |
| Alterar a vitrine `/eventos/{slug}` | ❌ **proibido** — ela já funciona e tem 12 cenários |
| Qualquer regra de inscrição, vaga ou pagamento | ❌ **proibido** (§7) |
| Cache da consulta | ❌ fora do escopo (§3.5) |

**Stack:** PHP 8.4 · Laravel 12 · Vue 3.5 + Inertia + TypeScript · Tailwind · shadcn-vue ·
Pest 4 · Playwright.

---

## 2. Direct Objective

Quem abre `https://inscricoes.cccista.com.br` vê **qual é o evento, quando é e como se
inscrever** — e chega à vitrine em um clique. Hoje vê a tela de exemplo do Laravel, com
links para a documentação do framework.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas — **NÃO reabrir**

| # | Tema | Decisão | Origem |
|---|------|---------|--------|
| **DA-34** | Formato | **Landing própria com o evento em destaque**, não redirecionamento para a vitrine. Quem chega entende onde está antes de clicar, e a página continua servindo quando houver nenhum evento ou mais de um | entrevista |
| **DA-35** | Sem evento aberto | Aviso claro — *"No momento não há inscrições abertas"* — **mais o próximo evento quando existir** um publicado com abertura futura, dizendo a partir de quando. Quem chegou cedo demais sabe quando voltar | entrevista |
| **DA-36** | Quem já se inscreveu | Link discreto **"Já fiz minha inscrição"** apontando para `/acesso`. Hoje, quem perde o e-mail só chega lá sabendo a URL de cor | entrevista |
| **DA-37** | Aparência | **Sem identidade visual nova.** Usar os tokens e componentes que o projeto já tem. O executor **não deve inventar logo, marca nem paleta** | entrevista |
| **DA-38** | Mais de um evento aberto | Destaca **o de data de início mais próxima** e lista os demais abaixo, de forma enxuta. Nunca escolher "o primeiro do banco": ordem sem critério é defeito esperando acontecer | §3.4 |

**Decisões anteriores que esta fase deve respeitar:**

| # | O que diz | Consequência aqui |
|---|---|---|
| **D-10** | Aplicação em `America/Sao_Paulo` | Datas formatadas no fuso do evento. Data errada na home é gente aparecendo no dia errado |
| **D-79** | Nenhum cache sem medição que justifique | A home **não** ganha cache nesta fase (§3.5) |
| **Fase 9** | CSP por nonce, **sem `unsafe-inline` em script-src** | A página **não pode** ter script inline. Já existe cenário Playwright que prova a CSP; ele não pode quebrar |
| **D-01/D-02** | Domínio em português sem acento; framework em inglês | Vale para identificadores. **O texto que a pessoa lê é acentuado corretamente** — foi corrigido hoje em quatro Enums, não repita o deslize |

### 3.2 O que já existe — e não pode ser refeito

| O quê | Onde | Por que importa |
|---|---|---|
| Vitrine do evento | `resources/js/pages/Eventos/Show.vue` · `EventoPublicoController` | Já tem programação, regulamento e o botão de inscrição. **A home não duplica nada disso** — ela apresenta e encaminha |
| `scopeComInscricoesAbertas` | `app/Models/Evento.php:92` | **Use este scope.** Ele já sabe o que é "inscrições abertas". A home **não** reimplementa essa regra |
| `scopePeloSlug` | `app/Models/Evento.php:104` | referência de estilo |
| `EventoPublicoResource` | `app/Http/Resources/` | O modelo a seguir para não vazar campo indevido — mas a home precisa de **muito menos** dados |
| Recuperação de acesso | rota `/acesso` (Fase 5b) | O destino do link da DA-36 |
| `SituacaoEvento` | `app/Enums/` | `Rascunho`, `Publicado`, `InscricoesAbertas`, `InscricoesEncerradas`, `Finalizado`, `Cancelado` |

### 3.3 Arquivos a ler antes de escrever

`routes/web.php` (a rota `/` na linha 26) · `app/Models/Evento.php` ·
`app/Http/Controllers/EventoPublicoController.php` · `app/Http/Resources/EventoPublicoResource.php` ·
`resources/js/pages/Eventos/Show.vue` · `resources/js/pages/Welcome.vue` (o que sai) ·
`app/Enums/SituacaoEvento.php` · `tests/e2e/caminho-feliz.spec.ts` (como os cenários navegam) ·
`tests/e2e/acessibilidade-e-responsividade.spec.ts` (o padrão de acessibilidade a seguir) ·
`tests/e2e/semear.ts` (o que existe no banco dos cenários) ·
`app/Http/Middleware/CabecalhosDeSeguranca.php` (a CSP que a página tem de respeitar)

### 3.4 Os três estados da página

A home tem de estar correta nos três, e **cada um precisa de teste**:

| Estado | O que mostrar |
|---|---|
| **Um evento com inscrições abertas** | Nome, datas, cidade, resumo curto, **botão grande "Fazer inscrição"** levando à vitrine, link para ver a programação e o link da DA-36 |
| **Mais de um aberto** | O de início mais próximo em destaque; os demais numa lista enxuta abaixo, cada um levando à sua vitrine (DA-38) |
| **Nenhum aberto** | Aviso claro; **se houver evento publicado com abertura futura**, dizer qual e a partir de quando; e o link da DA-36, que continua valendo |

Cuidado com o caso limite: evento **publicado mas com inscrições ainda não abertas** não é
"aberto" — ele aparece como *próximo*, nunca com botão de inscrição.

### 3.5 Desempenho e o que não fazer

A home é a página mais acessada do sistema e a primeira que um pico de acesso encontra.

- **Uma consulta só**, com o scope existente, trazendo **apenas as colunas necessárias**.
- **Sem N+1**: a home não precisa de dias, grupos nem atividades. Se você se pegar
  carregando a árvore inteira do evento, parou de fazer uma home e começou a refazer a
  vitrine.
- **Sem cache** (D-79): a Fase 9 mediu com 10.000 inscrições e concluiu que cache sem
  medição que justifique só acrescenta uma fonte de verdade defasada. Se achar que precisa,
  **meça e escreva o número** — não ligue por precaução.

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/HomeController.php` | create | consulta o destaque e os próximos; devolve os três estados da §3.4 |
| `app/Http/Resources/EventoEmDestaqueResource.php` | create | o mínimo que a home mostra: nome, slug, datas, cidade, resumo, situação. **Nada além disso** |
| `resources/js/pages/Home.vue` | create | a página; mobile-first, sem script inline (CSP) |
| `resources/js/pages/Welcome.vue` | **delete** | a tela de exemplo do starter kit sai |
| `routes/web.php` | modify | `/` passa a apontar para o `HomeController`; o nome `home` da rota **é preservado** |
| `tests/Feature/Publico/HomeTest.php` | create | os três estados, o caso limite e a ausência de vazamento |
| `tests/e2e/home.spec.ts` | create | os cenários da §5 |
| `docs/PROGRESS.md` | modify | Etapa 20; decisões DA-34 a DA-38 |
| `.planning/feat/features/home-publica-evento/plan.done.md` | create | relatório |

---

## 5. Quality Criteria

### Comportamento

- [ ] Com **um** evento aberto: nome, datas e cidade na tela, e o botão leva a
      `/eventos/{slug}`
- [ ] Com **dois** abertos: o de início mais próximo em destaque, o outro listado (DA-38) —
      teste com datas propositalmente fora de ordem no banco
- [ ] Com **nenhum** aberto: o aviso aparece e **não existe botão de inscrição**
- [ ] Evento **publicado com abertura futura** aparece como *próximo*, com a data — e
      **nunca** com botão de inscrição (§3.4)
- [ ] Evento em `Rascunho`, `Cancelado` ou `Finalizado` **não aparece de forma alguma**
- [ ] O link "Já fiz minha inscrição" leva a `/acesso` (DA-36)
- [ ] A rota continua se chamando `home` — `route('home')` é usado pelo starter kit

### Não vazar

- [ ] A resposta do Inertia traz **apenas** os campos da §4. Nenhum dado de participante,
      nenhuma contagem de inscritos, **nenhum id interno** — o slug é o identificador
      público
- [ ] Conferir as props na resposta real, não só no Resource

### Aparência e acessibilidade

- [ ] **Mobile-first**: a página é boa em 360 px de largura. O público se inscreve pelo
      celular, e é assim que os cenários rodam (Pixel 5)
- [ ] Um `h1` só, hierarquia de títulos correta, marcos semânticos (`main`, `header`)
- [ ] Contraste AA nos textos e no botão principal
- [ ] O botão principal é alcançável por teclado e tem foco visível
- [ ] **Texto em português acentuado corretamente** — nada de "Inscricao"
- [ ] Nenhum componente ou token novo inventado: só o que o projeto já usa (DA-37)

### Segurança e desempenho

- [ ] **Nenhum script inline** — a CSP da Fase 9 recusaria, e há cenário Playwright que
      prova a CSP nas páginas públicas
- [ ] **Uma consulta** para montar a página; provar com contagem de consultas no teste
- [ ] Nenhum carregamento de dias, grupos ou atividades (§3.5)

### O que não pode ter mudado

- [ ] `git diff --stat` sobre `app/Actions/`, `app/Models/`, `app/Enums/` → **vazio**
- [ ] `Eventos/Show.vue` e `EventoPublicoController` **intocados**
- [ ] **533 testes Pest** e **36 cenários Playwright** continuam verdes, **sem editar
      nenhum cenário existente**
- [ ] `vendor/bin/pint --test` · `npm run lint` · `npx vue-tsc --noEmit` · `npm run build`

### Playwright — cenários novos

- [ ] A home mostra o evento aberto e o botão leva à vitrine
- [ ] Da home até o formulário de inscrição, em dois cliques
- [ ] Sem evento aberto, o aviso aparece e não há botão de inscrição
- [ ] O link "Já fiz minha inscrição" chega em `/acesso`

---

## 6. Ambiguity Handling

**Assumptions made:**

- **O destaque é o evento de início mais próximo** entre os abertos (DA-38). Ordenar por id
  ou pela ordem do banco seria escolher por acaso.
- **A home não mostra contagem de vagas nem de inscritos.** Número de vaga restante é
  informação da vitrine, onde existe contexto; na entrada, vira pressão sem explicação — e
  fica errado no segundo seguinte.
- **O `Welcome.vue` é removido, não mantido de lado.** Arquivo órfão de starter kit vira
  dúvida para quem chegar depois. `ExampleTest.php` só espera 200 em `/`, e continua verde.
- **A rota `/dashboard` do starter kit não é tocada** — está fora do escopo, e a Fase 9 já
  a registrou como observação sem gravidade.
- **Resumo do evento:** se o model não tiver um campo curto adequado, use o que houver de
  descrição com um limite de caracteres. **Não crie coluna nova** — isso seria mudança de
  domínio, proibida aqui.

**If unsure during execution:**

- **Se precisar de coluna nova, de migration ou de mudar um Model, PARE.** Esta fase é de
  apresentação. Se a informação não existe, mostre menos.
- **Na dúvida sobre mostrar um dado, não mostre.** Página pública, sem login.
- **Se um cenário Playwright existente quebrar, o defeito é seu** — não edite o cenário.
- **Commite ao fim de cada step.**

---

## 7. Prohibitions

- ❌ **NUNCA** alterar Action, Model, Enum, migration ou regra de inscrição
- ❌ **NUNCA** tocar em `Eventos/Show.vue` nem no `EventoPublicoController`
- ❌ **NUNCA** reimplementar a regra de "inscrições abertas" — o scope existe
- ❌ **NUNCA** usar script inline (a CSP da Fase 9 recusa)
- ❌ **NUNCA** expor id interno, contagem de inscritos ou qualquer dado pessoal
- ❌ **NUNCA** inventar logo, marca ou paleta (DA-37)
- ❌ **NUNCA** editar cenário Playwright existente
- ❌ **NUNCA** adicionar dependência nova
- ❌ **NUNCA** ligar cache sem medição escrita (D-79)

---

## Execution Steps

1. **Backend da home.** `HomeController`, `EventoEmDestaqueResource`, a rota `/` (mantendo
   o nome `home`). Mais `HomeTest.php` cobrindo os três estados da §3.4, o caso limite do
   evento publicado com abertura futura, a contagem de consultas e a ausência de vazamento.
   → commit `feat(publico): add home controller`

2. **A página.** `Home.vue` mobile-first com o evento em destaque, o botão principal, o
   link da programação e o da DA-36. Remoção do `Welcome.vue`.
   → commit `feat(publico): add home page`

3. **Os outros dois estados na tela.** Lista de eventos adicionais (DA-38) e o estado sem
   evento aberto com o próximo (DA-35), com o cuidado do caso limite.
   → commit `feat(publico): handle empty and multiple event states`

4. **Acessibilidade e navegador.** Hierarquia de títulos, marcos, foco, contraste; os
   quatro cenários novos em `tests/e2e/home.spec.ts`. Rodar a suíte Playwright inteira e
   provar que os 36 anteriores continuam verdes **sem edição**.
   → commit `test(publico): prove the home page in the browser`

5. **A prova e o fechamento.** Suíte Pest, pint, lint, vue-tsc, build. `docs/PROGRESS.md`
   (Etapa 20, DA-34 a DA-38) e o relatório.
   → commit `docs(publico): close the public home page`

---

## Done

`/` mostra o evento com inscrições abertas — nome, quando é, onde é — e leva à vitrine em um
clique; diz com clareza quando não há inscrições e quando as próximas abrem; oferece o
caminho de volta a quem já se inscreveu; e a tela de exemplo do Laravel não existe mais.
Com os 533 testes Pest e os 36 cenários Playwright anteriores verdes, sem edição.

## Commit

`feat(publico): add public home page`

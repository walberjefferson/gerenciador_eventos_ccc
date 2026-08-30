# Execution Report — Página inicial pública que leva ao evento

> **Plan:** home-publica-evento
> **Executed:** 2026-08-27 (duas rodadas — ver "Desvios")
> **Status:** ✅ COMPLETE

## Sobre a primeira rodada

A primeira execução **morreu por erro de API** — a resposta parou de chegar no
meio do trabalho, não houve decisão de parada nem defeito no que estava escrito.
Ela havia acabado de escrever a rota e **não tinha commitado nada**. Esta rodada
começou lendo o que estava na árvore, revisou os três arquivos deixados por ela
(`HomeController.php`, `EventoEmDestaqueResource.php` e a mudança em
`routes/web.php`), **aproveitou o que estava certo** e completou o resto.

O que foi corrigido no trabalho parcial: a coleção de `outros_abertos` saía
embrulhada num `data` (o `$wrap = null` do Resource não se propaga para
`::collection()`), o que faria a tela receber `{data: [...]}` em vez da lista.
Passou a usar `->resolve()`, que já é o padrão do
`InscricaoPublicaController`. O resto do trabalho parcial foi mantido: ele usa
o scope `comInscricoesAbertas` e o método `inscricoesEstaoAbertas()` do model
em vez de reimplementar a regra, faz **uma consulta só** com colunas
selecionadas e ordenada por `data_inicio`, e trata o caso limite do evento
publicado com abertura futura.

## What Was Done

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/HomeController.php` | create | Uma consulta traz de uma vez os eventos abertos e os que ainda vão abrir; o corte em memória é o `inscricoesEstaoAbertas()` do próprio model. Destaque = início mais próximo (DA-38) |
| `app/Http/Resources/EventoEmDestaqueResource.php` | create | Dez campos e nada mais: nome, slug, resumo, as duas datas, período escrito, situação e rótulo, `inscricoes_abertas` e `abre_em_rotulo` |
| `resources/js/pages/Home.vue` | create | A página, mobile-first, sobre o `PublicoLayout` e os componentes que a vitrine já usa. Três estados na tela |
| `resources/js/pages/Welcome.vue` | **delete** | A tela de exemplo do pacote inicial saiu |
| `routes/web.php` | modify | `/` aponta para o `HomeController`; o nome `home` da rota foi preservado |
| `tests/Feature/Publico/HomeTest.php` | create | 9 testes: os três estados, dois casos limite, a lista fora de ordem, a contagem de consultas e a ausência de vazamento |
| `tests/e2e/home.spec.ts` | create | Os 4 cenários da §5, no aparelho de 360 px |
| `docs/PROGRESS.md` | modify | Etapa 20 no "Concluído", decisões DA-34 a DA-38 e cabeçalho atualizado |
| `.planning/feat/features/home-publica-evento/plan.done.md` | create | este relatório |

## Quality Criteria

### Comportamento

| Criterion | Status | Evidence |
|---|---|---|
| Um evento aberto: nome, datas, botão para `/eventos/{slug}` | ✅ | Pest `it mostra o evento com inscricoes abertas...` verde; Playwright `a home apresenta o evento aberto e o botao leva a vitrine` chega em `/eventos/copa-ccc-2026` |
| Dois abertos: o de início mais próximo em destaque, com datas fora de ordem no banco | ✅ | Pest `it destaca o evento de inicio mais proximo e lista os demais abaixo` — grava novembro, setembro e outubro nessa ordem e exige `encontro-de-setembro` no destaque |
| Nenhum aberto: aviso, sem botão de inscrição | ✅ | Pest `it avisa com clareza quando nao ha nenhuma inscricao aberta`; Playwright `sem evento aberto...` exige `botao-fazer-inscricao` com 0 ocorrências **e** nenhum `a[href*="/eventos/"]` |
| Publicado com abertura futura aparece como próximo, com a data, sem botão | ✅ | Pest `it o evento publicado cuja janela ainda nao abriu...` — `destaque` nulo, `proximo.abre_em_rotulo` = "As inscrições abrem em 15/01/2027 às 09:00." Um segundo teste cobre a mesma armadilha pelo outro lado (situação já "inscrições abertas", janela ainda fechada) |
| Rascunho, Cancelado ou Finalizado não aparecem | ✅ | Pest `it nao mostra evento em rascunho, cancelado ou finalizado nem quando ha um aberto` — os nomes entregues são exatamente `['Aberto de verdade']` |
| "Já fiz minha inscrição" leva a `/acesso` | ✅ | Playwright `o link de quem ja se inscreveu chega na recuperacao de acesso` — chega na tela e o campo "E-mail da inscrição" está visível |
| A rota continua se chamando `home` | ✅ | Pest `it a rota da porta da rua continua se chamando home` (`route('home')` = `/`); os 542 testes incluem os do pacote inicial que usam `route('home')` |

### Não vazar

| Criterion | Status | Evidence |
|---|---|---|
| Só os campos da §4, na resposta real | ✅ | Pest `it nao leva para o navegador nada alem do que a tela mostra` lê `viewData('page')['props']` e exige que **cada** um dos três eventos entregues tenha exatamente as dez chaves esperadas |
| Nenhum id interno, contagem de inscritos ou dado pessoal | ✅ | Mesmo teste: as chaves não incluem `id` nem `codigo_publico`; e o HTML cru é varrido por `vagas_reservadas`, `vagas_confirmadas`, `vagas_disponiveis`, `capacidade` e `valor_centavos` — nenhum aparece |

`codigo_publico` ficou de fora da varredura do HTML cru, com comentário no teste
explicando: a palavra aparece na tabela de rotas que o Ziggy escreve em toda
página (`/inscricoes/{codigo_publico}/pagamento`), é o **nome do parâmetro** e
não o código de ninguém. Que nenhum evento leve o seu já está provado pelas
chaves.

### Aparência e acessibilidade

| Criterion | Status | Evidence |
|---|---|---|
| Mobile-first, boa em 360 px | ✅ | Playwright cenário 1 com `setViewportSize({width: 360})`: `scrollWidth - clientWidth <= 0` |
| Um `h1` só, marcos semânticos | ✅ | Mesmo cenário: `getByRole('heading', {level: 1}).count() === 1`, `main#conteudo` e `header` visíveis. Os `h2` das seções vêm sempre depois do `h1` |
| Contraste AA no botão principal | ✅ | Mesmo cenário calcula a razão da WCAG na cor computada contra o fundo pintado mais próximo e exige `>= 4.5` |
| Botão alcançável por teclado com foco visível | ✅ | Mesmo cenário: `focus()` → `toBeFocused()`, e `outlineStyle !== 'none' \|\| boxShadow !== 'none'` |
| Texto em português acentuado | ✅ | "Fazer inscrição", "Ver a programação", "Já fiz minha inscrição", "Inscrições abertas", "No momento não há inscrições abertas.", "As inscrições abrem em …". Os identificadores (slug, chaves do Resource, nomes de método) seguem sem acento, como manda a D-01 |
| Nenhum componente ou token novo | ✅ | A página importa só `PublicoLayout`, `Button`, `Badge` e `Alert`, todos já usados pela vitrine. `git status` não mostra nenhum arquivo novo em `resources/js/components/` |

### Segurança e desempenho

| Criterion | Status | Evidence |
|---|---|---|
| Nenhum script inline | ✅ | `Home.vue` tem um único `<script setup>`, que o Vite compila para o pacote — nada é escrito no HTML. Os 4 cenários da CSP continuam verdes, e os cenários da home navegam pelo Inertia (clique que troca de página), o que só funciona com o JavaScript aceito pela política |
| Uma consulta, provada com contagem | ✅ | Pest `it monta a pagina inteira com uma consulta ao banco` — `DB::getQueryLog()` com 4 eventos no banco devolve exatamente 1 |
| Nenhum carregamento de dias, grupos ou atividades | ✅ | O controller não tem `with()` nenhum, e a contagem de 1 consulta prova que nada foi carregado depois |

### O que não pode ter mudado

| Comando | Resultado |
|---|---|
| `git diff --stat 8b0aaf9..HEAD -- app/Actions/ app/Models/ app/Enums/ database/migrations/` | vazio |
| `git diff --stat 8b0aaf9..HEAD -- resources/js/pages/Eventos/Show.vue app/Http/Controllers/EventoPublicoController.php` | vazio |
| `git diff --stat 8b0aaf9..HEAD -- tests/e2e/` | só `tests/e2e/home.spec.ts \| 160 +++` — nenhum cenário existente editado |

## Verification

| Command | Result |
|---|---|
| `php artisan test` | **542 passed (3.807 assertions)** — eram 533 com 3.681 |
| `npx playwright test` | **40 passed (49,8 s)** — os 36 anteriores mais os 4 novos |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | sem apontamento |
| `npx vue-tsc --noEmit` | sem saída (zero erros) |
| `npm run build` | `✓ built in 1.56s` |

## Deviations from Plan

1. **`Home.vue` entrou no commit do passo 1**, e não só no do passo 2. Motivo
   concreto: o `HomeTest` usa `assertInertia(...)->component('Home')`, e o
   Inertia falha o teste quando o arquivo do componente não existe
   (`ensure_pages_exist`). Commitar o passo 1 sem a página deixaria a suíte
   vermelha, o que a regra "não commitar sem lint e testes passando" proíbe. O
   commit do passo 2 ficou com a **remoção do `Welcome.vue`**, que é a outra
   entrega daquele passo, e o do passo 3 com os dois estados extras na tela.
2. **A cidade não aparece na home.** A §3.4 pedia "nome, datas, cidade", mas
   **não existe coluna de cidade em `eventos`** — `cidades` é catálogo do
   participante, não do evento. Como a §6 manda ("se a informação não existe,
   mostre menos") e a §7 proíbe migração, a página mostra nome, período e
   resumo. **Nenhuma coluna, migração ou model foi tocado.**
3. **`resumo` é a descrição encurtada em 180 caracteres**, porque não há campo
   curto no model — exatamente a saída que a §6 previa.
4. **O `proximo` só é exibido no estado sem evento aberto.** O controller sempre
   o envia, mas a tela só o mostra quando não há destaque, que é o que a §3.4
   descreve. Mostrá-lo junto com um evento aberto seria ruído na entrada.
5. **O cenário Playwright do estado vazio muda o banco** — fecha as inscrições
   do evento de demonstração por `artisan tinker` e as reabre num `finally`,
   como o `definirCapacidadeDaAtividade` de `apoio.ts` já fazia com capacidade.
   Não havia outro jeito de alcançar o estado vazio no banco semeado. `apoio.ts`
   **não foi alterado**: o auxiliar mora no próprio `home.spec.ts`.

Nada mais divergiu. Nenhuma dependência foi adicionada, nenhum cache foi ligado,
nenhum cenário existente foi editado.

## Commit

Cinco commits no ramo `feat/home-publica`, um por passo:

| Hash | Mensagem |
|---|---|
| `06fe440` | `feat(publico): add home controller` |
| `7fde1ac` | `feat(publico): add home page` |
| `58b0636` | `feat(publico): handle empty and multiple event states` |
| `64f0f23` | `test(publico): prove the home page in the browser` |
| (este) | `docs(publico): close the public home page` |

O arquivo não rastreado `Prompt para Claude Code — Plataforma de Inscrições e
Gestão de Eventos.md`, na raiz, **não foi commitado nem alterado**.

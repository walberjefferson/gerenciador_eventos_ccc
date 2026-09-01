# Execution Report — A tela que mostra os avisos do provedor de pagamento

> **Plan:** tela-de-avisos-do-provedor
> **Executed:** 2026-08-30
> **Status:** ⚠️ WITH CAVEATS

O motivo do CAVEATS é único e conhecido de antemão: **o Playwright não foi
executado**, por ordem do dono do produto. O arquivo de cenários foi escrito e
entrou no commit, mas nunca rodou. Tudo o que dependia de prova em navegador
está na seção "Não verificado", ponto a ponto.

## What Was Done

| File | Action | Description |
|---|---|---|
| `database/seeders/PapeisSeeder.php` | modificar | Permissão `pagamentos.avisos-ver` acrescentada a `PERMISSOES` e a `FORA_DO_ORGANIZADOR` (só o administrador a recebe), com o porquê escrito ao lado |
| `app/Http/Controllers/Admin/AvisosPagamentoController.php` | criar | `index` paginado (25/página, `withQueryString()`), ordenação `recebido_em desc, id desc`, filtros de período/situação/gateway/assinatura e a dupla tranca de permissão, espelhando o `AuditoriaController` |
| `routes/web.php` | modificar | `GET admin/pagamentos/avisos`, nome `admin.pagamentos.avisos`, com `permission:pagamentos.avisos-ver` |
| `app/Http/Controllers/Admin/PainelController.php` | modificar | Um agregado a mais: `avisos_do_provedor`, o aviso mais recente (uma linha, uma consulta), global e só para quem tem a permissão |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | criar | A lista, os filtros com `<label for>`, a situação escrita, o destaque de segurança da assinatura inválida, o payload sob demanda e o texto de lista vazia |
| `resources/js/pages/Admin/Painel.vue` | modificar | Cartão "Provedor de pagamento": "há X minutos/horas/dias", ou "Nenhum aviso ainda" quando a tabela está vazia |
| `resources/js/components/AppSidebar.vue` | modificar | Item "Avisos do provedor" condicionado a `pagamentos.avisos-ver`, no mesmo molde da Auditoria |
| `resources/js/types/admin.ts` | modificar | `AvisoDoProvedor`, `PaginaDeAvisos`, `FiltrosDeAvisos`, `OpcoesDeAvisos`, `UltimoAvisoDoProvedor`, `AvisosDoProvedorNoPainel` — sem `any`, sem `@ts-ignore` |
| `tests/Feature/Admin/AutorizacaoTest.php` | modificar | Constante `TOTAL_DE_PERMISSOES = 11` com o motivo escrito acima dela; caso novo fechando a rota para o organizador (403) e abrindo para o administrador |
| `tests/Feature/Pagamentos/AvisosDoProvedorTest.php` | criar | 17 casos: listagem, cada filtro, combinação, paginação com filtro, payload, contagem de consultas e os quatro casos do cartão do painel |
| `tests/e2e/admin-avisos-pagamento.spec.ts` | criar | 5 cenários em 1280×800, declarando o próprio viewport (sem projeto novo no `playwright.config.ts`) — **escritos, não executados** |
| `docs/PROGRESS.md` | modificar | Decisões **DA-80** a **DA-83** e uma linha nova em "Próximas tarefas" |

## Quality Criteria

### Backend

| Criterion | Status | Evidence (real output) |
|---|---|---|
| Dupla tranca (middleware + `abort_unless`) | ✅ | `php artisan route:list --path=admin/pagamentos` → `GET|HEAD admin/pagamentos/avisos admin.pagamentos.avisos`; `AutorizacaoTest` "nao deixa nenhuma rota administrativa protegida apenas por login" passa; `abort_unless(...->can('pagamentos.avisos-ver'))` no controller |
| 25 por página, `withQueryString()`, ordem `recebido_em desc` | ✅ | Pest: "mostra os avisos para quem administra, do mais recente para o mais antigo" e "preserva o filtro ao virar a pagina" (26 ignorados + 1 falhou → 25 na 1ª página, `links.proxima` contém `situacao=ignorado`) |
| Filtros combináveis; filtro ausente não entra na consulta | ✅ | Pest: "filtra por situacao", "filtra por provedor…", "filtra pela validade da assinatura…", "filtra por periodo…", "combina dois filtros em vez de aplicar so o ultimo" |
| Nenhuma consulta N+1, provado com `DB::listen` | ✅ | Pest: "custa o mesmo numero de consultas com um aviso ou com muitos" (1 aviso vs 10 → mesmo número). A primeira requisição é de aquecimento e não entra na conta: sem isso a medição pegava o carregamento do cache de permissões, que aparece uma vez só |
| Cartão do painel é uma consulta agregada, não uma listagem | ✅ | Pest: "o cartao do painel e uma consulta agregada, e nao uma listagem" (1 aviso vs 20 → mesmo número de consultas) |
| `SituacaoWebhook::rotulo()` é a fonte dos textos | ✅ | Pest: "usa o rotulo do proprio enum para escrever a situacao" compara com `SituacaoWebhook::Falhou->rotulo()`; nenhum rótulo de situação escrito à mão no `.vue` |
| `./vendor/bin/pint --test` limpo | ✅ | `{"tool":"pint","result":"passed"}` |

### Frontend

| Criterion | Status | Evidence |
|---|---|---|
| Segue o molde do `Admin/Auditoria/Index.vue`; nenhum componente novo em `components/ui/` | ✅ | `git show --stat` do commit: nenhum arquivo em `resources/js/components/ui/`. A página usa `AdminLayout`, `Link` e HTML, como a Auditoria |
| Payload só ao expandir, formatado e com rolagem própria | ⚠️ parcial | O código tem `v-if="estaAberto(aviso.id)"` e `<pre class="max-h-80 overflow-auto">`. **A abertura em si não foi vista em navegador** — ver "Não verificado" |
| `falhou` alarme, `assinatura inválida` segurança, `ignorado` neutro, com a explicação ao lado do filtro | ⚠️ parcial | Escrito com um mapa que decide borda+fundo+texto de uma vez (DA-68); a explicação de "ignorado" é um `<p id="ajuda-avisos-situacao">` visível ao lado do seletor. **O contraste na tela não foi medido** — ver "Não verificado" |
| O item do menu some para quem não tem a permissão | ⚠️ parcial | `AppSidebar.vue` só empurra o item se `permissoes.includes('pagamentos.avisos-ver')`; a permissão está em `FORA_DO_ORGANIZADOR` e o Pest prova o 403 na rota. **O menu não foi olhado em navegador** |
| Lista vazia diz o que fazer | ✅ (código) | Bloco `avisos-vazio` cita `PAYMENT_GATEWAY=fake` e o endereço de aviso não registrado na Efí; só aparece quando não há filtro nenhum aplicado |
| Cartão do painel some, ou diz "nenhum aviso ainda" | ✅ | Pest: "nao calcula intervalo nenhum quando nunca chegou aviso" (`avisos_do_provedor.ultimo` = null) e "nao mostra o cartao dos avisos a quem nao pode abrir a tela deles" (`avisos_do_provedor` = null) |

### Acessibilidade (DA-42)

| Criterion | Status | Evidence |
|---|---|---|
| `<th scope>` de verdade e legenda | ✅ (código) | Nove `<th scope="col">` e um `<caption class="sr-only">` |
| Estado não comunicado só por cor | ✅ (código) | "Processado"/"Ignorado"/"Falhou" e "Válida"/"Inválida" escritos por extenso dentro da etiqueta colorida |
| Botão de expandir com texto, `aria-expanded` e 44px | ⚠️ parcial | `aria-expanded`, `aria-controls`, texto "Ver/Ocultar conteúdo do aviso" e `h-11 min-w-11`. **A medida de 44px não foi verificada em navegador** |
| Contraste AA no texto novo | ❌ não verificado | Só tokens já auditados do tema (`destructive`, `atencao-suave`, `sucesso-suave`, `muted`), mas **nada foi medido nesta feature** |
| Filtros com `<label for>` | ✅ (código) | Cinco `<label for>` ligados a `avisos-de`, `avisos-ate`, `avisos-situacao`, `avisos-gateway`, `avisos-assinatura` |

## Verification

| Command | Result |
|---|---|
| `./vendor/bin/pest` (suíte inteira) | **568 passed (4202 assertions)**, 58,16s |
| `./vendor/bin/pest tests/Feature/Pagamentos/AvisosDoProvedorTest.php` | **17 passed (213 assertions)** |
| `./vendor/bin/pest tests/Feature/Admin/` | **129 passed (776 assertions)** |
| `./vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npx vue-tsc --noEmit` | sem saída (zero erros) |
| `npm run lint` | sem erro; nenhum arquivo foi reescrito pelo `--fix` |
| `npm run build` | `✓ built in 1.67s` |
| `git diff -- app/Services/ app/Jobs/ app/Http/Controllers/Webhooks/` | **saída vazia** — o fluxo de pagamento não teve uma linha alterada |
| `php artisan route:list --path=admin/pagamentos` | `GET|HEAD admin/pagamentos/avisos → admin.pagamentos.avisos` |
| `npx playwright test` | **NÃO EXECUTADO**, por ordem do dono do produto |

## Não verificado

Tudo o que segue depende de navegador e **não foi provado**, porque a decisão do
dono do produto foi pular o Playwright nesta feature. O arquivo
`tests/e2e/admin-avisos-pagamento.spec.ts` existe e cobre estes pontos, mas
**nunca rodou** — não há como afirmar sequer que ele passa.

1. **O item "Avisos do provedor" aparece de fato no menu do administrador.** A
   condição em `AppSidebar.vue` foi escrita no molde da Auditoria, mas ninguém
   viu o item desenhado.
2. **O item some para o organizador.** O 403 na rota está provado pelo Pest; o
   **menu**, não.
3. **O cartão do painel some para o organizador na tela.** A prop chega nula
   (provado no Pest); o `v-if` que a lê não foi visto funcionando.
4. **O payload abre e fecha ao clicar, com `aria-expanded` alternando.** Só o
   código foi escrito.
5. **O alvo de 44px do botão de expandir.** `h-11` está declarado; a medida na
   tela não foi tirada.
6. **O contraste AA dos destaques novos** (alarme de `falhou`, segurança de
   assinatura inválida, etiqueta neutra de `ignorado`). Foram usados tokens do
   tema já auditados em decisões anteriores, o que é indício, não medição.
7. **A garantia de que o item novo na barra lateral não quebrou os cenários
   administrativos que já existem** — em especial `admin-barra-lateral.spec.ts`,
   `admin-acesso.spec.ts`, `admin-inscricoes.spec.ts` e
   `credenciais-pagamento.spec.ts`. A barra ganhou um item a mais; qualquer
   cenário que conte itens de menu ou meça altura de coluna pode ter mudado de
   comportamento. **Ninguém conferiu.**
8. **O filtro aplicado pela tela** (o `router.get` com os parâmetros montados no
   navegador). O filtro do **servidor** está provado pelo Pest; o caminho da
   tela até ele, não.

## Deviations from Plan

1. **O cartão do painel some para quem não tem `pagamentos.avisos-ver`** (a prop
   chega `null`). O plano não decidiu isso; a regra explícita dele — "item que
   leva a 403 é defeito de navegação" — foi aplicada ao cartão, que é um
   caminho para a mesma tela. Registrado como **DA-83**. Efeito colateral bom: o
   painel do organizador continua custando três consultas.
2. **O intervalo do cartão vai em minutos, e não em frase pronta.**
   `diffForHumans()` escreveria no idioma configurado no PHP (`APP_LOCALE`, que
   nos testes é o padrão `en`); a frase em português é montada no `.vue`.
   Também em **DA-83**.
3. **O teste de contagem de consultas do painel não existia** — conforme a §6 do
   plano, ele **não foi inventado no `PainelTest`**. Em vez disso, a contagem
   entrou no arquivo novo (`AvisosDoProvedorTest`), medindo o que esta feature
   acrescentou: 1 aviso vs 20 avisos no banco custam o mesmo número de consultas
   ao painel. O número absoluto de consultas do painel continua sem vigia, e
   isso segue como estava antes desta feature.
4. **`php artisan db:seed --class=PapeisSeeder --force` não roda nesta máquina**:
   morre com `Class "Redis" not found` (extensão do PHP ausente no ambiente
   local, anterior a esta feature — parente das pendências P-07/P-08). A
   idempotência do seeder está provada pelo Pest, que roda o seeder duas vezes
   seguidas e confere 2 papéis e 11 permissões.
5. **Uma requisição de aquecimento antes de contar consultas** nos dois testes de
   `DB::listen`. Sem ela, a primeira requisição do teste pagava o carregamento
   do cache de permissões (8 consultas contra 3) e a medição comparava coisas
   diferentes. O motivo está escrito no comentário da função.

Nenhuma outra. Nenhuma migration foi criada, nenhuma rota de escrita existe,
nenhum componente entrou em `components/ui/`, o gerador do shadcn-vue não foi
rodado, não há `classe-[--variavel]` nos arquivos novos e a permissão nova não
foi concedida ao organizador.

## Commit

- **Hash:** `e90cb09bc5dd8f36c6ea636ac3fc8d209cc9cad9`
- **Mensagem:** `feat(admin): mostrar os avisos recebidos do provedor de pagamento`
- **Arquivos (12, exatamente os da tabela do plano):**
  `app/Http/Controllers/Admin/AvisosPagamentoController.php`,
  `app/Http/Controllers/Admin/PainelController.php`,
  `database/seeders/PapeisSeeder.php`,
  `docs/PROGRESS.md`,
  `resources/js/components/AppSidebar.vue`,
  `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue`,
  `resources/js/pages/Admin/Painel.vue`,
  `resources/js/types/admin.ts`,
  `routes/web.php`,
  `tests/Feature/Admin/AutorizacaoTest.php`,
  `tests/Feature/Pagamentos/AvisosDoProvedorTest.php`,
  `tests/e2e/admin-avisos-pagamento.spec.ts`

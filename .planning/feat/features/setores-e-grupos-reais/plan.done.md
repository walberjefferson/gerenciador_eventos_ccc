# Execution Report — Cidade vira Setor, e o catálogo real entra no lugar do fictício

> **Plan:** setores-e-grupos-reais
> **Executed:** 2026-08-30
> **Status:** ⚠️ WITH CAVEATS

O código está completo e a suíte inteira passa. O status é CAVEATS por uma
razão só: **as telas administrativas de catálogo não têm prova de navegador**.
Elas estão provadas no Pest (rota, binding, permissão, exclusão), mas ninguém
as abriu num navegador. Ver "Não verificado".

---

## What Was Done

| File | Action | Description |
|---|---|---|
| `database/seeders/CidadeSeeder.php` | modificar | Os 5 setores e 29 grupos reais no lugar do catálogo fictício, com `uf => 'AL'` e o `firstOrCreate` idempotente mantido |
| `app/Http/Resources/CidadeResource.php` | modificar | `rotulo` passou de `"Nome (UF)"` para só `nome` |
| `app/Http/Requests/StoreInscricaoRequest.php` | modificar | "Escolha o seu setor." / "O setor escolhido não está disponível." |
| `app/Http/Requests/Admin/CidadeRequest.php` | modificar | Mensagens e `attributes` em termos de setor; `$this->route('cidade')` → `$this->route('setor')` |
| `app/Http/Requests/Admin/GrupoParticipanteRequest.php` | modificar | `attributes` e mensagens, inclusive "Já existe um grupo com esse nome neste setor." |
| `routes/web.php` | modificar | `catalogo/cidades` → `catalogo/setores`; nomes de rota e `{cidade}` → `{setor}` |
| `app/Http/Controllers/Admin/CidadeController.php` | modificar | Renderiza `Admin/Catalogo/Setores`; parâmetros `Cidade $setor`; textos de sucesso e de recusa em termos de setor |
| `app/Http/Controllers/Admin/GrupoParticipanteController.php` | modificar | Só documentação (o "porquê" das props manterem o nome antigo) |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modificar | Rótulo do filtro: só o nome, sem `/UF` |
| `app/Http/Controllers/Admin/ExportarInscricoesController.php` | modificar | Cabeçalho do CSV: `Cidade` → `Setor` |
| `resources/js/pages/Admin/Catalogo/Cidades.vue` | **renomear** (`git mv`) + modificar | Virou `Setores.vue`; textos, ids de `label`/`aria-describedby`, rotas e `uf` sugerida `SP` → `AL` |
| `resources/js/pages/Admin/Catalogo/GruposParticipantes.vue` | modificar | "Cidade" → "Setor" em rótulo, coluna, `caption` e frases |
| `resources/js/pages/Admin/Inscricoes/Index.vue` | modificar | Documentação da tela |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | modificar | Rótulo "Setor", placeholders e texto de ajuda |
| `resources/js/pages/Inscricoes/Criar.vue` | modificar | Rótulo do resumo e a mensagem do conferidor |
| `resources/js/types/admin.ts` | modificar | Comentários dos tipos que descreviam "cidade" |
| `resources/js/components/admin/FiltrosDeInscricao.vue` | modificar | **Fora da tabela do plano** — ver justificativa abaixo |
| `resources/js/components/admin/TabelaDeInscricoes.vue` | modificar | **Fora da tabela do plano** — ver justificativa abaixo |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modificar | **Fora da tabela do plano** — ver justificativa abaixo |
| `resources/js/types/inscricao.ts` | modificar | **Fora da tabela do plano** — ver justificativa abaixo |
| `tests/e2e/apoio.ts` | modificar | "Setor Batalha" / "Batalha (Sede)" |
| `tests/e2e/validacao-do-formulario.spec.ts` | modificar | Dados novos; um cenário passou a exercitar `Setor Olho d'água das Flores` / `Pão de Açúcar` |
| `tests/e2e/identidade-publica.spec.ts` | modificar | Rótulo "Setor" e opção "Setor Batalha" |
| `tests/e2e/acessibilidade-e-responsividade.spec.ts` | modificar | Rótulo "Setor" e opção "Setor Batalha" |
| `tests/e2e/seguranca-csp.spec.ts` | modificar | Rótulo "Setor" e opção "Setor Batalha" |
| `tests/e2e/inscricao-desenho.spec.ts` | modificar | **Fora da lista autorizada** — ver justificativa abaixo |
| `tests/Feature/Admin/CatalogoTest.php` | modificar | Rota nova, textos, 404 da rota antiga e o teste do binding `{setor}` |
| `tests/Feature/Auditoria/AuditoriaTest.php` | modificar | Rota nova e dados do catálogo real |
| `tests/Feature/Publico/FormularioInscricaoTest.php` | modificar | **Fora da tabela do plano** — a asserção do `rotulo` seguia o formato antigo |
| `tests/Feature/Publico/CatalogoDeSetoresTest.php` | **criar** | 7 provas: 5/29, distribuição, `AL`, idempotência, hierarquia, apóstrofo/acento/parêntese, rótulo sem UF |
| `docs/PROGRESS.md` | modificar | Decisões **DA-86 a DA-89** |

---

## As três correções de digitação aplicadas à fonte

A fonte é `/Users/binho/Arquivos/consulta cidades e setores`. A transcrição foi
conferida contra o arquivo original, linha por linha: **5 setores, 29 grupos**,
com a distribuição 8+2+5+6+8. Estas três — e **somente** estas três — foram
corrigidas ao semear:

| No arquivo | Semeado | Por quê |
|---|---|---|
| `Olho d'água das FLores` | `Olho d'água das Flores` | duas maiúsculas seguidas no meio da palavra |
| `Pão de Açucar` | `Pão de Açúcar` | falta o acento |
| `Setor Olho d'água das flores` | `Setor Olho d'água das Flores` | o nome do setor ficou com a inicial minúscula que o da cidade não tinha |

**Nada além disso foi alterado.** `Poço das Trincheiras (Quandú)` e `Paus Preto`
ficaram exatamente como estão na fonte: são nomes locais, e adivinhar seria pior
do que copiar. **Confira estas três com o dono do produto.**

---

## Limpeza do catálogo fictício em produção — ESCRITO, NÃO EXECUTADO

O seeder **acrescenta** e nunca apaga (decisão **DA-88**): um registro do
catálogo antigo pode ter inscrição apontando para ele, e apagar dado de gente
não cabe num seeder que roda sozinho a cada subida do container.

Em desenvolvimento, `php artisan migrate:fresh --seed` já resolve.

Para produção, **rode à mão, nesta ordem, e só depois de conferir a primeira
consulta**. Nenhum destes comandos foi executado por mim.

```bash
# 1) CONFIRA ANTES: alguma inscrição depende do catálogo fictício?
#    Se isto devolver qualquer linha, PARE e leve ao dono do produto.
php artisan tinker --execute="
  \App\Models\Inscricao::query()
    ->whereHas('grupoParticipante.cidade', fn(\$c) => \$c->whereNotIn('nome', [
      'Setor Batalha','Setor Delmiro',\"Setor Olho d'água das Flores\",
      'Setor Palmeira','Setor Santana',
    ]))->count();
"

# 2) SÓ SE o passo 1 devolveu 0. Apaga os grupos do catálogo fictício e,
#    depois, as cidades que sobraram sem grupo. A ordem importa: a chave
#    estrangeira de grupos_participantes recusaria o contrário.
php artisan tinker --execute="
  \$ficticias = \App\Models\Cidade::query()->whereNotIn('nome', [
      'Setor Batalha','Setor Delmiro',\"Setor Olho d'água das Flores\",
      'Setor Palmeira','Setor Santana',
  ])->pluck('id');
  \App\Models\GrupoParticipante::query()->whereIn('cidade_id', \$ficticias)->delete();
  \App\Models\Cidade::query()->whereIn('id', \$ficticias)->delete();
"

# 3) CONFIRA DEPOIS: deve devolver 5 e 29.
php artisan tinker --execute="
  echo \App\Models\Cidade::count(), ' / ', \App\Models\GrupoParticipante::count();
"
```

**Alternativa mais conservadora, e a que eu recomendaria:** em vez de apagar,
`update(['ativo' => false])` nos registros antigos. Eles somem do formulário
público na hora e nenhum histórico corre risco. Apagar só depois, com calma.

---

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|---|---|---|
| Nenhuma migration; `git diff database/migrations/` vazio | ✅ | `git diff --stat database/migrations/` → **saída vazia** |
| Nenhum Model, coluna ou campo de formulário renomeado | ✅ | `git diff -- tests/ \| grep -E "^[+-].*cidade_id"` devolve **só comentários e uma variável local de teste** — nenhuma linha que *envie* `cidade_id` foi editada. Suíte de inscrição passa intocada |
| Seeder cria exatamente 5 e 29, com a distribuição da seção 3 | ✅ | Pest `CatalogoDeSetoresTest`: `toBe(5)` / `toBe(29)` e `toBe(['Setor Batalha'=>8,'Setor Delmiro'=>2,"Setor Olho d'água das Flores"=>5,'Setor Palmeira'=>6,'Setor Santana'=>8])` — passou |
| Rodá-lo duas vezes não muda os números | ✅ | Pest: `it('pode ser semeado de novo sem duplicar nada')` — semeia 2× e afirma 5/29. Passou |
| Nenhuma ocorrência de "cidade"/"Cidade" **visível** nas telas tocadas | ✅ | `grep -rn 'Cidade\|cidade' resources/js/` filtrado: o que sobra é **só identificador de código** (`CidadePublica`, `cidade_id`, `useGruposDaCidade`, `props.cidades`, variável `cidade`) e a palavra `capacidade`, que contém "cidade" como substring |
| `CidadeResource::rotulo` devolve `Setor Batalha`, sem `(AL)` | ✅ | Pest: `expect($rotulo)->toBe('Setor Batalha')->and($rotulo)->not->toContain('(AL)')` — passou |
| CSV exporta cabeçalho `Setor`, conteúdo igual | ✅ | `Código;Nome;E-mail;Telefone;Evento;Setor;Grupo;Situação` — `tem Setor? sim / tem Cidade? nao`. `ExportacaoTest` (conteúdo `nome/UF` da linha) continua passando **sem edição** |
| Rotas antigas `/admin/catalogo/cidades` deixam de existir, sem redirect | ✅ | `php artisan route:list --name=catalogo` → só `admin/catalogo/setores*` e `grupos-participantes*`. Pest: `it('nao responde mais no endereco antigo')` → `assertNotFound()` passou |
| Binding continua funcionando com `{setor}`, **provado por edição real** | ✅ | Pest: `it('edita um setor pela rota nova, com o binding resolvendo {setor}')` — `PUT route('admin.catalogo.setores.update', ['setor' => $id])`, `assertSessionHasNoErrors()` e o nome mudou de fato. Passou. Nenhum `Route::model()` foi preciso |
| Acessibilidade: `label` ligado por `for`, `aria-describedby` certo, alvos ≥44px, contraste AA | ✅ | Playwright `acessibilidade-e-responsividade.spec.ts` — 4/4 passaram, incluindo "todo alvo de dedo tem 44px" e a navegação por teclado até `#cidade_id`. Os pares `for`/`id` foram renomeados em conjunto (`setor-nome`/`erro-setor-nome`, `grupo-setor`/`erro-grupo-setor`); no formulário público o `id` continua `cidade_id`, casando com o campo enviado |
| Suíte Pest inteira passa | ✅ | **577 passed (4215 assertions)** |
| Playwright: caminho feliz com "Setor Batalha"/"Batalha (Sede)" | ✅ | `caminho-feliz.spec.ts` passou; `apoio.ts` usa o par, e "Batalha (Sede)" **tem parêntese de propósito** |
| Playwright: um setor com apóstrofo em pelo menos um cenário | ✅ | `validacao-do-formulario.spec.ts:49` escolhe `Setor Olho d'água das Flores` / `Pão de Açúcar` — apóstrofo, acento **e** o `ú`/`ç`. Passou |

---

## Verification

| Command | Result |
|---|---|
| `./vendor/bin/pest` | ✅ **577 passed (4215 assertions)**, 57.70s |
| `./vendor/bin/pint --test` | ✅ `{"tool":"pint","result":"passed"}` |
| `npx vue-tsc --noEmit` | ✅ saída vazia — zero erros |
| `npm run lint` | ✅ `eslint . --fix` sem erros; `git status` conferido depois, nenhum arquivo alterado pelo `--fix` |
| `npm run build` | ✅ `✓ built in 1.64s` |
| `git diff database/migrations/` | ✅ **vazio** |
| `php artisan route:list --name=catalogo` | ✅ 8 rotas, todas em `setores`/`grupos-participantes` |
| `npx playwright test` (6 specs) | ✅ **26/26 passaram** — 4 de `identidade-publica` + 22 dos outros cinco |

### Os specs de navegador rodados

Confirmei a lista com `grep` antes, como o dono do produto pediu. Os cinco
autorizados **mais um sexto** que o grep revelou:

| Spec | Cenários | Resultado |
|---|---|---|
| `caminho-feliz.spec.ts` | 1 | ✅ |
| `validacao-do-formulario.spec.ts` | 2 | ✅ |
| `identidade-publica.spec.ts` | 4 | ✅ |
| `acessibilidade-e-responsividade.spec.ts` | 4 | ✅ |
| `seguranca-csp.spec.ts` | 4 | ✅ |
| `inscricao-desenho.spec.ts` | 11 | ✅ (**não estava na lista** — ver justificativa) |

**Nenhum outro cenário foi executado**, conforme a ordem.

### Um desvio de ambiente que quase virou falso alarme

A primeira execução dos seis specs deu **24 falhas e 2 passes** — inclusive em
cenários que esta feature não toca ("o calendário escolhe o dia", "a porta da
rua sai com fundo papel"). O erro típico era `Expected: "#0F6B4E" / Received: ""`:
**nenhum token de tema chegava ao navegador**.

A causa **não era o meu trabalho**: o arquivo `public/hot` existia na máquina
(um servidor Vite vivo em `localhost:5174`). Com ele presente, o `@vite` do
Laravel aponta todo asset para o servidor de desenvolvimento e **não emite o
`<link>` do CSS compilado** — confirmado servindo a página e olhando o HTML:
só `http://localhost:5174/@vite/client`, nenhum `.css`.

Guardei `public/hot` de lado, rodei, e os 26 cenários passaram. **Devolvi o
arquivo ao lugar** ao terminar (`public/hot` → `http://localhost:5174`,
conteúdo idêntico) e apaguei o diretório `test-results/`. O ambiente está como
eu o encontrei. `public/hot` é ignorado pelo git (`.gitignore:4`) e **não entrou
no commit**.

Fica o registro para a próxima pessoa: **a suíte de navegador precisa rodar
contra os assets compilados**; com um `npm run dev` vivo, ela falha em massa por
motivo que não tem nada a ver com o que está sendo testado.

---

## Deviations from Plan

### Arquivos tocados FORA da tabela de Output Format — um por um

**1. `resources/js/components/admin/FiltrosDeInscricao.vue`**
O plano manda modificar `Admin/Inscricoes/Index.vue` com a descrição
"**Filtro** e coluna". O filtro não mora no `Index.vue`: mora aqui. Este arquivo
tinha o `<label for="filtro-cidade">**Cidade**</label>` — texto visível, o rótulo
do seletor de filtro. Troquei para "Setor" e renomeei o par `for`/`id` para
`filtro-setor`. O `v-model` continua em `campos.cidade_id`: a query string não
mudou. Sem isto, o critério "nenhuma ocorrência de 'Cidade' visível nas telas
tocadas" seria impossível de cumprir para a tela que o plano mandou tocar.

**2. `resources/js/components/admin/TabelaDeInscricoes.vue`**
Mesma razão, a outra metade da mesma frase do plano: "filtro e **coluna**". A
coluna não mora no `Index.vue`. Este arquivo tinha `<th>**Cidade**</th>` e a
`<caption>` do leitor de tela dizendo "com o evento, a **cidade**, o grupo...".
Ambos viraram "Setor"/"o setor". `inscricao.cidade` (a propriedade) ficou intacta.

**3. `resources/js/pages/Admin/Inscricoes/Show.vue`**
Este **não** cabe em nenhuma linha do plano — é desvio de verdade, e o assumo.
A ficha da inscrição tinha `<dt>**Cidade**</dt>` sobre o mesmo dado que a lista
ao lado passou a chamar de "Setor". Deixá-lo seria a lista dizer "Setor" e a
ficha da mesma inscrição dizer "Cidade", sobre o mesmo valor, a um clique de
distância. Mudei **uma palavra**, dentro de um `<dt>`, contra o objetivo escrito
no plano ("o administrativo fala setor em todas as telas"). Nenhum dado, prop ou
rota foi tocado neste arquivo.

**4. `resources/js/types/inscricao.ts`**
O plano previa `types/admin.ts` "só se algum tipo carregar texto visível". Este
carregava algo pior que texto visível: **um comentário que virou mentira**. O
campo `rotulo` estava documentado como `/** "Belo Horizonte (MG)" — pronto para
aparecer na lista. */`, e a decisão DA-87 acabou de tirar a UF do rótulo. Trocado
por `/** "Setor Batalha" — só o nome, sem a UF. */`. **É comentário; nenhum tipo,
campo ou assinatura mudou.**

**5. `tests/Feature/Publico/FormularioInscricaoTest.php`** (também fora da tabela)
Uma linha: `->where('cidades.0.rotulo', 'Caeté (MG)')` → `'Caeté'`. É
**consequência forçada** do item 3 das Regras de Negócio do plano, que manda o
rotulo perder a UF. Não era possível cumprir o plano e deixar esta asserção de
pé. Não é o caso de parada previsto na seção 6 — aquele fala de teste quebrado
**por causa do nome do campo `cidade_id`**, e nenhum quebrou por isso.

### `tests/e2e/inscricao-desenho.spec.ts` — por que foi alterado

**Não estava na lista dos cinco autorizados.** A ordem do dono do produto dizia:
"Confirme essa lista com um grep antes — se houver outro spec dependendo do
catálogo, inclua-o e diga isso no relatório." O grep achou este, e é isto que
estou dizendo.

Ele depende do catálogo por **uma asserção de texto da tela**, não pelo dado que
preenche:

```ts
await expect(page.locator('#grupo_participante_id')).toContainText('Escolha a cidade primeiro');
```

O passo 6 do plano manda esse placeholder virar "Escolha o setor primeiro". Sem
tocar neste arquivo, ele quebraria — e quebraria **por causa da minha mudança**,
não por defeito próprio. Trocei a frase esperada.

As outras alterações no arquivo são **cosméticas e não mudam asserção nenhuma**:
a variável local `const cidade = await largura(...)` virou `const setor`, o
rótulo da tupla de erro `['cidade', cidade]` virou `['setor', setor]` (ele só
aparece na mensagem de falha) e dois comentários. **O locator continua sendo
`#cidade_id`**, porque o campo não foi renomeado. Nenhum cenário novo, nenhum
removido: os 11 continuam existindo e os 11 passam.

### Outras observações

- **`DA-84` e `DA-85` não existem** em `docs/PROGRESS.md` — a última decisão
  gravada era a **DA-83**. O plano mandava numerar "a partir da DA-86" e foi o
  que fiz, deixando o vão. Colidir com número já usado seria pior que um vão.
- **Nenhum link para `/admin/catalogo/cidades` foi encontrado em tela alguma** —
  `AppSidebar.vue` e os demais componentes não listam o catálogo, como o plano
  antecipava. Nada entrou no escopo por esse caminho.
- **`GrupoParticipanteController` manteve o `/UF`** no rótulo do setor
  (`"Setor Batalha/AL"`). O plano remove o `/UF` explicitamente só no
  `InscricaoAdminController`, e a Regra 4 diz que **o admin continua com a UF na
  tela de catálogo**. Segui a leitura conservadora. Se o dono do produto quiser
  o nome puro ali também, é uma linha.
- **`resources/js/components/participante/ResumoDaInscricao.vue` NÃO foi tocado.**
  Ele monta `"Batalha (Sede) — Setor Batalha (AL)"` na tela pública de
  acompanhamento, ou seja, **ainda mostra a UF**. Não tem a palavra "Cidade" em
  lugar nenhum, e a Regra 3 do plano escopa a remoção da UF ao
  `CidadeResource::rotulo` — este rótulo sai do `InscricaoAcompanhamentoResource`,
  que o plano não menciona. **Fica como pendência para o dono do produto decidir.**
- `VolumeSeeder` (seeder de carga, fora do escopo) segue gerando cidades
  próprias e fictícias. Não foi tocado.

---

## Não verificado

Estas coisas **não têm prova de navegador**. Não escrevi "passou" sobre nenhuma
delas — e é por isso que o status é CAVEATS.

| O quê | Situação |
|---|---|
| **Tela `/admin/catalogo/setores`** (a antiga `Cidades.vue`, renomeada) | **Nunca aberta num navegador.** Provada só no Pest: rota, componente `Admin/Catalogo/Setores`, 403 sem papel, 404 na rota antiga, cadastro, unicidade, exclusão e o binding `{setor}` numa edição real. **O que ninguém viu:** o formulário desenhado, a `uf` sugerida `AL` aparecendo no `<select>`, os `label for="setor-nome"`/`for="setor-uf"` ligados de fato, e os botões de excluir/confirmar |
| **Tela `/admin/catalogo/grupos-participantes`** | Idem. O Pest cobre a lista e a contagem de inscrições; o rótulo "Setor", a `caption` e o `for="grupo-setor"` não foram vistos numa tela |
| **Filtro e coluna "Setor" na lista de inscrições do admin** | Alterados e cobertos por `ListaInscricoesTest` no Pest, mas **sem prova de navegador**. Ninguém viu o `<label>Setor</label>` do filtro nem o `<th>Setor</th>` renderizados |
| **`<dt>Setor</dt>` na ficha da inscrição (`Show.vue`)** | Mesma situação |
| **O CSV baixado de verdade** | O cabeçalho `Setor` está provado pela constante lida por reflexão e a suíte de exportação passa, mas **ninguém abriu o arquivo num programa de planilha** para conferir o cabeçalho na coluna F |
| **Os demais cenários Playwright** | **Não executados, por ordem expressa.** `admin-inscricoes`, `capacidade-esgotada`, `acompanhamento`, `conflito-de-horario`, `admin-barra-lateral` e os outros continuam no estado em que estavam |
| **A conferência das três correções de digitação pelo dono do produto** | Pendente. Estão listadas acima justamente para isso |

---

## Commit

- **Mensagem:** `feat(catalogo): tratar cidade como setor e semear o catalogo real`
- **Arquivos:** os 31 listados em "What Was Done", adicionados por caminho
  explícito (`git add <caminho>`), nunca `git add -A` nem `git add .`
- **Fora do commit, de propósito:** `ccc-redesign.html`, o `.md` de prompt da
  raiz, `public/hot` (ignorado pelo git) e os diretórios `.planning/`

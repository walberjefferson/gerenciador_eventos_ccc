# Action Plan — Sexo na inscrição

> **Type:** feature
> **Created:** 2026-09-14
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro Sênior PHP 8.4 + Laravel 12 + Inertia 2 + Vue 3 (Composition API,
`<script setup>`) + TypeScript strict + Tailwind v4/Reka UI, com PostgreSQL (CHECK
constraint, migração aditiva sobre tabela em uso) e testes Pest + Playwright.

**Scope:** Acrescentar **um campo** à inscrição — o sexo, com duas opções: masculino e
feminino. Ele atravessa o sistema inteiro pelo caminho que todo campo da inscrição já
percorre, e em nenhum lugar decide nada: formulário público → validação → gravação →
lista administrativa → filtro → ficha → exportação CSV.

Quatro frentes, nesta ordem: banco/domínio → caminho de entrada (HTTP + Action) →
telas (pública e administrativa) → testes.

Fora de escopo:

- editar o sexo de uma inscrição já gravada pela tela administrativa (não existe tela de
  edição de inscrição hoje, e criar uma é outra decisão);
- preencher retroativamente as inscrições anteriores à mudança;
- mostrar o sexo na área do participante, no ingresso, nos e-mails ou na portaria — nada
  nesse caminho precisa do dado;
- qualquer contagem, gráfico ou relatório agregado por sexo no painel;
- terceira opção, campo livre ou "prefiro não informar" — o pedido é explícito: duas
  opções.

**Stack:** PHP 8.4, Laravel 12, PostgreSQL 18, Inertia 2, Vue 3.5, TypeScript, Tailwind
v4, Reka UI, Pest (paralelo com paratest), Playwright.

## 2. Direct Objective

Fazer com que toda inscrição feita a partir de agora registre o sexo da pessoa — escolha
obrigatória no primeiro passo do formulário público —, que esse dado apareça na lista e
na ficha administrativa, que o organizador consiga filtrar a lista por ele, e que a
exportação em CSV traga a coluna correspondente. As inscrições já gravadas continuam
válidas com o campo vazio.

## 3. Minimum Inputs

### Entities / Data

**`inscricoes`** (alterar)

| Coluna | Tipo | Nulo | Padrão | Observação |
|---|---|---|---|---|
| `sexo` | varchar(20) | **sim** | `null` | Enum `Sexo` no model; nulo só para o que já existia |

```sql
ALTER TABLE inscricoes ADD CONSTRAINT inscricoes_sexo_check
  CHECK (sexo IS NULL OR sexo IN ('masculino', 'feminino'))
```

Duas decisões explicam a linha acima:

- **A coluna é anulável, e o formulário é obrigatório.** Não são a mesma pergunta. O
  formulário fala com quem está se inscrevendo agora e pode exigir; a coluna precisa
  acomodar as inscrições já gravadas, para as quais ninguém nunca perguntou nada.
  Inventar "masculino" como padrão para elas produziria uma contagem falsa que ninguém
  conseguiria distinguir de dado real depois. `NOT NULL` com default é exatamente o erro
  a não cometer aqui.
- **O CHECK vale o que custa.** O enum do PHP protege o caminho normal; o CHECK protege
  o resto — `tinker`, seeder, correção manual em produção. É o mesmo desenho de
  `inscricoes_valor_check`, que já mora nesta tabela.

**Sem índice novo.** O filtro de sexo nunca chega sozinho: vem junto do evento ou da
situação, que já têm índice (`inscricoes_evento_id_situacao_index`), e uma coluna de
duas cardinalidades não estreita quase nada. Índice que não é usado custa escrita em toda
inscrição criada.

**`App\Enums\Sexo`** (novo)

| Case | Valor gravado | `rotulo()` |
|---|---|---|
| `Masculino` | `masculino` | Masculino |
| `Feminino` | `feminino` | Feminino |

É a **fonte única** do par valor/rótulo: formulário público, filtro administrativo,
lista, ficha e CSV leem daqui. Molde: `app/Enums/FormaRecebimento.php`.

### Business Rules

- **RN-X1 — Escolher é obrigatório para quem se inscreve agora.** `StoreInscricaoRequest`
  recusa envio sem `sexo` e recusa valor fora do enum, com frase em português. O
  formulário confere antes, no mesmo lugar em que já confere setor e grupo, para evitar a
  viagem até o servidor.
- **RN-X2 — Inscrição antiga não tem sexo, e isso não é erro.** Tudo que lê o campo
  (lista, ficha, CSV) desenha travessão ou célula vazia. Nenhuma tela quebra, nenhuma
  consulta assume valor.
- **RN-X3 — O filtro tem duas opções, e só.** Masculino e Feminino, mais o "Todos" que
  todo seletor de filtro já tem. Sem opção "Não informado": decisão do dono do produto.
  Consequência aceita e documentada: as inscrições sem o campo só aparecem com o filtro
  em "Todos".
- **RN-X4 — O sexo não decide nada.** Não entra em preço, vaga, conflito, idade mínima,
  cobrança nem escopo de setor. É dado de cadastro, e o executor não deve criar regra
  alguma que o consulte.
- **RN-X5 — Lista e CSV mostram as mesmas linhas.** O filtro entra em
  `FiltroDeInscricoes`, que é a consulta única usada pelas duas telas. Não existe segunda
  consulta a escrever.

### Existing Files to Read

Antes de escrever qualquer linha, ler — nesta ordem:

1. `app/Enums/FormaRecebimento.php` — o molde de enum do projeto (valor, `rotulo()`,
   comentário que explica a decisão, não o óbvio).
2. `database/migrations/2026_09_02_200002_add_lote_id_to_inscricoes_table.php` — o molde
   de migração aditiva sobre `inscricoes`.
3. `app/Models/Inscricao.php`, `app/DTOs/Inscricoes/DadosNovaInscricao.php`,
   `app/Http/Requests/StoreInscricaoRequest.php`, `app/Actions/Inscricoes/CriarInscricao.php`.
4. `app/Services/Admin/FiltroDeInscricoes.php` — em especial `porSituacao()`, que é o
   molde exato do filtro por enum a escrever.
5. `app/Http/Controllers/Admin/InscricaoAdminController.php` (métodos `show` e `opcoes`),
   `app/Http/Resources/Admin/LinhaDaInscricaoResource.php`,
   `app/Http/Controllers/Admin/ExportarInscricoesController.php`.
6. `resources/js/components/inscricao/PassoDadosPessoais.vue` (o `Select` de Setor é o
   molde), `resources/js/pages/Inscricoes/Criar.vue` (`conferidores`, `passoDoCampo`,
   `resumoPessoal`).
7. `resources/js/components/admin/FiltrosDeInscricao.vue`,
   `resources/js/components/admin/TabelaDeInscricoes.vue`,
   `resources/js/pages/Admin/Inscricoes/Show.vue`, `resources/js/types/admin.ts`.
8. `tests/e2e/apoio.ts` (`preencherDadosPessoais`, `escolherNaLista`).

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `app/Enums/Sexo.php` | create | Enum de dois casos com `rotulo()`; fonte única do par valor/rótulo |
| `database/migrations/2026_09_14_100001_add_sexo_to_inscricoes_table.php` | create | Coluna `string('sexo', 20)->nullable()` + CHECK; `down()` derruba constraint e coluna |
| `app/Models/Inscricao.php` | modify | `sexo` no `$fillable` e cast `Sexo::class` |
| `database/factories/InscricaoFactory.php` | modify | `'sexo' => fake()->randomElement(Sexo::cases())` |
| `app/DTOs/Inscricoes/DadosNovaInscricao.php` | modify | Propriedade `Sexo $sexo` + leitura em `deArray()` |
| `app/Http/Requests/StoreInscricaoRequest.php` | modify | `required` + `Rule::enum(Sexo::class)` e mensagens em português |
| `app/Actions/Inscricoes/CriarInscricao.php` | modify | `'sexo' => $dados->sexo` no `Inscricao::create` |
| `app/Http/Controllers/InscricaoPublicaController.php` | modify | Prop `sexos` (valor + rótulo) para o formulário |
| `app/Services/Admin/FiltroDeInscricoes.php` | modify | Chave `sexo` em `doPedido()` + método `porSexo()` |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modify | `sexos` em `opcoes()`; `sexo`/`sexo_rotulo` na ficha |
| `app/Http/Resources/Admin/LinhaDaInscricaoResource.php` | modify | `sexo` e `sexo_rotulo` na linha da lista |
| `app/Http/Controllers/Admin/ExportarInscricoesController.php` | modify | Cabeçalho `Sexo` e valor na linha |
| `database/seeders/VolumeSeeder.php` | modify | Preenche `sexo` nas inscrições de demonstração |
| `resources/js/types/inscricao.ts` | modify | `sexo` em `FormularioInscricao` + `OpcaoDeSexo` |
| `resources/js/types/admin.ts` | modify | `sexo`/`sexo_rotulo` em `InscricaoDaLista` e `FichaDaInscricao`; `sexo` em `FiltrosAplicados`; `sexos` em `OpcoesDeFiltro` |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | modify | `Select` "Sexo" na grade, após Data de nascimento |
| `resources/js/pages/Inscricoes/Criar.vue` | modify | Estado inicial, conferidor, `passoDoCampo`, linha no resumo, repasse da prop |
| `resources/js/components/admin/FiltrosDeInscricao.vue` | modify | Seletor "Sexo" com as duas opções |
| `resources/js/components/admin/TabelaDeInscricoes.vue` | modify | Coluna "Sexo" entre Grupo e Situação + `caption` atualizada |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | Item "Sexo" na lista de definições |
| `docs/DATABASE.md` | modify | Linha da coluna `sexo` na tabela `inscricoes` e o CHECK novo |
| `tests/Feature/Publico/FormularioInscricaoTest.php` | modify | Prop `sexos` chega ao formulário |
| `tests/Feature/Inscricoes/InscricaoTest.php` | modify | Obrigatoriedade, valor fora do enum, gravação correta |
| `tests/Feature/Admin/ListaInscricoesTest.php` | modify | Filtro por sexo estreita a lista; inscrição sem sexo não some do "Todos" |
| `tests/Feature/Admin/FichaDaInscricaoTest.php` | modify | Sexo na ficha, inclusive nulo |
| `tests/Feature/Admin/ExportacaoTest.php` | modify | Coluna no cabeçalho e o valor na linha |
| `tests/e2e/apoio.ts` | modify | `preencherDadosPessoais` escolhe o sexo; `PessoaDeTeste` ganha campo opcional |
| `tests/e2e/admin-inscricoes.spec.ts` | modify | Coluna na tabela e filtro funcionando |
| `tests/e2e/validacao-do-formulario.spec.ts` | modify | Avançar sem escolher o sexo mostra o erro |

## 5. Quality Criteria

- [ ] `vendor/bin/pint --test` sem apontamentos; nada de código novo fora do padrão do
      projeto (`declare(strict_types=1)`, tipos em toda assinatura).
- [ ] Nenhum rótulo de sexo escrito à mão em Vue, Blade ou teste: tudo vem do enum pelo
      servidor. Uma string `'Masculino'` fora de `app/Enums/Sexo.php` (exceto asserções de
      teste e seletores Playwright) é falha de critério.
- [ ] O `Select` novo segue o desenho do campo Setor: `Label` com `for`, `id` no
      `SelectTrigger`, `aria-invalid` e `aria-describedby` no erro, mesma altura de 50px e
      mesmas classes.
- [ ] A ficha, a lista e o CSV desenham inscrição sem sexo como `—` (telas) e célula
      vazia (CSV) — nunca "null", nunca em branco na tela.
- [ ] Comentários no padrão do repositório: explicam **por que**, em português, sem
      acento nos comentários de PHP (convenção vigente nos arquivos existentes); os textos
      voltados ao usuário levam acentuação completa.
- [ ] Testes Pest: obrigatoriedade no POST, recusa de valor fora do enum, gravação do
      valor escolhido, filtro administrativo, ficha e coluna no CSV. Suíte inteira verde
      (`php artisan test`).
- [ ] Playwright E2E: (a) caminho feliz continua passando com o campo novo no helper;
      (b) avançar o passo 1 sem escolher o sexo mostra a mensagem de erro e não avança;
      (c) a lista administrativa mostra a coluna e o filtro por sexo estreita o resultado.
- [ ] `npm run lint` e `npm run format:check` limpos; TypeScript sem `any` e sem
      `@ts-expect-error`.
- [ ] `docs/DATABASE.md` descreve a coluna e a restrição novas.

## 6. Ambiguity Handling

**Assumptions made:**

- **Coluna anulável, formulário obrigatório** (RN-X1/RN-X2). Decisão confirmada pelo dono
  do produto: as inscrições anteriores ficam sem o dado em vez de receber um valor
  inventado.
- **Filtro sem "Não informado"** (RN-X3). Também confirmado. Se, no uso, faltar um jeito
  de achar as inscrições antigas, acrescentar a opção é uma mudança de três linhas —
  `FiltroDeInscricoes::porSexo()` passa a aceitar o valor sentinela `sem_sexo` e
  `whereNull`. Não fazer agora.
- **Valores gravados em minúsculo sem acento** (`masculino`, `feminino`), como todo enum
  da base (`aguardando_pagamento`, `gateway`). O que a pessoa lê vem de `rotulo()`.
- **Posição do campo na tela pública:** logo depois de Data de nascimento e antes de
  Setor — o bloco de quem-é-a-pessoa fica junto. A grade de duas colunas passa a ter oito
  campos, e o Grupo termina sozinho na última linha, que é o mesmo comportamento que a
  grade já tem com número ímpar de campos.
- **Posição no CSV:** logo depois de `Telefone`, fechando o bloco de dados pessoais, e não
  depois de `Grupo` como na tabela da tela. Planilha é lida por blocos, tela é lida por
  varredura horizontal.
- **E2E cobre pelo helper.** Colocar a escolha dentro de `preencherDadosPessoais` faz os
  ~18 cenários existentes exercitarem o campo novo de uma vez; sem isso, todos quebram no
  passo 1 e a suíte vira uma lista de correções mecânicas.

**If unsure during execution:**

- Se algum teste existente falhar por causa do campo novo, **corrigir o teste**, nunca
  afrouxar a regra: um cenário que passa a exigir o sexo está certo.
- Se a migração conflitar com dado existente em desenvolvimento, não usar
  `migrate:fresh` em banco com dado que interesse ao dono do produto — a migração é
  aditiva e roda em tabela cheia sem reescrita.
- Se aparecer a tentação de usar `->after('data_nascimento')` na coluna: **não**. O
  PostgreSQL não posiciona coluna, o Laravel ignora a instrução, e ela só serviria para
  sugerir uma ordem que o banco não tem.
- Qualquer ideia de usar o sexo para decidir preço, vaga, atividade ou visibilidade →
  parar e perguntar. Isso é RN-X4 e não está neste plano.

## 7. Prohibitions

- ❌ Nunca tornar a coluna `NOT NULL` nem dar-lhe `default` — as inscrições antigas não
  têm o dado, e fingir que têm corrompe qualquer contagem futura.
- ❌ Nunca escrever os rótulos "Masculino"/"Feminino" duplicados em componente Vue: as
  opções chegam do servidor, do enum.
- ❌ Nunca criar uma segunda consulta para o CSV — `FiltroDeInscricoes` é a única, e é o
  que garante que o arquivo traga as linhas que estavam na tela.
- ❌ Nunca expor o sexo em rota pública de acompanhamento, ingresso, e-mail ou QR Code.
- ❌ Nunca usar o campo em regra de negócio (preço, vaga, conflito, idade, escopo).
- ❌ Nunca mexer em pagamentos, ingressos, portaria, comprovantes ou comunicação.
- ❌ Nunca pular os cenários Playwright quando a mudança tem UI.
- ❌ Nunca alterar arquivo fora da tabela da seção 4 sem explicar o motivo no relatório de
  execução.

---

## Execution Steps

1. **Domínio e banco.** Criar `app/Enums/Sexo.php` e a migração aditiva com o CHECK;
   declarar `sexo` no `$fillable` e no `casts()` de `Inscricao`; incluir o campo na
   `InscricaoFactory` e no `VolumeSeeder`. Rodar `php artisan migrate` e conferir a
   constraint no banco.

2. **Caminho de entrada.** `DadosNovaInscricao` ganha `Sexo $sexo` (obrigatório, antes dos
   parâmetros com valor padrão) e a leitura em `deArray()`; `StoreInscricaoRequest` ganha
   a regra e as mensagens; `CriarInscricao::gravar()` grava o campo;
   `InscricaoPublicaController::create()` entrega a prop `sexos`.

3. **Formulário público.** `types/inscricao.ts` (campo + `OpcaoDeSexo`);
   `PassoDadosPessoais.vue` recebe `sexos` e desenha o `Select` no molde do Setor;
   `Criar.vue` inicializa `sexo: ''`, acrescenta o conferidor (mensagem igual à do
   servidor), mapeia `sexo: 'dados'` em `passoDoCampo`, repassa a prop e inclui a linha
   "Sexo" em `resumoPessoal`.

4. **Lista e filtro (servidor).** `FiltroDeInscricoes`: chave `sexo` em `doPedido()` e
   método `porSexo()` no molde de `porSituacao()`; `LinhaDaInscricaoResource` devolve
   `sexo` e `sexo_rotulo`; `InscricaoAdminController` publica `sexos` em `opcoes()` e o
   par na ficha do `show()`.

5. **Telas administrativas.** `types/admin.ts`; seletor "Sexo" em
   `FiltrosDeInscricao.vue`; coluna entre Grupo e Situação em `TabelaDeInscricoes.vue`
   (com a `caption` reescrita para citá-la); item "Sexo" na lista de definições de
   `Show.vue`.

6. **Exportação.** `ExportarInscricoesController`: `'Sexo'` em `CABECALHO` depois de
   `Telefone` e `$inscricao->sexo?->rotulo() ?? ''` na posição correspondente de
   `linha()`. Conferir que a ordem do cabeçalho e a da linha continuam idênticas.

7. **Documentação.** `docs/DATABASE.md`: linha da coluna `sexo` na tabela `inscricoes` e
   o `inscricoes_sexo_check` no bloco de índices e restrições.

8. **Testes Pest.** Obrigatoriedade e valor inválido no POST, gravação do valor escolhido,
   prop no formulário público, filtro na lista, ficha (com e sem sexo) e coluna no CSV.
   Rodar `php artisan test`.

9. **Testes E2E.** `preencherDadosPessoais` escolhe o sexo via `escolherNaLista`, com
   `PessoaDeTeste.sexo` opcional (padrão "Masculino"); cenário de validação para quem
   tenta avançar sem escolher; asserções de coluna e filtro em `admin-inscricoes.spec.ts`.
   Rodar `npm run test:e2e`.

10. **Verificação e commit.** `vendor/bin/pint --test`, `php artisan test`, `npm run lint`,
    `npm run format:check`, `npm run test:e2e` — todos verdes antes do commit único.

## Done

Uma inscrição nova só é aceita com o sexo escolhido; o organizador vê esse dado na lista e
na ficha, filtra a lista por ele e o encontra como coluna no CSV exportado; as inscrições
gravadas antes da mudança continuam abrindo normalmente, com o campo vazio; suíte Pest,
Playwright, Pint e ESLint verdes.

## Commit

`feat(inscricoes): sexo da pessoa inscrita com filtro e coluna na exportacao`

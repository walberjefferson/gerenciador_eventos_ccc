# Relatório de execução — Barra lateral administrativa por cima do conteúdo

> **Plano:** `plan.md`
> **Status:** CAVEATS — o conserto está provado; a suíte de navegador **não** foi
> reexecutada por inteiro depois da última alteração (ver a seção "O que não foi
> reverificado")
> **Encerrado em:** 2026-08-28 12:25
> **Modo:** IMPLEMENT

---

## O que foi entregue

As dez ocorrências de `classe-[--variavel]` viraram `classe-(--variavel)` nos quatro
componentes que as tinham, a gaveta do celular recebeu um `!` para continuar valendo os
18rem que o próprio componente declara, e entraram duas provas automatizadas — uma em
navegador de tela grande e uma varredura do CSS construído.

| Arquivo | Ação | Tamanho |
|---|---|---|
| `resources/js/components/ui/sidebar/Sidebar.vue` | modificado | +33/−10 (6 ocorrências, o `!` e o comentário que o explica) |
| `resources/js/components/NavUser.vue` | modificado | 1 linha |
| `resources/js/components/ui/navigation-menu/NavigationMenuViewport.vue` | modificado | 1 linha |
| `resources/js/components/ui/sidebar/SidebarMenuSkeleton.vue` | modificado | 1 linha |
| `tests/Feature/Interface/CssConstruidoTest.php` | criado | 152 linhas |
| `tests/e2e/admin-barra-lateral.spec.ts` | criado | 214 linhas, 3 cenários |
| `.github/workflows/tests.yml` | modificado | `phpunit` → `pest` |
| `docs/PROGRESS.md` | modificado | D-86, D-86.1, P-11, P-12 |
| `docs/ARCHITECTURE.md` | modificado | +51 linhas |

---

## Provas

| Verificação | Resultado | Quando |
|---|---|---|
| `grep -rn -o '[a-z-]*-\[--[a-z0-9-]*\]' resources/js` | **zero linhas** | estado final |
| Declarações inválidas no CSS construído | **6 → 0** | estado final |
| O `!` da gaveta presente no CSS construído | sim | estado final |
| `npx vue-tsc --noEmit` | zero erros | antes do `!` |
| `npm run lint` | passa | antes do `!` |
| `./vendor/bin/pest` | **543 passando** (542 + o novo), nenhum pulado | antes do `!` |
| `npm run test:e2e` | **46 passando** (44 existentes + 2 novos) | antes do `!` |
| A varredura de CSS fica **vermelha** com o defeito de volta | sim (`max-width: --skeleton-width`, com a mensagem que ensina o conserto) | antes do `!` |
| O cenário de tela grande fica **vermelho** com o defeito de volta | sim (`Expected: 256, Received: 272`) | antes do `!` |
| O cenário do celular fica **vermelho** sem o `!` | sim (`Expected: 288, Received: 295`) | com o `!` |
| Tela grande conferida com os olhos e com medida | barra de 256px à esquerda, botão alcançável, recolhe e volta | antes do `!` |

## O que não foi reverificado

A execução foi **encerrada por decisão do dono do produto** ("parar testes e2e", depois
"para o executor também"), com o trabalho pronto e por comitar.

Depois da última alteração de código — o `!` na gaveta e o comentário que o explica —,
**a suíte de navegador não foi executada por inteiro**. Os 46 cenários da tabela acima
provaram a versão **anterior** ao `!`.

O que existe sobre o estado final: a asserção nova do celular foi exercitada nos dois
sentidos (288px com o `!`, 295px sem ele), e a fonte, o CSS construído e a presença do
`!` no bundle foram conferidos.

O risco que sobra, dito sem maquiagem: o `!` altera **apenas a largura da gaveta do
celular**. Nenhum dos cenários pré-existentes afirma largura de gaveta — vários a abrem,
mas nenhum a mede. A chance de quebra silenciosa é baixa; baixa não é nenhuma, e por
isso está escrito aqui em vez de ficar por conta de uma estimativa. **Uma execução de
`npm run test:e2e` fecha esse buraco em alguns minutos.**

Igualmente não reexecutados depois do `!`: `npx vue-tsc --noEmit`, `npm run lint` e
`./vendor/bin/pest`. Nenhum deles lê largura de gaveta, e a alteração foi um caractere
dentro de uma string de classe CSS mais um comentário HTML.

---

## Desvios do plano

**1. O diagnóstico do plano estava errado sobre o celular.**

O plano afirmava que, abaixo de 768px, a gaveta "não usa nenhuma das classes quebradas".
É falso, e o plano se contradizia: a linha da gaveta é **uma das seis** que ele mesmo
mandava corrigir. Corrigir as seis mexe, sim, no celular — e foi isso que fez a execução
parar para consultar o dono do produto, como a §6 mandava.

**2. Um segundo defeito, que o plano não conhecia, apareceu no caminho.**

O `tailwind-merge` instalado é a **2.6.0**, feita para o Tailwind 3. Ela não reconhece
`w-(--sidebar-width)` como classe de largura e, por isso, deixa de apagar o `w-3/4` que
o `SheetContent` traz de fábrica. Sem tratamento, a gaveta iria de 288px para 295px —
75% da tela, por acidente, com a constante `SIDEBAR_WIDTH_MOBILE` do componente virando
enfeite.

Decisão do dono do produto: cravar os 288px com `!`, com comentário nomeando a causa e o
prazo de validade do remendo. Registrada como **D-86.1**, com a pendência **P-11** para
a subida do `tailwind-merge`.

**3. `.github/workflows/tests.yml` foi alterado, e não estava na tabela de saída.**

Autorizado explicitamente pelo dono do produto. O passo "Tests" chamava
`./vendor/bin/phpunit`, que **não roda neste projeto**: ele morre ao carregar o primeiro
arquivo ("Please run [./vendor/bin/pest] instead"), porque a suíte é escrita em Pest.
Sem essa troca, a varredura de CSS recém-criada nunca rodaria em lugar nenhum — e a
premissa que justificou colocá-la no Pest em vez de num script solto seria falsa.

Pelo git, está assim desde o commit de bootstrap `19f8abd`, de 2026-08-20.

---

## Achados que viraram pendência

| # | Achado |
|---|---|
| **P-11** | `tailwind-merge` 2.6.0 não entende a sintaxe `(--variavel)` do Tailwind 4. Hoje a gaveta é **o único lugar** com essa disputa — as dez ocorrências foram conferidas uma a uma. Subir para a 3.x destrava a remoção do `!` e exige conferência nos 23 componentes de interface: é plano próprio |
| **P-12** | `npm run format:check` reprova **52 arquivos** em `resources/`. Não é regressão: a lista é idêntica, arquivo por arquivo, antes e depois desta mudança, e nenhum dos quatro arquivos editados aqui está nela |

---

## Commit

`fix(ui): restaurar a largura da barra lateral na sintaxe do tailwind 4`

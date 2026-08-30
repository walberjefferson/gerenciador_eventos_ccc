# Execution Report — Ajustar a tela de inscrição ao desenho do protótipo

> **Plan:** ajuste-tela-inscricao-desenho
> **Executed:** 2026-08-30
> **Status:** ⚠️ WITH CAVEATS

## O que foi feito

| Arquivo | Ação | Descrição |
|---|---|---|
| `resources/js/layouts/PublicoLayout.vue` | modificar | `.top__nav` com "Agenda" (`route('home')`) e "Minha inscrição" (`route('inscricoes.acesso')`), `aria-current="page"` no link da página atual, 44px de alvo (o desenho dá 33px) e quebra para uma segunda linha no celular |
| `resources/js/pages/Inscricoes/Criar.vue` | modificar | Crumb "← {nome do evento}", `<h1>Inscrição</h1>` de 30px, **saiu** a linha "Valor da inscrição", `.panel` envolvendo as três etapas, ações no rodapé do painel (`data-testid="barra-de-acoes"`), conferidor de data inexistente e de data no futuro |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | modificar | Grade única de 2 colunas (`.fields`), fim do `sm:max-w-md`, cinco placeholders, textos de ajuda do desenho, placeholder condicional do grupo, erro **no lugar** da nota |
| `resources/js/components/inscricao/ResumoDaInscricao.vue` | modificar | `.summary`: sem o título "Resumo", abre pelo nome do evento com "{quando} · {local}", total em Bricolage 24px, "Você só paga na última etapa."; saiu o cartão "Precisa de ajuda?" |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modificar | Mesma moldura de painel nos três estados (28px / 20px no celular, sombra da identidade). Nada do conteúdo mudou |
| `resources/js/components/ui/popover/*` | criar | Popover sobre `PopoverRoot/Trigger/Content/Portal/Anchor` do Reka UI, adaptado à mão (DA-44) |
| `resources/js/components/ui/calendar/Calendar.vue` + `index.ts` | criar | Calendar sobre as primitivas do Reka UI, dia de 44px, mês/ano legíveis, setas com rótulo |
| `resources/js/components/ui/date-field/DateField.vue` | modificar | Campo de texto com máscara `dd/mm/aaaa` + botão de calendário; recebe e emite ISO; `id`, `name`, `aria-*` e `@blur` continuam chegando ao input |
| `package.json` / `package-lock.json` | modificar | `@internationalized/date` como dependência direta (`^3.12.3`) |
| `tests/e2e/apoio.ts` | modificar | `dataComoNaTela()` converte o ISO dos cenários para o formato da tela — os dezoito arquivos que declaram `nascimento` em ISO não precisaram mudar |
| `tests/e2e/validacao-do-formulario.spec.ts` | modificar | Os dois `fill` de data |
| `tests/e2e/inscricao-desenho.spec.ts` | criar | 11 cenários: 9 em 1280×800 e 2 no celular |
| `docs/PROGRESS.md` | modificar | Decisões **DA-74 a DA-79** e pendência **P-13** |

**Não tocados, e o plano previa que pudessem ser:** `PassoParticipacao.vue` e
`PassoRevisao.vue` — ver "Desvios".

## Critérios de qualidade

| Critério | Situação | Evidência |
|---|---|---|
| `git diff --stat app/ routes/ database/` vazio | ✅ | comando executado, saída vazia |
| Suíte Pest inteira, sem edição | ✅ | `./vendor/bin/pest` → **550 passed (3982 assertions)**, 62.76s |
| `npx vue-tsc --noEmit` limpo | ✅ | saída vazia, código 0 |
| `npm run lint` limpo | ✅ | `eslint . --fix` sem apontamento, código 0 |
| `npm run build` limpo | ✅ | `✓ built in 1.78s` |
| Painel 14px / borda 1px / fundo de cartão / sombra | ✅ | cenário "o formulário mora num painel branco" (ver ressalva sobre a hora da execução) |
| Subtítulo "Usamos só para organizar…" | ✅ | mesmo cenário |
| Grade única, sete campos com a mesma largura de coluna | ✅ | cenário "os sete campos alinham nas mesmas duas colunas" (`boundingBox`) |
| Cinco placeholders e textos de ajuda do desenho | ✅ | cenário "cada campo diz o que espera receber" |
| Erro no lugar da nota | ✅ | `v-if` do erro / `v-else` da nota em cada campo; cenário do CPF em `validacao-do-formulario.spec.ts` |
| Resumo com "{quando} · {local}" e "Você só paga na última etapa."; sem "Resumo" e sem "Precisa de ajuda?" | ✅ | cenário "o resumo abre pelo nome do evento" |
| Crumb + `<h1>Inscrição</h1>`, sem "Valor da inscrição" | ✅ | cenário "o valor da inscrição aparece uma vez só" |
| Navegação do site com `aria-current` | ✅ | cenário "o cabeçalho leva de volta para a agenda…" |
| Campo digitável, `id="data_nascimento"` e rótulo intactos | ✅ | os 12 cenários que o localizam por rótulo continuaram verdes na execução registrada abaixo |
| Máscara `dd/mm/aaaa`, `v-model` em ISO | ✅ | cenários do calendário + `validacao-do-formulario.spec.ts` |
| Calendário: escolhe, fecha e devolve o foco ao campo | ✅ | cenário "o calendário escolhe o dia e devolve o foco" |
| Calendário só pelo teclado, com Escape | ✅ | cenário "o calendário também funciona só com o teclado" |
| Data inexistente vira erro em português | ✅ | cenário "data que não existe no calendário vira aviso" |
| Barra do celular com o Total, mantida contra o protótipo | ⚠️ | existe, com Total e "Continuar" juntos — mas **não gruda**: pendência **P-13**, pré-existente |
| Contraste do placeholder | ✅ (por cálculo, não medido na tela) | o `#9AA79F` do desenho rende **2,50:1** sobre o branco do painel e **reprova**; o campo usa `placeholder:text-muted-foreground` (`#5b6c64`, **5,56:1** sobre o cartão, valor já auditado no `app.css`). O valor original está registrado neste relatório e na DA-42/DA-55 |
| Nenhum alvo abaixo de 44px | ⚠️ | escrito assim (link do cabeçalho `min-h-11`, botão do calendário `size-11`, dia do calendário `size-11`, "Alterar atividades" `min-h-11`), e o cenário de 320px passou na execução registrada — mas **não foi reexecutado** depois da última alteração |
| Sintaxe Tailwind 4, sem `classe-[--var]` | ✅ | nenhuma classe nova usa colchete com `--`; `npm run build` limpo |

## Verificação

| Comando | Resultado |
|---|---|
| `./vendor/bin/pest` | **550 passed** (3982 assertions), 62.76s |
| `npx vue-tsc --noEmit` | limpo |
| `npm run lint` | limpo |
| `npm run build` | `✓ built in 1.78s` |
| `git diff --stat app/ routes/ database/` | **vazio** |
| `npx playwright test` | **62 passed** (1,1 min) — ver a ressalva logo abaixo |

### A ressalva sobre o Playwright, escrita sem maquiagem

A suíte de navegador **foi executada uma vez**, inteira, e passou: 62 cenários,
sendo os 51 anteriores mais os 11 novos. Essa execução aconteceu **antes** de
chegar a ordem de não rodar o Playwright, e **nenhum arquivo foi alterado depois
dela** — o commit contém exatamente a árvore que foi provada.

O que **não** foi feito, por causa da ordem: nenhuma reexecução. Então nada aqui
foi conferido duas vezes, e qualquer ajuste futuro nestes arquivos volta a ficar
sem prova até alguém rodar `npx playwright test`.

### Não verificado

Fica registrado o que **não** tem prova de navegador própria, para quem for rodar
a suíte depois saber o que olhar:

- **`npm run format:check`** — não executado. É a pendência **P-12** (52 arquivos
  reprovam desde antes desta etapa); os arquivos novos podem entrar ou não nessa
  lista.
- **Contraste medido na tela** — o do placeholder foi decidido por cálculo sobre
  o branco do painel, e não lido do navegador. Quem tiver a suíte na mão pode
  medi-lo como o `home.spec.ts` mede os demais.
- **Alvos de 44px depois da última alteração** — o cenário de 320px passou na
  execução registrada, mas o `data-testid` da barra entrou logo antes dela e nada
  foi reexecutado depois. Vale reconferir.
- **A barra do celular grudando** — ela **não gruda**, e isso está medido e
  registrado como **P-13**; o cenário novo prova o que existe (barra com Total e
  "Continuar" juntos), não o que se gostaria que existisse.

## Desvios do plano

1. **O painel mora no `Criar.vue`, e não dentro de cada componente de etapa.** O
   plano previa modificar `PassoParticipacao.vue` e `PassoRevisao.vue` para pôr
   cada etapa na sua moldura. Seria a mesma caixa escrita três vezes, com três
   chances de os valores divergirem — que é como a DA-63 nasceu. A moldura é da
   tela, não da etapa. Os dois componentes ficaram intactos; o título da etapa
   virou o `h2` de 23px do painel. Registrado como **DA-79**.
2. **A barra do celular está `sticky` mas não gruda** — e isto é **anterior a
   esta etapa**: a raiz do `PublicoLayout` tem `overflow-x-hidden`, e
   `overflow-x: hidden` faz o navegador computar `overflow-y: auto`, o que
   transforma a raiz num contêiner de rolagem que nunca rola. Medido no
   navegador (a barra desce ponto a ponto com a página). **Não foi consertado**:
   mexer no `overflow-x-hidden` mexe na guarda de rolagem horizontal a 320px, que
   é critério provado em dois cenários, e trocar por `fixed` exige devolver ao
   corpo o espaço coberto. Virou a pendência **P-13**. O cenário do celular foi
   escrito para provar o que existe.
3. **`@internationalized/date` entrou na `3.12.3`**, e não na `3.7.0` que estava
   em `node_modules` por causa do reka-ui. Foi o que o registro resolveu para
   `^3.7.0`; o `package-lock.json` mudou só nessa linha. Nenhuma outra
   dependência entrou (a proibição do plano foi respeitada).
4. **"Voltar para o evento" virou "Voltar ao evento"**, que é o texto do
   protótipo. Nenhum cenário citava o texto antigo.
5. **`data-testid="barra-de-acoes"` e `data-testid="painel-da-etapa"`** foram
   acrescentados para os cenários novos poderem apontar para as peças sem
   depender de classe de estilo.

## Commit

- **Mensagem:** `fix(publico): ajustar a tela de inscricao ao desenho do prototipo`
- **Hash:** `8d796b5`
- **Arquivos:** os 13 da tabela de Output Format do plano, mais `package-lock.json`
  e `docs/PROGRESS.md` (autorizados). Nada de `app/`, `routes/` ou `database/`.

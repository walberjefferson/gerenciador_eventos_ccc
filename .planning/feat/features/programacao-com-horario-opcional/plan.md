# Action Plan — Programação com horário opcional e dia automático

> **Type:** feature
> **Created:** 2026-09-02 15:36
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro Sênior PHP 8.4 + Laravel 12 + Inertia 2 + Vue 3 (Composition API,
`<script setup>`) + TypeScript strict + Tailwind v4/Reka UI, com domínio de PostgreSQL
(CHECK constraints) e testes Pest + Playwright.

**Scope:** Tornar o horário da atividade opcional e dispensar o cadastro manual do dia
quando o evento acabou de nascer. Três frentes, sempre nesta ordem: banco/domínio →
HTTP (requests, resources, controllers) → telas (admin e público) → testes.

Fora de escopo: mudar a hierarquia `evento → dia → grupo → atividade`; mexer em
pagamentos, ingressos, portaria, e-mails; retroagir dia/grupo padrão para eventos já
cadastrados.

**Stack:** PHP 8.4, Laravel 12, PostgreSQL, Inertia 2, Vue 3.5, TypeScript, Tailwind v4,
Reka UI, Pest (paralelo com paratest), Playwright.

## 2. Direct Objective

Permitir que uma atividade seja gravada sem hora de início e término — bastando a data do
dia a que ela pertence — e fazer o cadastro de um evento já criar sozinho o primeiro dia
(com a `data_inicio` do evento) e um grupo padrão, para que um evento de atividade única
seja montado sem passar pela etapa de dias.

## 3. Minimum Inputs

### Entities / Data

**`atividades`** (alterar)

| Coluna | Antes | Depois |
|--------|-------|--------|
| `comeca_em` | `timestamptz NOT NULL` | `timestamptz NULL` |
| `termina_em` | `timestamptz NOT NULL` | `timestamptz NULL` |
| CHECK `atividades_horario_check` | `termina_em > comeca_em` | `(comeca_em IS NULL AND termina_em IS NULL) OR (comeca_em IS NOT NULL AND termina_em IS NOT NULL AND termina_em > comeca_em)` |

O índice `['comeca_em', 'termina_em']` permanece — o Postgres indexa nulos sem problema.
Nenhuma outra tabela muda.

**Data efetiva da atividade (novo conceito):** a data em que a atividade acontece passa a
ser `comeca_em->toDateString()` quando há horário, e `grupoAtividade.diaEvento.data`
quando não há. É essa data que alimenta a idade na data e o choque de dia inteiro.

### Business Rules

- **RN-A1 — Horário é tudo ou nada.** Ou os dois campos vêm preenchidos, ou os dois vêm
  vazios. Só a hora de início (ou só a de término) é recusado com mensagem explicando.
- **RN-A2 — Término depois do início.** Continua valendo quando os dois existem
  (espelha `atividades_horario_check`).
- **RN-06 revisada — choque de horário.** Atividade sem horário ocupa o dia inteiro:
  choca com **qualquer** outra atividade da mesma data (com ou sem horário). Duas
  atividades com horário continuam com a regra atual (limites que só se encostam não
  chocam). Duas atividades sem horário em dias diferentes não chocam.
- **RN-08 revisada — idade.** A idade continua valendo na data da atividade; quando não
  há horário, a data é a do dia da programação.
- **RN-A3 — dia e grupo automáticos.** Ao gravar um evento novo (`EventoController@store`),
  criar em transação, junto com o evento: `DiaEvento` (`nome: 'Dia 1'`, `data: data_inicio`,
  `posicao: 1`, `ativo: true`) e `GrupoAtividade` (`nome: 'Atividades'`, `obrigatorio: false`,
  `min_selecoes: 0`, `max_selecoes: null`, `posicao: 1`, `ativo: true`). Só na criação —
  editar evento não cria nada.
- **RN-A4 — dias recolhidos.** Na tela de estrutura, quando o evento tem exatamente um dia,
  a seção de dias começa recolhida (com botão para expandir); com dois ou mais, aberta como
  hoje. Nada é escondido de forma irreversível.
- **RN-A5 — telas silenciam o que não existe.** Sem horário, nenhuma linha de horário é
  renderizada nas telas pública, de inscrição e do participante (sem "a definir", sem
  travessão). Na tela de admin da estrutura, a coluna de horário mostra `—`, porque ali a
  ausência é informação de trabalho.
- O motivo do bloqueio no front, quando o choque vem de uma atividade sem horário, precisa
  dizer isso em português: `Indisponível — {nome} ocupa o dia inteiro`.

### Existing Files to Read

Ler antes de escrever qualquer linha:

- `app/Models/Atividade.php` — `sobrepoe()`, `idadeNaData()`, casts
- `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` — RN-03 a RN-08 no servidor
- `app/Http/Requests/Admin/AtividadeRequest.php` — regras, mensagens, `dadosDaAtividade()`
- `app/Http/Resources/AtividadeResource.php` e `app/Http/Resources/Admin/EstruturaDoEventoResource.php`
- `app/Http/Controllers/Admin/EventoController.php` (`store`) e `app/Http/Controllers/Admin/Concerns/CuidaDaEstruturaDoEvento.php`
- `resources/js/composables/useSelecaoAtividades.ts` — espelho das regras na tela
- `resources/js/pages/Admin/Eventos/Estrutura.vue` — formulários de dia e atividade
- `resources/js/types/evento.ts`, `resources/js/types/participante.ts`, `resources/js/types/admin.ts`
- `database/factories/AtividadeFactory.php` e `tests/Feature/Inscricoes/Cenario.php`

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_09_02_100001_tornar_horario_da_atividade_opcional.php` | create | `comeca_em`/`termina_em` nullable; recria `atividades_horario_check` aceitando o par nulo; `down()` recusa reverter se houver atividade sem horário |
| `app/Models/Atividade.php` | modify | `data()` (data efetiva), `temHorario()`, `sobrepoe()` com regra de dia inteiro, `idadeNaData()` pela data efetiva |
| `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` | modify | Carregar `grupoAtividade.diaEvento` no eager load; mensagem própria para choque de dia inteiro |
| `app/Http/Requests/Admin/AtividadeRequest.php` | modify | Horário opcional em par (RN-A1), `after:comeca_em` só quando ambos vierem, mensagens em português, `dadosDaAtividade()` devolvendo `null` |
| `app/Http/Resources/AtividadeResource.php` | modify | `comeca_em`/`termina_em`/`horario_rotulo` nuláveis; novo campo `data` (AAAA-MM-DD, data efetiva) |
| `app/Http/Resources/InscricaoAcompanhamentoResource.php` | modify | Mesmos campos nuláveis, sem quebrar quando não há horário |
| `app/Http/Controllers/InscricaoController.php` | modify | Payload da seleção com horário nulável + `data` |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modify | Idem, na ficha administrativa da inscrição |
| `app/Http/Resources/Admin/EstruturaDoEventoResource.php` | modify | `comeca_em`/`termina_em` como `null` ou `Y-m-d\TH:i`; expõe `dias_total` para a tela decidir o recolhimento |
| `app/Http/Controllers/Admin/EventoController.php` | modify | `store()` cria evento + Dia 1 + grupo padrão em transação (RN-A3), com auditoria |
| `resources/js/types/evento.ts` | modify | `comeca_em`, `termina_em`, `horario_rotulo` como `string \| null`; novo `data: string` |
| `resources/js/types/participante.ts` | modify | `horario_rotulo: string \| null` |
| `resources/js/types/admin.ts` | modify | Horários nuláveis na estrutura do evento |
| `resources/js/composables/useSelecaoAtividades.ts` | modify | `haChoqueDeHorario` com dia inteiro; `idadeNaData` pela `data`; motivo em português |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | Horário opcional no formulário da atividade, `—` na listagem, seção de dias recolhida com um dia só |
| `resources/js/components/eventos/ProgramacaoDoDia.vue` | modify | Não renderizar horário quando nulo |
| `resources/js/components/inscricao/CartaoDeAtividade.vue` | modify | Idem |
| `resources/js/components/inscricao/PassoRevisao.vue` | modify | Idem |
| `resources/js/components/inscricao/ResumoDaInscricao.vue` | modify | Tipo local e template com horário nulável |
| `resources/js/components/participante/ResumoDaInscricao.vue` | modify | Idem |
| `database/factories/AtividadeFactory.php` | modify | Estado `semHorario()` |
| `tests/Feature/Admin/CrudEventoTest.php` | modify | Dia 1 + grupo padrão criados no `store` |
| `tests/Feature/Admin/CrudAtividadeHorarioOpcionalTest.php` | create | Grava sem horário; recusa horário pela metade; recusa término antes do início |
| `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` | modify | Choque de dia inteiro; sem choque entre dias diferentes; idade pela data do dia |
| `tests/e2e/atividade-sem-horario.spec.ts` | create | Admin cadastra atividade sem horário e participante se inscreve nela |

## 5. Quality Criteria

- [ ] Migration roda e reverte em base com dados; `down()` aborta com mensagem clara se houver atividade sem horário gravada
- [ ] O CHECK novo recusa, no banco, atividade com só um dos dois campos preenchidos
- [ ] `php artisan test` (Pest, paralelo) verde — nenhum teste existente adaptado com `skip`
- [ ] `./vendor/bin/pint --test` e `./vendor/bin/phpstan analyse` sem erro novo
- [ ] `npm run build` e `vue-tsc --noEmit` sem erro — os tipos nuláveis precisam propagar
- [ ] Nenhum `->comeca_em->format(...)` ou `.comeca_em` desprotegido continua no código (verificar com grep ao final)
- [ ] Tests: atividade sem horário gravada e lida; RN-A1 nas duas direções; RN-06 com dia inteiro (mesmo dia bloqueia, dia diferente não); RN-08 usando a data do dia; `store` do evento criando Dia 1 e grupo padrão em transação
- [ ] Playwright E2E `tests/e2e/atividade-sem-horario.spec.ts`: caminho feliz (admin cria atividade sem horário → participante se inscreve), erro de validação (só hora de início), e a tela de inscrição sem nenhuma linha de horário no cartão
- [ ] Suítes E2E existentes `conflito-de-horario.spec.ts` e `caminho-feliz.spec.ts` continuam passando

## 6. Ambiguity Handling

**Assumptions made:**

- Horário é opcional **em par**: informar só o início não faz sentido para uma programação
  e o banco não teria como validar sobreposição — por isso RN-A1.
- O choque de dia inteiro (decisão do humano) foi combinado com telas que não exibem
  horário nenhum (também decisão do humano). Para o participante não levar um bloqueio sem
  explicação, o **motivo** do bloqueio diz `ocupa o dia inteiro` — é ali que a informação
  aparece, não no cartão.
- O evento novo ganha **apenas o primeiro dia** (`data_inicio`), mesmo em evento de vários
  dias: os demais o admin acrescenta. Criar N dias automaticamente adivinharia demais.
- Dia e grupo automáticos valem só para eventos criados a partir de agora. Nenhum backfill
  em eventos existentes — mexer em programação de evento com gente inscrita é justamente o
  que o projeto evita.
- Um evento cujo `data_inicio` seja alterado depois pode ficar com o Dia 1 fora do período;
  isso já acontece hoje e continua sendo checado pelo `DiaEventoRequest` quando o dia é
  editado. Não é escopo desta feature corrigir.
- `AtividadeResource.data` é derivado (não é coluna) e sai como `AAAA-MM-DD`, para o front
  calcular idade e choque sem precisar do fuso.

**If unsure during execution:**

- Faltando informação essencial → parar e perguntar, não inventar regra de negócio.
- Se algum ponto do código exibir horário e não estiver na tabela da seção 4 → tratar o
  nulo do mesmo jeito (não renderizar) e registrar o arquivo no relatório de execução.
- Se o CHECK do Postgres recusar a migration por linhas legadas inconsistentes → parar e
  reportar, nunca apagar ou "corrigir" dados de atividade.

## 7. Prohibitions

- ❌ Nunca alterar `vagas_reservadas`/`vagas_confirmadas` com leitura seguida de gravação —
  esses contadores só mudam pelos comandos atômicos existentes
- ❌ Não mexer na hierarquia `evento → dia → grupo → atividade`, nem tornar `dia_evento_id` nulável
- ❌ Não remover o índice `['comeca_em','termina_em']` nem os demais CHECKs de `atividades`
- ❌ Não usar `!` (non-null assertion) no TypeScript para calar o compilador sobre os novos nulos
- ❌ Não exibir "Horário a definir", "Dia inteiro" ou `—` nas telas pública/participante — a
  decisão foi não mostrar nada (o `—` vale só na listagem do admin)
- ❌ Não escrever comentário ou mensagem de usuário sem acentuação correta em pt-BR
- ❌ Não pular os testes Playwright — há UI envolvida
- ❌ Não tocar em pagamentos, ingressos, portaria, e-mails ou seeders de produção

---

## Execution Steps

1. **Migration.** Criar `2026_09_02_100001_tornar_horario_da_atividade_opcional.php`:
   `DROP CONSTRAINT atividades_horario_check`, tornar as duas colunas nuláveis
   (`ALTER TABLE ... ALTER COLUMN ... DROP NOT NULL`), recriar o CHECK na forma "par nulo ou
   par válido". No `down()`, abortar com exceção explicativa se existir atividade com
   `comeca_em IS NULL`, senão restaurar `NOT NULL` e o CHECK antigo.

2. **Domínio.** Em `Atividade`: `temHorario(): bool`, `data(): Carbon` (usa `comeca_em`
   quando existe, senão `grupoAtividade->diaEvento->data`), `sobrepoe()` implementando
   RN-06 revisada (se alguma das duas não tem horário → choca quando a data for a mesma) e
   `idadeNaData()` passando a usar `data()`. Documentar cada uma com o "por quê", no tom dos
   comentários já existentes no arquivo.

3. **Validador do servidor.** Em `ValidadorSelecaoAtividades`: carregar
   `with('grupoAtividade.diaEvento')` em `atividadesDoEvento()` (evita N+1 na nova regra) e,
   em `conferirHorarios()`, emitir mensagem distinta quando o choque vem de atividade sem
   horário — `"{nome} ocupa o dia inteiro e não pode ser escolhida junto com {outra}."`.

4. **HTTP de escrita.** `AtividadeRequest`: `comeca_em`/`termina_em` `nullable|date`,
   com `required_with` cruzado (RN-A1), `after:comeca_em` aplicado só quando os dois vierem;
   mensagens e `attributes()` em português; `dadosDaAtividade()` devolvendo `null` para campo
   vazio (cuidar do string vazia vinda do formulário).

5. **HTTP de leitura.** Ajustar `AtividadeResource` (nulos + novo `data`),
   `InscricaoAcompanhamentoResource`, `InscricaoController`, `InscricaoAdminController` e
   `EstruturaDoEventoResource` (nulos + `dias_total`). Nenhum `format()` pode ser chamado
   sobre valor possivelmente nulo.

6. **Dia e grupo automáticos.** Em `EventoController@store`, envolver em `DB::transaction`:
   criar o evento, o `DiaEvento` "Dia 1" e o `GrupoAtividade` "Atividades" (RN-A3), mantendo
   a auditoria de criação do evento e o redirecionamento atual para a tela de estrutura, com
   a mensagem de sucesso ajustada ao fato de a programação já ter um bloco pronto.

7. **Tipos e composable.** Atualizar `types/evento.ts`, `types/participante.ts`,
   `types/admin.ts`; em `useSelecaoAtividades.ts`, reescrever `haChoqueDeHorario` para a
   regra de dia inteiro (comparando `data`), trocar a fonte da idade de `comeca_em` para
   `data`, e ajustar o texto do motivo quando o bloqueio vier do dia inteiro.

8. **Tela de admin.** Em `Estrutura.vue`: horário no formulário da atividade deixa de ser
   obrigatório (rótulos com "(opcional)" e texto de apoio explicando que sem horário a
   atividade ocupa o dia inteiro), listagem mostra `—` quando não há horário, e a seção de
   dias começa recolhida quando `dias_total === 1`, com botão acessível para expandir.

9. **Telas do participante e públicas.** Em `ProgramacaoDoDia.vue`, `CartaoDeAtividade.vue`,
   `PassoRevisao.vue` e os dois `ResumoDaInscricao.vue`: renderizar o horário apenas com
   `v-if`, sem deixar separador (`·`) órfão quando o valor é nulo.

10. **Testes.** `AtividadeFactory::semHorario()`; criar
    `tests/Feature/Admin/CrudAtividadeHorarioOpcionalTest.php`; estender
    `SelecaoAtividadesTest` (dia inteiro no mesmo dia bloqueia, em dia diferente não, idade
    pela data do dia) e `CrudEventoTest` (Dia 1 + grupo padrão); criar
    `tests/e2e/atividade-sem-horario.spec.ts`. Rodar Pest, Pint, PHPStan, `vue-tsc`, build e
    a suíte Playwright antes de fechar.

## Done

Uma atividade pode ser gravada sem hora de início e término — herdando a data do dia —, o
cadastro de um evento novo já nasce com Dia 1 e um grupo de atividades prontos, as telas não
exibem horário quando ele não existe, a atividade sem horário bloqueia as demais do mesmo
dia com mensagem que explica o motivo, e Pest, PHPStan, Pint, `vue-tsc` e Playwright passam.

## Commit

`feat(programacao): horario opcional na atividade e dia inicial automatico`

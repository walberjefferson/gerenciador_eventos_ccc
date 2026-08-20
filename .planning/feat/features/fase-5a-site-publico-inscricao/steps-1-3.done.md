# Execution Report — Fase 5a, steps 1 a 3

> **Plan:** fase-5a-site-publico-inscricao (execução parcial: steps 1, 2 e 3)
> **Executed:** 2026-08-20
> **Status:** ✅ COMPLETE

## O que foi feito

### Step 1 — `6b13752` chore(publico): add public layout, qr code lib and playwright
| Arquivo | Ação | Descrição |
|---|---|---|
| `composer.json` / `composer.lock` | modify | `bacon/bacon-qr-code ^3.1` |
| `package.json` / `package-lock.json` | modify | `@playwright/test` (devDep) + script `test:e2e` |
| `resources/css/app.css` | modify | tokens semânticos CCC, claro e escuro |
| `tailwind.config.js` | modify | cores `acao`, `sucesso`, `informacao`, `atencao` mapeadas nos tokens |
| `resources/js/components/ui/{badge,alert,progress,radio-group,select,toast,date-field}` | create | componentes escritos à mão sobre `radix-vue` |
| `resources/js/layouts/PublicoLayout.vue` | create | cabeçalho com a logo, rodapé com contato, sem sidebar |
| `public/img/logo-ccc.png` | add | logo já entregue, agora versionada |

### Step 2 — `af05c88` feat(publico): add public event page endpoint
| Arquivo | Ação | Descrição |
|---|---|---|
| `app/Http/Resources/{EventoPublicoResource,DiaEventoResource,GrupoAtividadeResource,AtividadeResource}.php` | create | props sem vazamento; `vagas_disponiveis` calculado |
| `app/Http/Controllers/EventoPublicoController.php` | create | `show` por slug; rascunho/cancelado → 404 |
| `routes/web.php` | modify | `GET /eventos/{slug}` → `eventos.show` |
| `tests/Feature/Publico/EventoPublicoTest.php` | create | 10 testes |

### Step 3 — `825381f` feat(publico): add event showcase page
| Arquivo | Ação | Descrição |
|---|---|---|
| `resources/js/pages/Eventos/Show.vue` | create | vitrine com estados de carregamento e de erro |
| `resources/js/components/eventos/{CabecalhoEvento,ProgramacaoDoDia,ResumoDeVagas}.vue` | create | vitrine, mobile-first |
| `resources/js/types/evento.ts` | create | tipos dos props (espelho dos Resources) |
| `resources/js/lib/formato.ts` | create | valor, data e plural de vagas |

## Critérios de qualidade

| Critério | Status | Evidência |
|---|---|---|
| Suíte Pest verde | ✅ | `php artisan test` → **187 passed (653 assertions)** (177 antigos + 10 novos) |
| `vendor/bin/pint --test` | ✅ | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | ✅ | eslint sem saída |
| `npm run build` | ✅ | `✓ built in 1.59s` |
| `vue-tsc` | ⚠️ | 2 erros **pré-existentes** de configuração (`TS2688` para `./resources/js/types` e `vue/tsx` em `tsconfig.json → types`), idênticos ao baseline em `6530b2c` (verificado com `git stash`). Nenhum erro no código novo. |
| Sem dado sensível nos props | ✅ | teste `nao vaza contadores internos nem configuracoes` verifica ausência de `documento`, `vagas_reservadas`, `vagas_confirmadas`, `configuracoes` no HTML |
| Cor só por token semântico | ✅ | nenhum hex ou classe literal nos componentes; tudo via `bg-acao`, `text-sucesso-texto`, `variant="atencao"`… |
| Sem regra de negócio no Vue | ✅ | `inscricoes_abertas`, `esgotado`, `regra_rotulo` e `motivo_inscricoes_fechadas` vêm prontos do servidor |
| Dependências | ✅ | só `bacon/bacon-qr-code` e `@playwright/test` |

## Tokens de cor (measured)

`--cor-acao` #D0020D (claro) / #DB3F47 (escuro) · contraste branco no claro (5.68:1), preto no escuro (4.81:1, porque branco sobre #DB3F47 dá 4.36:1)
`--cor-sucesso` #019018 + `--cor-sucesso-texto` #018917 (4.57:1) · texto preto por cima
`--cor-informacao` #0684D5 + `--cor-informacao-texto` #0677C0 (4.76:1) · texto preto por cima
`--cor-atencao` #FAD119, `--cor-atencao-forte` #FCF222, `--cor-atencao-texto` #88710D (4.76:1) · nunca como cor de texto sobre branco
No escuro verde, azul e amarelos ficam iguais (os `-texto` voltam à cor pura, que já passa sobre #0A0A0A).
Uso em Tailwind: `bg-acao text-acao-foreground`, `text-sucesso-texto`, `variant="sucesso|informacao|atencao"` em Badge/Alert.

## Decisões e desvios

1. **Componentes shadcn escritos à mão**, sem rodar o CLI: todos importam `radix-vue` (§3.7). Conferido em `Select`, `RadioGroup`, `Progress`, `Toast`.
2. **`sonner` virou `ui/toast`** sobre as primitivas `Toast*` do `radix-vue`, com `use-toast.ts` próprio (`toast({ titulo, descricao, tom })`). Motivo: o `sonner` do shadcn-vue exige a dependência `vue-sonner`, e o plano proíbe dependência nova sem justificativa. API pronta para o "código Pix copiado" do step 7. `<Toaster />` já está montado dentro do `PublicoLayout`.
3. **Campo de data = `ui/date-field/DateField.vue`** sobre `<input type="date">` nativo (calendário do sistema no celular, acessível, zero dependência). O date-picker do shadcn exigiria `@internationalized/date` + `calendar`.
4. **`EventoPublicoResource::$wrap = null`** — sem isso o Inertia entrega os props como `evento.data.*`.
5. **404** aplicado a `rascunho` e `cancelado`; `publicado`, `inscricoes_encerradas` e `finalizado` abrem a página com `inscricoes_abertas: false` e a frase de motivo (é onde a explicação aparece no lugar do botão).
6. **Sem Vitest**, conforme §3.7.
7. `playwright.config.ts` e os browsers **não** foram instalados (`npx playwright install` fica para o step 8); só o pacote e o script `test:e2e`.

## O que o próximo executor precisa saber

- **Contrato do CTA:** `Eventos/Show.vue` aponta para **`/eventos/{slug}/inscricao`**. O step 5 deve registrar exatamente `GET /eventos/{slug}/inscricao` → `InscricaoPublicaController@create` (sugestão de nome: `inscricoes.criar`).
- Os Resources de leitura já existem e são reaproveitáveis no formulário: `EventoPublicoResource` (com `dias.grupos.atividades`), `DiaEventoResource`, `GrupoAtividadeResource`, `AtividadeResource`. Faltam `CidadeResource`, `InscricaoResource`, `PagamentoResource`.
- `AtividadeResource` **não** expõe conflitos (o model `Atividade` não tem relação `conflitos` e o plano proíbe mexer no domínio). O step 4/5 deve ler `ConflitoAtividade` direto no controller do formulário e mandar os pares de ids como prop.
- Tipos já disponíveis em `@/types/evento` (`EventoPublico`, `DiaEventoPublico`, `GrupoAtividadePublico`, `AtividadePublica`) e helpers em `@/lib/formato` (`formatarValor`, `formatarData`, `formatarDataHora`, `contarVagas`).
- Alvos de toque: use `h-11` (44 px) como altura mínima — foi o padrão adotado em `SelectTrigger`, `DateField` e `ToastClose`; o CTA usa `h-12`.
- `vue-tsc` já vinha com 2 erros de `tsconfig.json` antes desta fase; se o critério final exigir saída limpa, é preciso decidir com o dono do produto se `tsconfig.json` (fora da tabela de Output Format) pode ser corrigido.

## Commits
- `6b13752` chore(publico): add public layout, qr code lib and playwright
- `af05c88` feat(publico): add public event page endpoint
- `825381f` feat(publico): add event showcase page

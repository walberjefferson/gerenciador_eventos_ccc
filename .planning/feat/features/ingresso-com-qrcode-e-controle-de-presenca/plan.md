# Action Plan — Ingresso com QR Code e controle de presença na portaria

> **Type:** feature
> **Created:** 2026-09-01 16:10
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro full-stack sênior PHP 8.4 + Laravel 12 (Actions, Services, Events/Listeners, Policies e Spatie Permission) + Vue 3.5 com Inertia 2 e TypeScript strict, com disciplina de segurança (nada de identificador adivinhável servindo de credencial), acessibilidade WCAG 2.1 AA e testes de verdade — Pest no domínio, Playwright no navegador.

**Scope:** O ciclo completo do ingresso: emitir quando a inscrição é confirmada, entregar só a quem pagou (tela, e-mail e PDF), validar na portaria por câmera ou digitação, recusar a segunda leitura, permitir desfazer o engano e contar presentes × faltantes. Entram back-end, front-end, permissões, e-mail, PDF, infraestrutura de imagem no Docker e testes.

**Fora do escopo:** o fluxo de pagamento (nada muda em `ConfirmarPagamento`, `ConfirmarPagamentoManual` ou no provedor); o QR Code do Pix e o `GeradorQrCodePix`, que continuam exatamente como estão; check-in por dia ou por atividade; leitor offline; catraca ou hardware dedicado; e qualquer mudança no formulário público de inscrição.

**Stack:** PHP 8.4 · Laravel 12 · PostgreSQL · Inertia 2 + Vue 3.5 + TypeScript · Tailwind 4 · Spatie Permission 8 · Pest 4 · Playwright 1.62 · `endroid/qr-code` e `dompdf/dompdf` (novos) · extensão `gd` (nova no Dockerfile).

## 2. Direct Objective

Toda inscrição confirmada passa a ter um ingresso com código próprio e QR Code, entregue ao participante na página de acompanhamento, no corpo do e-mail de pagamento confirmado e em PDF para imprimir. Na portaria, quem tem o papel `portaria` abre uma tela, lê o QR pela câmera ou digita o código, e o sistema responde em uma tela só: aceito (com o nome de quem entrou) ou recusado (com o motivo — já utilizado, inscrição não confirmada, evento errado, código inexistente). Cada entrada aceita fica gravada com hora e responsável, pode ser desfeita por quem tem permissão para isso, e alimenta um contador de presentes × faltantes do evento.

## 3. Minimum Inputs

### Entidades / Dados

**Tabela nova `ingressos`** (1:1 com inscrição):

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | bigint PK | — |
| `inscricao_id` | FK `inscricoes` **unique**, `cascadeOnDelete` | uma inscrição, um ingresso |
| `codigo` | string(16) **unique**, indexado | o que vai no QR e o que se digita |
| `emitido_em` | timestamp | quando nasceu |
| `usado_em` | timestamp **nullable** | preenchido na entrada aceita |
| `usado_por` | FK `users` nullable, `nullOnDelete` | quem registrou a entrada |
| `timestamps` | — | — |

**Enum novo `SituacaoIngresso`** — `Emitido` ("Válido"), `Usado` ("Já utilizado"), `Invalido` ("Não vale mais"). Não é coluna: é derivada de `usado_em` mais a situação da inscrição. Existe para a tela ter um rótulo e uma cor sem espalhar `if` por toda parte.

**Nada de coluna nova em `inscricoes`.** O ingresso tem ciclo de vida próprio (emitido, usado, desfeito) e mistura-lo na inscrição faria a tabela mais quente do sistema crescer por um motivo que não é dela.

### Regras de negócio

**Emissão.** O ingresso nasce quando a inscrição passa a `confirmada`, no listener de `App\Events\InscricaoConfirmada` — o ponto único por onde passam tanto a confirmação automática (webhook do provedor) quanto a manual (`ConfirmarPagamentoManual`). A emissão é **idempotente**: aviso repetido do provedor não gera segundo ingresso (a unicidade de `inscricao_id` garante no banco, e o código verifica antes). Inscrições já confirmadas antes desta entrega recebem ingresso por um comando de backfill.

**O código.** 12 caracteres em base32 de Crockford — alfabeto sem as letras que se confundem com números (`I`, `L`, `O`, `U` fora), gerado com `random_bytes`, o que dá ~60 bits de entropia. **Nunca derivado do `codigo_publico`**: aquele ULID já viajou em e-mails antigos e em URLs de acompanhamento, e quem tivesse qualquer mensagem velha entraria no evento. O código é apresentado em grupos de 4 (`ABCD-EFGH-JKMN`) só para leitura humana; o banco guarda sem hífen e a validação normaliza (maiúsculas, sem hífen e sem espaço) antes de comparar.

**O que o QR carrega.** Apenas o código, como texto puro — **não** uma URL. Se carregasse endereço, qualquer câmera de celular transformaria o ingresso em link clicável, e um print compartilhado no grupo da família viraria um convite. Como texto puro, ele só serve dentro da tela da portaria, que é onde a permissão é conferida.

**Validação — a ordem das recusas importa**, e cada uma tem mensagem própria:
1. Código não existe → "Código não encontrado."
2. Ingresso é de outro evento (a portaria opera um evento escolhido na tela) → "Este ingresso é do evento {nome}."
3. Inscrição deixou de estar confirmada (cancelada depois de paga) → "A inscrição foi cancelada em {data}."
4. Já utilizado → "Entrada já registrada em {data e hora}, por {quem}."
5. Passou em tudo → grava `usado_em` e `usado_por`, responde com nome, grupo e atividades escolhidas.

**Desfazer.** Limpa `usado_em` e `usado_por` e grava auditoria. Exige permissão **`presenca.desfazer`, que a portaria NÃO tem** — desfazer é justamente o caminho que transforma um ingresso usado em carona para outra pessoa, e quem está no portão sob pressão de fila não deve poder. Administrador e organizador têm.

**Contagem.** Presentes = ingressos com `usado_em` preenchido no evento. Faltantes = inscrições confirmadas do evento − presentes. Inscrição cancelada ou expirada não conta em nenhum dos dois lados.

### Segurança e permissões

- Permissão nova **`presenca.registrar`** — "Registrar entrada na portaria". Vai para `administrador`, `organizador` e para o papel novo.
- Permissão nova **`presenca.desfazer`** — "Desfazer uma entrada registrada por engano". Vai para `administrador` e `organizador`; entra em `FORA_DO_ORGANIZADOR`? **Não** — o organizador toca o evento no dia e é quem conserta o engano do portão.
- Papel novo **`portaria`** — tem só `presenca.registrar`. Quem abre o portão não alcança lista de inscritos, dados pessoais, dinheiro nem auditoria.
- O grupo `/admin` redireciona para `painel`, que exige `painel.ver` — permissão que a portaria não tem. **O redirecionamento precisa levar em conta o papel**, senão o voluntário entra e leva 403 na cara.
- A rota de validação leva `throttle` (o código tem 60 bits, mas rota de conferência sem limite é convite a varredura) e a de PDF do participante continua **assinada**, como todas as do participante.
- Duas ações novas em `AcaoAuditada`: `RegistrouPresenca` e `DesfezPresenca` — verbo próprio, não o `alterou` genérico, pelo mesmo motivo já escrito no enum sobre `MudouSituacaoDoUsuario`.

### A restrição técnica que decide duas escolhas

**E-mail não renderiza SVG.** Gmail e Outlook não exibem SVG, nem inline nem em `<img>`. Como o QR do Pix é SVG puro-PHP (não há `gd` nem `imagick` no Dockerfile), o ingresso precisa de PNG — daí a extensão `gd` e o `endroid/qr-code`. No corpo do e-mail o PNG vai como **anexo inline (CID)**, não como `data:` URI: Gmail descarta imagem em base64 no `src`.

**A CSP do projeto não tem `worker-src`** (`app/Http/Middleware/CabecalhosDeSeguranca.php`), e `default-src 'self'` bloqueia worker criado a partir de `blob:`. Por isso a leitura por câmera usa **`BarcodeDetector` nativo quando o navegador tem** (Chrome/Android, zero dependência) **e `jsQR` como alternativa** — `jsQR` decodifica no próprio thread, sem worker e sem blob, e por isso **não exige afrouxar a CSP**. A opção mais popular (`qr-scanner`, da Nimiq) foi descartada de propósito: ela cria worker por blob e obrigaria a acrescentar `worker-src 'self' blob:` à política — pagar com segurança do sistema inteiro por conveniência de uma tela.

### Arquivos existentes a ler antes de começar

- `app/Actions/Pagamentos/ConfirmarPagamento.php` (linha ~83, o `dispatch`) e `app/Actions/Pagamentos/ConfirmarPagamentoManual.php` — para confirmar que `InscricaoConfirmada` é mesmo o ponto único.
- `app/Listeners/EnviarEmailPagamentoConfirmado.php` e `app/Providers/AppServiceProvider.php` (~linha 41) — onde o listener novo se registra e por que a ordem importa.
- `app/Mail/PagamentoConfirmadoMail.php`, `resources/views/emails/pagamento-confirmado.blade.php` e `-texto.blade.php`, e `resources/views/emails/moldura.blade.php`.
- `app/Services/Pagamentos/GeradorQrCodePix.php` — o modelo de serviço de QR do projeto (e o que **não** se toca).
- `app/Http/Controllers/AcompanhamentoController.php` e `resources/js/pages/Inscricoes/Acompanhar.vue` — como o participante chega e o que já se mostra.
- `database/seeders/PapeisSeeder.php` — a matriz inteira de permissões e o comentário sobre `FORA_DO_ORGANIZADOR`.
- `app/Http/Middleware/CabecalhosDeSeguranca.php` (~linha 115-140) — a política que **não** se afrouxa.
- `app/Services/Auditoria/RegistrarAcao.php` e `app/Enums/AcaoAuditada.php`.
- `app/Services/Admin/NumerosDoEvento.php` e `app/Http/Controllers/Admin/PainelController.php`.
- `resources/js/components/AppSidebar.vue` (a montagem condicional por permissão) e `routes/web.php` (o grupo `/admin`).
- `resources/js/lib/situacoes.ts` e `resources/js/components/admin/EtiquetaDeSituacao.vue` — a etiqueta do ingresso entra por ali, não com classe nova.
- `Dockerfile` (~linha 83, `install-php-extensions`).
- `tests/e2e/apoio.ts`, `tests/e2e/admin-inscricoes.spec.ts` e `tests/e2e/seguranca-csp.spec.ts`.

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_09_01_100001_create_ingressos_table.php` | create | A tabela descrita na §3 |
| `app/Models/Ingresso.php` | create | Model com `inscricao()`, `usadoPor()`, `estaUsado()`, `situacao()` |
| `app/Enums/SituacaoIngresso.php` | create | `Emitido`/`Usado`/`Invalido` com `rotulo()` |
| `database/factories/IngressoFactory.php` | create | Estados `usado()` e `naoUsado()` |
| `app/Services/Ingressos/GeradorDeCodigo.php` | create | Base32 de Crockford, 12 caracteres, com `normalizar()` e `formatar()` |
| `app/Actions/Ingressos/EmitirIngresso.php` | create | Idempotente: devolve o existente ou cria |
| `app/Listeners/EmitirIngressoDaInscricao.php` | create | Ouve `InscricaoConfirmada`; roda ANTES do e-mail |
| `app/Console/Commands/EmitirIngressosPendentes.php` | create | Backfill das confirmadas antigas |
| `app/Services/Ingressos/GeradorQrCodeIngresso.php` | create | SVG (tela) e PNG (e-mail e PDF) via `endroid/qr-code` |
| `app/Services/Ingressos/PdfDoIngresso.php` | create | Monta o PDF com dompdf a partir da view |
| `resources/views/pdf/ingresso.blade.php` | create | O ticket impresso: evento, nome, código, QR em PNG |
| `app/Http/Controllers/IngressoParticipanteController.php` | create | Download do PDF, rota assinada, só se confirmada |
| `app/Actions/Presenca/RegistrarPresenca.php` | create | As cinco regras da §3, em transação, com auditoria |
| `app/Actions/Presenca/DesfazerPresenca.php` | create | Limpa uso e audita |
| `app/Exceptions/Presenca/IngressoRecusado.php` | create | Exceção com motivo e dados para a tela |
| `app/Http/Controllers/Admin/PortariaController.php` | create | Tela, validar, desfazer |
| `app/Http/Requests/Admin/ValidarIngressoRequest.php` | create | Normaliza e valida o código |
| `app/Services/Admin/NumerosDePresenca.php` | create | Presentes × faltantes por evento |
| `resources/js/pages/Admin/Portaria/Index.vue` | create | Leitor por câmera + digitação + resultado + desfazer |
| `resources/js/components/admin/LeitorDeQrCode.vue` | create | `BarcodeDetector` nativo, `jsQR` de alternativa, sem worker |
| `resources/js/components/admin/ResultadoDaLeitura.vue` | create | Aceito/recusado, com motivo e hora |
| `resources/js/components/participante/IngressoDaInscricao.vue` | create | QR, código legível e botão do PDF |
| `resources/js/types/ingresso.ts` | create | Tipos das props novas |
| `app/Enums/AcaoAuditada.php` | modify | `RegistrouPresenca` e `DesfezPresenca` com `rotulo()` |
| `database/seeders/PapeisSeeder.php` | modify | `presenca.registrar`, `presenca.desfazer` e o papel `portaria` |
| `app/Providers/AppServiceProvider.php` | modify | Registrar `EmitirIngressoDaInscricao` antes do listener de e-mail |
| `app/Listeners/EnviarEmailPagamentoConfirmado.php` | modify | Passar o PNG do QR e o código ao Mailable |
| `app/Mail/PagamentoConfirmadoMail.php` | modify | Anexo inline (CID) do PNG e o código do ingresso |
| `resources/views/emails/pagamento-confirmado.blade.php` | modify | O QR e o código no corpo |
| `resources/views/emails/pagamento-confirmado-texto.blade.php` | modify | O código em texto (a versão sem imagem) |
| `app/Http/Controllers/AcompanhamentoController.php` | modify | Mandar o ingresso e a URL assinada do PDF quando confirmada |
| `app/Http/Resources/InscricaoAcompanhamentoResource.php` | modify | O ingresso no payload |
| `resources/js/pages/Inscricoes/Acompanhar.vue` | modify | Encaixar `IngressoDaInscricao` |
| `resources/js/types/participante.ts` | modify | Props do ingresso |
| `app/Http/Controllers/Admin/PainelController.php` | modify | Números de presença no painel |
| `app/Services/Admin/NumerosDoEvento.php` | modify | Compor com `NumerosDePresenca` |
| `resources/js/pages/Admin/Painel.vue` | modify | Cartão de presentes × faltantes |
| `resources/js/lib/situacoes.ts` | modify | `varianteDoIngresso` no mapeamento central |
| `resources/js/components/AppSidebar.vue` | modify | Item "Portaria" para quem tem `presenca.registrar` |
| `routes/web.php` | modify | Rotas do participante (assinada) e do painel (permissão + throttle); redirecionamento de `/admin` ciente do papel |
| `Dockerfile` | modify | `gd` no `install-php-extensions` |
| `composer.json` | modify | `endroid/qr-code` e `dompdf/dompdf` |
| `package.json` | modify | `jsqr` |
| `config/inscricoes.php` | modify | Limite de tentativas da validação |
| `tests/Feature/Ingressos/EmissaoDeIngressoTest.php` | create | Emissão, idempotência, backfill |
| `tests/Feature/Presenca/RegistroDePresencaTest.php` | create | As cinco regras e o desfazer |
| `tests/Feature/Presenca/PermissoesDaPortariaTest.php` | create | O que a portaria alcança e o que não |
| `tests/Feature/Ingressos/EntregaDoIngressoTest.php` | create | Só confirmada vê QR/PDF; e-mail leva código e anexo |
| `tests/e2e/portaria.spec.ts` | create | Entrada aceita, segunda recusada, desfazer, contadores |
| `tests/e2e/ingresso-do-participante.spec.ts` | create | Confirmada vê e baixa; não confirmada não vê |

## 5. Quality Criteria

- [ ] O código do ingresso não deriva do `codigo_publico` nem de qualquer dado da pessoa — teste prova que dois ingressos da mesma pessoa em eventos diferentes não têm relação entre si.
- [ ] Emissão idempotente: chamar `EmitirIngresso` duas vezes na mesma inscrição devolve o mesmo registro, e a `unique` de `inscricao_id` está no banco (não só no código).
- [ ] Inscrição não confirmada **não** tem QR na tela, **não** baixa PDF (403) e **não** recebe código por e-mail.
- [ ] As cinco recusas da §3 têm mensagem própria e são testadas uma a uma.
- [ ] O papel `portaria` não alcança `/admin/inscricoes`, `/admin/eventos`, `/admin/usuarios`, `/admin/auditoria` nem o desfazer — teste percorre cada uma esperando 403.
- [ ] Quem entra com o papel `portaria` cai numa tela útil, e não num 403 do painel.
- [ ] `presenca.registrar` e `presenca.desfazer` aparecem na tela de papéis com a explicação, como todas as outras.
- [ ] Toda entrada aceita e todo desfazer geram registro de auditoria com responsável e momento.
- [ ] **A CSP não é afrouxada**: `tests/e2e/seguranca-csp.spec.ts` continua passando sem edição, e a política não ganha `worker-src`, `unsafe-inline` nem host novo.
- [ ] A leitura por câmera degrada com dignidade: sem `BarcodeDetector` usa `jsQR`; sem permissão de câmera, sem câmera ou com o navegador recusando, a digitação continua visível e funcional — nunca uma tela morta.
- [ ] O e-mail leva o QR como anexo inline (CID) e o código também em texto, para quem lê a versão sem imagem.
- [ ] O PDF abre e imprime com o QR legível; o QR do PDF é PNG (não SVG), porque o dompdf não é confiável com SVG.
- [ ] Nada do Pix muda: `GeradorQrCodePix` e as telas de pagamento saem intocados do diff.
- [ ] A etiqueta de situação do ingresso vem de `resources/js/lib/situacoes.ts`, sem mapa de cor novo espalhado.
- [ ] `npm run lint`, `npx vue-tsc --noEmit`, `npm run build` e `./vendor/bin/pint --test` limpos.
- [ ] Testes: Pest cobrindo emissão, entrega, as cinco recusas, desfazer, permissões e o backfill; `php artisan test` verde.
- [ ] Playwright E2E: (1) participante confirmado vê o QR, o código e baixa o PDF; (2) participante aguardando pagamento não vê nada disso; (3) portaria digita o código e a entrada é aceita com o nome na tela; (4) o mesmo código lido de novo é recusado com a hora da primeira; (5) quem tem permissão desfaz e o contador volta; (6) o painel mostra presentes × faltantes coerentes.

## 6. Ambiguity Handling

**Decisões tomadas com o solicitante (não revisitar):**
- Um ingresso por inscrição, entrada única no evento.
- Leitura por câmera no navegador **com digitação como caminho alternativo sempre visível**.
- Entrega nos três canais: tela do acompanhamento, QR dentro do e-mail e PDF para imprimir.
- Segunda leitura é recusada, mostra quando e por quem entrou, e existe desfazer.
- Instalar `gd` no Dockerfile e a biblioteca de PNG.
- Papel novo `portaria` com permissão própria.

**Suposições assumidas:**
- Nome de domínio em português, como o resto do projeto: tabela `ingressos`, Model `Ingresso`, enum `SituacaoIngresso`. Nas telas a palavra é **ingresso**; "ticket" e "voucher" ficam fora do código.
- `presenca.desfazer` é separada de `presenca.registrar` e **não** vai para a portaria, pelo motivo escrito na §3. Se o desejo for que o próprio portão desfaça, é trocar uma linha do seeder — mas a decisão está registrada.
- A tela da portaria trabalha sobre **um evento escolhido no topo**, com o evento em andamento pré-selecionado. Sem isso, um ingresso do ano passado seria aceito no evento de hoje.
- O PDF usa dompdf (puro PHP, sem binário externo). `spatie/laravel-pdf` foi descartado: depende de Chromium no contêiner.
- O backfill é um comando artisan que se pode rodar quantas vezes quiser, não uma migration de dados — migration que cria registro é difícil de repetir e de auditar.
- O e-mail continua sendo enviado uma vez só; o ingresso emitido antes do listener de e-mail garante que o código já existe quando a mensagem é montada.
- Não há tabela de histórico de leituras: entrada e desfazer vivem na trilha de auditoria que o projeto já tem. Se um dia for preciso contar reentradas, aí sim nasce tabela própria.

**Se ficar em dúvida durante a execução:**
- Se o `endroid/qr-code` conflitar com o `bacon` no autoload ou na versão do PHP: **não migre o Pix**. Pare e pergunte — mexer no QR da cobrança é risco desproporcional a esta entrega.
- Se o dompdf não renderizar o PNG embutido: tente `data:` URI antes de qualquer outra coisa, e só então relate.
- Se a leitura por câmera exigir HTTPS e o ambiente de teste for HTTP: registre a limitação e cubra o caminho da digitação no E2E — `getUserMedia` não funciona em origem insegura, e isso é do navegador, não do código.
- Se qualquer caminho exigir afrouxar a CSP: **pare e pergunte**. Não afrouxe por conta própria.

## 7. Prohibitions

- ❌ Nunca usar o `codigo_publico` da inscrição como código do ingresso, nem derivá-lo dele.
- ❌ Nunca colocar URL, nome, CPF, e-mail ou qualquer dado pessoal dentro do QR — só o código.
- ❌ Nunca expor o QR, o código ou o PDF para inscrição que não esteja confirmada.
- ❌ Nunca afrouxar a Content-Security-Policy: sem `worker-src`, sem `unsafe-inline`, sem host novo.
- ❌ Nunca deixar rota de validação sem `throttle`, nem rota do participante sem assinatura.
- ❌ Nunca dar `presenca.desfazer` ao papel `portaria`.
- ❌ Nunca tocar em `GeradorQrCodePix`, nas telas de pagamento ou no fluxo do provedor.
- ❌ Nunca fazer a emissão do ingresso depender do envio do e-mail (e-mail falha; ingresso não pode falhar junto).
- ❌ Nunca instalar biblioteca de leitura que exija worker por blob (`qr-scanner` e similares).
- ❌ Nunca remover ou renomear `data-testid` existente.
- ❌ Nunca deixar a tela da portaria sem o caminho da digitação visível.

---

## Execution Steps

1. **Base.** Migration `ingressos`, `Model Ingresso`, `SituacaoIngresso`, factory e `GeradorDeCodigo` (base32 de Crockford, com `normalizar()` e `formatar()`), com teste do gerador.
2. **Emissão.** `EmitirIngresso` (idempotente), listener `EmitirIngressoDaInscricao` registrado **antes** do de e-mail em `AppServiceProvider`, e o comando de backfill das confirmadas antigas.
3. **Infra de imagem.** `gd` no `Dockerfile`, `endroid/qr-code` e `dompdf/dompdf` no `composer.json`, e `GeradorQrCodeIngresso` entregando SVG e PNG. Confirmar que `composer install` e o build do Docker seguem funcionando.
4. **Entrega ao participante.** `IngressoDaInscricao.vue`, ajuste do `AcompanhamentoController` e do Resource, rota assinada do PDF com `IngressoParticipanteController`, `PdfDoIngresso` e a view do ticket. Inscrição não confirmada não vê nada.
5. **E-mail.** PNG como anexo inline (CID) no `PagamentoConfirmadoMail`, com o código também nas duas views (HTML e texto).
6. **Permissões.** `presenca.registrar` e `presenca.desfazer` no `PapeisSeeder`, papel `portaria`, as duas ações novas em `AcaoAuditada`, o item "Portaria" no `AppSidebar` e o redirecionamento de `/admin` ciente do papel.
7. **Validação (back-end).** `RegistrarPresenca` e `DesfazerPresenca` com as cinco regras em ordem, `IngressoRecusado`, `PortariaController`, `ValidarIngressoRequest`, rotas com permissão e throttle, e auditoria nos dois caminhos.
8. **Tela da portaria.** `Admin/Portaria/Index.vue` com seletor de evento, `LeitorDeQrCode.vue` (`BarcodeDetector` nativo, `jsQR` de alternativa, sem worker) e `ResultadoDaLeitura.vue`. Digitação sempre visível. Rodar `seguranca-csp.spec.ts` para provar que a política não mudou.
9. **Contagem.** `NumerosDePresenca`, composição em `NumerosDoEvento`, cartão de presentes × faltantes no `Painel.vue`, e `varianteDoIngresso` no `situacoes.ts`.
10. **Testes e verificação.** Os quatro arquivos Pest e os dois Playwright da §4; depois `pint --test`, `php artisan test`, `npm run lint`, `npx vue-tsc --noEmit`, `npm run build` e `npm run test:e2e` inteiro, comparando com a linha de base do HEAD (a suíte tem 4 falhas pré-existentes conhecidas).

## Done

Uma inscrição confirmada nasce com ingresso; o participante vê o QR e o código na página, recebe os dois por e-mail e imprime o PDF; a portaria lê pela câmera ou digita, a entrada é aceita uma única vez e a segunda tentativa é recusada com hora e responsável; quem tem permissão desfaz o engano; o painel mostra quantos entraram e quantos faltam; a portaria não alcança nada além disso; a CSP continua intacta e a suíte inteira passa.

## Commit

`feat(ingressos): qrcode do ingresso e controle de presenca na portaria`

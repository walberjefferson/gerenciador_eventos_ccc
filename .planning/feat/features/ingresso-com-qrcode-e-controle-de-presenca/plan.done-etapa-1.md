# Relatório de execução — Ingresso com QR Code (ETAPA 1 de 2)

> **Plano:** ingresso-com-qrcode-e-controle-de-presenca
> **Executado:** 2026-09-01
> **Escopo desta rodada:** Execution Steps **1 a 5** do plano (base, emissão, infra de imagem, entrega ao participante, e-mail) + os testes que cobrem esses cinco passos
> **Status:** ⚠️ WITH CAVEATS

## O que ficou pronto (passos 1 a 5)

1. **Base** — tabela `ingressos`, Model, enum, factory e o gerador de código.
2. **Emissão** — Action idempotente, ouvinte de `InscricaoConfirmada` e comando de backfill.
3. **Infra de imagem** — `gd` no Dockerfile, `endroid/qr-code` e `dompdf/dompdf` instalados, gerador de QR em SVG e PNG.
4. **Entrega ao participante** — tela de acompanhamento, rota assinada e PDF.
5. **E-mail** — QR como anexo inline (CID) e o código também em texto.

## O que NÃO entra nesta rodada (passos 6 a 10, para o próximo spawn)

Permissões (`presenca.registrar`, `presenca.desfazer`, papel `portaria`, `AcaoAuditada`,
`AppSidebar`, redirecionamento de `/admin`), validação na portaria
(`RegistrarPresenca`, `DesfazerPresenca`, `IngressoRecusado`, `PortariaController`,
`ValidarIngressoRequest`, rotas com throttle), tela da portaria (`Admin/Portaria/Index.vue`,
`LeitorDeQrCode.vue`, `ResultadoDaLeitura.vue`, `jsqr` no `package.json`), contagem
(`NumerosDePresenca`, `NumerosDoEvento`, `PainelController`, `Painel.vue`,
`varianteDoIngresso` em `situacoes.ts`) e os testes `tests/Feature/Presenca/*` e
`tests/e2e/portaria.spec.ts`. **Nenhum arquivo desses foi criado**, e não há código morto
preparando-os.

## O que foi feito

| Arquivo | Ação | Descrição |
|---|---|---|
| `database/migrations/2026_09_01_100001_create_ingressos_table.php` | criado | Tabela 1:1 com inscrição, `codigo` único, `usado_em`/`usado_por` já previstos |
| `app/Enums/SituacaoIngresso.php` | criado | `Emitido`/`Usado`/`Invalido` com `rotulo()` |
| `app/Models/Ingresso.php` | criado | `inscricao()`, `usadoPor()`, `estaUsado()`, `situacao()`, `codigoFormatado()` |
| `database/factories/IngressoFactory.php` | criado | Estados `usado()` e `naoUsado()` |
| `app/Services/Ingressos/GeradorDeCodigo.php` | criado | Base32 de Crockford, 12 caracteres (~60 bits), `normalizar()` e `formatar()` |
| `app/Actions/Ingressos/EmitirIngresso.php` | criado | Idempotente; trata a colisão de unicidade das duas colunas |
| `app/Listeners/EmitirIngressoDaInscricao.php` | criado | Ouve `InscricaoConfirmada`, **fora da fila** e registrado antes do e-mail |
| `app/Console/Commands/EmitirIngressosPendentes.php` | criado | Backfill repetível (`ingressos:emitir-pendentes`) |
| `app/Services/Ingressos/GeradorQrCodeIngresso.php` | criado | SVG (tela) e PNG (e-mail e PDF), só o código como texto puro |
| `app/Services/Ingressos/PdfDoIngresso.php` | criado | dompdf, PNG em `data:` URI, `isRemoteEnabled` desligado |
| `resources/views/pdf/ingresso.blade.php` | criado | O ticket impresso |
| `app/Http/Controllers/IngressoParticipanteController.php` | criado | Download do PDF; rota assinada + exigência de inscrição confirmada |
| `resources/js/components/participante/IngressoDaInscricao.vue` | criado | QR, código legível e botão do PDF |
| `resources/js/types/ingresso.ts` | criado | Tipos do ingresso |
| `app/Providers/AppServiceProvider.php` | modificado | Ouvinte novo registrado ANTES do de e-mail |
| `app/Listeners/EnviarEmailPagamentoConfirmado.php` | modificado | Passa o código do ingresso ao Mailable |
| `app/Mail/PagamentoConfirmadoMail.php` | modificado | Código do ingresso + PNG gerado na hora de montar o conteúdo |
| `resources/views/emails/pagamento-confirmado.blade.php` | modificado | Bloco do ingresso com `embedData` (CID) |
| `resources/views/emails/pagamento-confirmado-texto.blade.php` | modificado | O código na versão sem imagem |
| `app/Http/Controllers/AcompanhamentoController.php` | modificado | `qr_ingresso` (SVG) e `url_ingresso_pdf` (assinada) só para confirmada |
| `app/Http/Resources/InscricaoAcompanhamentoResource.php` | modificado | `ingresso` no payload, só para confirmada |
| `resources/js/pages/Inscricoes/Acompanhar.vue` | modificado | Encaixe do `IngressoDaInscricao` |
| `resources/js/types/participante.ts` | modificado | Props do ingresso |
| `app/Models/Inscricao.php` | modificado | **Acréscimo necessário:** relação `ingresso()` (ver Desvios) |
| `Dockerfile` | modificado | `gd` no `install-php-extensions`, com o motivo escrito ao lado |
| `composer.json` / `composer.lock` | modificado | `endroid/qr-code ^6.0` e `dompdf/dompdf ^3.1` |
| `routes/web.php` | modificado | Só a rota assinada do PDF do participante |
| `tests/Feature/Ingressos/EmissaoDeIngressoTest.php` | criado | 9 cenários |
| `tests/Feature/Ingressos/EntregaDoIngressoTest.php` | criado | 10 cenários |
| `tests/e2e/ingresso-do-participante.spec.ts` | criado | 2 cenários de navegador |

## Critérios de qualidade aplicáveis a esta etapa

| Critério | Status | Evidência |
|---|---|---|
| Código não deriva do `codigo_publico` nem de dado da pessoa | ✅ | `it nao deriva o codigo do codigo publico nem de qualquer dado da pessoa` — compara as duas inscrições da mesma pessoa em dois eventos e recusa qualquer trecho de 4 caracteres em comum |
| Emissão idempotente, com a `unique` no banco | ✅ | `it devolve o mesmo ingresso...` (3 chamadas, 1 registro) e `it e o banco, e nao o codigo, que recusa o segundo ingresso...` (`UniqueConstraintViolationException` num INSERT cru) |
| Inscrição não confirmada não tem QR, não baixa PDF (403), não recebe código | ✅ | `it nao manda nada do ingresso para quem ainda nao pagou`, `it recusa quem ainda nao pagou, quem expirou e quem foi cancelado` (403), `it sai sem o bloco do ingresso quando nao ha ingresso` |
| Rota do participante assinada | ✅ | `it recusa quem chega sem a assinatura na URL` → 403; e o e2e `semAssinatura.status() === 403` |
| E-mail com QR inline (CID) e o código em texto | ✅ | `it embute o QR como anexo inline...`: 1 anexo `image/png`, corpo com `src="cid:` e **sem** `src="data:image`; corpo texto com o código formatado |
| PDF abre, com QR em PNG (não SVG) | ✅ | Resposta `application/pdf`, `attachment`, bytes começando em `%PDF-` e > 2000 bytes; e2e baixa o arquivo pelo link assinado |
| Nada do Pix muda | ✅ | `git diff --stat` não toca `GeradorQrCodePix`, `QrCodePix.vue` nem telas de pagamento |
| CSP não afrouxada | ✅ | `CabecalhosDeSeguranca.php` fora do diff; `seguranca-csp.spec.ts` passa sem edição |
| `./vendor/bin/pint --test` | ⚠️ | Única falha: `database/seeders/DatabaseSeeder.php` — **pré-existente**, arquivo intocado por esta entrega (`git diff` vazio nele) |
| `php artisan test` (Pest) | ⚠️ | **645 passed, 1 failed** — a falha é `Admin/AutorizacaoTest > it nao deixa nenhuma rota a...` (rota `admin.`), **pré-existente**: reproduzida com as mudanças guardadas em `git stash` (HEAD limpo) |
| `npx vue-tsc --noEmit` | ✅ | Sem saída (limpo) |
| `npm run lint` | ✅ | ESLint sem apontamentos |
| `npm run build` | ✅ | `✓ built in 2.42s` |
| Playwright | ⚠️ | **79 passed, 4 failed** — exatamente as 4 falhas conhecidas da linha de base (3 em `admin-avisos-pagamento`, 1 em `admin-usuarios`); os 2 cenários novos passam |

## Verificação

| Comando | Resultado |
|---|---|
| `./vendor/bin/pest tests/Feature/Ingressos` | 19 passed (523 assertions) |
| `./vendor/bin/pest` | 645 passed, 1 failed (pré-existente) — 84.60s |
| `./vendor/bin/pint --test` | 1 arquivo pré-existente fora do padrão (`DatabaseSeeder.php`) |
| `npx vue-tsc --noEmit` | limpo |
| `npm run lint` | limpo |
| `npm run build` | ok |
| `npx playwright test tests/e2e/ingresso-do-participante.spec.ts` | 2 passed (5.5s) |
| `npx playwright test` (suíte inteira) | 79 passed, 4 failed (as 4 conhecidas) |

`public/hot` foi movido para fora antes da suíte Playwright e **devolvido intacto** ao fim
(`diff` contra a cópia de segurança: idêntico).

## Desvios do plano

- **`app/Models/Inscricao.php` não estava na tabela §4 e foi modificado.** O acréscimo é
  uma única relação, `ingresso(): HasOne`, mais o `use` correspondente. Sem ela, o
  controller, o Resource e o listener precisariam consultar `Ingresso::where('inscricao_id', …)`
  à mão em três lugares, e o `whereDoesntHave('ingresso')` do backfill não existiria.
- **`Ingresso::scopeDoEvento()` foi escrito e removido** ainda antes do commit: ele só
  serviria à contagem de presença, que é do passo 9. Nada de código morto ficou.
- **`ext-gd` NÃO foi declarado em `composer.json`.** O plano pede a extensão no Dockerfile
  (feito) e não a exige no manifesto. Declará-la faria `composer install` falhar em qualquer
  máquina ou runner sem `gd` — e o workflow `tests.yml` usa `shivammathur/setup-php` sem
  lista explícita de extensões. Fica registrado como decisão, não como esquecimento.
- **`resources/views/emails/moldura.blade.php` tem um comentário agora impreciso**
  ("Sem imagem, sem rastreador..."): desde esta entrega o comprovante de pagamento leva uma
  imagem inline. O arquivo não está no escopo desta etapa e não foi tocado — **corrigir o
  comentário na etapa 2**.
- **A variável da view do e-mail chama-se `codigoIngressoFormatado`, e não `codigoIngresso`.**
  O Laravel joga as propriedades públicas do Mailable por cima do `with()`, e a propriedade
  pública `codigoIngresso` guarda o código cru: com o nome repetido, o e-mail sairia com os
  doze caracteres colados e nada acusaria o engano. Está escrito em comentário no arquivo.
- **`resources/js/pages/Inscricoes/Acompanhar.vue` continua fora do padrão do Prettier**, como
  já estava no HEAD (verificado com a configuração do projeto sobre a versão de HEAD). O bloco
  acrescentado por esta entrega, esse sim, está no formato que o Prettier produziria.

## Pendências conhecidas (não são desta etapa)

- Passos 6 a 10 do plano, listados acima.
- A falha pré-existente de `Admin/AutorizacaoTest` tem cura natural no **passo 6**: é a rota
  `admin.` (o `Route::redirect('/', 'painel')`) sem permissão, e o passo 6 justamente reescreve
  esse redirecionamento para ser ciente do papel.

## Commit

- Mensagem: `feat(ingressos): emissao do ingresso com qrcode e entrega ao participante`
- Não foram adicionados ao índice: `ccc-redesign.html`, o `.md` do prompt na raiz e a pasta
  `.planning/` desta feature.

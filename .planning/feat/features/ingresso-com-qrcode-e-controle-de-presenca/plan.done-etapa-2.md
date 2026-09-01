# Relatório de execução — Controle de presença na portaria (ETAPA 2 de 2)

> **Plano:** ingresso-com-qrcode-e-controle-de-presenca
> **Executado:** 2026-09-01
> **Escopo desta rodada:** Execution Steps **6 a 10** (permissões, validação no back-end, tela da portaria, contagem, testes e verificação)
> **Status:** ⚠️ WITH CAVEATS

## O que ficou pronto (passos 6 a 10)

6. **Permissões** — `presenca.registrar` e `presenca.desfazer`, papel `portaria`, as duas ações novas em `AcaoAuditada`, o item "Portaria" na barra lateral e o redirecionamento de `/admin` ciente do papel.
7. **Validação (back-end)** — `RegistrarPresenca` com as quatro recusas em ordem, `DesfazerPresenca`, `IngressoRecusado`, `PortariaController`, `ValidarIngressoRequest`, rotas com permissão e throttle, auditoria nos dois caminhos.
8. **Tela da portaria** — `Admin/Portaria/Index.vue` com seletor de evento e digitação sempre visível, `LeitorDeQrCode.vue` (`BarcodeDetector` nativo, `jsQR` de alternativa, sem worker) e `ResultadoDaLeitura.vue`.
9. **Contagem** — `NumerosDePresenca`, composição em `NumerosDoEvento`, cartão de presentes × faltantes no painel e `varianteDoIngresso` no `situacoes.ts`.
10. **Testes e verificação** — dois arquivos Pest novos, um Playwright novo, e a suíte inteira medida contra a linha de base.

## O que foi feito

| Arquivo | Ação | Descrição |
|---|---|---|
| `app/Actions/Presenca/RegistrarPresenca.php` | criado | As quatro recusas em ordem, gravação travada dentro da transação, auditoria fora dela |
| `app/Actions/Presenca/DesfazerPresenca.php` | criado | Limpa `usado_em`/`usado_por` e audita; devolve `false` quando não havia o que desfazer |
| `app/Exceptions/Presenca/IngressoRecusado.php` | criado | Motivo estável + mensagem em português + dados para a tela |
| `app/Http/Controllers/Admin/PortariaController.php` | criado | Tela, validar e desfazer; resposta por redirecionamento, não por JSON |
| `app/Http/Requests/Admin/ValidarIngressoRequest.php` | criado | Normaliza (reusando `GeradorDeCodigo::normalizar`) antes de validar |
| `app/Services/Admin/NumerosDePresenca.php` | criado | Presentes × faltantes em **uma** consulta agregada, com `left join` |
| `resources/js/pages/Admin/Portaria/Index.vue` | criado | Seletor de evento, digitação sempre visível, resultado e contadores |
| `resources/js/components/admin/LeitorDeQrCode.vue` | criado | `BarcodeDetector` nativo → `jsQR`; sem worker, sem `blob:` |
| `resources/js/components/admin/ResultadoDaLeitura.vue` | criado | Aceito/recusado, com motivo, hora e botão de desfazer condicional |
| `app/Enums/AcaoAuditada.php` | modificado | `RegistrouPresenca` e `DesfezPresenca`; só o segundo entra em `sensiveis()` |
| `database/seeders/PapeisSeeder.php` | modificado | Duas permissões novas, papel `portaria` e `PERMISSOES_DA_PORTARIA` |
| `app/Http/Controllers/Admin/PainelController.php` | modificado | `entrada()`: o `/admin` ciente do papel |
| `app/Services/Admin/NumerosDoEvento.php` | modificado | Compõe `NumerosDePresenca` (injeção no construtor) |
| `resources/js/pages/Admin/Painel.vue` | modificado | Seção "Presença no portão" com os três cartões |
| `resources/js/lib/situacoes.ts` | modificado | Mapa `INGRESSO`, `varianteDoIngresso` e o domínio no despachante |
| `resources/js/components/AppSidebar.vue` | modificado | Item "Portaria" e **fim dos itens fixos** (ver Desvios) |
| `routes/web.php` | modificado | `admin.inicio` com permissão OU, e o grupo `admin.portaria.*` |
| `config/inscricoes.php` | modificado | `limites.validar_ingresso` (`240,1`, ajustável por variável de ambiente) |
| `package.json` / `package-lock.json` | modificado | `jsqr ^1.4.0` |
| `resources/js/types/ingresso.ts` | modificado | `EventoDaPortaria`, `LeituraAceita`, `LeituraRecusada`, `ResultadoDaLeitura` |
| `resources/js/types/painel.ts` | modificado | `PresencaDoEvento` dentro de `NumerosDoEvento` |
| `app/Http/Controllers/Admin/UsuarioController.php` | modificado | **Só um comentário** (ver Desvios) |
| `tests/Feature/Presenca/RegistroDePresencaTest.php` | criado | 16 cenários |
| `tests/Feature/Presenca/PermissoesDaPortariaTest.php` | criado | 12 cenários |
| `tests/e2e/portaria.spec.ts` | criado | 3 cenários de navegador |
| `tests/Feature/Admin/AutorizacaoTest.php` | modificado | Consertado de verdade (ver a seção própria) |
| `tests/Feature/Admin/UsuariosTest.php` | modificado | Dois números de "dois papéis" que passaram a ser três |
| `tests/Feature/Desempenho/ConsultasDoPainelTest.php` | modificado | O painel passou de 3 para 4 consultas agregadas |

## O defeito do `AutorizacaoTest`: o que estava errado

O teste `it nao deixa nenhuma rota administrativa protegida apenas por login` estava
**vermelho desde o commit `5337420`**, antes desta feature, e o motivo é sutil.

Aquele commit acrescentou `Route::redirect('/', 'painel')` dentro do grupo `/admin`.
Uma rota sem nome **herda o prefixo de nome do grupo** — o Laravel monta `as` como
`'admin.' . ''` —, então o desvio passou a se chamar `admin.` e a ser varrido pelo teste,
que percorre toda rota cujo nome começa com `admin.` exigindo `permission:` ou `role:`.
O desvio não tinha nenhum dos dois: era a única rota do painel protegida apenas por login.
O teste estava **certo**; o código é que estava errado.

O passo 6 do plano pedia exatamente o conserto: um redirecionamento **ciente do papel**.
`Route::redirect` não sabe olhar permissão, então ele virou uma rota de verdade —
`PainelController::entrada()`, com `middleware('permission:painel.ver|presenca.registrar')`
(a barra vertical do Spatie é um **OU**). Quem tem `painel.ver` vai para o painel; quem só
tem `presenca.registrar` vai para a portaria; quem não tem nenhuma das duas recebe 403 ali
mesmo, porque não tem destino nenhum no painel.

Isso resolve **dois** problemas de uma vez: a rota deixa de estar aberta e o voluntário do
portão para de levar 403 ao digitar o endereço mais óbvio do sistema.

**Nada foi afrouxado nem marcado como skip.** As três asserções que mudaram de número no
arquivo (`TOTAL_DE_PERMISSOES` 11 → 13 e a contagem de papéis 2 → 3) mudaram porque esta
entrega **de fato** criou duas permissões e um papel — que é exatamente o que o comentário
do próprio arquivo manda declarar por escrito ao mexer no número. E um cenário novo foi
acrescentado (`it leva cada papel para a tela que ele alcanca ao entrar em /admin`), com o
histórico do defeito escrito ao lado.

## Critérios de qualidade aplicáveis a esta etapa

| Critério | Status | Evidência |
|---|---|---|
| As quatro recusas têm mensagem própria e são testadas uma a uma | ✅ | `RegistroDePresencaTest`: `recusa 1: codigo que nao existe`, `recusa 2: ingresso de outro evento, dizendo de qual`, `recusa 3: inscricao cancelada depois de paga, com a data`, `recusa 4: segunda leitura, com a hora e o nome`. Mais o cenário da **ordem**: um ingresso de outro evento com inscrição cancelada diz "é de outro evento" |
| O papel `portaria` não alcança `/admin/inscricoes`, `/admin/eventos`, `/admin/usuarios`, `/admin/auditoria` nem o desfazer | ✅ | `PermissoesDaPortariaTest > it fecha para a portaria todas as outras telas do painel` percorre 9 endereços esperando 403; `it recusa a portaria no desfazer` prova o 403 **e** que nada mudou no banco. No navegador: `portaria.spec.ts > a portaria nao alcanca mais nada do painel` |
| Quem entra com o papel `portaria` cai numa tela útil, e não num 403 | ✅ | `it leva quem so tem o portao para a portaria` (`/admin` → 302 `/admin/portaria`) e o cenário de navegador que abre `/admin` e vê o `<h1>Portaria</h1>` |
| `presenca.registrar` e `presenca.desfazer` aparecem na tela de papéis com a explicação | ✅ | `it mostra as duas permissoes novas na tela de papeis, com a explicacao` — compara com `PapeisSeeder::PERMISSOES` e confere que o papel `portaria` está na matriz |
| Toda entrada aceita e todo desfazer geram auditoria com responsável e momento | ✅ | `it grava auditoria da entrada...` e `it grava auditoria do desfazer, guardando a entrada que foi apagada` (o registro é o único lugar onde a hora apagada continua existindo) |
| A CSP **não** é afrouxada | ✅ | `CabecalhosDeSeguranca.php` está fora do diff (`git status` não o lista) e `seguranca-csp.spec.ts` passa sem edição. O `qr-scanner` foi descartado justamente por exigir `worker-src 'self' blob:` |
| A leitura por câmera degrada com dignidade | ✅ | `LeitorDeQrCode` traduz `NotAllowedError`, `NotFoundError`, `NotReadableError` e a ausência de `mediaDevices` (http) para frases em português e **some do caminho**; a digitação nunca depende dele. O cenário de navegador prova a digitação funcionando sem câmera nenhuma |
| A etiqueta do ingresso vem de `lib/situacoes.ts` | ✅ | `varianteDoIngresso` + `'ingresso'` em `DominioDeSituacao`; `ResultadoDaLeitura` usa `EtiquetaDeSituacao`, sem mapa de cor solto |
| A tela usa `BotaoDeAcao` e `EtiquetaDeSituacao`, não classe solta | ✅ | `ResultadoDaLeitura.vue` importa os dois; o único botão próprio é o `Button` do projeto |
| Rota de validação com throttle; nenhuma rota administrativa só com login | ✅ | `it exige permissao e limite de tentativas nas rotas da portaria` e `AutorizacaoTest > it nao deixa nenhuma rota administrativa protegida apenas por login` (agora verde) |
| A portaria **não** tem `presenca.desfazer` | ✅ | `it da a portaria uma unica permissao` compara a lista inteira: `['presenca.registrar']` |
| Presentes + faltantes fecham com os esperados | ✅ | `it conta presentes e faltantes, e os dois sempre fecham`; `it conta como faltante a inscricao confirmada que ainda nao tem ingresso` (o caso do backfill) |
| Nada do Pix muda | ✅ | `GeradorQrCodePix`, telas de pagamento e provedor fora do diff |
| `./vendor/bin/pint --test` | ⚠️ | Única falha: `database/seeders/DatabaseSeeder.php` — **pré-existente**, arquivo intocado por esta entrega |
| `php artisan test` | ✅ | **675 passed (5090 assertions)**, 0 falhas — inclusive o `AutorizacaoTest`, que estava vermelho ao entrar |
| `npm run lint` | ✅ | ESLint sem apontamentos |
| `npx vue-tsc --noEmit` | ✅ | Sem saída |
| `npm run build` | ✅ | `✓ built in 2.14s`; o `jsQR` sai em pedaço próprio (130 kB), carregado só quando alguém liga a câmera |
| `npm run test:e2e` | ⚠️ | **82 passed, 4 failed** — exatamente as 4 falhas conhecidas da linha de base (3 em `admin-avisos-pagamento`, 1 em `admin-usuarios`). Os 3 cenários novos passam |

## Verificação

| Comando | Resultado |
|---|---|
| `./vendor/bin/pint --test` | 1 falha, só em `database/seeders/DatabaseSeeder.php` (pré-existente) |
| `php artisan test` | 675 passed, 0 failed |
| `npm run lint` | limpo |
| `npx vue-tsc --noEmit` | limpo |
| `npm run build` | ok |
| `npm run test:e2e` | 82 passed, 4 failed (as 4 da linha de base) |

Sobre o e2e: `public/hot` foi movido para fora antes da suíte e **devolvido intacto** depois
(mesmo conteúdo, `http://localhost:5174`).

Durante a rodada apareceu **uma** falha nova, e ela era minha: a seção nova do painel
chamava-se "Presença no evento" e, como `<section aria-labelledby>` tem nome acessível,
`getByLabel('Evento')` do `admin-acesso.spec.ts` passou a encontrar dois elementos. O
conserto foi no **código**, não no teste: a seção passou a se chamar "Presença no portão",
que além de resolver a ambiguidade é o nome mais correto. Depois disso a suíte voltou
exatamente à linha de base.

## Desvios do plano

1. **`app/Http/Controllers/Admin/UsuarioController.php`** — não estava na lista da etapa.
   A mudança é de **uma palavra, num comentário**: "Os dois papeis que existem" virou "Os
   papeis que existem". Nenhuma linha de código mudou. O papel `portaria` aparece sozinho
   entre os atribuíveis da tela de usuários porque `papeis()` já lia da tabela, e não de uma
   lista escrita à mão (D-50) — o comentário é que tinha ficado mentindo.
2. **`resources/js/components/AppSidebar.vue`: os "itens fixos" deixaram de ser fixos.**
   O plano pedia só o item "Portaria". Mas Painel, Eventos e Inscrições apareciam para
   **todo mundo**, e isso funcionava porque todo mundo que entrava tinha as três permissões.
   Com o papel `portaria`, o voluntário veria três itens que só o levariam a 403 — exatamente
   o que o resto daquele arquivo passou seis fases evitando, com o motivo escrito lá dentro.
   Os três passaram a depender de `painel.ver`, `eventos.gerenciar` e `inscricoes.ver`.
   No mesmo arquivo, o logotipo passou a apontar para `admin.inicio` em vez de `admin.painel`,
   pelo mesmo motivo.
3. **`tests/Feature/Admin/UsuariosTest.php` e `tests/Feature/Desempenho/ConsultasDoPainelTest.php`**
   — não estavam na lista, e mudaram por consequência direta do que o plano pediu: o terceiro
   papel (dois números de `2` para `3`) e a composição de `NumerosDePresenca` dentro de
   `NumerosDoEvento` (o painel passou de 3 para 4 consultas agregadas). Para não pagar duas
   consultas, `NumerosDePresenca` faz as duas contagens numa varredura só, com `left join` e
   `sum(case when ...)`. Nenhuma asserção foi afrouxada; as duas ganharam cenário a mais.
4. **`resources/js/types/ingresso.ts` recebeu os tipos da portaria** em vez de um arquivo novo:
   é o mesmo domínio, e o plano listava "tipos das props novas" sem exigir arquivo separado.
5. **A câmera não é exercitada no Playwright**, e o plano já previa isto na §6: `getUserMedia`
   não funciona em origem insegura e a suíte roda em http. O cenário percorre a digitação
   inteira — que é o caminho principal — e prova que a tela **não depende** da câmera.

## Estado final da feature (etapas 1 e 2)

A entrega está **completa** em relação ao "Done" do plano:

- Inscrição confirmada nasce com ingresso (etapa 1), com código sorteado de ~60 bits que não
  deriva do `codigo_publico`.
- O participante vê o QR e o código na página de acompanhamento, recebe os dois por e-mail
  (PNG como anexo inline/CID) e baixa o PDF por rota assinada (etapa 1).
- A portaria lê pela câmera ou digita; a entrada é aceita **uma única vez** e a segunda
  tentativa é recusada com hora e responsável (etapa 2).
- Quem tem `presenca.desfazer` — administrador e organizador, nunca a portaria — desfaz o
  engano, e o ingresso volta a valer (etapa 2).
- O painel mostra presentes × faltantes × esperados, e os três fecham (etapa 2).
- O papel `portaria` alcança uma tela e nada além dela (etapa 2).
- A CSP continua intacta, sem `worker-src`, sem `unsafe-inline` novo e sem host novo.

**Pendências conhecidas, todas anteriores a esta feature e fora do escopo:**

- `./vendor/bin/pint --test` reprova `database/seeders/DatabaseSeeder.php`.
- 4 cenários de navegador falham na linha de base: 3 em `admin-avisos-pagamento` (o nome
  "Avisos do provedor" resolve para dois links desde que o painel ganhou o botão "Ver os
  avisos do provedor") e 1 em `admin-usuarios`.
- `npm run format:check` reprova ~24 arquivos alheios a esta entrega.

## Commit

- Mensagem: `feat(portaria): validacao do ingresso e controle de presenca`
- Arquivos: os 26 de código/teste da tabela acima mais o diretório
  `.planning/feat/features/ingresso-com-qrcode-e-controle-de-presenca/` (o plano, o relatório
  da etapa 1 e este) — a etapa 1 deixou o `.planning` de fora por engano.
- **Não** entraram: `ccc-redesign.html` e `Prompt para Claude Code — ....md`, que não são desta entrega.

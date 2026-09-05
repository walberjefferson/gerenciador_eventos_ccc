# Action Plan — Pagamento pela chave Pix do responsável do setor

> **Type:** feature
> **Created:** 2026-09-02 23:38
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro Sênior PHP 8.4 + Laravel 12 + Inertia 2 + Vue 3 (Composition API,
`<script setup>`) + TypeScript strict + Tailwind v4/Reka UI, com domínio de PostgreSQL,
de controle de acesso com spatie/permission e de manipulação segura de arquivo enviado
por terceiro. Testes Pest + Playwright.

**Scope:** Dar ao evento uma **forma de recebimento**: pelo provedor de pagamento, como
hoje, ou **pela chave Pix do responsável do setor**, com o participante enviando o
comprovante pela própria tela e o responsável conferindo no painel. Cinco frentes, nesta
ordem: banco/domínio → emissão da cobrança → HTTP e acesso → telas → testes.

Fora de escopo:
- trocar de provedor, mexer em credenciais, webhook ou reconciliação;
- conciliação bancária automática (ninguém lê extrato: quem reconhece o dinheiro é uma
  pessoa, e é isso que a auditoria registra);
- reembolso, estorno ou política de devolução — não existem no sistema;
- pagamento parcial, parcelamento ou desconto;
- mexer em lotes (RN-L*), ingresso, portaria ou no fuso da aplicação.

**Stack:** PHP 8.4, Laravel 12, PostgreSQL, Inertia 2, Vue 3.5, TypeScript, Tailwind v4,
Reka UI, spatie/permission, Pest (paralelo com paratest), Playwright.

## 2. Direct Objective

Permitir que um evento seja cadastrado para receber **pela chave Pix do responsável do
setor** em vez de pelo provedor: nesse modo, a tela de pagamento mostra a chave Pix e o QR
Code do responsável do setor da pessoa, ela envia o comprovante por ali, e o responsável
— entrando no painel e vendo **apenas o seu setor** — confere e confirma, caindo no mesmo
caminho de confirmação que já existe. Evento por provedor continua funcionando
exatamente como hoje.

## 3. Minimum Inputs

### O que já existe e vai ser reaproveitado (ler antes de escrever)

Metade desta feature já está no repositório e **não deve ser reescrita**:

| Peça existente | O que já resolve |
|---|---|
| `App\Actions\Pagamentos\ConfirmarPagamentoManual` | declara entrada de dinheiro sem fonte externa, exige observação, registra auditoria e delega a `ConfirmarPagamento` |
| `App\Enums\MetodoPagamento` | já tem `Dinheiro`, `Transferencia`, `Outro` e `ehManual()` |
| `Pagamento` com `gateway='manual'`, `id_externo=null` | cobrança sem provedor já é um caso previsto |
| `App\Services\Pagamentos\GeradorQrCodePix` | desenha o SVG de **qualquer** payload copia e cola |
| `ReconciliarPagamentosPendentes` | já filtra `whereNotNull('id_externo')` — cobrança sem provedor nunca entra |
| `CancelarPagamento` | só avisa o provedor quando há `id_externo` — cobrança do setor já passa batida |
| `FiltroDeInscricoes` | já tem `cidade_id` como filtro; falta usá-lo como **escopo obrigatório** |
| `PapeisSeeder` | o molde exato de papel, permissão e a explicação em português de cada uma |

### Entities / Data

**`eventos`** (alterar)

| Coluna | Tipo | Nulo | Padrão | Observação |
|---|---|---|---|---|
| `forma_recebimento` | string(20) | não | `'gateway'` | `gateway` ou `setor` (Enum `FormaRecebimento`) |

```sql
CHECK eventos_forma_recebimento_check
  forma_recebimento IN ('gateway', 'setor')
```

`prazo_pagamento_minutos` **não ganha coluna irmã**: continua sendo *o* prazo. O que muda
é a validação — ver RN-S8.

**`cidades`** (o Setor — alterar)

| Coluna | Tipo | Nulo | Observação |
|---|---|---|---|
| `responsavel_id` | bigint FK → `users` | sim | `nullOnDelete`: apagar a conta não apaga o setor |
| `chave_pix` | string(140) | sim | **em claro, de propósito** — ver RN-S3 |
| `titular_chave_pix` | string(120) | sim | o nome que aparece no aplicativo do banco |

**`comprovantes_pagamento`** (nova tabela)

| Coluna | Tipo | Nulo | Observação |
|---|---|---|---|
| `id` | bigserial | não | |
| `inscricao_id` | bigint FK → `inscricoes` | não | `restrictOnDelete` |
| `caminho` | string(255) | não | disco **privado**, nunca `public` |
| `nome_original` | string(180) | não | como a pessoa chamou o arquivo |
| `mime` | string(80) | não | conferido pelo conteúdo, não pela extensão |
| `tamanho_bytes` | integer | não | |
| `situacao` | string(20) | não | `enviado`, `aceito`, `recusado` (Enum `SituacaoComprovante`) |
| `enviado_em` | timestamptz | não | |
| `conferido_por_id` | bigint FK → `users` | sim | `nullOnDelete` |
| `conferido_em` | timestamptz | sim | |
| `motivo_recusa` | string(300) | sim | obrigatório quando `situacao = recusado` |
| `created_at` / `updated_at` | timestamptz | não | |

```sql
INDEX (inscricao_id, situacao)
INDEX (situacao, enviado_em)

-- No máximo UM comprovante aguardando conferência por inscrição.
CREATE UNIQUE INDEX comprovantes_um_em_aberto_por_inscricao
  ON comprovantes_pagamento (inscricao_id)
  WHERE situacao = 'enviado';

CHECK comprovantes_recusa_com_motivo_check
  situacao <> 'recusado' OR motivo_recusa IS NOT NULL

CHECK comprovantes_tamanho_check
  tamanho_bytes > 0
```

O índice único parcial é a trava contra o duplo clique e contra a fila de comprovantes:
enviar de novo por cima do que ainda não foi conferido substitui o anterior (RN-S6).

### Business Rules

- **RN-S1 — A forma de recebimento é do evento, e é uma só.** `gateway` (padrão, o de
  hoje) ou `setor`. Vale para todas as inscrições daquele evento. Ela é lida no momento
  de emitir a cobrança e em nenhum outro lugar decide nada.

- **RN-S2 — No modo `setor`, nenhuma chamada sai para o provedor.**
  `CriarPagamentoDaInscricao` bifurca **antes** de tocar no `PaymentGateway`: cria um
  `Pagamento` com `gateway = 'setor'`, `id_externo = null`, `metodo = Pix`,
  `expira_em = prazo_pagamento` da inscrição, e `pix_copia_e_cola` com um **BR Code
  estático** montado a partir da chave do setor. Nenhuma credencial, nenhum certificado,
  nenhuma rede.

- **RN-S3 — A chave Pix do setor NÃO é cifrada, e isso é decisão, não descuido.**
  `CredencialPagamento.chave_pix` é cifrada porque é a chave da conta que recebe **e**
  fica ao lado de segredos de instituição financeira. A chave do setor é o oposto: ela
  existe **para ser mostrada** a todo participante daquele setor. Cifrar o que a própria
  tela publica é teatro. O que ela exige é outra coisa: aparecer **só** para quem já está
  numa inscrição daquele setor, nunca numa listagem pública de setores.

- **RN-S4 — O setor precisa estar pronto antes de o evento ser salvo no modo `setor`.**
  Salvar um evento com `forma_recebimento = setor` é recusado enquanto existir setor ativo
  sem `chave_pix` **ou** sem `responsavel_id`. A mensagem nomeia os setores que faltam.
  Um evento que cobra por chave que não existe é uma inscrição que ninguém consegue pagar.

- **RN-S5 — O comprovante é do participante, pela tela dele.** Imagem (`jpeg`, `png`,
  `webp`) ou `pdf`, no máximo 5 MB, validado pelo **conteúdo** (`mimetypes`), não pela
  extensão. Guardado em disco privado, sob `comprovantes/{ano}/{codigo_publico}/`, com
  nome gerado — o nome original vai para a coluna, nunca para o caminho. A rota de envio
  é assinada e limitada por tentativas, como as outras rotas do participante.

- **RN-S6 — Um comprovante em aberto por inscrição.** Enviar outro enquanto o anterior
  ainda está `enviado` substitui o anterior: o arquivo antigo é apagado do disco e a linha
  é sobrescrita. Depois de `recusado`, um envio novo cria linha nova — o histórico de
  recusa não se apaga.

- **RN-S7 — Enviar comprovante NÃO confirma nada.** A situação da inscrição continua
  `aguardando_pagamento` até uma pessoa conferir. O que a tela do participante mostra
  ("comprovante enviado, em conferência") vem do **comprovante**, não da situação da
  inscrição: é informação de tela, e não estado de domínio. Ver o risco em §6.

- **RN-S8 — Evento no modo `setor` tem prazo mínimo maior.** `prazo_pagamento_minutos`
  passa a ser validado condicionalmente: mínimo de **5 minutos** no modo `gateway` (como
  hoje) e mínimo de **2880** (2 dias) no modo `setor`, com **10080** (7 dias) sugerido no
  formulário. Uma transferência conferida por pessoa não cabe em 24 horas.

- **RN-S9 — Quem confere é o responsável daquele setor, e só enxerga o setor dele.**
  Papel novo `responsavel-setor`, com duas permissões: `inscricoes.ver` (já existe) e
  `pagamentos.conferir-comprovante` (nova). Em toda consulta que ele alcança, o setor é
  **escopo obrigatório**, aplicado no servidor — nunca um filtro que ele possa mudar.
  Administrador continua vendo tudo e também confere.

- **RN-S10 — Aceitar o comprovante é confirmar o pagamento pelo caminho que já existe.**
  Aceitar delega a `ConfirmarPagamentoManual` com `metodo = Transferencia` e a observação
  escrita por quem conferiu. Nenhuma regra de dinheiro nova: a vaga presa vira vaga paga,
  o anúncio é `InscricaoConfirmada`, a auditoria é a mesma. Recusar exige motivo, marca o
  comprovante como `recusado` e **não mexe** na inscrição — ela segue aguardando
  pagamento até o prazo.

- **RN-S11 — O arquivo nunca é servido direto.** O download passa por rota autenticada,
  com a mesma checagem de escopo da RN-S9, e responde `Storage::download()`. Nenhum
  comprovante em disco público, nenhuma URL adivinhável.

- **RN-S12 — Evento no modo `gateway` não muda em nada.** Nenhuma tela, nenhum teste,
  nenhum caminho de cobrança se comporta diferente do que se comporta hoje.

- **RN-S13 — O código da inscrição viaja no BR Code, em dois campos e por dois motivos.**

  | Campo EMV | Conteúdo | Limite | Por quê |
  |---|---|---|---|
  | `26-02` (informação adicional do arranjo Pix) | `Inscricao <codigo_publico>` — o ULID **inteiro** | 72 | é o campo que os aplicativos de banco mostram como **descrição** do pagamento, e é onde o código cabe sem mutilação |
  | `62-05` (reference label) | os **últimos 25** caracteres do código | 25 | é o identificador de transação; o ULID tem 26 caracteres e **não cabe inteiro** — por isso ele não pode ser a única cópia |

  O `codigo_publico` é um ULID de 26 caracteres (`inscricoes.codigo_publico`, gerado em
  `Inscricao::booted()`). Escrever só no `62-05` entregaria um código truncado — quem
  confere teria de adivinhar o primeiro caractere. Por isso a descrição do `26-02` é a
  cópia que vale para leitura humana, e o `62-05` é conveniência para quem quiser casar
  por identificador.

  **Nem um nem outro é conciliação.** Nem todo aplicativo preserva ou exibe esses campos a
  quem recebe, e nada impede a pessoa de copiar a chave e pagar pela mão, sem ler o QR.
  Eles ajudam quem confere a achar a inscrição; **nenhuma decisão de dinheiro pode depender
  deles**. Pelo mesmo motivo, o valor gravado no `54` não é trava: quem digita a chave paga
  o que quiser, e quem confere precisa olhar o valor no comprovante.

  Os dois campos passam pelo mesmo saneamento EMV do resto do payload (`Str::ascii`, sem
  acento, sem pontuação) — o ULID já é alfanumérico maiúsculo, então atravessa intacto; a
  palavra que o acompanha, não por acaso, é escrita sem acento na origem.

### Existing Files to Read

- `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` — onde a bifurcação da RN-S2 entra
- `app/Actions/Pagamentos/ConfirmarPagamentoManual.php` — o caminho que a RN-S10 reaproveita
- `app/Actions/Pagamentos/CancelarPagamento.php` e
  `app/Console/Commands/ReconciliarPagamentosPendentes.php` — confirmar que cobrança sem
  `id_externo` já passa batida (e **provar isso num teste**, não presumir)
- `app/Services/Payments/Fake/FakePaymentGateway.php` — o método privado `pixPayload()` e
  os auxiliares `emv26()`: é a montagem de BR Code a ser **extraída** para um serviço
- `app/Services/Pagamentos/GeradorQrCodePix.php`
- `app/Http/Controllers/PagamentoController.php` — a tela que ganha o modo `setor`
- `app/Http/Requests/Admin/EventoRequest.php` — RN-S4 e RN-S8 entram aqui
- `app/Services/Admin/FiltroDeInscricoes.php` — onde o escopo da RN-S9 é aplicado
- `app/Http/Controllers/Admin/InscricaoAdminController.php`
- `database/seeders/PapeisSeeder.php` — o molde do papel e o tom das explicações
- `app/Models/CredencialPagamento.php` — o contraste que justifica a RN-S3
- `app/Http/Requests/Admin/SalvarCredencialPagamentoRequest.php` +
  `app/Actions/Pagamentos/SalvarCredencialPagamento.php` — o único upload que já existe
- `resources/js/pages/Inscricoes/Pagamento.vue`
- `resources/js/pages/Admin/Eventos/Formulario.vue`

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/{ts}_add_forma_recebimento_to_eventos_table.php` | create | Coluna + CHECK |
| `database/migrations/{ts}_add_responsavel_to_cidades_table.php` | create | `responsavel_id`, `chave_pix`, `titular_chave_pix` |
| `database/migrations/{ts}_create_comprovantes_pagamento_table.php` | create | Tabela, único parcial, dois CHECK |
| `database/factories/ComprovantePagamentoFactory.php` | create | States `enviado`, `aceito`, `recusado` |
| `app/Enums/FormaRecebimento.php` | create | `Gateway`, `Setor` + `rotulo()` + `exigeSetorPreparado()` |
| `app/Enums/SituacaoComprovante.php` | create | `Enviado`, `Aceito`, `Recusado` + `rotulo()` |
| `app/Models/ComprovantePagamento.php` | create | Casts, relações, scope `emAberto()` |
| `app/Models/Cidade.php` | modify | `responsavel()`, `estaPreparadaParaReceber()` |
| `app/Models/Evento.php` | modify | Cast de `forma_recebimento`, `recebePeloSetor()` |
| `app/Models/Inscricao.php` | modify | `comprovantes()`, `comprovanteEmAberto()`, `setor()` |
| `app/Services/Pagamentos/MontadorDeBrCodePix.php` | create | BR Code estático — extraído de `FakePaymentGateway::pixPayload()`, com descrição opcional no `26-02` (RN-S13) |
| `app/Services/Payments/Fake/FakePaymentGateway.php` | modify | Passa a usar o montador extraído (sem mudar a saída) |
| `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` | modify | Bifurcação da RN-S2 |
| `app/Actions/Comprovantes/EnviarComprovante.php` | create | RN-S5 e RN-S6, com a substituição do anterior |
| `app/Actions/Comprovantes/ConferirComprovante.php` | create | RN-S10: aceitar delega, recusar exige motivo |
| `app/Http/Requests/EnviarComprovanteRequest.php` | create | Tipo por conteúdo, 5 MB |
| `app/Http/Controllers/ComprovanteController.php` | create | `store` (participante) — rota assinada |
| `app/Http/Controllers/Admin/ConferenciaComprovanteController.php` | create | `index`, `show` (download), `aceitar`, `recusar` |
| `app/Http/Requests/Admin/ConferirComprovanteRequest.php` | create | Observação obrigatória; motivo obrigatório na recusa |
| `app/Policies/ComprovantePagamentoPolicy.php` | create | O escopo da RN-S9 num lugar só |
| `app/Services/Admin/FiltroDeInscricoes.php` | modify | Escopo obrigatório por setor (RN-S9) |
| `app/Http/Controllers/PagamentoController.php` | modify | Modo `setor`: chave, titular, QR, estado do comprovante |
| `app/Http/Requests/Admin/EventoRequest.php` | modify | RN-S4 e RN-S8 |
| `app/Http/Controllers/Admin/EventoController.php` | modify | Enviar a forma e o aviso de setor despreparado |
| `app/Http/Controllers/Admin/CidadeController.php` | modify | Responsável e chave Pix no cadastro do setor |
| `app/Http/Requests/Admin/CidadeRequest.php` | modify | Validação da chave e do responsável |
| `database/seeders/PapeisSeeder.php` | modify | Papel `responsavel-setor` + permissão nova |
| `routes/web.php` | modify | 1 rota pública assinada + 4 administrativas |
| `config/filesystems.php` | modify | Disco privado `comprovantes` |
| `resources/js/types/*.ts` | modify | Tipos da forma, do setor e do comprovante |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modify | Bloco do Pix do setor + envio do comprovante |
| `resources/js/components/participante/EnvioDeComprovante.vue` | create | Upload com pré-visualização e estado |
| `resources/js/pages/Admin/Eventos/Formulario.vue` | modify | Escolha da forma + prazo sugerido |
| `resources/js/pages/Admin/Catalogo/*.vue` | modify | Responsável e chave Pix no setor |
| `resources/js/pages/Admin/Comprovantes/Index.vue` | create | A fila de conferência |
| `tests/Feature/Pagamentos/RecebimentoPeloSetorTest.php` | create | RN-S1 a RN-S4, RN-S12 |
| `tests/Feature/Comprovantes/EnvioDeComprovanteTest.php` | create | RN-S5, RN-S6, RN-S7, RN-S11 |
| `tests/Feature/Comprovantes/ConferenciaTest.php` | create | RN-S9, RN-S10 |
| `tests/e2e/pagamento-pelo-setor.spec.ts` | create | Playwright — ver §5 |

## 5. Quality Criteria

- [ ] **Nenhuma chamada de rede no modo `setor`.** Provado com um teste que registra um
      `PaymentGateway` falso que **lança exceção** se `createPayment` for chamado.
- [ ] **A cobrança do setor não é consultada nem cancelada no provedor.** Teste explícito
      passando `pagamentos:reconciliar` e a expiração sobre uma cobrança `gateway='setor'`
      — hoje isso já é verdade por `id_externo` nulo, e o teste **trava** essa verdade.
- [ ] **O BR Code extraído produz byte a byte o mesmo payload de antes** quando chamado
      sem descrição. Um teste compara a saída do `MontadorDeBrCodePix` com a que o
      `FakePaymentGateway` produzia, incluindo o CRC16. Refatoração que muda payload de Pix
      é defeito, não melhoria. A descrição do `26-02` é **parâmetro opcional**: ausente,
      o campo não é emitido e o payload é idêntico ao de hoje.
- [ ] **A descrição carrega o código inteiro (RN-S13).** Teste afirma que o payload do modo
      setor contém o `codigo_publico` completo — os 26 caracteres — dentro do `26-02`, que
      o `62-05` traz os últimos 25, e que o CRC16 continua conferindo depois do campo novo.
      Um segundo teste decodifica o payload emitido e reencontra a inscrição pelo código,
      que é exatamente o que quem confere vai fazer na mão.
- [ ] **Nenhum ponto flutuante no valor do BR Code — e isto é um defeito a corrigir na
      extração, não a preservar.** `FakePaymentGateway::pixPayload()` monta hoje o campo
      `54` com `number_format($amountCents / 100, 2, '.', '')`: divisão em ponto flutuante,
      exatamente o que D-06 proíbe e o que `EfiPaymentGateway::emDecimal()` já evita por
      recorte de inteiro. Era inofensivo num provedor fictício; deixa de ser no instante em
      que este payload passa a cobrar pela chave real de uma pessoa. O `MontadorDeBrCodePix`
      converte centavos por recorte de inteiro, com teste sobre os valores que denunciam o
      erro (por exemplo 1_00, 10_05, 999_99, 1_234_56).
- [ ] **O comprovante nunca fica em disco público.** Teste afirma que o caminho gravado não
      está sob `storage/app/public` e que a rota de download recusa (403) quem não é do
      setor nem administrador.
- [ ] **Escopo por setor é do servidor.** Teste em que o responsável do Setor A pede a
      inscrição do Setor B pela URL direta e recebe 403/404 — não uma lista filtrada.
- [ ] **RN-S12 provada por ausência:** a suíte inteira de hoje continua verde sem nenhuma
      alteração de expectativa em teste de evento por gateway.
- [ ] Tipo de arquivo validado por `mimetypes` (conteúdo), nunca por `mimes` (extensão).
- [ ] PSR-12 / Pint limpo; Vue `<script setup>` + TypeScript strict, sem `any`.
- [ ] Comentários em português explicando **por quê**, no tom de
      `ConfirmarPagamentoManual` e `CredencialPagamento`. Em especial, a RN-S3 escrita por
      extenso no model `Cidade`: por que esta chave **não** é cifrada e a da credencial é.
- [ ] **Pest** — os três arquivos de teste cobrindo cada RN-S nomeadamente, incluindo:
      substituição do comprovante em aberto (RN-S6), recusa sem motivo negada, aceite
      caindo em `ConfirmarPagamentoManual` com auditoria gravada, e evento recusado por
      setor despreparado (RN-S4).
- [ ] **Playwright E2E** (`pagamento-pelo-setor.spec.ts`), quatro cenários:
      1. **Caminho feliz** — evento no modo setor; a tela mostra chave, titular e QR do
         responsável; a pessoa envia o comprovante e vê "em conferência".
      2. **Conferência** — o responsável entra, vê só o seu setor, abre o comprovante,
         aceita com observação e a inscrição fica confirmada.
      3. **Recusa** — recusa com motivo; o participante vê a recusa e envia outro.
      4. **Isolamento** — o responsável do Setor A não vê nem alcança inscrição do Setor B.
- [ ] Acessibilidade: o campo de upload tem rótulo, anuncia formatos e tamanho aceitos,
      e o erro de arquivo é lido por leitor de tela — não só pintado de vermelho.
- [ ] A suíte inteira continua verde: `php artisan test --parallel`.

## 6. Ambiguity Handling

**Decisões do dono do produto (entrevista de 2026-09-02):**

- O responsável do setor **é um usuário do sistema**, vinculado ao setor, e enxerga apenas
  as inscrições do setor dele.
- O comprovante **entra pelo sistema**, enviado pelo participante na tela de pagamento.
- A escolha entre provedor e setor é **única, no evento**.
- Eventos no modo setor têm **prazo próprio, mais longo**.

**Assumptions made:**

- *"Setor" é a tabela `cidades`* — renomeada no vocabulário público na entrega
  "setores-e-grupos-reais". O plano mantém o nome da tabela e fala "setor" na interface,
  como o resto do sistema já faz.
- *Prazo maior é validação, não coluna nova* (RN-S8). Criar
  `prazo_pagamento_manual_minutos` ao lado de `prazo_pagamento_minutos` deixaria duas
  colunas onde uma responde: qual delas vale passa a depender de outro campo, e é assim
  que nasce cobrança com prazo errado. O mínimo condicional resolve com um campo só.
- *Um setor tem um responsável; um responsável pode ter vários setores.* Vínculo por
  `cidades.responsavel_id`, não por tabela pivô.
- *O aceite usa `MetodoPagamento::Transferencia`.* É o que de fato aconteceu — um Pix
  direto para a conta do responsável. `Outro` ficaria mais vago do que o fato.
- *A montagem do BR Code é extraída, não duplicada.* Ela já existe, privada, dentro do
  provedor simulado (`FakePaymentGateway::pixPayload()`, linha 255). Copiar seria manter
  duas implementações do mesmo padrão EMV. A extração corrige o ponto flutuante do campo
  `54` — e essa é a **única** diferença de comportamento permitida entre o código antigo e
  o novo; o teste de igualdade byte a byte cobre todo o resto do payload.

**⚠️ Risco que o dono do produto precisa enxergar (RN-S7 + RN-S8):**

Você escolheu **não** criar uma situação "em conferência" na inscrição e resolver o prazo
com um valor maior. A consequência é real e vai acontecer: **o prazo pode vencer enquanto
o comprovante espera conferência.** A pessoa paga no sexto dia de um prazo de sete, o
responsável só abre o painel no oitavo, e a rotina de expiração — que roda de minuto em
minuto e não sabe o que é comprovante — já devolveu a vaga. O dinheiro está na conta do
responsável e a inscrição não existe mais.

O plano executa a decisão como tomada e inclui **duas mitigações que não mudam a máquina
de estados**:
1. a fila de conferência ordena por prazo mais próximo e **destaca em vermelho** o que
   vence em menos de 24 horas;
2. a tela do participante, depois do envio, diz com todas as letras até quando a
   conferência precisa acontecer.

Elas reduzem a chance, não a eliminam. A eliminação exigiria a situação "em conferência"
(ou que a expiração consulte comprovantes) — e isso é outro plano, não este.

**Segundo ponto de atenção — a permissão que já existe.** `pagamentos.confirmar-manual` é
descrita no `PapeisSeeder` como a permissão mais perigosa do sistema, deliberadamente fora
do organizador. A permissão nova `pagamentos.conferir-comprovante` **é da mesma família**,
mas mais estreita: só age sobre inscrição do próprio setor e só a partir de um comprovante
enviado. A distinção precisa estar escrita no seeder, no mesmo tom das outras.

**If unsure during execution:**

- Faltou texto de mensagem, rótulo ou nome de coluna → **pare e pergunte**. Não invente
  regra de dinheiro nem de acesso.
- A refatoração do BR Code mudar qualquer byte do payload → **pare**: é regressão em
  cobrança, e o teste de igualdade existe para pegar isso antes do commit.
- Um teste existente quebrar → **leia o teste antes de mexer nele**. Se quebrou porque a
  RN-S12 não foi respeitada, o defeito é do código novo.

## 7. Prohibitions

- ❌ **Nunca** chamar o provedor de pagamento quando `forma_recebimento = setor`.
- ❌ **Nunca** guardar comprovante em disco público, nem servi-lo por URL direta.
- ❌ **Nunca** validar tipo de arquivo por extensão (`mimes`) — sempre por conteúdo.
- ❌ **Nunca** deixar o escopo de setor depender de parâmetro que o navegador manda.
- ❌ **Nunca** confirmar inscrição só porque o comprovante chegou (RN-S7).
- ❌ **Nunca** duplicar a regra de confirmação: aceitar delega a `ConfirmarPagamentoManual`.
- ❌ **Nunca** inventar `id_externo` para cobrança sem provedor.
- ❌ **Nunca** cifrar a chave Pix do setor "por precaução" — ela é publicada por desenho
  (RN-S3); o que a protege é o escopo de quem a vê.
- ❌ **Nunca** alterar o comportamento de evento no modo `gateway` (RN-S12).
- ❌ **Nunca** mexer em lotes, ingresso, portaria, e-mails ou no fuso da aplicação.
- ❌ **Nunca** pular os testes Playwright: há UI nova em quatro telas.
- ❌ **Nunca** usar float em dinheiro (D-06).

---

## Execution Steps

1. **Banco e vocabulário.** As três migrações, os dois Enums (`FormaRecebimento`,
   `SituacaoComprovante`), o model `ComprovantePagamento`, a factory, e o disco privado
   `comprovantes` em `config/filesystems.php`. Ajustar `Cidade`, `Evento` e `Inscricao`
   com relações e atalhos. Rodar `migrate:fresh` e conferir o único parcial e os CHECK.

2. **O BR Code, extraído com prova.** Criar `MontadorDeBrCodePix` a partir do
   `pixPayload()`/`emv26()` privados do `FakePaymentGateway`, fazer o provedor simulado
   passar a usá-lo, e escrever **primeiro** o teste que compara a saída com a de antes
   (payload e CRC16 idênticos, chamando sem descrição). Sem esse teste verde, não siga.
   Só então acrescentar o parâmetro opcional de descrição (`26-02`) e o teste da RN-S13.

3. **A bifurcação da cobrança (RN-S2).** `CriarPagamentoDaInscricao` passa a olhar
   `evento->forma_recebimento`: no modo `setor`, monta a cobrança local com a chave do
   setor da inscrição e **não toca no gateway**. Escrever junto o teste do gateway que
   explode se for chamado, e o que trava reconciliação e cancelamento fora dessa cobrança.

4. **Cadastro do setor e do evento (RN-S4, RN-S8).** Responsável e chave Pix no
   `CidadeController`/`CidadeRequest`; a forma de recebimento e o prazo mínimo condicional
   no `EventoRequest`, com a mensagem que nomeia os setores despreparados.

5. **Envio do comprovante (RN-S5, RN-S6, RN-S11).** `EnviarComprovante`,
   `EnviarComprovanteRequest`, `ComprovanteController` e a rota pública assinada com
   limite de tentativas. A substituição do comprovante em aberto apaga o arquivo anterior
   do disco.

6. **Acesso e conferência (RN-S9, RN-S10).** Papel `responsavel-setor` e permissão
   `pagamentos.conferir-comprovante` no `PapeisSeeder`, com a explicação escrita;
   `ComprovantePagamentoPolicy` como lugar único do escopo; escopo obrigatório em
   `FiltroDeInscricoes`; `ConferirComprovante` delegando o aceite a
   `ConfirmarPagamentoManual`; o controller administrativo com `index`, download,
   `aceitar` e `recusar`; as quatro rotas.

7. **Testes de servidor (Pest).** Os três arquivos de teste com todos os casos de §5.
   A suíte inteira precisa estar verde antes de abrir qualquer `.vue`.

8. **Tela do participante.** `Pagamento.vue` ganha o bloco do modo setor — chave, titular,
   QR Code, e o `EnvioDeComprovante.vue` com estado (nada enviado / em conferência /
   recusado com motivo / aceito). Depois do envio, dizer até quando a conferência precisa
   acontecer (mitigação do risco de §6).

9. **Telas administrativas.** A escolha da forma no formulário do evento (com o prazo
   sugerido de 7 dias ao trocar para setor), responsável e chave Pix no cadastro do setor,
   e a fila `Admin/Comprovantes/Index.vue` — **ordenada por prazo mais próximo**, com
   destaque para o que vence em menos de 24 horas.

10. **Fechamento.** `./vendor/bin/pint --dirty`, `php artisan test --parallel`,
    `npx vue-tsc --noEmit`, `npm run build` e a suíte Playwright, todos verdes. Atualizar
    `docs/BUSINESS_RULES.md` (as RN-S), `docs/DATABASE.md` (as três mudanças de esquema),
    `docs/PAYMENTS.md` (a forma de recebimento pelo setor e por que ela não conversa com
    provedor) e `docs/PROGRESS.md` (o entregue e o risco da RN-S7 registrado).

## Done

Um evento pode ser cadastrado para receber pela chave Pix do responsável do setor; nesse
modo nenhuma chamada sai para o provedor, a tela mostra chave, titular e QR Code do setor
da pessoa, ela envia o comprovante por ali, e o responsável — vendo apenas o seu setor —
aceita ou recusa, com o aceite caindo no mesmo `ConfirmarPagamentoManual` de sempre;
evento por provedor continua idêntico ao de hoje; e tudo isso está coberto por Pest e por
Playwright, com a suíte inteira verde.

## Commit

`feat(pagamentos): recebimento pela chave pix do responsavel do setor`

# Regras de negócio

> **Versão:** 1.0 · **Data:** 2026-08-20
> Cada regra tem um identificador (`RN-01`, `RN-02`…), o momento em que é verificada, a mensagem que o participante vê e o teste automatizado que prova que ela funciona.

---

## Como ler este documento

| Campo | O que significa |
|-------|-----------------|
| **Regra** | O que o sistema exige, em uma frase |
| **Gatilho** | Em que momento a verificação acontece |
| **Onde é aplicada** | O arquivo do código responsável |
| **Mensagem ao participante** | O texto exato exibido quando a regra é violada |
| **Teste** | O teste automatizado que prova a regra |

**Princípio que vale para todas as regras:** toda verificação acontece **no servidor**, dentro de uma única operação indivisível (transação). O que o navegador verifica serve apenas para conforto do usuário e nunca substitui a verificação do servidor.

**Princípio de escrita das mensagens:** a mensagem é escrita para o participante, não para o programador. "Você precisa escolher pelo menos 1 modalidade esportiva" — nunca "min_selections constraint violated".

---

## RN-01 — Janela de inscrição

| | |
|---|---|
| **Regra** | Só é possível se inscrever quando o evento está com a situação `inscricoes_abertas` **e** o momento atual está entre `inscricoes_abrem_em` e `inscricoes_fecham_em` |
| **Gatilho** | Início da criação da inscrição, antes de qualquer reserva |
| **Onde é aplicada** | `app/Actions/Inscricoes/CriarInscricao.php` |
| **Mensagem (antes de abrir)** | "As inscrições para este evento ainda não começaram." |
| **Mensagem (depois de fechar ou evento fora de `inscricoes_abertas`)** | "As inscrições para este evento estão encerradas." |
| **Teste** | `tests/Feature/Inscricoes/InscricaoTest.php` |

**Por que duas verificações e não uma.** A situação do evento é um controle manual da organização (permite fechar antes da hora). As datas são o controle automático. As duas precisam concordar.

---

## RN-02 — Cidade e grupo compatíveis

| | |
|---|---|
| **Regra** | O grupo de participantes escolhido precisa pertencer à cidade escolhida, e tanto a cidade quanto o grupo precisam estar ativos |
| **Gatilho** | Na criação da inscrição, mesmo que o formulário já limite as opções |
| **Onde é aplicada** | `app/Actions/Inscricoes/CriarInscricao.php` |
| **Mensagem** | "O grupo escolhido não pertence à cidade selecionada. Escolha a cidade novamente." |
| **Teste** | `tests/Feature/Inscricoes/InscricaoTest.php` |

**Por que revalidar algo que a tela já controla.** A tela pode ser contornada. Qualquer pessoa consegue enviar a requisição direto, com uma combinação inválida. Verificação no navegador é conforto; verificação no servidor é a regra.

---

## RN-03 — Grupo obrigatório precisa de escolha

| | |
|---|---|
| **Regra** | Todo grupo de atividades ativo do evento com `obrigatorio = true` precisa receber pelo menos `min_selecoes` escolhas |
| **Gatilho** | Validação da seleção, dentro da transação |
| **Onde é aplicada** | `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` |
| **Mensagem** | "Você precisa escolher pelo menos {min} {nome do grupo}." — ex.: "Você precisa escolher pelo menos 1 modalidade esportiva." |
| **Teste** | `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` |

O banco garante, por restrição própria, que um grupo obrigatório sempre tem `min_selecoes >= 1`. Assim a configuração contraditória "obrigatório com mínimo zero" nem chega a existir.

---

## RN-04 — Mínimo e máximo por grupo

| | |
|---|---|
| **Regra** | A quantidade de atividades escolhidas em cada grupo respeita `min_selecoes` e `max_selecoes`. `max_selecoes` nulo significa sem limite. Grupo não obrigatório sem nenhuma escolha é aceito |
| **Gatilho** | Validação da seleção, dentro da transação |
| **Onde é aplicada** | `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` |
| **Mensagem (abaixo do mínimo)** | "Você precisa escolher pelo menos {min} opções em {nome do grupo}." |
| **Mensagem (acima do máximo)** | "Você pode escolher no máximo {max} opções em {nome do grupo}." |
| **Teste** | `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` |

**Detalhe importante:** o mínimo só é cobrado quando o participante escolheu **alguma coisa** no grupo, ou quando o grupo é obrigatório. Um grupo opcional com `min_selecoes = 2` significa "ou você não escolhe nada, ou escolhe pelo menos 2". Essa é a interpretação mais restritiva compatível com "opcional".

---

## RN-05 — Atividade pertence ao evento e está ativa

| | |
|---|---|
| **Regra** | Toda atividade escolhida precisa pertencer, pela cadeia grupo → dia → evento, ao evento da inscrição, e precisa estar ativa — assim como o grupo e o dia aos quais pertence |
| **Gatilho** | Validação da seleção, dentro da transação |
| **Onde é aplicada** | `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` |
| **Mensagem** | "Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção." |
| **Teste** | `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` |

**Por que essa regra existe.** Sem ela, seria possível enviar o identificador de uma atividade de **outro** evento e reservar vaga lá. É a regra que impede a inscrição "atravessar" para um evento em que a pessoa não se inscreveu.

**Por que a mensagem não diz qual atividade.** Dizer "a atividade 87 não pertence a este evento" confirmaria a existência da atividade 87 para quem está sondando o sistema. A mensagem é útil sem revelar isso.

---

## RN-06 — Conflito de horário

| | |
|---|---|
| **Regra** | Duas atividades escolhidas não podem se sobrepor no tempo. A sobreposição é: `comecaA < terminaB E terminaA > comecaB` |
| **Gatilho** | Validação da seleção, dentro da transação |
| **Onde é aplicada** | `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` |
| **Mensagem** | "{Atividade A} e {Atividade B} acontecem no mesmo horário. Escolha apenas uma das duas." |
| **Teste** | `tests/Feature/Inscricoes/ConflitoAtividadeTest.php` |

**Limites que se tocam são permitidos.** Se o futebol vai das 08:00 às 10:00 e o vôlei das 10:00 às 12:00, **não há conflito**: a comparação usa `<` e `>`, nunca `<=` e `>=`. Isso é intencional — na prática o participante consegue sair de uma quadra e entrar na outra.

| Caso | Futebol | Vôlei | Resultado |
|------|---------|-------|-----------|
| Sobreposição parcial | 08:00–10:00 | 09:00–11:00 | **Recusado** |
| Contenção total | 08:00–12:00 | 09:00–10:00 | **Recusado** |
| Horários idênticos | 08:00–10:00 | 08:00–10:00 | **Recusado** |
| Limites que se tocam | 08:00–10:00 | 10:00–12:00 | **Aceito** |
| Sem relação | 08:00–10:00 | 14:00–16:00 | **Aceito** |

A comparação usa data e hora completas com fuso, então funciona corretamente entre dias diferentes e em atividades que passam da meia-noite.

---

## RN-07 — Conflito explícito (definido pela organização)

| | |
|---|---|
| **Regra** | Um par de atividades registrado em `conflitos_atividades` não pode ser escolhido junto, mesmo que os horários não se sobreponham |
| **Gatilho** | Validação da seleção, dentro da transação |
| **Onde é aplicada** | `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` |
| **Mensagem (com motivo cadastrado)** | "{Atividade A} e {Atividade B} não podem ser escolhidas juntas: {motivo}." |
| **Mensagem (sem motivo)** | "{Atividade A} e {Atividade B} não podem ser escolhidas juntas." |
| **Teste** | `tests/Feature/Inscricoes/ConflitoAtividadeTest.php` |

**Vale nos dois sentidos.** Escolher futebol e handebol ou handebol e futebol dá o mesmo resultado. O par é guardado no banco sempre com o menor identificador primeiro, e a consulta ordena o par da mesma forma antes de comparar.

Serve para casos operacionais que o horário não expressa: mesma quadra, mesma equipe de apoio, mesmo equipamento.

---

## RN-08 — Faixa etária por atividade

| | |
|---|---|
| **Regra** | Para cada atividade com `idade_minima` ou `idade_maxima`, a idade do participante **na data em que a atividade acontece** precisa estar dentro da faixa |
| **Gatilho** | Validação da seleção, dentro da transação |
| **Onde é aplicada** | `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` |
| **Mensagem (muito jovem)** | "{Atividade} é permitida a partir de {idade} anos." |
| **Mensagem (acima do limite)** | "{Atividade} é permitida até {idade} anos." |
| **Teste** | `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` |

**A idade é calculada na data da atividade, não na data da inscrição.** Alguém que se inscreve com 15 anos e faz 16 antes da trilha **pode** participar de uma trilha com idade mínima 16. Calcular na inscrição recusaria injustamente.

**Não existe idade mínima geral para se inscrever.** A restrição é sempre por atividade. Sem `idade_minima` e sem `idade_maxima`, não há restrição.

---

## RN-09 — Capacidade da atividade

| | |
|---|---|
| **Regra** | Não é possível reservar vaga em atividade cuja soma de `vagas_reservadas + vagas_confirmadas` já alcançou a `capacidade`. Capacidade nula significa sem limite |
| **Gatilho** | No momento da reserva, dentro da transação, por comando atômico |
| **Onde é aplicada** | `app/Actions/Inscricoes/ReservarVagas.php` |
| **Mensagem** | "As vagas de {Atividade} acabaram. Escolha outra opção." |
| **Teste** | `tests/Feature/Inscricoes/CapacidadeAtividadeTest.php`, `tests/Feature/Inscricoes/ConcorrenciaTest.php` |

A reserva usa um único comando que confere e soma ao mesmo tempo. Se o banco responder "nenhuma linha alterada", esgotou. O detalhe técnico está em `ARCHITECTURE.md`, seção 5.

Antes de recusar, o sistema executa a expiração das inscrições vencidas **daquele evento** e tenta a operação inteira **uma vez mais**. Só então recusa. Isso impede que uma vaga presa por reserva vencida bloqueie um participante real.

---

## RN-10 — Capacidade do evento

| | |
|---|---|
| **Regra** | Não é possível reservar vaga em evento cuja soma de `vagas_reservadas + vagas_confirmadas` já alcançou a `capacidade`. Capacidade nula significa sem limite |
| **Gatilho** | No momento da reserva, **antes** das atividades |
| **Onde é aplicada** | `app/Actions/Inscricoes/ReservarVagas.php` |
| **Mensagem** | "As vagas para este evento acabaram." |
| **Teste** | `tests/Feature/Inscricoes/InscricaoTest.php`, `tests/Feature/Inscricoes/ConcorrenciaTest.php` |

**O evento vem sempre primeiro, e as atividades depois em ordem crescente de identificador.** Essa ordem é obrigatória em todos os caminhos que mexem em contador. Ordens diferentes poderiam fazer duas inscrições ficarem travadas esperando uma pela outra.

---

## RN-11 — Uma inscrição ativa por pessoa por evento

| | |
|---|---|
| **Regra** | O mesmo e-mail não pode ter duas inscrições ativas (`aguardando_pagamento` ou `confirmada`) no mesmo evento. A mesma regra vale para o CPF |
| **Gatilho** | Na gravação, garantida por unicidade parcial no banco |
| **Onde é aplicada** | `app/Actions/Inscricoes/CriarInscricao.php` (traduz a violação do banco em erro amigável) |
| **Mensagem (e-mail)** | "Já existe uma inscrição ativa com este e-mail neste evento." |
| **Mensagem (CPF)** | "Já existe uma inscrição ativa com este CPF neste evento." |
| **Teste** | `tests/Feature/Inscricoes/InscricaoDuplicadaTest.php` |

**Depois que a inscrição expira ou é cancelada, uma nova é permitida.** A unicidade só vale para as situações ativas, então o registro antigo continua guardado sem bloquear a nova tentativa.

**Por que confiar no banco e não em uma consulta antes de gravar.** Entre a consulta e a gravação existe um intervalo, e duas requisições simultâneas passariam as duas pela consulta. A unicidade do banco não tem esse intervalo. A aplicação captura a violação e a transforma em mensagem amigável — o participante nunca vê um erro técnico.

**O CPF é comparado pela impressão digital**, nunca pelo número guardado cifrado. Explicado em `DATABASE.md`, seção 3.8.

---

## RN-12 — Idempotência do envio

| | |
|---|---|
| **Regra** | Dois envios do formulário com a mesma `chave_idempotencia` no mesmo evento produzem **uma** inscrição e **uma** reserva. O segundo envio recebe a inscrição já criada |
| **Gatilho** | Antes de abrir a transação e novamente ao capturar violação de unicidade |
| **Onde é aplicada** | `app/Actions/Inscricoes/CriarInscricao.php` |
| **Mensagem** | Nenhuma. O participante recebe a mesma inscrição, com sucesso |
| **Teste** | `tests/Feature/Inscricoes/InscricaoTest.php` |

**Por que isso importa.** Clique duplo no botão, conexão instável e recarregamento de página são comuns no celular. Sem essa proteção, o participante teria duas inscrições, duas cobranças e duas vagas ocupadas.

---

## RN-13 — Aceite dos termos

| | |
|---|---|
| **Regra** | O aceite do regulamento é obrigatório. A inscrição guarda a `versao_termos` que estava valendo no evento e o momento exato do aceite |
| **Gatilho** | Validação do formulário e gravação da inscrição |
| **Onde é aplicada** | `app/Http/Requests/StoreInscricaoRequest.php` e `app/Actions/Inscricoes/CriarInscricao.php` |
| **Mensagem** | "Você precisa aceitar o regulamento do evento para continuar." |
| **Teste** | `tests/Feature/Inscricoes/InscricaoTest.php` |

**Por que guardar a versão e não apenas "aceitou".** Se o regulamento mudar depois, precisamos saber qual texto a pessoa aceitou. A versão guardada na inscrição responde a isso, mesmo com o texto atual já alterado.

Optamos por **não** criar uma tabela separada de aceites: há um aceite por inscrição, e dois campos resolvem. Se um dia houver mais de um termo por inscrição (regulamento e política de imagem, por exemplo), promovemos para tabela.

---

## Regras de pagamento e prazo

Estas complementam as 13 regras da inscrição.

### RN-P01 — Prazo de pagamento

O prazo é calculado na criação: `prazo_pagamento = momento da criação + prazo_pagamento_minutos do evento`. Ele fica congelado na inscrição — mudar a configuração do evento depois não altera prazos já concedidos.
**Teste:** `tests/Feature/Pagamentos/PrazoPagamentoTest.php`

### RN-P02 — Expiração automática

A cada minuto, uma rotina procura inscrições `aguardando_pagamento` com `prazo_pagamento` já vencido, muda a situação para `expirada`, devolve uma vaga no evento e uma em cada atividade escolhida, e cancela a cobrança pendente. **Nenhum registro é apagado.** Rodar a rotina duas vezes seguidas não altera nada na segunda.
**Teste:** `tests/Feature/Pagamentos/ExpiracaoInscricaoTest.php`

### RN-P03 — Confirmação vem só de fonte confiável

Uma inscrição só é confirmada por um aviso autenticado do provedor ou por consulta que o próprio servidor faz ao provedor. **Nunca** por parâmetro vindo do navegador, e **nunca** porque o participante voltou para uma página de sucesso.
**Teste:** `tests/Feature/Pagamentos/WebhookPagamentoTest.php`

### RN-P04 — Aviso repetido não duplica efeito

O mesmo aviso recebido duas vezes confirma a inscrição uma única vez e não altera os contadores na segunda. Garantido por unicidade no banco e por processamento idempotente.
**Teste:** `tests/Feature/Pagamentos/WebhookPagamentoTest.php`

### RN-P05 — Reconciliação

Uma rotina consulta o provedor para pagamentos `pendente` cujo vencimento está próximo ou já passou, e aplica o mesmo caminho de confirmação quando descobre que já foram pagos. Cobre a falha do aviso que nunca chegou.
**Teste:** `tests/Feature/Pagamentos/ReconciliacaoTest.php`

### RN-P06 — Simulação bloqueada fora de desenvolvimento

Os endereços que simulam pagamento só existem quando o ambiente é `local` ou `testing` **e** a configuração de simulação está ligada. Em qualquer outro caso respondem "não encontrado" (404).
**Teste:** `tests/Feature/Pagamentos/PaymentGatewayTest.php`

### RN-P07 — Nada é apagado

Inscrições e pagamentos nunca são removidos do banco. Toda mudança é de situação, com o momento registrado em coluna própria (`confirmada_em`, `expirada_em`, `cancelada_em`).
**Teste:** `tests/Feature/Pagamentos/ExpiracaoInscricaoTest.php`

---

## Regras de lote de inscrição

O **lote** é um degrau de preço entre o evento e a inscrição: ele vale até uma data, até acabarem as vagas dele, ou até o que vier primeiro. Um evento pode ter uma sequência deles — e pode não ter nenhum, que é o caso mais comum e continua funcionando exatamente como antes.

### RN-L1 — Todo lote encerra

Pelo menos um dos dois limites (`disponivel_ate` ou `quantidade`) precisa estar preenchido. Um lote sem limite nenhum nunca encerraria, e um lote que não encerra não é um lote: é o preço do evento com outro nome.
**Onde é aplicada:** `app/Http/Requests/Admin/LoteRequest.php` (avisa antes) e o CHECK `lotes_tem_limite_check` (recusa depois).
**Mensagem:** "Todo lote precisa de um limite: uma data, uma quantidade de vagas, ou os dois. Sem limite, ele nunca encerraria — e aí é o valor do próprio evento."
**Teste:** `tests/Feature/Admin/LotesAdminTest.php`

### RN-L2 — A ordem é a `posicao`

A posição decide qual lote sucede qual e não se repete dentro do evento, no mesmo molde de `dias_evento`. Cobrada pelo índice único `lotes_evento_id_posicao_unique`.
**Mensagem:** "Já existe um lote nesta posição. Cada lote ocupa uma posição diferente na sequência."

### RN-L3 — O lote vigente é calculado, nunca gravado

O lote vigente é o primeiro da ordem que ainda está disponível: dentro do prazo e com vaga. **Não existe coluna "ativo".** Situação derivada de data e contador é sempre verdadeira; situação gravada envelhece no primeiro minuto em que ninguém roda a rotina que a atualizaria.
**Onde é aplicada:** `app/Actions/Inscricoes/ResolverLoteVigente.php` — o único lugar onde a regra mora. A Action de inscrição, o Resource público e os testes perguntam todos ali.

### RN-L4 — Todos aparecem, só o vigente é selecionável

A página do evento e o formulário listam os lotes na ordem, cada um com nome, valor e situação (`encerrado`, `vigente`, `futuro`). Os não vigentes chegam à tela já marcados como não selecionáveis — e o servidor decide de novo no envio.
**Onde é aplicada:** `app/Http/Resources/LotePublicoResource.php`, `resources/js/components/eventos/ListaDeLotes.vue`.

### RN-L5 — O lote virou enquanto a pessoa preenchia

O formulário envia o `lote_id` que a pessoa viu. Se, no instante do envio, esse não for mais o vigente, a inscrição é **recusada** com 422 e uma mensagem que diz o que mudou e qual é o novo valor; a tela recarrega os lotes. Aceitar em silêncio pelo lote novo cobraria um preço que a pessoa não viu; aceitar pelo lote velho venderia abaixo do combinado.
**Onde é aplicada:** `app/Actions/Inscricoes/CriarInscricao.php`, `app/Exceptions/Inscricoes/LoteIndisponivelException.php`.
**Mensagem:** "O lote de inscrição mudou enquanto você preenchia o formulário. Agora vale o {lote}, por {valor}. Confira o novo valor e envie a inscrição novamente."
**Teste:** `tests/Feature/Inscricoes/LotesTest.php`, `tests/e2e/inscricao-por-lote.spec.ts`

### RN-L6 — O contador do lote nunca volta

A vaga é presa no lote no mesmo instante em que é presa no evento, com o mesmo UPDATE condicional. Quando a inscrição expira ou é cancelada, a vaga volta para o evento e para as atividades, **mas não para o lote**: `lotes.vagas_ocupadas` só cresce, e lote esgotado não reabre.

**Decisão do dono do produto, com o efeito colateral à vista:** quem reserva e não paga queima a vaga do lote barato, e ela será vendida pelo preço do lote seguinte. Num evento popular, o 1º lote pode esgotar em minutos e, no dia seguinte, ver metade daquelas inscrições expiradas. Duas mitigações existem e estão fora deste escopo: prazo de pagamento mais curto nos eventos com lote, ou devolver a vaga ao lote de origem enquanto ele ainda estiver na data.
**Onde é aplicada:** `app/Actions/Inscricoes/LiberarVagas.php` (que documenta a ausência).

### RN-L7 — O valor é fotografado, e a fotografia não se refaz

Na criação da inscrição, `valor_centavos` recebe o valor do lote vigente e `lote_id` guarda de qual lote ela veio. Alterar o valor de um lote depois **não muda inscrição nenhuma**, nem cobrança já emitida, nem segunda via de Pix — que continua lendo `inscricoes.valor_centavos`. O `lote_id` serve a relatório e conferência, nunca a cálculo de cobrança.

### RN-L8 — Evento sem lote continua como hoje

Nenhum lote cadastrado → `lote_id` nulo e `valor_centavos` copiado de `eventos.valor_centavos`. Nenhuma tela muda de comportamento.

### RN-L9 — Lotes esgotados fecham a inscrição

Evento **com** lotes e **sem** lote vigente tem as inscrições fechadas, com motivo em palavras, pelo mesmo caminho que já explica capacidade cheia e janela fechada. Evento **sem** lotes nunca cai nesta regra.
**Mensagem:** "Os lotes de inscrição se esgotaram."
**Onde é aplicada:** `Evento::aceitaInscricaoPorLote()`, combinada com `inscricoesEstaoAbertas()` e `temVagaDisponivel()` nos três pontos de decisão (Resource público, formulário público e `CriarInscricao`).

### RN-L10 — Lote e capacidade são tetos independentes

`eventos.capacidade` continua sendo o teto físico e manda em último caso; os lotes são degraus de preço dentro dela. A soma das quantidades pode ser menor que a capacidade (sobra vaga sem lote — e aí a inscrição fecha por RN-L9) ou maior (o evento lota antes do último lote). **Não há validação cruzada entre os dois**; a tela administrativa apenas informa a soma ao lado da capacidade.

### RN-L11 — Ordem canônica das travas

`evento → lote → atividades (id crescente)`. O lote entra logo depois do evento, antes das atividades. Cada inscrição toca um único lote, então não há ordem a arbitrar entre lotes.

### RN-L12 — Lote com inscrição não some nem encolhe

Excluir lote que já tem inscrição é recusado (`restrictOnDelete`, com a explicação do caminho certo). Reduzir `quantidade` abaixo de `vagas_ocupadas` é recusado no `FormRequest` e no CHECK `lotes_quantidade_check`.

---

## Mapeamento com os testes obrigatórios do briefing

O briefing exige oito testes com nomes em inglês. Como o domínio deste projeto é escrito em português, eles receberam nomes equivalentes. A correspondência é esta:

| Teste exigido no briefing | Arquivo neste projeto |
|---------------------------|------------------------|
| `RegistrationTest` | `tests/Feature/Inscricoes/InscricaoTest.php` |
| `ActivitySelectionTest` | `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` |
| `ActivityCapacityTest` | `tests/Feature/Inscricoes/CapacidadeAtividadeTest.php` |
| `ActivityConflictTest` | `tests/Feature/Inscricoes/ConflitoAtividadeTest.php` |
| `PaymentDeadlineTest` | `tests/Feature/Pagamentos/PrazoPagamentoTest.php` |
| `PaymentWebhookTest` | `tests/Feature/Pagamentos/WebhookPagamentoTest.php` |
| `RegistrationExpirationTest` | `tests/Feature/Pagamentos/ExpiracaoInscricaoTest.php` |
| `PaymentGatewayTest` | `tests/Feature/Pagamentos/PaymentGatewayTest.php` (nome mantido: trata do contrato de pagamento, que é a fronteira em inglês) |

Testes adicionais criados além dos exigidos:

| Arquivo | O que prova |
|---------|-------------|
| `tests/Feature/Dominio/EventoTest.php` | Relacionamentos, filtros e o fato de que as restrições do banco realmente recusam dados inválidos |
| `tests/Feature/Inscricoes/InscricaoDuplicadaTest.php` | RN-11, incluindo a liberação depois da expiração |
| `tests/Feature/Inscricoes/ConcorrenciaTest.php` | RN-09 e RN-10 sob concorrência real, com processos paralelos |
| `tests/Feature/Pagamentos/ReconciliacaoTest.php` | RN-P05 |
| `tests/Feature/Inscricoes/LotesTest.php` | RN-L1 a RN-L11: vigência, fotografia do preço, corrida pela última vaga do lote e a vaga que não volta |
| `tests/Feature/Admin/LotesAdminTest.php` | RN-L1, RN-L2 e RN-L12 no cadastro, com auditoria |

---

## Índice rápido: regra → mensagem

| ID | Mensagem exibida ao participante |
|----|----------------------------------|
| RN-01 | "As inscrições para este evento ainda não começaram." / "As inscrições para este evento estão encerradas." |
| RN-02 | "O grupo escolhido não pertence à cidade selecionada. Escolha a cidade novamente." |
| RN-03 | "Você precisa escolher pelo menos {min} {grupo}." |
| RN-04 | "Você precisa escolher pelo menos {min} opções em {grupo}." / "Você pode escolher no máximo {max} opções em {grupo}." |
| RN-05 | "Uma das atividades escolhidas não está disponível neste evento. Revise sua seleção." |
| RN-06 | "{A} e {B} acontecem no mesmo horário. Escolha apenas uma das duas." |
| RN-07 | "{A} e {B} não podem ser escolhidas juntas: {motivo}." |
| RN-08 | "{Atividade} é permitida a partir de {idade} anos." / "{Atividade} é permitida até {idade} anos." |
| RN-09 | "As vagas de {Atividade} acabaram. Escolha outra opção." |
| RN-10 | "As vagas para este evento acabaram." |
| RN-11 | "Já existe uma inscrição ativa com este e-mail neste evento." / "Já existe uma inscrição ativa com este CPF neste evento." |
| RN-12 | (sem mensagem — devolve a inscrição já criada) |
| RN-13 | "Você precisa aceitar o regulamento do evento para continuar." |
| RN-L1 | "Todo lote precisa de um limite: uma data, uma quantidade de vagas, ou os dois." |
| RN-L2 | "Já existe um lote nesta posição. Cada lote ocupa uma posição diferente na sequência." |
| RN-L5 | "O lote de inscrição mudou enquanto você preenchia o formulário. Agora vale o {lote}, por {valor}." / "As vagas do {lote} acabaram neste instante." |
| RN-L9 | "Os lotes de inscrição se esgotaram." |
| RN-L12 | "Não é possível excluir este lote: {n} inscrição(ões) vieram dele." / "Este lote já ocupou {n} vaga(s). A quantidade não pode ser menor do que isso." |

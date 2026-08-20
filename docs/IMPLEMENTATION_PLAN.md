# Plano de implementação

> **Versão:** 1.2 · **Data:** 2026-08-20 · **Revisado ao final da Fase 5a**
> Divide o trabalho em dez fases. As fases 0 a 4 estão detalhadas porque são o que esta entrega cobre. As fases 5 a 9 estão em alto nível, para que a direção esteja clara sem congelar decisões que ainda vão amadurecer.
>
> **Estado real em 2026-08-20:** as fases 0 a 4 e a **Fase 5a** (site público, inscrição em quatro etapas e cobrança Pix) estão **concluídas e verificadas** — 205 testes Pest passando (795 asserções), 12 cenários Playwright passando num navegador que imita um celular, `pint`, `eslint` e `npm run build` limpos. Falta a **Fase 5b** (área do participante) e as fases 6 a 9 não foram iniciadas.

---

## Visão geral

| Fase | Nome | Nesta entrega | Resultado esperado |
|------|------|:-------------:|--------------------|
| 0 | Planejamento e documentação | ✅ | Sete documentos consistentes entre si |
| 1 | Base do projeto | ✅ | Aplicação rodando, banco de pé, autenticação administrativa funcionando |
| 2 | Domínio do evento | ✅ | Evento configurável com dias, grupos, atividades, cidades e grupos de participantes |
| 3 | Inscrição | ✅ | Inscrição válida com reserva de vaga à prova de concorrência |
| 4 | Pagamento simulado | ✅ | Cobrança Pix, aviso automático, expiração e reconciliação |
| 5a | Site público | ✅ | Página do evento, formulário de inscrição em quatro etapas e cobrança Pix |
| 5b | Área do participante | ❌ | Acompanhamento da inscrição por link assinado |
| 6 | Administração | ❌ | Painel com números e cadastros |
| 7 | Comunicação | ❌ | E-mails em fila |
| 8 | Provedor real | ❌ | Pix de verdade em produção |
| 9 | Endurecimento | ❌ | Auditoria, desempenho, revisão de segurança e LGPD |

---

## Fase 0 — Planejamento e documentação ✅

**Objetivo:** decidir antes de codar, e deixar registrado o porquê de cada decisão.

| Etapa | Entrega | Situação |
|-------|---------|:--------:|
| 0.1 | `docs/PRD.md` com 24 seções + Glossário; `docs/PROGRESS.md` | ✅ |
| 0.2 | `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/BUSINESS_RULES.md` | ✅ |
| 0.3 | `docs/PAYMENTS.md`, `docs/IMPLEMENTATION_PLAN.md` e revisão cruzada dos documentos | ✅ |

**Decisões tomadas nesta fase** (detalhadas em `PROGRESS.md`): domínio em português sem acento; inscrição sem estado "pago"; reserva por contador atômico; dinheiro em centavos; CPF cifrado com impressão digital separada; sem tabela de aceite de termos; cidades e grupos como catálogo global.

**Critério de conclusão:** os sete documentos existem, não se contradizem em nomes de tabela, situações e regras de capacidade, e um leigo consegue ler o PRD do começo ao fim.

---

## Fase 1 — Base do projeto ✅

**Objetivo:** ter uma aplicação Laravel 12 rodando com banco, fila e autenticação administrativa, sem nenhuma regra de negócio ainda.

| Etapa | Entrega |
|-------|---------|
| 1.1 | Repositório Git inicializado |
| 1.2 | Projeto Laravel 12 com o kit inicial Vue (Inertia 2 + TypeScript + Tailwind 4) |
| 1.3 | Ambiente em Docker (Laravel Sail) com PostgreSQL 18, Redis e Mailpit |
| 1.4 | `.env` configurado: PostgreSQL, Redis, `APP_TIMEZONE=America/Sao_Paulo`, `PAYMENT_GATEWAY=fake` |
| 1.5 | `config/payments.php` com o provedor padrão e as configurações do provedor simulado |
| 1.6 | Pest e Pint configurados |
| 1.7 | Migrações do kit inicial rodando e autenticação administrativa funcionando |

**O que o kit inicial entrega:** cadastro, login, recuperação de senha, verificação de e-mail e ajustes de perfil. **As tabelas e telas em inglês que ele traz não são traduzidas** — são infraestrutura do framework, não domínio.

**Critério de conclusão:** `php artisan migrate` cria o banco; a suíte de testes do kit inicial passa; é possível autenticar.

---

## Fase 2 — Domínio do evento ✅

**Objetivo:** permitir cadastrar qualquer evento, com qualquer estrutura de dias e atividades, sem mexer no código.

| Etapa | Entrega |
|-------|---------|
| 2.1 | Migrações: `cidades`, `grupos_participantes`, `eventos`, `dias_evento`, `grupos_atividades`, `atividades`, `conflitos_atividades`, com todas as restrições e índices de `DATABASE.md` |
| 2.2 | Enum `SituacaoEvento` com texto amigável |
| 2.3 | Models `Cidade`, `GrupoParticipante`, `Evento`, `DiaEvento`, `GrupoAtividade`, `Atividade`, `ConflitoAtividade`, com relacionamentos e filtros em português |
| 2.4 | Factories para todos os models |
| 2.5 | `CidadeSeeder` e `EventoDemoSeeder` reproduzindo o evento real de dois dias |
| 2.6 | `tests/Feature/Dominio/EventoTest.php` |

**Estrutura do evento de demonstração:**

```text
Copa CCC 2026
├── Dia 1 — Esportes
│   └── Modalidades esportivas   (obrigatório, mínimo 1, máximo 2)
│       ├── Futebol      08:00–10:00
│       ├── Vôlei        09:00–11:00   ← conflita por horário com Futebol
│       ├── Handebol     10:00–12:00   ← encosta em Futebol, sem conflito
│       └── Basquete     14:00–16:00
└── Dia 2 — Trilha
    └── Trilha                    (opcional, máximo 1)
        ├── Trilha leve   07:00–10:00
        └── Trilha longa  07:00–13:00  ← idade mínima 16 anos
```

**Critério de conclusão:** o teste do domínio prova que as restrições do banco realmente recusam dados inválidos — capacidade estourada, contador negativo, data final antes da inicial e par de conflito fora de ordem.

---

## Fase 3 — Inscrição ✅

**Objetivo:** o coração do sistema. Criar uma inscrição válida, com reserva de vaga que resiste a acessos simultâneos.

| Etapa | Entrega |
|-------|---------|
| 3.1 | Migrações `inscricoes` e `inscricoes_atividades`, com as unicidades parciais |
| 3.2 | Enum `SituacaoInscricao` com texto amigável e `estaAtiva()` |
| 3.3 | DTO `DadosNovaInscricao` |
| 3.4 | `ValidadorSelecaoAtividades` — regras RN-03 a RN-08 |
| 3.5 | `ReservarVagas` e `LiberarVagas` — contador atômico na ordem canônica |
| 3.6 | `CriarInscricao` — transação, varredura sob demanda, uma retentativa, tradução de violação de unicidade em erro amigável |
| 3.7 | Exceções `VagasEsgotadasException`, `SelecaoAtividadesInvalidaException`, `InscricaoDuplicadaException` |
| 3.8 | Evento de domínio `InscricaoCriada` |
| 3.9 | `StoreInscricaoRequest` e `InscricaoController@store` |
| 3.10 | Suíte de testes da fase 3, incluindo concorrência com processos paralelos |

**Critério de conclusão:** as 13 regras de `BUSINESS_RULES.md` têm teste passando; o teste paralelo prova que a soma de reservadas e confirmadas nunca passa da capacidade.

---

## Fase 4 — Pagamento simulado ✅

**Objetivo:** fechar o ciclo do dinheiro sem depender de nenhuma instituição financeira.

| Etapa | Entrega |
|-------|---------|
| 4.1 | Migrações `pagamentos` e `webhooks_pagamento` |
| 4.2 | Enums `SituacaoPagamento`, `MetodoPagamento`, `SituacaoWebhook` |
| 4.3 | Contrato `PaymentGateway` e os seis DTOs imutáveis |
| 4.4 | `FakePaymentGateway` completo |
| 4.5 | `PaymentServiceProvider` com escolha por configuração |
| 4.6 | Actions `CriarPagamentoDaInscricao`, `ConfirmarPagamento`, `CancelarPagamento` |
| 4.7 | `PaymentWebhookController` e job `ProcessarWebhookPagamento` |
| 4.8 | `routes/dev.php` com os endereços de simulação bloqueados fora de desenvolvimento |
| 4.9 | Comandos `inscricoes:expirar-vencidas` e `pagamentos:reconciliar`, agendados |
| 4.10 | Eventos `InscricaoConfirmada` e `InscricaoExpirada` |
| 4.11 | Suíte de testes da fase 4 |

Todas as onze etapas foram entregues. Três ajustes de rumo, feitos durante a execução e já refletidos no código:

- O contrato ganhou o método `name()`, além dos seis previstos: as tabelas `pagamentos` e `webhooks_pagamento` precisam saber de qual provedor veio cada registro, e ler isso da configuração dentro do domínio recriaria justamente o acoplamento que o contrato existe para evitar.
- `CancelarPagamento` recebe a situação de destino (`cancelado`, `expirado` ou `falhou`). A mecânica é a mesma nos três casos, e quem chama sabe o motivo.
- O comando de expiração aceita `--evento` (a mesma rotina serve à varredura sob demanda de um evento específico) e o de reconciliação aceita `--margem` e `--lote`.

**Agendamento:** expiração **a cada minuto** — é o menor intervalo possível e é a precisão que a pessoa da fila sente. Reconciliação **a cada cinco minutos**, olhando apenas cobranças a até quinze minutos do vencimento: suficiente para reconhecer o pagamento antes do prazo e educado com o limite de consultas do provedor.

**Critério de conclusão:** é possível, a partir de um banco recém-semeado, criar uma inscrição, gerar a cobrança Pix simulada, simular o pagamento e ver a inscrição confirmada — e também deixar o prazo vencer e ver as vagas voltarem, sem nenhum registro apagado.

**Verificado em 2026-08-20**, nesta ordem, sobre o evento de demonstração (Copa CCC 2026): inscrição criada → evento e as duas atividades escolhidas com 1 vaga reservada cada → cobrança Pix emitida com `expira_em` igual ao `prazo_pagamento` → aviso assinado entregue em `POST /webhooks/pagamentos` (HTTP 200) → inscrição `confirmada`, com evento e atividades passando a 0 reservadas e 1 confirmada. Em seguida, uma segunda inscrição com o prazo vencido → `php artisan inscricoes:expirar-vencidas` devolveu a vaga do evento **e a de cada atividade**, marcou a cobrança como "prazo vencido" e não apagou nenhuma linha (2 inscrições, 2 pagamentos e 4 vínculos permaneceram no banco). A segunda execução do mesmo comando não alterou mais nada.

**O que a Fase 4 não cobre, de propósito:** estorno como fluxo de domínio (o contrato tem `refundPayment()`, mas nenhuma Action o usa — depende da política de reembolso, pendência P-02) e a decisão sobre pagamento reconhecido depois do prazo (pendência P-03; hoje esse aviso é registrado como *ignorado*, e a mudança, se houver, é em um único ponto de `ConfirmarPagamento`).

---

## Fase 5a — Site público ✅

**Objetivo:** dar rosto ao que já funciona por baixo.

| Entrega | Situação |
|---------|:--------:|
| Página `/eventos/{slug}` com nome, descrição, datas, programação por dia, valor, vagas e regulamento | ✅ |
| Evento em rascunho ou cancelado responde 404; inscrições fechadas explicam o motivo no lugar do botão | ✅ |
| Formulário em quatro etapas: dados pessoais, participação, revisão, pagamento | ✅ |
| Atividades com horário, vagas restantes, "Esgotado", "Indisponível — conflito de horário com Futebol" e o contador "2 de 2 selecionadas" | ✅ |
| Tela de pagamento com QR Code, código copia e cola, botão de copiar e contador regressivo | ✅ |
| Volta à cobrança por URL assinada com validade (decisão DA-05, agora tomada) | ✅ |
| Testes de ponta a ponta com Playwright | ✅ 12 cenários |
| Área do participante (linha do tempo, histórico, reenvio do link) | ❌ Fase 5b |

**Regra que não muda:** tudo que a tela valida é conforto. A regra continua sendo a do servidor — e o 422 dele devolve o participante ao passo do campo com problema, com o campo em foco.

**Acessibilidade:** rótulo de verdade em todo campo, erro ligado por `aria-describedby` e anunciado com `role="alert"`, troca de etapa anunciada em região viva, foco visível em todo controle, alvos de toque de 44 px e nenhuma rolagem horizontal a partir de 320 px. Contraste AA medido nos modos claro e escuro.

---

## Fase 5b — Área do participante ❌

**Objetivo:** deixar o participante acompanhar a própria inscrição depois de fechar o navegador.

- Linha do tempo da inscrição: criada, aguardando pagamento, confirmada ou expirada.
- Histórico da cobrança e segunda via do Pix enquanto o prazo não venceu.
- Reenvio do link de acesso (por enquanto o link assinado só chega no redirecionamento logo após a inscrição; e-mail é Fase 7).
- Decidir se o participante pode cancelar a própria inscrição.

---

## Fase 6 — Administração ❌

- Painel com capacidade, inscritos, confirmados, aguardando pagamento, expirados, cancelados, vagas restantes, valor recebido e valor pendente.
- Cadastro de eventos, dias, grupos de atividades, atividades, cidades e grupos de participantes.
- Busca e filtros de inscrições por evento, cidade, grupo, atividade, situação, pagamento e período.
- Ações administrativas: cancelar, confirmar manualmente quando permitido, registrar motivo.
- Políticas de acesso (`Policies`) por perfil.

---

## Fase 7 — Comunicação ❌

- Ouvintes para os anúncios já disparados: `InscricaoCriada`, `InscricaoConfirmada`, `InscricaoExpirada`.
- E-mails: inscrição criada com link de pagamento, lembrete antes do prazo, pagamento confirmado, inscrição expirada, cancelamento.
- Tudo em fila, para que lentidão de servidor de e-mail nunca atrase a inscrição.
- Momento do lembrete configurável (decisão DA-08).
- Estrutura preparada para acrescentar WhatsApp depois sem tocar no domínio.

**Nenhuma regra de inscrição é alterada nesta fase.** Se for preciso alterar, o desenho de eventos de domínio falhou.

---

## Fase 8 — Provedor de pagamento real ❌

- Escolher o provedor com base na matriz de `PAYMENTS.md` e na proposta comercial (decisão DA-01).
- Implementar a classe do provedor cumprindo o contrato existente.
- Cadastrar credenciais e configurar o endereço de aviso no painel do provedor.
- Homologar: cobrança, pagamento, aviso, consulta, cancelamento e estorno.
- Rodar a suíte apontando para o provedor real em homologação.

**Nenhum arquivo de domínio deve mudar.**

---

## Fase 9 — Endurecimento ❌

- Tabela `logs_auditoria` e registro de ações administrativas sensíveis.
- Revisão de desempenho: consultas do painel, índices, cache dos números.
- Revisão de segurança: limite de requisições, cabeçalhos, revisão de dependências.
- Revisão de LGPD: política de retenção e rotina de anonimização (decisão DA-04).
- Testes de carga no caminho de inscrição.
- Credenciamento (check-in) e lista de espera, se o negócio priorizar.

---

## Ordem de trabalho e dependências

```mermaid
flowchart LR
    F0[Fase 0<br/>Documentação] --> F1[Fase 1<br/>Base]
    F1 --> F2[Fase 2<br/>Evento]
    F2 --> F3[Fase 3<br/>Inscrição]
    F3 --> F4[Fase 4<br/>Pagamento]
    F4 --> F5[Fase 5a<br/>Site público]
    F4 --> F6[Fase 6<br/>Administração]
    F4 --> F7[Fase 7<br/>Comunicação]
    F4 --> F8[Fase 8<br/>Provedor real]
    F5 --> F9[Fase 9<br/>Endurecimento]
    F6 --> F9
    F7 --> F9
    F8 --> F9
```

As fases 5, 6, 7 e 8 dependem apenas da fase 4 e podem ser feitas em paralelo por pessoas diferentes. Foi para isso que o domínio foi isolado das telas e do provedor de pagamento.

---

## Rotina antes de cada fase

1. Ler o `PRD.md` e o `PROGRESS.md`.
2. Identificar as dependências da fase.
3. Implementar somente o necessário — nada "para o futuro".
4. Rodar migrações, testes e o verificador de formatação.
5. Corrigir o que falhar.
6. Atualizar o `PROGRESS.md` com o que foi feito, o que foi decidido e o que ficou pendente.

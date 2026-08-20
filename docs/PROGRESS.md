# Progresso

> Atualizado ao final de cada etapa de trabalho. Escrito para ser lido por qualquer pessoa da equipe.
> **Última atualização:** 2026-08-20 — Etapa 4 (base do projeto)

---

## Concluído

- [x] Etapa 1 — `docs/PRD.md` com as 24 seções exigidas + Glossário; `docs/PROGRESS.md`
- [x] Etapa 2 — `docs/ARCHITECTURE.md`, `docs/DATABASE.md` (ERD + dicionário de dados), `docs/BUSINESS_RULES.md` (RN-01 a RN-13 + regras de pagamento)
- [x] Etapa 3 — `docs/PAYMENTS.md` (matriz de provedores com data de consulta) e `docs/IMPLEMENTATION_PLAN.md`; revisão cruzada dos 7 documentos
- [x] Etapa 4 — Base do projeto: Laravel 12 com pacote inicial Vue (Inertia + TypeScript + Tailwind), Laravel Sail com PostgreSQL 18, Redis e Mailpit, `config/payments.php`, fuso `America/Sao_Paulo`, Pest e Pint funcionando. Migrações do framework aplicadas no PostgreSQL e 27 testes do pacote inicial passando

## Em andamento

- [ ] Etapa 5 — Domínio do evento (estrutura de banco e modelos)

## Próximas tarefas
- [ ] Etapa 6 — Dados de exemplo e testes do domínio do evento
- [ ] Etapa 7 — Inscrição (regras, reserva de vaga, concorrência)
- [ ] Etapa 8 — Testes das regras de inscrição
- [ ] Etapa 9 — Pagamento, provedor simulado e aviso automático
- [ ] Etapa 10 — Prazo, expiração, reconciliação e fechamento

---

## Decisões

| # | Decisão | Motivo |
|---|---------|--------|
| D-01 | O domínio (banco, modelos, situações, regras) é escrito em **português, sem acento nem cedilha** | Quem discute as regras do evento fala português. Acento em nome de coluna obrigaria aspas em toda consulta no PostgreSQL |
| D-02 | A estrutura do framework permanece em **inglês** (`app/Http/Controllers`, `app/Jobs`, `created_at`) | É a convenção da ferramenta; mudar quebraria comportamentos automáticos |
| D-03 | A inscrição **não** tem estado "pago" | "Pago" é fato do dinheiro e pertence ao pagamento. Duas fontes da verdade discordariam em caso de estorno |
| D-04 | Reserva de vaga por **contador atômico** (compare-and-swap), não por trava na linha do evento | Evita fila única em eventos de alta procura, com a mesma garantia contra venda a mais |
| D-05 | Ordem de reserva sempre **evento → atividades em ordem crescente de id** | Ordem fixa elimina a possibilidade de duas inscrições travarem uma esperando a outra |
| D-06 | Dinheiro sempre em **centavos, número inteiro** | Número decimal aproximado gera erro de arredondamento em dinheiro |
| D-07 | **Sem tabela de aceite de termos** no MVP: dois campos na inscrição bastam | Menos superfície para o mesmo requisito. Se um dia houver mais de um termo por inscrição, vira tabela |
| D-08 | CPF **cifrado** no banco + **impressão digital** separada para duplicidade | Permite bloquear inscrição duplicada sem manter o número legível |
| D-09 | Cidades e grupos de participantes são **catálogo global**, não pertencem a um evento | Simplifica o cadastro. Se o negócio exigir cidades por evento, é mudança de escopo |
| D-10 | Fuso: aplicação em `America/Sao_Paulo`, banco em UTC, colunas com fuso embutido | Evita erro de horário de verão e de servidor em outro fuso |
| D-11 | `lista_espera` existe na lista de situações mas **não é alcançável** nesta entrega | Reserva o valor para a fase pós-MVP sem criar caminho de código morto |
| D-12 | Nenhum e-mail nesta entrega: apenas os anúncios internos (eventos de domínio) sem ouvintes | A fase 7 adiciona os ouvintes sem tocar nas regras de inscrição |
| D-13 | Situações gravadas como **texto** no banco, controladas por Enum do PHP, em vez do tipo `enum` do PostgreSQL | Acrescentar um valor ao tipo `enum` do PostgreSQL é alteração de esquema com restrições. A lista de situações vai crescer (`lista_espera`) |
| D-14 | O par de `conflitos_atividades` é **normalizado** (`atividade_a_id < atividade_b_id`), garantido por restrição do banco | Sem isso, (7,3) e (3,7) seriam duas linhas para o mesmo conflito e a unicidade não protegeria nada |
| D-15 | Unicidade de e-mail e CPF é **parcial** (só vale para inscrições ativas) | Uma unicidade comum bloquearia para sempre; o participante precisa poder tentar de novo depois da expiração |
| D-16 | Em RN-04, grupo **opcional** com mínimo maior que zero significa "ou nada, ou pelo menos o mínimo" | Interpretação mais restritiva entre as possíveis, conforme a política de ambiguidade do plano |
| D-17 | O provedor de pagamento devolve `parseWebhook` (tradução), não `handleWebhook` (ação) | Mantém a decisão sobre o domínio de um lado só da fronteira; trocar de provedor não muda o efeito na inscrição |
| D-18 | O endereço de webhook responde **200 mesmo com assinatura inválida**, gravando o aviso como inválido e sem produzir efeito | Responder 401 informaria a quem tenta forjar avisos que ele acertou o endereço e errou só a assinatura |
| D-19 | O PostgreSQL do Sail é publicado na porta **55432** do computador (variável `FORWARD_PGSQL_PORT`), e não na 5432 | A porta 5432 já estava ocupada por outro PostgreSQL instalado na máquina de desenvolvimento, que respondia no lugar do contêiner. A porta interna do contêiner continua sendo 5432 |
| D-20 | Os testes rodam no banco `testing` do mesmo PostgreSQL, com `DB_HOST`/`DB_PORT` fixados em `phpunit.xml` | Restrição parcial de unicidade, `CHECK`, `jsonb` e concorrência real só têm valor testados no mesmo motor de produção |

---

## Pendências

| # | Pendência | Responsável |
|---|-----------|-------------|
| P-01 | Escolher o provedor de pagamento real | Dono do produto |
| P-02 | Definir política de reembolso | Dono do produto |
| P-03 | Definir o que fazer com pagamento recebido após o prazo | Dono do produto |
| P-04 | Definir prazo de retenção e descarte de dados pessoais | Dono do produto |
| P-05 | Definir como o participante acessa a inscrição depois (link assinado, código por e-mail) | Fase 5 |
| P-06 | Confirmar as taxas de Pagar.me, Mercado Pago e Asaas diretamente com o comercial | Dono do produto. Ver seção 6.3 de `PAYMENTS.md` |
| P-07 | Ajustar o arquivo `.env` local para `DB_PORT=55432` e `FORWARD_PGSQL_PORT=55432`, como já está em `.env.example` (decisão D-19) | Pessoa desenvolvedora, na própria máquina |

---

## Revisão cruzada dos documentos (etapa 3)

Verificações feitas nos sete documentos, com o resultado:

| Verificação | Resultado |
|-------------|-----------|
| Nomes de tabela iguais em todos os documentos | Sem divergência |
| Situações de inscrição e de pagamento iguais em todos os documentos | Sem divergência |
| A palavra "pago" nunca aparece como situação de inscrição | Confirmado: só aparece como situação de pagamento ou na justificativa da decisão D-03 |
| Nenhum identificador de banco com acento ou cedilha | Confirmado |
| `float`/`double` só aparecem como o que **não** se deve usar | Confirmado |
| `lockForUpdate()` só aparece como prática proibida, com o motivo | Confirmado |
| Regra de capacidade descrita igual no PRD, na arquitetura, no banco e nas regras | Sem divergência |
| Mapeamento dos oito testes exigidos pelo briefing | Presente em `BUSINESS_RULES.md` |
| Diagramas Mermaid válidos | Os 11 diagramas foram renderizados com `@mermaid-js/mermaid-cli`; todos passaram |

Nenhuma contradição exigiu correção. As decisões D-13 a D-18 foram registradas durante a redação dos documentos técnicos.

**Sobre as taxas de gateway:** apenas a Efí publica os percentuais em página aberta. Pagar.me apresenta preço negociado, e Mercado Pago e Asaas não expõem valores em conteúdo público acessível. Essas células ficaram como **"a validar"**, conforme a regra de nunca registrar número comercial não confirmado.

---

## Dependências externas adicionadas

Nenhuma até o momento além do que o framework já entrega.

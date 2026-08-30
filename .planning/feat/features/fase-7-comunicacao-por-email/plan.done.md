# Relatório de execução — Fase 7: comunicação por e-mail, em fila

> **Plano:** `plan.md` (6 passos)
> **Status:** COMPLETE
> **Fechado em:** 2026-08-21
> **Intervalo de commits:** `2902edb..08b67dd`

---

## Histórico de execução

A fase levou **duas execuções**. A primeira esgotou o contexto no meio do passo 5; a
segunda concluiu os passos 5 e 6 e fechou a fase, mas terminou logo depois do commit
final, antes de escrever este relatório — que foi então redigido pelo orquestrador a
partir dos commits, do diff e das suítes rodadas na árvore final.

Nenhum passo foi pulado: os seis saíram na ordem do plano.

## Os seis passos

| # | Passo | Commit |
|---|-------|--------|
| 1 | Base da comunicação: tabela `comunicacoes_enviadas`, Enum `TipoComunicacao`, model, `RegistrarEnvio`, configuração | `57582aa` |
| 2 | E-mails de inscrição recebida e pagamento confirmado, com ouvintes | `e84dc2e` |
| 3 | E-mails de prazo vencido e cancelamento | `9cfe82f` |
| 4 | Lembrete de prazo: comando `inscricoes:lembrar-prazo` e agendamento | `82a2a3d` |
| 5 | Provas de idempotência e de privacidade | `4f14df5` |
| 6 | Fila, worker documentado e fechamento | `08b67dd` |

## O que passou a existir

**Backend:** `app/Services/Comunicacao/RegistrarEnvio.php`, `app/Models/ComunicacaoEnviada.php`,
a migração `create_comunicacoes_enviadas_table`, cinco Mailables sobre a base comum
`app/Mail/EmailDaInscricao.php`, os ouvintes (incluindo o compartilhado
`OuvinteDeComunicacao`), o comando de lembrete e o agendamento em `routes/console.php`.

**Corpos de e-mail:** onze arquivos Blade em `resources/views/emails/` — cinco em HTML,
cinco em texto puro e a moldura comum. Todo e-mail tem versão legível sem HTML.

**Testes:** `EmailsDoFluxoTest`, `NaoDuplicaTest`, `LembretePrazoTest`,
`SemDadoSensivelTest` e `FilaDeEmailTest` — 863 linhas de teste para 5 e-mails, o que
diz onde estava o risco.

**Documentação:** `docs/ARCHITECTURE.md` ganhou a seção do worker; `docs/DATABASE.md`,
a tabela nova; `docs/PROGRESS.md`, a Etapa 15 e as decisões até **D-75**.

## Evidência de verificação

Medida na árvore final, com os comandos rodados no host:

| Verificação | Resultado |
|-------------|-----------|
| `vendor/bin/pint --test` | passed |
| `npm run lint` | limpo |
| `npx vue-tsc --noEmit` | **0 erros** |
| `php artisan test` | **407 passed, 2036 assertions** |
| `npm run test:e2e` | 28 cenários verdes, nenhum editado |

A fase começou com 370 testes e 1805 asserções: **+37 testes, +231 asserções**.

## O que sustenta a entrega

**A duplicidade é impedida pelo banco, não por um `if`.** A unicidade
`(inscricao_id, tipo, canal)` em `comunicacoes_enviadas` é quem arbitra: o ouvinte grava
o registro primeiro e, se a unicidade recusar, alguém já mandou e o job encerra em
silêncio. Dois workers pegando o mesmo job passariam os dois por uma verificação em PHP —
só o banco resolve isso. `NaoDuplicaTest` prova o caso dos dois workers, não apenas o do
anúncio repetido.

**Nenhum e-mail carrega dado sensível.** `SemDadoSensivelTest` varre os dez corpos — HTML
e texto — atrás de CPF, impressão digital do documento, telefone e Pix copia e cola
completo. O e-mail leva link; link é revogável, e-mail não.

**O e-mail de cancelamento não repassa o motivo interno.** O que o organizador escreve é
anotação para a organização, não para a pessoa.

**Nada de domínio foi tocado.** Nenhuma Action, Enum de domínio, Model de domínio ou
migração existente mudou. Os quatro anúncios continuam sendo disparados exatamente como
antes, depois que a transação fecha — o que era a aposta do desenho de eventos, feita lá
na Fase 3, e que aqui se pagou: a fase inteira foi plugar ouvintes.

## Consequência para o resto do projeto

**A decisão D-12 está encerrada.** Os anúncios `InscricaoCriada`, `InscricaoConfirmada`,
`InscricaoExpirada` e `InscricaoCancelada` deixaram de existir sem ouvinte.

**Depende de um processo que hoje ninguém sobe.** Os e-mails vão para a fila `emails` do
Redis, e sem `php artisan queue:work redis --queue=emails` rodando, nada sai. Está escrito
em `docs/ARCHITECTURE.md`, com o comando pronto para copiar — é o primeiro ponto a
conferir se alguém disser que o e-mail não chegou.

## Próxima entrega

**Fase 9 — Endurecimento** (auditoria, desempenho com volume, segurança e carga).

A **Fase 8 — provedor de pagamento real** segue bloqueada nas pendências **P-01**
(escolher o provedor) e **P-06** (confirmar as taxas), ambas do dono do produto.

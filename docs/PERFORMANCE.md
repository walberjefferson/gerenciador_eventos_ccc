# Desempenho — medição com volume real

> **Versão:** 1.0 · **Data:** 2026-08-21 · **Fase 9**
> Escrito para quem precisa **decidir**, não para quem já lê plano de execução de banco de dados.
> Os detalhes técnicos ficam no fim de cada seção, sempre depois da conclusão.

---

## 1. Por que este documento existe

O sistema foi construído com índices e consultas agregadas desde o começo, com bom
raciocínio por trás de cada escolha. Mas **raciocínio não é medição**: com as trinta
inscrições do banco de demonstração, qualquer consulta parece instantânea — inclusive
a que travaria a tela no dia do evento.

Este documento registra o que acontece quando o banco tem **10.000 inscrições**, que é
uma ordem de grandeza acima do evento real esperado. Ele responde a três perguntas:

1. O painel e a lista de inscrições continuam rápidos? **Sim** — dez milésimos de segundo
   ou menos, com dez mil inscrições no banco.
2. Alguma coisa precisou ser corrigida? **Não.** Nenhum índice novo, nenhum cache. A
   seção 5 explica por que **não mexer também é uma decisão**, e uma decisão cara de errar.
3. Existe algum ponto que merece atenção antes do evento? **Sim, um:** a varredura de
   inscrições vencidas, seção 4.6.

A ordem em que este documento foi escrito é proposital: primeiro a medição (seções 3 e 4),
depois a conclusão (seção 5). Otimização sem linha de base é melhoria que ninguém consegue
provar.

---

## 2. Como reproduzir a medição

Tudo roda no computador de quem desenvolve, contra o banco de testes. O seeder e o
comando de medição **recusam-se a rodar** em qualquer ambiente que não seja `local` ou
`testing` — dez mil inscrições falsas no banco de produção não teriam volta.

```bash
# 1. Banco limpo
DB_DATABASE=testing DB_PORT=55432 CACHE_STORE=array \
  php artisan migrate:fresh --force

# 2. Volume: 10.000 inscrições, 19.999 escolhas de atividade, 10.565 cobranças
DB_DATABASE=testing DB_PORT=55432 CACHE_STORE=array \
  php artisan db:seed --class=Database\\Seeders\\VolumeSeeder --force

# 3. Medição (use --plano para ver o EXPLAIN ANALYZE completo)
DB_DATABASE=testing DB_PORT=55432 CACHE_STORE=array \
  QUEUE_CONNECTION=database MAIL_MAILER=array SESSION_DRIVER=array \
  php artisan desempenho:medir --repeticoes=5 --plano
```

O comando `desempenho:medir` **não reescreve consulta nenhuma**: ele chama os mesmos
serviços, o mesmo controlador e a mesma rotina que o sistema usa em produção. O que ele
acrescenta é o cronômetro e o `EXPLAIN ANALYZE` (a explicação que o próprio PostgreSQL dá
sobre como executou a consulta) da consulta mais cara de cada cenário.

**Por que existe um comando em vez de um script jogado fora depois:** a fase exige o
número *antes* e o número *depois* de cada correção. Duas medições feitas por caminhos
diferentes não provam nada; o mesmo comando, rodado duas vezes, prova.

### O que há no banco durante a medição

| Tabela | Linhas |
|--------|-------:|
| `inscricoes` | 10.000 |
| `inscricoes_atividades` | 19.999 |
| `pagamentos` | 10.565 |
| `atividades` | 36 |
| `grupos_participantes` | 40 |

Distribuição das inscrições: 5.500 confirmadas, 2.000 aguardando pagamento, 1.800
expiradas, 700 canceladas. Não é uniforme de propósito — um evento real tem muito mais
confirmada do que cancelada, e o banco escolhe caminhos diferentes conforme essa
proporção. Uma em cada dez inscrições confirmadas tem **duas** cobranças (uma que venceu
e outra que foi paga), que é o caso que faz a consulta "a cobrança mais recente" trabalhar
de verdade.

### Máquina da medição

Apple M5 (macOS 25.5), PHP 8.4.22, PostgreSQL 18.6 rodando em contêiner Docker, acesso
pela porta 55432. Máquina de desenvolvimento, não servidor. Os números servem para
**comparar entre si** e para detectar ordem de grandeza; não são promessa de tempo em
produção.

---

## 3. O resumo, em uma tabela

Cada cenário roda cinco vezes. O número que vale é a **mediana**: a primeira execução paga
o custo de encher a memória do banco e não representa o dia a dia.

| # | Consulta | **ANTES** (mediana) | Pior caso | Consultas SQL | O que foi feito | **DEPOIS** |
|---|----------|--------------------:|----------:|--------------:|-----------------|-----------:|
| 1 | Painel — os três blocos | **5,1 ms** | 15,4 ms | 3 | nada — §5.1 | **4,8 ms** |
| 2a | Lista de inscrições — sem filtro | **4,9 ms** | 10,2 ms | 6 | nada — §5.1 | **4,9 ms** |
| 2b | Lista de inscrições — filtros combinados | **10,0 ms** | 12,7 ms | 7 | nada — §5.1 | **10,7 ms** |
| 3 | Exportação CSV — evento inteiro | **690,5 ms** | 760,7 ms | 1 | nada — §5.2 | **699,6 ms** |
| 4 | Página pública do evento | **9,9 ms** | 37,3 ms | 4 | nada — §5.1 | **7,7 ms** |
| 5 | Expirar 2.000 inscrições vencidas de uma vez | **17.934 ms** | 18.961 ms | 17.990 | nada — §5.3, corrigir mudaria regra de negócio | **18.058 ms** |

A coluna **DEPOIS** é uma segunda medição completa, feita com o banco recarregado do zero,
depois de fechada a análise. Como nada foi alterado, ela serve a dois propósitos: mostrar
que a linha de base **se repete** (diferença de menos de 1 ms nas telas) e deixar registrado
o número contra o qual uma futura otimização terá de se comparar.

**Leitura em uma frase:** as quatro consultas que uma pessoa espera na tela respondem em
**dez milésimos de segundo ou menos**, com dez mil inscrições no banco. A exportação do
arquivo leva sete décimos de segundo, e a varredura de vencidas é a única que exige
conversa — §4.6.

---

## 4. Consulta por consulta

### 4.1 Painel do organizador — 5,1 ms

Os três blocos do painel (inscrições por situação, vagas por atividade, dinheiro) saem de
**três consultas agregadas**, uma por bloco. Nenhuma delas traz linha para o PHP contar:
quem conta é o banco.

O PostgreSQL escolheu ler a tabela `inscricoes` inteira em vez de usar o índice
`(evento_id, situacao)` — e **essa escolha está certa**. As 10.000 inscrições pertencem
ao mesmo evento; percorrer o índice para depois buscar todas as linhas seria mais caro do
que ler a tabela de uma vez. É exatamente o que aconteceria no dia do evento, com um
evento dominando o banco.

<details><summary>Plano de execução</summary>

```
HashAggregate  (actual time=2.874..2.877 rows=4 loops=1)
  ->  Hash Join  (actual time=1.489..2.320 rows=10565 loops=1)
        ->  Seq Scan on pagamentos  (actual time=0.003..0.303 rows=10565 loops=1)
        ->  Hash  (actual time=1.474..1.475 rows=10000 loops=1)
              ->  Seq Scan on inscricoes  (actual time=0.002..0.581 rows=10000 loops=1)
Planning Time: 0.117 ms
Execution Time: 2.944 ms
```
</details>

### 4.2 Lista de inscrições sem filtro — 4,9 ms

Seis consultas: a contagem da paginação, a página de 25 linhas e o carregamento
antecipado das relações (evento, grupo, cidade, cobranças). **Seis é o número certo** —
não cresce com a quantidade de inscrições, que é o que caracterizaria o problema
conhecido como "N+1" (uma consulta a mais para cada linha da tela).

O banco ordena as 10.000 linhas por data e devolve as 25 primeiras usando *top-N
heapsort*, que é a técnica em que ele mantém só as 25 melhores na memória em vez de
ordenar tudo. Custa 1,4 ms.

### 4.3 Lista com os filtros mais pesados combinados — 10,0 ms

Atividade + situação + situação da cobrança + período, todos ao mesmo tempo. Sete
consultas, 10 ms. O filtro por atividade usa o índice
`inscricoes_atividades_atividade_id_index`, e o filtro por "situação da cobrança mais
recente" usa `pagamentos_inscricao_id_situacao_index` — **os dois índices que já existiam
foram usados**, o que confirma que a decisão de criá-los na Fase 1 estava certa.

### 4.4 Exportação CSV do evento inteiro — 690,5 ms

Uma única consulta traz as 10.000 linhas com nome do evento, cidade, grupo, situação da
cobrança e lista de atividades. O arquivo sai por streaming, linha a linha, então a
memória do servidor não cresce com o tamanho do evento.

**690 ms para gerar um arquivo de dez mil linhas é aceitável.** É um download que a pessoa
pede conscientemente e espera; não é uma tela que precisa aparecer instantaneamente.

Duas observações honestas, registradas como **hipóteses não confirmadas** (§5.2):

- O banco precisou usar disco para ordenar (`external merge Disk: 5984kB`), porque ordena
  linhas largas — inclui o documento cifrado, que é um campo grande e que o CSV **nem
  exporta**.
- As buscas de cidade e grupo repetem-se 10.000 vezes, mas sobre tabelas minúsculas
  (40 e 10 linhas), sempre em memória. O custo real disso é próximo de zero.

O que fazer com as duas fica registrado na §5.

### 4.5 Página pública do evento — 9,9 ms

Quatro consultas: o evento, os dias, os grupos de atividades e as 36 atividades. Não
cresce com o número de inscrições — a página pública não olha para inscrição nenhuma.
A programação inteira, com três dias e 36 atividades, sai em menos de dez milésimos de
segundo.

### 4.6 Expirar 2.000 inscrições vencidas de uma vez — 17,9 segundos ⚠️

Este é o único número deste relatório que merece atenção. Vale explicar com cuidado.

**O que a rotina faz.** De minuto em minuto, o sistema procura inscrições que passaram do
prazo de pagamento e as marca como expiradas, devolvendo as vagas que elas prendiam. Cada
inscrição é tratada **uma por uma, dentro da sua própria transação**, com uma escrita
condicional ("só muda se ainda estiver aguardando pagamento"). É essa escrita condicional
— e não uma trava de banco — que garante que duas execuções simultâneas nunca devolvam a
mesma vaga duas vezes.

**Onde está o custo.** Não há consulta lenta aqui. São **17.990 idas e voltas ao banco**
para 2.000 inscrições — cerca de nove por inscrição (marcar a inscrição, devolver a vaga
do evento, devolver a vaga de cada atividade, encerrar a cobrança, recarregar o registro,
anunciar o evento interno que dispara o e-mail). Cada ida custa em torno de um milésimo de
segundo; o problema é a quantidade, não a velocidade de cada uma.

**Por que não foi corrigido.** Corrigir significaria fazer tudo em lote: um único comando
marcando as 2.000 inscrições, um único comando devolvendo os contadores. Isso mudaria
**regra de negócio**, não desempenho:

- a escrita condicional por linha deixaria de existir, e com ela a garantia contra
  devolução dupla de vaga quando duas execuções se cruzam;
- o anúncio por inscrição — que dispara o e-mail de "prazo vencido" para a pessoa certa —
  deixaria de acontecer naturalmente.

O plano da Fase 9 é explícito: *"consulta lenta cuja correção exigiria mudar regra de
domínio → PARE. Desempenho não paga o preço de correção."* Foi o que se fez.

**O risco de verdade, em português claro.** A rotina agendada roda a cada minuto e, no dia
a dia, encontra poucas inscrições vencidas por vez; 18 segundos só aconteceriam se 2.000
prazos vencessem no mesmo minuto, que não é o comportamento esperado. Só que a mesma
rotina é chamada **na hora da inscrição**, quando o contador diz "lotado": antes de recusar
alguém, o sistema varre as reservas vencidas daquele evento para ver se alguma vaga já
voltou. Se houver acúmulo grande de vencidas nesse momento, **a pessoa que está se
inscrevendo espera pela varredura**.

**Recomendação registrada, não implementada:** limitar a varredura sob demanda a um teto de
inscrições por chamada (por exemplo, as 100 mais antigas), deixando o resto para a rotina
agendada. É mudança de regra de negócio — precisa de decisão do dono do produto e de plano
próprio. Fica como pendência **P-10** em `PROGRESS.md`.

---

## 5. O que foi corrigido — e por que quase nada foi

### 5.1 Nenhum índice novo foi criado

**Esta é a conclusão principal do relatório, e ela é boa notícia.**

Todas as consultas que uma pessoa espera olhando para a tela respondem em 10 ms ou menos
com 10.000 inscrições no banco. Os índices criados na Fase 1 —
`inscricoes(evento_id, situacao)`, `inscricoes(situacao, prazo_pagamento)`,
`pagamentos(inscricao_id, situacao)`, `pagamentos(situacao, expira_em)` e
`inscricoes_atividades(atividade_id)` — apareceram nos planos de execução sendo usados
(§4.3), ou foram corretamente **ignorados** pelo banco quando ler a tabela inteira era mais
barato (§4.1). Nos dois casos, a decisão original se sustentou na medição.

Criar índice "por precaução" seria o erro fácil de cometer aqui. **Todo índice custa na
escrita**, e escrita é justamente o caminho da inscrição — o único que não pode ficar
lento, porque é o que a pessoa faz enquanto disputa uma vaga com outras. Índice sem medição
que o justifique é custo garantido em troca de benefício imaginário.

### 5.2 Nenhum cache foi ligado

O painel responde em 5 ms. Um cache de 60 segundos existiria para economizar 5 ms e, em
troca, mostraria número velho ao organizador no dia em que ele mais precisa do número
certo. **Não compensa.**

Como não há cache, também **não há aviso de atraso na tela** — e não deve haver: o painel
mostra o número de agora, e escrever "pode estar até um minuto atrasado" seria mentira.

As duas observações da §4.4 (a ordenação que vai ao disco na exportação e as subconsultas
repetidas sobre tabelas minúsculas) ficam registradas como **hipóteses não confirmadas**:
são coisas que *pareceriam* úteis de otimizar, mas que a medição não pediu. Se um dia a
exportação passar de dois segundos, este é o primeiro lugar para olhar.

### 5.3 Nenhuma regra de negócio foi alterada

O único número desconfortável do relatório — os 18 segundos da §4.6 — tem correção
conhecida e ela foi **recusada de propósito**, porque custaria uma garantia de
concorrência e um e-mail ao participante. O motivo completo está na §4.6, e a
recomendação virou a pendência **P-10**.

---

## 6. Teste de carga — 50 processos disputando as últimas vagas

### 6.1 O que foi simulado

O momento em que este sistema fica realmente sob pressão não é o dia do evento: é o
minuto em que o link das inscrições cai no grupo da comunidade e todo mundo abre ao mesmo
tempo. **Cinquenta processos** de sistema operacional, cada um com a sua própria conexão
com o banco, largando no mesmo instante para disputar **cinco vagas** da mesma atividade.

Não é simulação de tela: cada processo percorre o caminho de verdade da inscrição —
reserva de vaga no evento, reserva de vaga na atividade e gravação da inscrição, tudo
dentro da mesma transação. É o mesmo teste que `ConcorrenciaTest` já fazia com seis
processos desde a Fase 3, agora com pressão de verdade. O código está em
`tests/Feature/Inscricoes/CargaTest.php`, e a máquina de disputa que os dois testes
compartilham, em `tests/Feature/Inscricoes/Disputa.php`.

### 6.2 O resultado

| O que precisava ficar provado | Resultado |
|---|---|
| A capacidade nunca é furada, nem por uma | ✅ **5 entraram, 45 foram recusados.** `vagas_reservadas + vagas_confirmadas = 5`, exatamente a capacidade |
| Ninguém trava esperando outro (sem impasse) | ✅ Nenhum erro de *deadlock*; nenhum processo falhou por motivo alheio à disputa |
| Nenhuma vaga fica presa por quem foi recusado | ✅ O contador do evento também ficou em 5 — a transação inteira volta atrás quando a vaga falta na atividade |

**Tempo de resposta do caminho da inscrição, sob disputa de 50 processos:**

| Medida | Tempo |
|---|---|
| Mínimo | **0,200 s** |
| Mediana | **0,396 s** |
| p95 | **0,442 s** |
| Máximo | **0,455 s** |

### 6.3 Como ler esses números

O pior caso entre cinquenta pedidos simultâneos ficou em **menos de meio segundo**. Mais
importante que o valor: a diferença entre o mais rápido e o mais lento é de um quarto de
segundo. Quando existe fila — alguém esperando a tranca de outro para poder gravar —, essa
distância cresce e o último a chegar espera o tempo de todos os anteriores somado. Aqui ela
não cresceu, e é isso que confirma na prática a decisão de arquitetura: a garantia de vaga
vem de **gravação condicional**, não de `lockForUpdate()`. Quem perde a disputa recebe
"esgotado" na hora, em vez de ficar parado numa fila invisível esperando para descobrir
que não havia vaga.

O teste registra o tempo no relatório, mas **não transforma esses números em teto de
falha**. O único limite que ele cobra é largo — mediana abaixo de cinco segundos —, e serve
apenas para pegar regressão grosseira. Máquina de desenvolvimento oscila, e teste que falha
por milissegundo é teste que a equipe aprende a ignorar. O que o teste cobra com rigor é a
capacidade, e essa não tem folga nenhuma.

---

## 7. O que fica garantido por teste automático

`tests/Feature/Desempenho/ConsultasDoPainelTest.php` roda em toda execução da suíte e
falha se alguém, no futuro, transformar uma consulta agregada em contagem no PHP ou
introduzir um "N+1" nas telas do painel e da lista. Ele não substitui esta medição — os
tetos de tempo de um teste automático são folgados de propósito, porque a máquina que roda
a suíte varia. O que ele garante é a **quantidade de consultas**, que é o número que não
pode crescer.

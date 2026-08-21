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

1. O painel e a lista de inscrições continuam rápidos?
2. Alguma coisa precisa ser corrigida?
3. Existe algum ponto que merece atenção antes do evento?

> **Estado deste documento:** medição **antes** concluída. A seção 5 (o que foi corrigido)
> e a seção 6 (teste de carga) são preenchidas nos passos seguintes da fase. A ordem é
> proposital: medir primeiro, corrigir depois. Otimização sem linha de base é melhoria que
> ninguém consegue provar.

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
| 1 | Painel — os três blocos | **5,1 ms** | 15,4 ms | 3 | a decidir | — |
| 2a | Lista de inscrições — sem filtro | **4,9 ms** | 10,2 ms | 6 | a decidir | — |
| 2b | Lista de inscrições — filtros combinados | **10,0 ms** | 12,7 ms | 7 | a decidir | — |
| 3 | Exportação CSV — evento inteiro | **690,5 ms** | 760,7 ms | 1 | a decidir | — |
| 4 | Página pública do evento | **9,9 ms** | 37,3 ms | 4 | a decidir | — |
| 5 | Expirar 2.000 inscrições vencidas de uma vez | **17.934 ms** | 18.961 ms | 17.990 | a decidir | — |

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

Duas observações honestas, registradas como **hipóteses** a avaliar na §5:

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

O que fazer com esse número está na §5.

---

## 5. O que foi corrigido

> **A preencher no passo seguinte da fase.** Esta versão do documento registra apenas a
> medição **antes**, sem nenhuma otimização aplicada.

---

## 6. Teste de carga — 50 processos disputando as últimas vagas

Preenchido no fechamento da fase. Ver `tests/Feature/Inscricoes/CargaTest.php`.

---

## 7. O que fica garantido por teste automático

`tests/Feature/Desempenho/ConsultasDoPainelTest.php` roda em toda execução da suíte e
falha se alguém, no futuro, transformar uma consulta agregada em contagem no PHP ou
introduzir um "N+1" nas telas do painel e da lista. Ele não substitui esta medição — os
tetos de tempo de um teste automático são folgados de propósito, porque a máquina que roda
a suíte varia. O que ele garante é a **quantidade de consultas**, que é o número que não
pode crescer.

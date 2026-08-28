# Pedido de orientação — barra lateral administrativa

> **Plano:** correcao-barra-lateral-admin
> **Momento:** passo 8 (conferência nos dois tamanhos), com tudo o mais já verde
> **Estado:** nada comitado, árvore de trabalho intacta

---

## Pergunta que bloqueia

A correção conserta a barra em tela grande **exatamente** como o plano previu, mas
**muda a largura da gaveta do celular de 230px para 295px** — e o desenho que existia
antes da Etapa 21 era **288px**. Aceito os 295px e registro, ou acrescento o modificador
`!` (uma linha, dentro de um arquivo que o plano já autoriza) para cravar os 288px?

A §6 do plano manda parar quando uma tela muda de aparência além de a barra sair de cima
do conteúdo. Esta mudou.

---

## Contexto

### O que o plano assumiu, e que não é verdade

O diagnóstico do plano diz:

> "Abaixo de 768px o componente `Sidebar` nem chega a renderizar esse trecho — ele vira
> uma gaveta (`Sheet`), **que não usa nenhuma das classes quebradas**."

Isso está errado, e o próprio plano se contradiz: a **linha 43** de `Sidebar.vue` — uma
das seis ocorrências que a tabela do diagnóstico manda corrigir — é justamente a da
gaveta do celular:

```
class="w-[--sidebar-width] bg-sidebar p-0 text-sidebar-foreground [&>button]:hidden"
```

Corrigir as seis ocorrências, como o dono do produto pediu, mexe **sim** no celular.

### Por que a gaveta não voltou aos 288px

Medido no navegador, no painel administrativo, com o Pixel 5 de 393px de largura:

| Estado | Largura da gaveta | Classes de largura que sobrevivem |
|---|---|---|
| Antes da Etapa 21 (Tailwind 3) | **288px** (18rem) | só `w-[--sidebar-width]` |
| Hoje, com o defeito | **230px** | nenhuma válida — a caixa encolhe até o conteúdo |
| Com a correção do plano | **295px** | `w-3/4` **e** `w-(--sidebar-width)` |
| Com a correção mais `!` | **288px** | `w-(--sidebar-width)!` vence |

A causa é um **segundo defeito latente, que o plano não conhece**: o projeto usa
`tailwind-merge` **2.5.5**, que é a versão feita para o Tailwind 3. Ela reconhece
`w-[--sidebar-width]` como uma classe de largura e, por isso, apagava o `w-3/4` que o
`SheetContent` traz de fábrica. A sintaxe nova `w-(--sidebar-width)` só é entendida pelo
`tailwind-merge` **3.x** — a 2.5.5 não a reconhece como largura, deixa as duas classes na
lista, e no CSS construído o `w-3/4` vem depois e vence.

Ou seja: hoje o `w-3/4` está sendo apagado **pelo motivo errado** (a classe quebrada
ainda "parecia" uma largura para o merge). A correção conserta o CSS e, no mesmo gesto,
faz o `w-3/4` voltar à disputa.

Isso importa além desta tela: **qualquer** classe `(--var)` que precise vencer uma classe
do mesmo tipo vinda de um `variant` do shadcn vai se comportar assim enquanto o
`tailwind-merge` for 2.x. Hoje o único lugar com essa disputa é a gaveta — conferi as dez
ocorrências uma a uma.

### O que já está provado (tudo verde)

- Fonte limpa: `grep -rn -o '[a-z-]*-\[--[a-z0-9-]*\]' resources/js` → zero linhas
- CSS construído: **6 declarações inválidas → 0**
- `npx vue-tsc --noEmit` → zero erros
- `npm run lint` → passa
- `./vendor/bin/pest` → **543 passed** (542 + o novo), nenhum pulado
- `npm run test:e2e` → **46 passed** (44 existentes + 2 novos)
- O cenário novo **fica vermelho** com o defeito de volta (`Expected: 256, Received: 272`)
  e a varredura de CSS também (`max-width: --skeleton-width`, com a mensagem que ensina o
  conserto)
- Em tela grande, com os olhos e com medida: barra de 256px na coluna da esquerda, borda
  direita encostando na borda esquerda do `<main>`, botão de recolher visível, recolhe
  para a faixa de ícones e volta

---

## Opções consideradas

### A) Aceitar os 295px e registrar a mudança

- **A favor:** nenhuma linha a mais; os 230px de hoje nunca foram desenho, eram o defeito;
  295px está mais perto do original do que 230px.
- **Contra:** a gaveta continua **sem obedecer** o `SIDEBAR_WIDTH_MOBILE` que o próprio
  componente declara — ela passa a valer 75% da tela, por acidente. E é uma mudança de
  aparência numa tela que o plano jurou não tocar, o que a **DA-45** reserva ao dono do
  produto.

### B) Acrescentar `!` na linha 43 de `Sidebar.vue` → `w-(--sidebar-width)!`

- **A favor:** devolve **exatamente** os 288px de antes da Etapa 21, que é o que a §6 do
  plano pede ("devolver o desenho que existia antes da Etapa 21"). Cabe em um caractere,
  dentro de um arquivo que a tabela de saída do plano já autoriza. **Medido: 288px.**
- **Contra:** `!important` é remendo. Ele esconde o defeito real (o `tailwind-merge`
  desatualizado) em vez de nomeá-lo, e a próxima pessoa que encontrar o `!` não vai saber
  por que ele está ali — a menos que o comentário conte, o que eu escreveria.

### C) Subir o `tailwind-merge` para 3.x

- **A favor:** conserta a causa, não o sintoma, e protege todas as futuras classes
  `(--var)`.
- **Contra:** é **troca de versão maior de dependência**, que o plano não lista — e as
  minhas regras de parada mandam parar diante de dependência não prevista. A 3.x muda o
  comportamento de merge de várias famílias de classe e teria de ser conferida nos 23
  componentes de interface. Isso é plano próprio, não um passo deste.

### D) Deixar a linha 43 como está (só ela)

- **Descartada:** deixaria uma declaração inválida viva no CSS, e a varredura nova —
  que este mesmo plano manda criar — ficaria vermelha. O plano se contradiria.

---

## O que já foi feito (não comitado)

| Arquivo | Situação |
|---|---|
| `resources/js/components/ui/sidebar/Sidebar.vue` | modificado — 6 ocorrências, **sem** o `!` da opção B |
| `resources/js/components/ui/sidebar/SidebarMenuSkeleton.vue` | modificado |
| `resources/js/components/NavUser.vue` | modificado |
| `resources/js/components/ui/navigation-menu/NavigationMenuViewport.vue` | modificado |
| `tests/Feature/Interface/CssConstruidoTest.php` | criado, passa, e provado que fica vermelho com o defeito |
| `tests/e2e/admin-barra-lateral.spec.ts` | criado, 2 cenários em 1280×800, passam, e provado que ficam vermelhos com o defeito |
| `docs/PROGRESS.md` | **não escrito ainda** — a decisão D-86 depende desta resposta |
| `docs/ARCHITECTURE.md` | **não escrito ainda** — mesma razão |

`public/build` está reconstruído (é ignorado pelo git).

---

## Duas observações que a resposta talvez queira levar em conta

1. **A correção do `NavUser.vue` também muda uma aparência**, e essa está autorizada: o
   menu da conta passa a ter a largura do gatilho em vez da largura mínima. É exatamente o
   defeito que a **DA-45** mandou registrar em vez de corrigir, e que o dono do produto
   desta vez mandou corrigir ("corrigir as seis"). Não é motivo de dúvida — só não quero
   que apareça como surpresa depois.

2. **Dois defeitos pré-existentes**, que não toquei e que não bloqueiam nada aqui:
   - `./vendor/bin/phpunit` **não roda** neste projeto, com ou sem a minha mudança: ele
     morre carregando `tests/Feature/Admin/AutorizacaoTest.php` com "Please run
     [./vendor/bin/pest] instead". O executor real é `./vendor/bin/pest`. Como o
     `.github/workflows/tests.yml` chama `./vendor/bin/phpunit`, **o passo de testes do CI
     está quebrado** — e isso enfraquece a premissa do plano de que a varredura de CSS
     estaria protegida pelo CI. `.github/` não está na tabela de saída, então não mexi.
   - `npm run format:check` reprova **52 arquivos**, exatamente os mesmos antes e depois
     da minha mudança (comparei as duas saídas: são idênticas byte a byte). Nenhum dos
     quatro arquivos que editei está na lista.

---

## Arquivos envolvidos

- `/Users/binho/Projetos/gestao_eventos_ccc/resources/js/components/ui/sidebar/Sidebar.vue` (linha 43)
- `/Users/binho/Projetos/gestao_eventos_ccc/resources/js/components/ui/sheet/SheetContent.vue` (de onde vem o `w-3/4`)
- `/Users/binho/Projetos/gestao_eventos_ccc/package.json` (`tailwind-merge` 2.5.5)
- `/Users/binho/Projetos/gestao_eventos_ccc/tests/e2e/admin-barra-lateral.spec.ts`
- `/Users/binho/Projetos/gestao_eventos_ccc/tests/Feature/Interface/CssConstruidoTest.php`

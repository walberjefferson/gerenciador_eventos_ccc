<script setup lang="ts">
import { DateField } from '@/components/ui/date-field';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AtividadeParaEscolha, DiaParaEscolha, InscricaoEmEdicao, OpcaoDeGrupoParticipante, OpcaoDeSetor, OpcaoDeSituacao } from '@/types/admin';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * A correção de uma inscrição que já existe.
 *
 * Mora em página própria, e não num diálogo: são nove campos mais a
 * programação inteira do evento para escolher. Num diálogo, isso viraria uma
 * caixa rolando dentro de outra caixa rolando — justamente no celular, que é
 * onde a secretaria costuma resolver esse tipo de coisa.
 *
 * **O CPF NÃO ESTÁ AQUI.** Não é esquecimento nem campo desabilitado: trocar o
 * documento mexeria na chave que impede a mesma pessoa de se inscrever duas
 * vezes no mesmo evento, e isso é outro assunto. Evento, lote e valor também
 * ficam de fora — não são correção de cadastro.
 *
 * NADA SOME EM SILÊNCIO. Atividade que foi desativada depois de escolhida
 * continua aparecendo, marcada: escondê-la faria a pessoa perdê-la ao salvar
 * uma correção de nome, sem que ninguém tivesse decidido isso.
 */
const props = defineProps<{
    inscricao: InscricaoEmEdicao;
    grupos: OpcaoDeGrupoParticipante[];
    setores: OpcaoDeSetor[];
    sexos: OpcaoDeSituacao[];
    dias: DiaParaEscolha[];
}>();

/**
 * O SETOR É ESTADO DA TELA, E NÃO CAMPO DO FORMULÁRIO.
 *
 * Ele não entra no `useForm` e não viaja para o servidor: quem determina o
 * setor continua sendo o grupo escolhido, porque o setor é a cidade do grupo —
 * não há coluna de setor na inscrição. O campo existe aqui por um motivo de
 * tela: a lista de grupos de todos os setores juntos é longa demais para se
 * procurar dentro dela.
 */
const setorEscolhido = ref<number | null>(
    props.grupos.find((grupo) => grupo.id === props.inscricao.grupo_participante_id)?.cidade_id ?? props.setores[0]?.id ?? null,
);

const gruposDoSetor = computed<OpcaoDeGrupoParticipante[]>(() => props.grupos.filter((grupo) => grupo.cidade_id === setorEscolhido.value));

const formulario = useForm({
    nome_completo: props.inscricao.nome_completo,
    email: props.inscricao.email,
    telefone: props.inscricao.telefone ?? '',
    data_nascimento: props.inscricao.data_nascimento ?? '',
    sexo: props.inscricao.sexo ?? props.sexos[0]?.valor ?? '',
    grupo_participante_id: props.inscricao.grupo_participante_id,
    atividades: [...props.inscricao.atividades],
    permitir_exceder_capacidade: false as boolean,
});

/**
 * A recusa por lotação é a única que tem saída pela própria tela.
 *
 * Enquanto ela não aparece, a segunda confirmação fica escondida: oferecer de
 * saída um "pode furar a lotação" convidaria a marcá-lo por reflexo, antes de
 * existir problema nenhum. Ela só entra em cena depois que o servidor disse
 * que não cabe.
 */
const lotacaoEsgotada = computed<boolean>(() => (formulario.errors.atividades ?? '').includes('lotação esgotada'));

/**
 * Trocar de setor reduz a lista de grupos — e desfaz a escolha que deixou de
 * caber nela.
 *
 * Deixar o grupo antigo preso no formulário seria pior do que limpá-lo: a tela
 * mostraria um setor e enviaria o grupo de outro, e o erro só apareceria no
 * servidor, sem que nada na tela explicasse a recusa.
 */
function trocarSetor(): void {
    const aindaCabe = gruposDoSetor.value.some((grupo) => grupo.id === formulario.grupo_participante_id);

    if (!aindaCabe) {
        formulario.grupo_participante_id = gruposDoSetor.value[0]?.id ?? 0;
    }
}

/**
 * A data existe mesmo?
 *
 * Enquanto o campo de nascimento era um `<input type="date">`, quem impedia
 * 31/02/2000 era o próprio navegador — não havia como digitar um dia que não
 * existe. Com o campo digitável do projeto isso deixou de ser verdade, e a
 * conferência precisa ser escrita: fevereiro não tem 31 dias, e quem digitou
 * merece ouvir isso aqui em vez de esperar a viagem até o servidor. É a mesma
 * conferência que o formulário público faz no mesmo campo.
 */
function dataExiste(iso: string): boolean {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return false;
    }

    const [ano, mes, dia] = iso.split('-').map(Number);
    const candidata = new Date(Date.UTC(ano, mes - 1, dia));

    // O Date "corrige" 31/02 para 03/03 em silêncio. Se o que voltou não é o
    // que entrou, a data digitada não existe no calendário.
    return candidata.getUTCFullYear() === ano && candidata.getUTCMonth() === mes - 1 && candidata.getUTCDate() === dia;
}

/** Hoje em ISO — o mesmo teto que o calendário do campo respeita. */
const hojeEmIso = new Date().toLocaleDateString('sv-SE');

/** A recusa da data escrita na própria tela, antes de ir ao servidor. */
const erroDaData = ref<string | null>(null);

function conferirData(): string | null {
    if (formulario.data_nascimento === '') {
        return 'Informe a data de nascimento.';
    }

    if (!dataExiste(formulario.data_nascimento)) {
        return 'Esta data não existe. Confira o dia e o mês.';
    }

    return formulario.data_nascimento > hojeEmIso ? 'A data de nascimento não pode estar no futuro.' : null;
}

function alternar(atividade: AtividadeParaEscolha): void {
    const marcada = formulario.atividades.includes(atividade.id);

    formulario.atividades = marcada ? formulario.atividades.filter((id) => id !== atividade.id) : [...formulario.atividades, atividade.id];

    // Mudou a escolha, mudou o problema: a confirmação de furar a lotação não
    // pode sobreviver a uma seleção diferente da que a provocou.
    formulario.permitir_exceder_capacidade = false;
}

/** "das 9h às 11h", ou nada quando a atividade ocupa o dia inteiro. */
function horario(atividade: AtividadeParaEscolha): string {
    if (atividade.comeca_em === null) {
        return 'Dia inteiro';
    }

    const hora = (iso: string): string => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    return atividade.termina_em === null ? hora(atividade.comeca_em) : `${hora(atividade.comeca_em)} às ${hora(atividade.termina_em)}`;
}

/** "12 de 30 vagas", ou "sem limite" quando a atividade não tem teto. */
function vagas(atividade: AtividadeParaEscolha): string {
    return atividade.capacidade === null ? 'sem limite de vagas' : `${atividade.vagas_ocupadas} de ${atividade.capacidade} vagas`;
}

function lotada(atividade: AtividadeParaEscolha): boolean {
    return atividade.capacidade !== null && atividade.vagas_ocupadas >= atividade.capacidade && !atividade.escolhida;
}

function dataEmPortugues(iso: string): string {
    return new Date(`${iso}T12:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/** A regra do bloco, escrita antes de a pessoa tentar. */
function regraDoGrupo(grupo: DiaParaEscolha['grupos'][number]): string {
    if (grupo.obrigatorio && grupo.max_selecoes === 1 && grupo.min_selecoes === 1) {
        return 'Escolha 1';
    }

    const teto = grupo.max_selecoes === null ? 'sem máximo' : `no máximo ${grupo.max_selecoes}`;

    return grupo.obrigatorio ? `Mínimo ${grupo.min_selecoes}, ${teto}` : `Opcional · ${teto}`;
}

function gravar(): void {
    erroDaData.value = conferirData();

    if (erroDaData.value !== null) {
        return;
    }

    formulario.put(route('admin.inscricoes.update', { inscricao: props.inscricao.id }), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        :titulo="`Editar ${props.inscricao.nome_completo}`"
        :descricao="`Inscrição ${props.inscricao.codigo_publico} no evento ${props.inscricao.evento}.`"
    >
        <div>
            <Link
                :href="route('admin.inscricoes.show', { inscricao: props.inscricao.id })"
                class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
            >
                Voltar para a ficha
            </Link>
        </div>

        <!-- Trocar de atividade só move vaga quando existe vaga presa (RN-E3).
             Dito antes, ninguém procura depois a vaga que "sumiu". -->
        <p v-if="!props.inscricao.move_vagas" role="status" class="border-border bg-muted/40 rounded-md border px-4 py-2 text-sm">
            Esta inscrição está <strong>{{ props.inscricao.situacao_rotulo.toLowerCase() }}</strong> e não ocupa vaga. Corrigir os dados continua
            valendo; trocar de atividade aqui não devolve nem prende vaga nenhuma.
        </p>

        <form class="grid gap-6" @submit.prevent="gravar">
            <section aria-labelledby="titulo-pessoa" class="border-border grid gap-4 rounded-lg border p-4">
                <h2 id="titulo-pessoa" class="text-lg font-semibold">Dados da pessoa inscrita</h2>

                <!-- O CPF não aparece de propósito: ele fica cifrado e não é
                     corrigível por aqui. Dizer isso em voz alta evita a
                     pergunta "cadê o CPF?" a cada uso da tela. -->
                <p class="text-muted-foreground max-w-3xl text-sm">
                    O CPF não pode ser corrigido por aqui: ele é a chave que impede a mesma pessoa de se inscrever duas vezes no mesmo evento.
                </p>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label for="inscricao-nome" class="text-sm font-medium">Nome completo</label>
                        <input
                            id="inscricao-nome"
                            v-model="formulario.nome_completo"
                            type="text"
                            maxlength="160"
                            required
                            :aria-describedby="formulario.errors.nome_completo ? 'erro-inscricao-nome' : undefined"
                            :aria-invalid="formulario.errors.nome_completo ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.nome_completo" id="erro-inscricao-nome" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.nome_completo }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="inscricao-email" class="text-sm font-medium">E-mail</label>
                        <input
                            id="inscricao-email"
                            v-model="formulario.email"
                            type="email"
                            maxlength="190"
                            required
                            aria-describedby="ajuda-inscricao-email"
                            :aria-invalid="formulario.errors.email ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <!-- RN-E7: corrigir o e-mail não reenvia nada sozinho.
                             Quem decide reenviar é a pessoa, pela ficha. -->
                        <p id="ajuda-inscricao-email" class="text-muted-foreground text-sm">
                            Vale para as próximas mensagens. Corrigir aqui não reenvia nada: o reenvio fica na ficha.
                        </p>
                        <p v-if="formulario.errors.email" role="alert" class="text-destructive text-sm">{{ formulario.errors.email }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="inscricao-telefone" class="text-sm font-medium">Telefone com DDD</label>
                        <input
                            id="inscricao-telefone"
                            v-model="formulario.telefone"
                            type="text"
                            maxlength="40"
                            required
                            :aria-invalid="formulario.errors.telefone ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <p v-if="formulario.errors.telefone" role="alert" class="text-destructive text-sm">{{ formulario.errors.telefone }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="inscricao-nascimento" class="text-sm font-medium">Data de nascimento</label>
                        <!-- O campo do projeto, e não o `type="date"` do
                             navegador: o nativo entrega a aparência ao sistema
                             operacional e sai no formato dele, que nem sempre é
                             o do Brasil. O v-model continua trocando ISO, que é
                             o que o servidor manda e valida. -->
                        <DateField
                            id="inscricao-nascimento"
                            v-model="formulario.data_nascimento"
                            name="data_nascimento"
                            :max="hojeEmIso"
                            rotulo-do-calendario="Escolher a data de nascimento no calendário"
                            class="border-input h-10 rounded-md border px-3 text-sm"
                            :aria-invalid="erroDaData || formulario.errors.data_nascimento ? true : undefined"
                            :aria-describedby="
                                erroDaData || formulario.errors.data_nascimento ? 'erro-inscricao-nascimento' : 'ajuda-inscricao-nascimento'
                            "
                            @blur="erroDaData = conferirData()"
                        />
                        <!-- A idade mínima das atividades é conferida de novo na
                             hora de gravar, com a data NOVA (RN-E1). -->
                        <p id="ajuda-inscricao-nascimento" class="text-muted-foreground text-sm">
                            A idade mínima das atividades escolhidas é conferida de novo com esta data.
                        </p>
                        <p
                            v-if="erroDaData || formulario.errors.data_nascimento"
                            id="erro-inscricao-nascimento"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ erroDaData ?? formulario.errors.data_nascimento }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="inscricao-sexo" class="text-sm font-medium">Sexo</label>
                        <select
                            id="inscricao-sexo"
                            v-model="formulario.sexo"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option v-for="sexo in props.sexos" :key="sexo.valor" :value="sexo.valor">{{ sexo.rotulo }}</option>
                        </select>
                        <p v-if="formulario.errors.sexo" role="alert" class="text-destructive text-sm">{{ formulario.errors.sexo }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="inscricao-setor" class="text-sm font-medium">Setor</label>
                        <select
                            id="inscricao-setor"
                            v-model="setorEscolhido"
                            data-testid="setor-da-inscricao"
                            aria-describedby="ajuda-inscricao-setor"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            @change="trocarSetor"
                        >
                            <option v-for="setor in props.setores" :key="setor.id" :value="setor.id">{{ setor.nome }}</option>
                        </select>
                        <!-- O setor não é campo da inscrição: ele é a cidade do
                             grupo, e só existe aqui para encurtar a lista de
                             baixo. Quem vai gravado é o grupo. -->
                        <p id="ajuda-inscricao-setor" class="text-muted-foreground text-sm">
                            É o setor que decide quem confere o pagamento desta inscrição.
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="inscricao-grupo" class="text-sm font-medium">Grupo</label>
                        <select
                            id="inscricao-grupo"
                            v-model="formulario.grupo_participante_id"
                            data-testid="grupo-da-inscricao"
                            :aria-describedby="formulario.errors.grupo_participante_id ? 'erro-inscricao-grupo' : 'ajuda-inscricao-grupo'"
                            :aria-invalid="formulario.errors.grupo_participante_id ? true : undefined"
                            class="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option v-for="grupo in gruposDoSetor" :key="grupo.id" :value="grupo.id">{{ grupo.nome }}</option>
                        </select>
                        <p id="ajuda-inscricao-grupo" class="text-muted-foreground text-sm">Só os grupos do setor escolhido acima.</p>
                        <p v-if="formulario.errors.grupo_participante_id" id="erro-inscricao-grupo" role="alert" class="text-destructive text-sm">
                            {{ formulario.errors.grupo_participante_id }}
                        </p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="titulo-atividades" class="border-border grid gap-4 rounded-lg border p-4">
                <h2 id="titulo-atividades" class="text-lg font-semibold">Atividades escolhidas</h2>

                <p class="text-muted-foreground max-w-3xl text-sm">
                    Valem as mesmas regras do formulário de inscrição: choque de horário, conflito declarado, mínimo e máximo por bloco e idade
                    mínima.
                </p>

                <p
                    v-if="formulario.errors.atividades"
                    role="alert"
                    class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm"
                >
                    {{ formulario.errors.atividades }}
                </p>

                <!-- A segunda confirmação para furar a lotação (RN-E2). Só
                     aparece depois da recusa: sem problema à vista, ela seria
                     marcada por reflexo. -->
                <div v-if="lotacaoEsgotada" class="border-border bg-muted/40 grid gap-2 rounded-md border px-3 py-3">
                    <label class="flex items-start gap-2 text-sm font-medium">
                        <input
                            v-model="formulario.permitir_exceder_capacidade"
                            type="checkbox"
                            class="border-input focus-visible:ring-ring mt-0.5 size-4 rounded focus-visible:ring-2 focus-visible:outline-hidden"
                            data-testid="permitir-exceder-capacidade"
                        />
                        <span>Inscrever mesmo assim, aumentando a lotação da atividade</span>
                    </label>
                    <p class="text-muted-foreground text-sm">
                        A lotação da atividade sobe para caber exatamente esta pessoa, e fica registrado quem autorizou. Para todo o resto do sistema
                        a atividade continua cheia.
                    </p>
                </div>

                <div v-for="dia in props.dias" :key="dia.id" class="grid gap-3">
                    <h3 class="text-base font-semibold">{{ dia.nome }} · {{ dataEmPortugues(dia.data) }}</h3>

                    <fieldset v-for="grupo in dia.grupos" :key="grupo.id" class="grid gap-2">
                        <legend class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm font-medium">
                            {{ grupo.nome }}
                            <span class="text-muted-foreground text-xs font-normal">({{ regraDoGrupo(grupo) }})</span>
                        </legend>

                        <label
                            v-for="atividade in grupo.atividades"
                            :key="atividade.id"
                            class="border-border hover:bg-muted/40 flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                :checked="formulario.atividades.includes(atividade.id)"
                                :data-testid="`atividade-${atividade.id}`"
                                class="border-input focus-visible:ring-ring mt-0.5 size-4 rounded focus-visible:ring-2 focus-visible:outline-hidden"
                                @change="alternar(atividade)"
                            />

                            <span class="grid gap-0.5">
                                <span class="font-medium">{{ atividade.nome }}</span>
                                <span class="text-muted-foreground text-xs">
                                    {{ horario(atividade) }} · {{ vagas(atividade) }}
                                    <template v-if="atividade.idade_minima !== null">· a partir de {{ atividade.idade_minima }} anos</template>
                                </span>

                                <!-- Marcadas, e não escondidas: quem corrige
                                     precisa enxergar o que há para resolver. -->
                                <span v-if="!atividade.ativa" class="text-destructive text-xs font-medium">
                                    Desativada na programação. Ela precisa ser desmarcada para que a correção possa ser gravada.
                                </span>
                                <span v-else-if="lotada(atividade)" class="text-destructive text-xs font-medium">Lotada</span>
                            </span>
                        </label>

                        <p v-if="grupo.atividades.length === 0" class="text-muted-foreground text-sm">Nenhuma atividade neste bloco.</p>
                    </fieldset>
                </div>

                <p v-if="props.dias.length === 0" class="text-muted-foreground text-sm">Este evento não tem programação cadastrada.</p>
            </section>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    :disabled="formulario.processing"
                    data-testid="gravar-edicao"
                    class="bg-acao text-acao-foreground focus-visible:ring-ring h-10 rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                >
                    Gravar correção
                </button>

                <Link
                    :href="route('admin.inscricoes.show', { inscricao: props.inscricao.id })"
                    class="border-border focus-visible:ring-ring inline-flex h-10 items-center rounded-md border px-4 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Cancelar
                </Link>
            </div>
        </form>
    </AdminLayout>
</template>

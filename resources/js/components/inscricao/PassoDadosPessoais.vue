<script setup lang="ts">
import CampoMascarado from '@/components/inscricao/CampoMascarado.vue';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { apenasDigitos, mascararCpf, mascararTelefone } from '@/lib/formato';
import type { CidadePublica, FormularioInscricao, GrupoParticipantePublico } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Primeira etapa: quem e a pessoa. Todo campo tem rotulo de verdade e o erro
 * fica ligado ao campo por aria-describedby, para o leitor de tela anunciar o
 * problema junto com o nome do campo.
 *
 * Os sete campos moram numa GRADE UNICA de duas colunas — o `.fields` do
 * prototipo —, e nao em tres grades empilhadas com larguras proprias. A
 * largura "do tamanho do dado" que valia aqui antes tinha um custo maior do
 * que o ganho: CPF e nascimento ficavam encolhidos no meio da linha e a coluna
 * da direita sumia na altura deles, quebrando o alinhamento do formulario
 * inteiro. Quem precisa de largura propria e so o nome, que ocupa as duas
 * colunas.
 */
const props = defineProps<{
    cidades: CidadePublica[];
    gruposDaCidade: GrupoParticipantePublico[];
    avisoSemGrupos: string | null;
    erros: Record<string, string>;
    /**
     * Confere UM campo quando a pessoa sai dele.
     *
     * Quem decide o que e valido continua sendo a tela de cima (e, no fim, o
     * servidor): aqui so avisamos que o campo foi abandonado.
     */
    aoSairDoCampo?: (campo: string) => void;
}>();

const formulario = defineModel<FormularioInscricao>({ required: true });

/**
 * Telefone e CPF escrevem sozinhos a pontuacao enquanto a pessoa digita.
 *
 * Os dois sao numeros longos que ninguem confere de cabeca: em bloco, "11988881111"
 * e "11122233396" nao denunciam um digito trocado de lugar. Pontuados, sim.
 *
 * O QUE CADA UM GUARDA E DIFERENTE, e isso e de proposito:
 *
 * - O TELEFONE guarda o texto pontuado, que ja e o que este formulario grava
 *   hoje quando alguem digita com parenteses. E um recado para uma pessoa ler
 *   e ligar de volta — o formato faz parte do dado.
 *
 * - O CPF guarda SO OS DIGITOS. Ele nao e recado, e identificador: e dele que
 *   sai o `documento_hash`, e e por esse hash que o sistema reconhece
 *   inscricao repetida. Deixar a pontuacao entrar tambem mudaria o formato da
 *   coluna em relacao a tudo o que ja foi gravado ate hoje. A mascara e so o
 *   que aparece na caixa; o que viaja continua igual ao de antes.
 *
 * A pontuacao e escrita pelo `CampoMascarado`, que reescreve a caixa a cada
 * tecla — e por isso que letra e caractere especial nao entram: eles nao
 * sobrevivem a mascara, e o elemento e reescrito mesmo quando o valor
 * resultante nao mudou.
 */
const cpfParaOModelo = (mascarado: string): string => apenasDigitos(mascarado);

/** O Select do reka trabalha com texto; os identificadores sao numeros. */
const cidadeSelecionada = computed<string>({
    get: () => (formulario.value.cidade_id === null ? '' : String(formulario.value.cidade_id)),
    set: (valor: string) => {
        formulario.value.cidade_id = valor === '' ? null : Number(valor);
    },
});

const grupoSelecionado = computed<string>({
    get: () => (formulario.value.grupo_participante_id === null ? '' : String(formulario.value.grupo_participante_id)),
    set: (valor: string) => {
        formulario.value.grupo_participante_id = valor === '' ? null : Number(valor);
    },
});

/**
 * O teto do campo de nascimento: hoje, no fuso de quem esta na tela.
 *
 * `toLocaleDateString('sv-SE')` devolve AAAA-MM-DD, que e o formato ISO que o
 * campo troca com o formulario. O `toISOString()` que estava aqui devolvia a
 * data em UTC: depois das 21h no Brasil, o teto virava o dia seguinte.
 */
const hoje = new Date().toLocaleDateString('sv-SE');

function erro(campo: string): string | undefined {
    return props.erros[campo];
}

function sair(campo: string): void {
    props.aoSairDoCampo?.(campo);
}
</script>

<template>
    <!--
        .fields — uma grade SO, de duas colunas com 18px de intervalo e 26px de
        topo, virando uma coluna abaixo de 640px.

        Antes eram tres grades empilhadas, e uma delas com `sm:max-w-md`: CPF e
        nascimento sobravam encolhidos no meio da largura, e a coluna da
        direita deixava de existir na altura deles. Numa grade unica os sete
        campos alinham nas mesmas duas colunas, do primeiro ao ultimo.
    -->
    <div class="mt-[26px] grid gap-[18px] sm:grid-cols-2">
        <!-- .f--full — o nome ocupa as duas colunas: e o unico dado sem
             tamanho previsivel. -->
        <div class="space-y-[7px] sm:col-span-2">
            <Label for="nome_completo" class="text-[14.5px] font-medium">Nome completo</Label>
            <Input
                id="nome_completo"
                v-model="formulario.nome_completo"
                name="nome_completo"
                type="text"
                autocomplete="name"
                placeholder="Como está no documento"
                class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] text-base"
                :aria-invalid="erro('nome_completo') ? 'true' : undefined"
                :aria-describedby="erro('nome_completo') ? 'erro-nome_completo' : undefined"
                @blur="sair('nome_completo')"
            />
            <p v-if="erro('nome_completo')" id="erro-nome_completo" role="alert" class="text-destructive text-[13.5px] font-medium">
                {{ erro('nome_completo') }}
            </p>
        </div>

        <div class="space-y-[7px]">
            <Label for="email" class="text-[14.5px] font-medium">E-mail</Label>
            <Input
                id="email"
                v-model="formulario.email"
                name="email"
                type="email"
                autocomplete="email"
                inputmode="email"
                placeholder="nome@email.com"
                class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] text-base"
                :aria-invalid="erro('email') ? 'true' : undefined"
                :aria-describedby="erro('email') ? 'erro-email' : 'ajuda-email'"
                @blur="sair('email')"
            />
            <!-- .f__e no LUGAR do .f__n: onde ha erro, a nota de ajuda sai. Duas
                 frases pequenas embaixo do mesmo campo competem entre si, e a
                 que precisa ser lida e a do erro. -->
            <p v-if="erro('email')" id="erro-email" role="alert" class="text-destructive text-[13.5px] font-medium">{{ erro('email') }}</p>
            <p v-else id="ajuda-email" class="text-muted-foreground text-[13.5px]">Enviamos a confirmação para este endereço.</p>
        </div>

        <div class="space-y-[7px]">
            <Label for="telefone" class="text-[14.5px] font-medium">Telefone com DDD</Label>
            <CampoMascarado
                id="telefone"
                v-model="formulario.telefone"
                :mascara="mascararTelefone"
                name="telefone"
                type="tel"
                autocomplete="tel"
                inputmode="tel"
                placeholder="(00) 00000-0000"
                maxlength="15"
                class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] font-mono text-base tabular-nums"
                :aria-invalid="erro('telefone') ? 'true' : undefined"
                :aria-describedby="erro('telefone') ? 'erro-telefone' : 'ajuda-telefone'"
                @blur="sair('telefone')"
            />
            <p v-if="erro('telefone')" id="erro-telefone" role="alert" class="text-destructive text-[13.5px] font-medium">
                {{ erro('telefone') }}
            </p>
            <p v-else id="ajuda-telefone" class="text-muted-foreground text-[13.5px]">Usado só se precisarmos falar com você.</p>
        </div>

        <div class="space-y-[7px]">
            <Label for="documento" class="text-[14.5px] font-medium">CPF</Label>
            <CampoMascarado
                id="documento"
                v-model="formulario.documento"
                :mascara="mascararCpf"
                :para-o-modelo="cpfParaOModelo"
                name="documento"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="000.000.000-00"
                maxlength="14"
                class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] font-mono text-base tabular-nums"
                :aria-invalid="erro('documento') ? 'true' : undefined"
                :aria-describedby="erro('documento') ? 'erro-documento' : 'ajuda-documento'"
                @blur="sair('documento')"
            />
            <p v-if="erro('documento')" id="erro-documento" role="alert" class="text-destructive text-[13.5px] font-medium">
                {{ erro('documento') }}
            </p>
            <p v-else id="ajuda-documento" class="text-muted-foreground text-[13.5px]">Só os números.</p>
        </div>

        <div class="space-y-[7px]">
            <Label for="data_nascimento" class="text-[14.5px] font-medium">Data de nascimento</Label>
            <DateField
                id="data_nascimento"
                v-model="formulario.data_nascimento"
                name="data_nascimento"
                :max="hoje"
                class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] font-mono text-base tabular-nums"
                :aria-invalid="erro('data_nascimento') ? 'true' : undefined"
                :aria-describedby="erro('data_nascimento') ? 'erro-data_nascimento' : 'ajuda-data_nascimento'"
                @blur="sair('data_nascimento')"
            />
            <p v-if="erro('data_nascimento')" id="erro-data_nascimento" role="alert" class="text-destructive text-[13.5px] font-medium">
                {{ erro('data_nascimento') }}
            </p>
            <!--
                EXCECAO DELIBERADA AO DESENHO. O prototipo escreve "Algumas
                atividades têm idade mínima."; aqui fica "mínima ou máxima"
                porque o sistema aceita as DUAS regras e recusa a inscricao
                pelas duas. Escrever so uma faria a tela mentir sobre a propria
                validacao, e quem fosse recusado por idade maxima nao teria
                lido nada a respeito.
            -->
            <p v-else id="ajuda-data_nascimento" class="text-muted-foreground text-[13.5px]">Algumas atividades têm idade mínima ou máxima.</p>
        </div>

        <div class="space-y-[7px]">
            <Label for="cidade_id" class="text-[14.5px] font-medium">Cidade</Label>
            <Select v-model="cidadeSelecionada">
                <SelectTrigger
                    id="cidade_id"
                    class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] text-base"
                    :aria-invalid="erro('cidade_id') ? 'true' : undefined"
                    :aria-describedby="erro('cidade_id') ? 'erro-cidade_id' : undefined"
                >
                    <SelectValue placeholder="Escolha a sua cidade" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="cidade in cidades" :key="cidade.id" :value="String(cidade.id)">{{ cidade.rotulo }}</SelectItem>
                </SelectContent>
            </Select>
            <p v-if="erro('cidade_id')" id="erro-cidade_id" role="alert" class="text-destructive text-[13.5px] font-medium">
                {{ erro('cidade_id') }}
            </p>
        </div>

        <div class="space-y-[7px]">
            <Label for="grupo_participante_id" class="text-[14.5px] font-medium">Grupo</Label>
            <Select v-model="grupoSelecionado" :disabled="formulario.cidade_id === null || gruposDaCidade.length === 0">
                <SelectTrigger
                    id="grupo_participante_id"
                    class="border-input h-[50px] rounded-[10px] border-[1.5px] px-[14px] text-base"
                    :aria-invalid="erro('grupo_participante_id') ? 'true' : undefined"
                    :aria-describedby="erro('grupo_participante_id') ? 'erro-grupo_participante_id' : 'ajuda-grupo'"
                >
                    <!-- O texto de espera diz o que fazer ANTES, e nao so o que
                         escolher: sem cidade a lista esta vazia, e "Escolha o
                         seu grupo" seria um convite para um campo que nao
                         responde. -->
                    <SelectValue :placeholder="formulario.cidade_id === null ? 'Escolha a cidade primeiro' : 'Escolha o seu grupo'" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="grupo in gruposDaCidade" :key="grupo.id" :value="String(grupo.id)">{{ grupo.nome }}</SelectItem>
                </SelectContent>
            </Select>
            <p
                v-if="erro('grupo_participante_id')"
                id="erro-grupo_participante_id"
                role="alert"
                class="text-destructive text-[13.5px] font-medium"
            >
                {{ erro('grupo_participante_id') }}
            </p>
            <p v-else id="ajuda-grupo" class="text-muted-foreground text-[13.5px]">
                {{ avisoSemGrupos ?? 'A lista mostra os grupos da cidade escolhida.' }}
            </p>
        </div>
    </div>
</template>

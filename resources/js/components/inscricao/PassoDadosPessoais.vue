<script setup lang="ts">
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { CidadePublica, FormularioInscricao, GrupoParticipantePublico } from '@/types/inscricao';
import { computed } from 'vue';

/**
 * Primeira etapa: quem e a pessoa. Todo campo tem rotulo de verdade e o erro
 * fica ligado ao campo por aria-describedby, para o leitor de tela anunciar o
 * problema junto com o nome do campo.
 *
 * Os campos tem a largura do dado que recebem, e nao a largura da tela. CPF,
 * data de nascimento e telefone tem tamanho conhecido e fixo; uma caixa de 740
 * pixels para onze digitos nao e generosidade, e uma pista errada — ela sugere
 * que cabe mais coisa ali. Nome e e-mail, que variam de verdade, seguem
 * ocupando a linha inteira.
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

const hoje = new Date().toISOString().slice(0, 10);

function erro(campo: string): string | undefined {
    return props.erros[campo];
}

function sair(campo: string): void {
    props.aoSairDoCampo?.(campo);
}
</script>

<template>
    <div class="space-y-5">
        <div class="space-y-2">
            <Label for="nome_completo">Nome completo</Label>
            <Input
                id="nome_completo"
                v-model="formulario.nome_completo"
                name="nome_completo"
                type="text"
                autocomplete="name"
                class="h-11"
                :aria-invalid="erro('nome_completo') ? 'true' : undefined"
                :aria-describedby="erro('nome_completo') ? 'erro-nome_completo' : undefined"
                @blur="sair('nome_completo')"
            />
            <p v-if="erro('nome_completo')" id="erro-nome_completo" role="alert" class="text-destructive text-sm font-medium">
                {{ erro('nome_completo') }}
            </p>
        </div>

        <!-- E-mail e telefone lado a lado a partir do tablet: os dois sao "como
             falamos com voce", e lidos juntos fazem mais sentido do que
             separados por uma rolagem. -->
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="space-y-2">
                <Label for="email">E-mail</Label>
                <Input
                    id="email"
                    v-model="formulario.email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    inputmode="email"
                    class="h-11"
                    :aria-invalid="erro('email') ? 'true' : undefined"
                    :aria-describedby="erro('email') ? 'erro-email' : 'ajuda-email'"
                    @blur="sair('email')"
                />
                <p id="ajuda-email" class="text-muted-foreground text-sm">É por ele que enviaremos a confirmação da sua inscrição.</p>
                <p v-if="erro('email')" id="erro-email" role="alert" class="text-destructive text-sm font-medium">{{ erro('email') }}</p>
            </div>

            <div class="space-y-2">
                <Label for="telefone">Telefone com DDD</Label>
                <Input
                    id="telefone"
                    v-model="formulario.telefone"
                    name="telefone"
                    type="tel"
                    autocomplete="tel"
                    inputmode="tel"
                    class="h-11 font-mono tabular-nums"
                    :aria-invalid="erro('telefone') ? 'true' : undefined"
                    :aria-describedby="erro('telefone') ? 'erro-telefone' : undefined"
                    @blur="sair('telefone')"
                />
                <p v-if="erro('telefone')" id="erro-telefone" role="alert" class="text-destructive text-sm font-medium">{{ erro('telefone') }}</p>
            </div>
        </div>

        <!-- CPF e data de nascimento: dois dados de tamanho fixo. A caixa para
             de crescer em telas grandes porque onze digitos nao ficam mais
             legiveis por ocuparem meia tela. -->
        <div class="grid gap-5 sm:max-w-md sm:grid-cols-2">
            <div class="space-y-2">
                <Label for="documento">CPF</Label>
                <Input
                    id="documento"
                    v-model="formulario.documento"
                    name="documento"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    class="h-11 font-mono tabular-nums"
                    :aria-invalid="erro('documento') ? 'true' : undefined"
                    :aria-describedby="erro('documento') ? 'erro-documento' : 'ajuda-documento'"
                    @blur="sair('documento')"
                />
                <p id="ajuda-documento" class="text-muted-foreground text-sm">Só os números, sem ponto nem traço.</p>
                <p v-if="erro('documento')" id="erro-documento" role="alert" class="text-destructive text-sm font-medium">{{ erro('documento') }}</p>
            </div>

            <div class="space-y-2">
                <Label for="data_nascimento">Data de nascimento</Label>
                <DateField
                    id="data_nascimento"
                    v-model="formulario.data_nascimento"
                    name="data_nascimento"
                    :max="hoje"
                    class="font-mono tabular-nums"
                    :aria-invalid="erro('data_nascimento') ? 'true' : undefined"
                    :aria-describedby="erro('data_nascimento') ? 'erro-data_nascimento' : 'ajuda-data_nascimento'"
                    @blur="sair('data_nascimento')"
                />
                <p id="ajuda-data_nascimento" class="text-muted-foreground text-sm">Algumas atividades têm idade mínima ou máxima.</p>
                <p v-if="erro('data_nascimento')" id="erro-data_nascimento" role="alert" class="text-destructive text-sm font-medium">
                    {{ erro('data_nascimento') }}
                </p>
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="space-y-2">
                <Label for="cidade_id">Cidade</Label>
                <Select v-model="cidadeSelecionada">
                    <SelectTrigger
                        id="cidade_id"
                        :aria-invalid="erro('cidade_id') ? 'true' : undefined"
                        :aria-describedby="erro('cidade_id') ? 'erro-cidade_id' : undefined"
                    >
                        <SelectValue placeholder="Escolha a sua cidade" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="cidade in cidades" :key="cidade.id" :value="String(cidade.id)">{{ cidade.rotulo }}</SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="erro('cidade_id')" id="erro-cidade_id" role="alert" class="text-destructive text-sm font-medium">{{ erro('cidade_id') }}</p>
            </div>

            <div class="space-y-2">
                <Label for="grupo_participante_id">Grupo</Label>
                <Select v-model="grupoSelecionado" :disabled="formulario.cidade_id === null || gruposDaCidade.length === 0">
                    <SelectTrigger
                        id="grupo_participante_id"
                        :aria-invalid="erro('grupo_participante_id') ? 'true' : undefined"
                        :aria-describedby="erro('grupo_participante_id') ? 'erro-grupo_participante_id' : 'ajuda-grupo'"
                    >
                        <SelectValue placeholder="Escolha o seu grupo" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="grupo in gruposDaCidade" :key="grupo.id" :value="String(grupo.id)">{{ grupo.nome }}</SelectItem>
                    </SelectContent>
                </Select>
                <p id="ajuda-grupo" class="text-muted-foreground text-sm">
                    {{ avisoSemGrupos ?? 'A lista mostra apenas os grupos da cidade que você escolheu.' }}
                </p>
                <p v-if="erro('grupo_participante_id')" id="erro-grupo_participante_id" role="alert" class="text-destructive text-sm font-medium">
                    {{ erro('grupo_participante_id') }}
                </p>
            </div>
        </div>
    </div>
</template>

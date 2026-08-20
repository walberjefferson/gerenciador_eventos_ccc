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
 */
const props = defineProps<{
    cidades: CidadePublica[];
    gruposDaCidade: GrupoParticipantePublico[];
    avisoSemGrupos: string | null;
    erros: Record<string, string>;
}>();

const formulario = defineModel<FormularioInscricao>({ required: true });

/** O Select do radix trabalha com texto; os identificadores sao numeros. */
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
            />
            <p v-if="erro('nome_completo')" id="erro-nome_completo" role="alert" class="text-sm font-medium text-destructive">
                {{ erro('nome_completo') }}
            </p>
        </div>

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
            />
            <p id="ajuda-email" class="text-sm text-muted-foreground">É por ele que enviaremos a confirmação da sua inscrição.</p>
            <p v-if="erro('email')" id="erro-email" role="alert" class="text-sm font-medium text-destructive">{{ erro('email') }}</p>
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
                class="h-11"
                :aria-invalid="erro('telefone') ? 'true' : undefined"
                :aria-describedby="erro('telefone') ? 'erro-telefone' : undefined"
            />
            <p v-if="erro('telefone')" id="erro-telefone" role="alert" class="text-sm font-medium text-destructive">{{ erro('telefone') }}</p>
        </div>

        <div class="space-y-2">
            <Label for="documento">CPF</Label>
            <Input
                id="documento"
                v-model="formulario.documento"
                name="documento"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                class="h-11"
                :aria-invalid="erro('documento') ? 'true' : undefined"
                :aria-describedby="erro('documento') ? 'erro-documento' : 'ajuda-documento'"
            />
            <p id="ajuda-documento" class="text-sm text-muted-foreground">Só os números, sem ponto nem traço.</p>
            <p v-if="erro('documento')" id="erro-documento" role="alert" class="text-sm font-medium text-destructive">{{ erro('documento') }}</p>
        </div>

        <div class="space-y-2">
            <Label for="data_nascimento">Data de nascimento</Label>
            <DateField
                id="data_nascimento"
                v-model="formulario.data_nascimento"
                name="data_nascimento"
                :max="hoje"
                :aria-invalid="erro('data_nascimento') ? 'true' : undefined"
                :aria-describedby="erro('data_nascimento') ? 'erro-data_nascimento' : 'ajuda-data_nascimento'"
            />
            <p id="ajuda-data_nascimento" class="text-sm text-muted-foreground">Algumas atividades têm idade mínima ou máxima.</p>
            <p v-if="erro('data_nascimento')" id="erro-data_nascimento" role="alert" class="text-sm font-medium text-destructive">
                {{ erro('data_nascimento') }}
            </p>
        </div>

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
            <p v-if="erro('cidade_id')" id="erro-cidade_id" role="alert" class="text-sm font-medium text-destructive">{{ erro('cidade_id') }}</p>
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
            <p id="ajuda-grupo" class="text-sm text-muted-foreground">
                {{ avisoSemGrupos ?? 'A lista mostra apenas os grupos da cidade que você escolheu.' }}
            </p>
            <p v-if="erro('grupo_participante_id')" id="erro-grupo_participante_id" role="alert" class="text-sm font-medium text-destructive">
                {{ erro('grupo_participante_id') }}
            </p>
        </div>
    </div>
</template>

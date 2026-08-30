<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * As credenciais do provedor de pagamento.
 *
 * **É a tela mais perigosa do sistema**: o que se digita aqui decide para qual
 * conta bancária vai o dinheiro do evento. Três decisões governam o desenho, e
 * nenhuma delas é estética.
 *
 * 1. **Nenhum valor guardado volta para cá.** Nem inteiro, nem com os últimos
 *    quatro caracteres à mostra. O servidor manda apenas um "sim, existe um
 *    valor guardado", e é isso que o campo indica. O corolário é a regra que
 *    aparece em toda a tela: **campo em branco mantém o que está guardado** —
 *    nunca apaga. Não poderia ser diferente: ninguém consegue redigitar o que
 *    a tela nunca mostrou.
 *
 * 2. **A URL do webhook é montada aqui, no navegador.** Ela precisa carregar o
 *    valor do webhook para ser colada no painel da Efí — e esse valor é
 *    segredo. Como ele nunca volta do servidor, a URL completa só aparece
 *    depois que a pessoa digita ou gera o valor, que é exatamente o momento em
 *    que ela vai registrá-la na Efí. Enquanto o campo está vazio, a tela mostra
 *    o endereço com um espaço reservado no lugar do valor.
 *
 * 3. **Trocar para produção pede confirmação escrita.** A partir dali as
 *    cobranças são reais. O servidor também exige a confirmação: uma trava que
 *    vive só no navegador cai com um clique no lugar errado.
 */

interface Cadastro {
    id: number;
    ambiente: string;
    ambiente_rotulo: string;
    ativo: boolean;
    completa: boolean;
    tem_client_id: boolean;
    tem_client_secret: boolean;
    tem_chave_pix: boolean;
    tem_webhook_hmac: boolean;
    tem_certificado: boolean;
    certificado_nome: string | null;
    certificado_expira_em: string | null;
    certificado_vencido: boolean;
    atualizado_em: string | null;
    atualizado_por: string | null;
}

interface Ambiente {
    valor: string;
    rotulo: string;
    eh_producao: boolean;
    cadastro: Cadastro | null;
}

interface ResultadoDoTeste {
    ambiente: string;
    sucesso: boolean;
    mensagem: string;
}

const props = defineProps<{
    ambientes: Ambiente[];
    origem: string;
    ambiente_em_uso: string;
    webhook: { base: string; parametro_assinatura: string };
    sucesso: string | null;
    erro: string | null;
    teste: ResultadoDoTeste | null;
}>();

function criarFormulario() {
    return useForm({
        client_id: '',
        client_secret: '',
        chave_pix: '',
        webhook_hmac: '',
        certificado: null as File | null,
    });
}

type FormularioDeCredencial = ReturnType<typeof criarFormulario>;

/** Um formulário por ambiente. Eles não se misturam em nenhum momento. */
const formularios: Record<string, FormularioDeCredencial> = reactive(
    Object.fromEntries(props.ambientes.map((ambiente) => [ambiente.valor, criarFormulario()])),
);

/** O texto que a pessoa precisa digitar para liberar a virada para produção. */
const PALAVRA_DE_CONFIRMACAO = 'PRODUCAO';

const confirmacaoDigitada = ref('');
const trocandoParaProducao = ref(false);

function salvar(ambiente: string): void {
    formularios[ambiente].post(route('admin.pagamentos.credenciais.salvar', { ambiente }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => formularios[ambiente].reset(),
    });
}

function receberArquivo(ambiente: string, evento: Event): void {
    const alvo = evento.target as HTMLInputElement;

    formularios[ambiente].certificado = alvo.files?.[0] ?? null;
}

function testar(ambiente: string): void {
    router.post(route('admin.pagamentos.credenciais.testar', { ambiente }), {}, { preserveScroll: true });
}

function ativar(ambiente: Ambiente): void {
    if (!ambiente.eh_producao) {
        router.post(route('admin.pagamentos.credenciais.ativar', { ambiente: ambiente.valor }), {}, { preserveScroll: true });

        return;
    }

    trocandoParaProducao.value = true;
    confirmacaoDigitada.value = '';
}

function confirmarProducao(): void {
    if (confirmacaoDigitada.value.trim().toUpperCase() !== PALAVRA_DE_CONFIRMACAO) {
        return;
    }

    router.post(
        route('admin.pagamentos.credenciais.ativar', { ambiente: 'producao' }),
        { confirmacao: true },
        {
            preserveScroll: true,
            onFinish: () => {
                trocandoParaProducao.value = false;
                confirmacaoDigitada.value = '';
            },
        },
    );
}

/**
 * Gera um valor aleatório forte para o webhook.
 *
 * Existe porque ninguém deve inventar esse valor à mão: ele é comparado a cada
 * aviso da Efí, e um valor curto ou previsível permitiria que alguém de fora
 * confirmasse inscrição sem pagar. São 32 bytes do gerador do próprio
 * navegador, o mesmo que a criptografia da web usa.
 */
function gerarValorDoWebhook(ambiente: string): void {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);

    formularios[ambiente].webhook_hmac = Array.from(bytes)
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * O endereço que a pessoa cola no painel da Efí.
 *
 * O `?ignorar=` no fim não é enfeite: a Efí acrescenta `/pix` ao endereço
 * registrado quando vai notificar, e o `?ignorar=` faz esse sufixo cair na
 * parte descartável da URL.
 */
function urlDoWebhook(ambiente: string): string {
    const valor = String(formularios[ambiente].webhook_hmac ?? '').trim();
    const parametro = valor === '' ? 'COLE-AQUI-O-VALOR-GERADO' : valor;

    return `${props.webhook.base}?${props.webhook.parametro_assinatura}=${parametro}&ignorar=`;
}

function webhookPronto(ambiente: string): boolean {
    return String(formularios[ambiente].webhook_hmac ?? '').trim() !== '';
}

async function copiar(texto: string): Promise<void> {
    await navigator.clipboard?.writeText(texto);
}

const avisoDeOrigem = computed(() =>
    props.origem === 'banco'
        ? `O sistema está usando o cadastro desta tela, no ambiente de ${props.ambiente_em_uso}.`
        : 'Nenhum ambiente está ativo aqui: o sistema ainda está lendo a configuração do arquivo de ambiente do servidor.',
);

function resultadoDe(ambiente: string): ResultadoDoTeste | null {
    return props.teste?.ambiente === ambiente ? props.teste : null;
}
</script>

<template>
    <AdminLayout
        titulo="Credenciais de pagamento"
        descricao="Aqui ficam guardadas a credencial e o certificado da Efí. Tudo é gravado cifrado e nenhum valor volta para esta tela depois de salvo — por isso um campo deixado em branco mantém o que já está guardado, e nunca apaga."
    >
        <p data-testid="credenciais-origem" role="status" class="rounded-md border border-border bg-muted/40 px-4 py-2 text-sm">
            {{ avisoDeOrigem }}
        </p>

        <p v-if="props.sucesso" data-testid="credenciais-sucesso" role="status" class="rounded-md border border-border bg-muted/40 px-4 py-2 text-sm">
            {{ props.sucesso }}
        </p>

        <p v-if="props.erro" data-testid="credenciais-erro" role="alert" class="rounded-md border border-destructive px-4 py-2 text-sm text-destructive">
            {{ props.erro }}
        </p>

        <section
            v-for="ambiente in props.ambientes"
            :key="ambiente.valor"
            :data-testid="`credenciais-bloco-${ambiente.valor}`"
            class="grid gap-4 rounded-lg border border-border p-4"
            :aria-labelledby="`titulo-${ambiente.valor}`"
        >
            <header class="flex flex-wrap items-center justify-between gap-2">
                <h2 :id="`titulo-${ambiente.valor}`" class="text-lg font-semibold">{{ ambiente.rotulo }}</h2>

                <span
                    v-if="ambiente.cadastro?.ativo"
                    :data-testid="`credenciais-ativo-${ambiente.valor}`"
                    class="rounded-full border border-border px-3 py-1 text-xs font-medium"
                >
                    Em uso agora
                </span>
            </header>

            <p v-if="ambiente.cadastro" class="text-sm text-muted-foreground">
                Guardado
                <template v-if="ambiente.cadastro.atualizado_em">em {{ ambiente.cadastro.atualizado_em }}</template>
                <template v-if="ambiente.cadastro.atualizado_por"> por {{ ambiente.cadastro.atualizado_por }}</template
                >. {{ ambiente.cadastro.completa ? 'O cadastro está completo.' : 'Falta preencher alguma coisa: este ambiente ainda não pode ser ativado.' }}
            </p>
            <p v-else class="text-sm text-muted-foreground">Nada cadastrado neste ambiente ainda.</p>

            <form class="grid gap-4" enctype="multipart/form-data" @submit.prevent="salvar(ambiente.valor)">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label :for="`client-id-${ambiente.valor}`" class="text-sm font-medium">Identificação da aplicação</label>
                        <input
                            :id="`client-id-${ambiente.valor}`"
                            v-model="formularios[ambiente.valor].client_id"
                            type="text"
                            autocomplete="off"
                            :data-testid="`credenciais-client-id-${ambiente.valor}`"
                            :placeholder="ambiente.cadastro?.tem_client_id ? 'Há um valor guardado. Deixe em branco para mantê-lo.' : 'Cole aqui o Client Id do painel da Efí'"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularios[ambiente.valor].errors.client_id" class="text-sm text-destructive">
                            {{ formularios[ambiente.valor].errors.client_id }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label :for="`client-secret-${ambiente.valor}`" class="text-sm font-medium">Chave secreta da aplicação</label>
                        <input
                            :id="`client-secret-${ambiente.valor}`"
                            v-model="formularios[ambiente.valor].client_secret"
                            type="password"
                            autocomplete="new-password"
                            :data-testid="`credenciais-client-secret-${ambiente.valor}`"
                            :placeholder="ambiente.cadastro?.tem_client_secret ? 'Há um valor guardado. Deixe em branco para mantê-lo.' : 'Cole aqui o Client Secret do painel da Efí'"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularios[ambiente.valor].errors.client_secret" class="text-sm text-destructive">
                            {{ formularios[ambiente.valor].errors.client_secret }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label :for="`chave-pix-${ambiente.valor}`" class="text-sm font-medium">Chave Pix da conta que recebe</label>
                        <input
                            :id="`chave-pix-${ambiente.valor}`"
                            v-model="formularios[ambiente.valor].chave_pix"
                            type="text"
                            autocomplete="off"
                            :data-testid="`credenciais-chave-pix-${ambiente.valor}`"
                            :placeholder="ambiente.cadastro?.tem_chave_pix ? 'Há um valor guardado. Deixe em branco para mantê-lo.' : 'A chave Pix da conta do evento'"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <p v-if="formularios[ambiente.valor].errors.chave_pix" class="text-sm text-destructive">
                            {{ formularios[ambiente.valor].errors.chave_pix }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label :for="`webhook-hmac-${ambiente.valor}`" class="text-sm font-medium">Valor de segurança do aviso automático</label>
                        <div class="flex gap-2">
                            <input
                                :id="`webhook-hmac-${ambiente.valor}`"
                                v-model="formularios[ambiente.valor].webhook_hmac"
                                type="text"
                                autocomplete="off"
                                :data-testid="`credenciais-webhook-hmac-${ambiente.valor}`"
                                :placeholder="ambiente.cadastro?.tem_webhook_hmac ? 'Há um valor guardado. Deixe em branco para mantê-lo.' : 'Use o botão ao lado'"
                                class="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            <button
                                type="button"
                                :data-testid="`credenciais-gerar-hmac-${ambiente.valor}`"
                                class="h-10 shrink-0 rounded-md border border-border px-3 text-sm font-medium"
                                @click="gerarValorDoWebhook(ambiente.valor)"
                            >
                                Gerar valor
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Este valor é conferido a cada aviso que a Efí manda. Não invente um à mão: use o botão.
                        </p>
                        <p v-if="formularios[ambiente.valor].errors.webhook_hmac" class="text-sm text-destructive">
                            {{ formularios[ambiente.valor].errors.webhook_hmac }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <label :for="`certificado-${ambiente.valor}`" class="text-sm font-medium">Certificado (.p12 ou .pem)</label>
                    <input
                        :id="`certificado-${ambiente.valor}`"
                        type="file"
                        accept=".p12,.pfx,.pem"
                        :data-testid="`credenciais-certificado-${ambiente.valor}`"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        @change="receberArquivo(ambiente.valor, $event)"
                    />
                    <p v-if="ambiente.cadastro?.tem_certificado" class="text-xs text-muted-foreground">
                        Guardado: {{ ambiente.cadastro.certificado_nome }}
                        <template v-if="ambiente.cadastro.certificado_expira_em">
                            — vale até {{ ambiente.cadastro.certificado_expira_em }}
                        </template>
                        <span v-if="ambiente.cadastro.certificado_vencido" class="font-medium text-destructive"> (vencido)</span>
                    </p>
                    <p v-else class="text-xs text-muted-foreground">Nenhum certificado guardado neste ambiente.</p>
                    <p v-if="formularios[ambiente.valor].errors.certificado" class="text-sm text-destructive">
                        {{ formularios[ambiente.valor].errors.certificado }}
                    </p>
                </div>

                <div class="flex flex-col gap-1 rounded-md border border-border p-3">
                    <span class="text-sm font-medium">Endereço para registrar no painel da Efí</span>
                    <code :data-testid="`credenciais-webhook-url-${ambiente.valor}`" class="break-all text-xs">{{ urlDoWebhook(ambiente.valor) }}</code>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="webhookPronto(ambiente.valor)">
                            Copie este endereço e registre no painel da Efí. Ele termina em <code>?ignorar=</code> de propósito.
                        </template>
                        <template v-else>
                            Gere ou digite o valor de segurança acima para ver o endereço pronto. O valor já guardado não é mostrado
                            aqui — se você não o tem anotado, gere um novo e salve.
                        </template>
                    </p>
                    <button
                        type="button"
                        :disabled="!webhookPronto(ambiente.valor)"
                        :data-testid="`credenciais-copiar-webhook-${ambiente.valor}`"
                        class="mt-1 h-9 w-fit rounded-md border border-border px-3 text-sm font-medium disabled:opacity-50"
                        @click="copiar(urlDoWebhook(ambiente.valor))"
                    >
                        Copiar endereço
                    </button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        :disabled="formularios[ambiente.valor].processing"
                        :data-testid="`credenciais-salvar-${ambiente.valor}`"
                        class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground disabled:opacity-50"
                    >
                        Salvar {{ ambiente.rotulo }}
                    </button>

                    <button
                        type="button"
                        :data-testid="`credenciais-testar-${ambiente.valor}`"
                        class="h-10 rounded-md border border-border px-4 text-sm font-medium"
                        @click="testar(ambiente.valor)"
                    >
                        Testar conexão
                    </button>

                    <button
                        v-if="!ambiente.cadastro?.ativo"
                        type="button"
                        :data-testid="`credenciais-ativar-${ambiente.valor}`"
                        class="h-10 rounded-md border border-border px-4 text-sm font-medium"
                        @click="ativar(ambiente)"
                    >
                        Usar este ambiente
                    </button>
                </div>

                <p
                    v-if="resultadoDe(ambiente.valor)"
                    :data-testid="`credenciais-teste-${ambiente.valor}`"
                    :role="resultadoDe(ambiente.valor)!.sucesso ? 'status' : 'alert'"
                    class="rounded-md border px-4 py-2 text-sm"
                    :class="resultadoDe(ambiente.valor)!.sucesso ? 'border-border bg-muted/40' : 'border-destructive text-destructive'"
                >
                    {{ resultadoDe(ambiente.valor)!.mensagem }}
                </p>
            </form>
        </section>

        <!--
            A confirmação da virada para produção.

            Digitar a palavra, e não só clicar em "sim", é proposital: a partir
            daqui sai cobrança de verdade, e um clique de confirmação já virou
            reflexo para qualquer pessoa que usa computador.
        -->
        <div
            v-if="trocandoParaProducao"
            data-testid="credenciais-confirmar-producao"
            role="dialog"
            aria-modal="true"
            aria-labelledby="titulo-confirmar-producao"
            class="grid gap-3 rounded-lg border border-destructive p-4"
        >
            <h2 id="titulo-confirmar-producao" class="text-lg font-semibold text-destructive">Passar a cobrar de verdade</h2>

            <p class="text-sm">
                A partir do momento em que produção for ativada, toda cobrança gerada será real e sairá do bolso de quem se
                inscrever. Para confirmar, digite <strong>{{ PALAVRA_DE_CONFIRMACAO }}</strong> abaixo.
            </p>

            <label for="confirmacao-producao" class="text-sm font-medium">Confirmação</label>
            <input
                id="confirmacao-producao"
                v-model="confirmacaoDigitada"
                type="text"
                autocomplete="off"
                data-testid="credenciais-palavra-confirmacao"
                class="h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
            />

            <div class="flex gap-2">
                <button
                    type="button"
                    data-testid="credenciais-confirmar-ativacao"
                    :disabled="confirmacaoDigitada.trim().toUpperCase() !== PALAVRA_DE_CONFIRMACAO"
                    class="h-10 rounded-md bg-acao px-4 text-sm font-medium text-acao-foreground disabled:opacity-50"
                    @click="confirmarProducao()"
                >
                    Ativar produção
                </button>

                <button
                    type="button"
                    data-testid="credenciais-cancelar-ativacao"
                    class="h-10 rounded-md border border-border px-4 text-sm font-medium"
                    @click="trocandoParaProducao = false"
                >
                    Cancelar
                </button>
            </div>
        </div>
    </AdminLayout>
</template>

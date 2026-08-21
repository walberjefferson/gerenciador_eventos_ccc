<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicoLayout from '@/layouts/PublicoLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MailCheck } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * "Perdi o link da minha inscricao."
 *
 * Um campo so. A resposta e sempre a mesma — com ou sem inscricao para aquele
 * endereco — porque esta tela nao pode virar uma maquina de descobrir quem
 * esta inscrito. Quem escreve essa resposta e o servidor; aqui so a mostramos.
 */
const props = defineProps<{
    evento: { nome: string | null; slug: string | null } | null;
    mensagem: string | null;
}>();

const formulario = useForm({
    email: '',
    evento: props.evento?.slug ?? '',
});

const erro = computed<string | undefined>(() => formulario.errors.email);

function enviar(): void {
    formulario.post('/acesso', {
        preserveScroll: true,
        onSuccess: () => formulario.reset('email'),
    });
}
</script>

<template>
    <Head title="Acessar minha inscrição" />

    <PublicoLayout>
        <div class="space-y-6">
            <header class="space-y-1">
                <p v-if="evento?.nome" class="text-sm text-muted-foreground">{{ evento.nome }}</p>
                <h1 class="text-2xl font-semibold leading-tight sm:text-3xl">Acessar minha inscrição</h1>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Informe o e-mail que você usou na inscrição. Enviaremos para ele o link de acompanhamento.
                </p>
            </header>

            <!-- A resposta do servidor, anunciada ao leitor de tela sem
                 interromper o que a pessoa estiver lendo. -->
            <Alert v-if="mensagem" variant="sucesso" role="status" data-testid="mensagem-do-acesso">
                <MailCheck aria-hidden="true" />
                <AlertTitle>Pedido recebido</AlertTitle>
                <AlertDescription>{{ mensagem }}</AlertDescription>
            </Alert>

            <Card>
                <CardContent class="pt-6">
                    <form class="space-y-5" novalidate @submit.prevent="enviar">
                        <div class="space-y-2">
                            <Label for="email">E-mail da inscrição</Label>
                            <Input
                                id="email"
                                v-model="formulario.email"
                                name="email"
                                type="email"
                                autocomplete="email"
                                inputmode="email"
                                class="h-12"
                                :aria-invalid="erro ? 'true' : undefined"
                                :aria-describedby="erro ? 'ajuda-email erro-email' : 'ajuda-email'"
                                data-testid="campo-email"
                            />
                            <p id="ajuda-email" class="text-sm text-muted-foreground">Use o mesmo e-mail que você informou ao se inscrever.</p>
                            <p v-if="erro" id="erro-email" role="alert" class="text-sm font-medium text-destructive">{{ erro }}</p>
                        </div>

                        <Button
                            type="submit"
                            class="h-12 w-full bg-acao text-base text-acao-foreground hover:bg-acao/90"
                            :disabled="formulario.processing"
                            data-testid="botao-enviar-acesso"
                        >
                            {{ formulario.processing ? 'Enviando...' : 'Enviar o link de acesso' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <p class="text-sm leading-relaxed text-muted-foreground">
                O link vale por alguns dias. Se ele parar de funcionar, é só pedir outro por aqui.
            </p>

            <Button v-if="evento?.slug" as-child variant="outline" class="h-12 w-full">
                <Link :href="`/eventos/${evento.slug}`">Voltar para a página do evento</Link>
            </Button>
        </div>
    </PublicoLayout>
</template>

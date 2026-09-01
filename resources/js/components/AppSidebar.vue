<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, CalendarDays, KeyRound, LayoutGrid, MapPin, ScanLine, ScrollText, ShieldCheck, Users, UsersRound } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

/**
 * A navegação administrativa de verdade. Cada fase acrescenta o seu item aqui,
 * e não em um menu paralelo.
 *
 * NENHUM ITEM É FIXO, e isso mudou com o papel `portaria`. Até esta entrega,
 * Painel, Eventos e Inscrições apareciam para todo mundo, e funcionava porque
 * todo mundo que entrava no painel tinha as três permissões. O voluntário do
 * portão tem UMA permissão: com a lista fixa, ele veria três itens que só o
 * levariam a 403 — exatamente o que o resto deste arquivo passou seis fases
 * evitando.
 */
const itensPorPermissao: { permissao: string; item: NavItem }[] = [
    { permissao: 'painel.ver', item: { title: 'Painel', href: '/admin/painel', icon: LayoutGrid } },
    { permissao: 'eventos.gerenciar', item: { title: 'Eventos', href: '/admin/eventos', icon: CalendarDays } },
    { permissao: 'inscricoes.ver', item: { title: 'Inscrições', href: '/admin/inscricoes', icon: Users } },
    // A portaria vem logo depois das três de sempre: no dia do evento ela é a
    // tela mais usada do sistema, e para quem tem só ela é a única.
    { permissao: 'presenca.registrar', item: { title: 'Portaria', href: '/admin/portaria', icon: ScanLine } },
];

/**
 * A auditoria so aparece para quem pode abri-la.
 *
 * Nao e por estetica: o organizador nao tem "auditoria.ver" e receberia 403 ao
 * clicar. Um item de menu que so leva a uma porta fechada ensina a pessoa a
 * ignorar o menu.
 */
const itensDoPainel = computed<NavItem[]>(() => {
    const permissoes = page.props.auth?.permissoes ?? [];
    const itens = itensPorPermissao.filter(({ permissao }) => permissoes.includes(permissao)).map(({ item }) => item);

    // O catalogo: setores e grupos. As duas telas existiam desde a Fase 6b, com
    // cadastro, edicao e exclusao completos — mas SEM nenhum link apontando
    // para elas em lugar nenhum do sistema. Quem administra so chegava la
    // digitando o endereco, ou seja, na pratica nao chegava: um CRUD que
    // ninguem alcanca e um CRUD que nao existe.
    if (permissoes.includes('catalogo.gerenciar')) {
        itens.push({ title: 'Setores', href: '/admin/catalogo/setores', icon: MapPin });
        itens.push({ title: 'Grupos', href: '/admin/catalogo/grupos-participantes', icon: UsersRound });
    }

    // Quem entra no painel e com que papel. Mesma regra dos demais: o
    // organizador não tem 'usuarios.gerenciar' e receberia 403 ao clicar.
    //
    // A TELA DE PAPÉIS NÃO GANHA ITEM PRÓPRIO, de propósito. Ela responde a
    // uma pergunta que só nasce depois de a pessoa já estar olhando a lista
    // de contas — "esse tal de organizador alcança o quê?" —, e é de lá que
    // se chega a ela, por um link no fim da página. Um item de menu para uma
    // tabela que ninguém abre sozinha só faria a barra crescer e afastar o que
    // é usado todo dia. As duas telas moram sob a mesma permissão: quem vê
    // "Usuários" alcança a matriz, e quem não vê, não alcança nenhuma das duas.
    if (permissoes.includes('usuarios.gerenciar')) {
        itens.push({ title: 'Usuários', href: '/admin/usuarios', icon: ShieldCheck });
    }

    if (permissoes.includes('auditoria.ver')) {
        itens.push({ title: 'Auditoria', href: '/admin/auditoria', icon: ScrollText });
    }

    // Mesma regra para as credenciais de pagamento, e com mais razao ainda:
    // e a tela que decide para qual conta o dinheiro do evento vai, e so o
    // administrador alcanca.
    if (permissoes.includes('pagamentos.credenciais')) {
        itens.push({ title: 'Credenciais de pagamento', href: '/admin/pagamentos/credenciais', icon: KeyRound });
    }

    // E para os avisos do provedor, de novo pela mesma razão: quem organiza o
    // evento não tem 'pagamentos.avisos-ver' e receberia 403 ao clicar.
    if (permissoes.includes('pagamentos.avisos-ver')) {
        itens.push({ title: 'Avisos do provedor', href: '/admin/pagamentos/avisos', icon: BellRing });
    }

    return itens;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <!--
                            O logotipo aponta para a ENTRADA do painel, e não
                            para a tela do painel: é o servidor que decide o
                            destino conforme o papel. Apontar direto para
                            `admin.painel` daria 403 a quem só tem o portão.
                        -->
                        <Link :href="route('admin.inicio')" aria-label="Ir para o início do painel">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="itensDoPainel" titulo="Acompanhamento" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

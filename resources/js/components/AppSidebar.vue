<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, CalendarDays, KeyRound, LayoutGrid, ScrollText, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// A navegação administrativa de verdade. Cada fase acrescenta o seu item aqui,
// e não em um menu paralelo.
const itensFixos: NavItem[] = [
    {
        title: 'Painel',
        href: '/admin/painel',
        icon: LayoutGrid,
    },
    {
        title: 'Eventos',
        href: '/admin/eventos',
        icon: CalendarDays,
    },
    {
        title: 'Inscrições',
        href: '/admin/inscricoes',
        icon: Users,
    },
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
    const itens = [...itensFixos];

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
                        <Link :href="route('admin.painel')" aria-label="Ir para o painel">
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

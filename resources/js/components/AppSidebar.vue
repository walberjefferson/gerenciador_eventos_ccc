<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { CalendarDays, LayoutGrid, Users } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

// A navegação administrativa de verdade. Cada fase acrescenta o seu item aqui,
// e não em um menu paralelo.
const itensDoPainel: NavItem[] = [
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

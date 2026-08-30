<script setup lang="ts">
import Sheet from '@/components/ui/sheet/Sheet.vue';
import SheetContent from '@/components/ui/sheet/SheetContent.vue';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';
import { SIDEBAR_WIDTH_MOBILE, useSidebar } from './utils';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        side?: 'left' | 'right';
        variant?: 'sidebar' | 'floating' | 'inset';
        collapsible?: 'offcanvas' | 'icon' | 'none';
        class?: HTMLAttributes['class'];
    }>(),
    {
        side: 'left',
        variant: 'sidebar',
        collapsible: 'offcanvas',
    },
);

const { isMobile, state, openMobile, setOpenMobile } = useSidebar();
</script>

<template>
    <div
        v-if="collapsible === 'none'"
        :class="cn('flex h-full w-(--sidebar-width) flex-col bg-sidebar text-sidebar-foreground', props.class)"
        v-bind="$attrs"
    >
        <slot />
    </div>

    <Sheet v-else-if="isMobile" :open="openMobile" v-bind="$attrs" @update:open="setOpenMobile">
        <!--
            O "!" na largura não é capricho, e sai no dia em que uma dependência
            subir de versão.

            O `SheetContent` traz `w-3/4` de fábrica (veja o `side: 'left'` em
            `components/ui/sheet/index.ts`). Quem deveria apagar essa classe é o
            `tailwind-merge`, que existe justamente para resolver esse tipo de
            briga — só que a versão instalada é a **2.6.0**, feita para o
            Tailwind 3, e ela não reconhece `w-(--sidebar-width)` como sendo uma
            classe de largura. Resultado sem o "!": as duas classes sobrevivem, o
            `w-3/4` vence no CSS construído, e a gaveta passa a valer 75% da tela
            (295px num aparelho de 393px) em vez dos 18rem que o componente
            declara logo abaixo.

            O prejuízo não são os 7 pixels de diferença: é que a constante
            `SIDEBAR_WIDTH_MOBILE` deixaria de governar a própria gaveta e viraria
            enfeite — alguém mudaria o valor dela um dia e nada aconteceria.

            Quando o `tailwind-merge` chegar na 3.x, este "!" pode e deve sair
            (pendência **P-11** em `docs/PROGRESS.md`).
        -->
        <SheetContent
            data-sidebar="sidebar"
            data-mobile="true"
            :side="side"
            class="w-(--sidebar-width)! bg-sidebar p-0 text-sidebar-foreground [&>button]:hidden"
            :style="{
                '--sidebar-width': SIDEBAR_WIDTH_MOBILE,
            }"
        >
            <div class="flex h-full w-full flex-col">
                <slot />
            </div>
        </SheetContent>
    </Sheet>

    <div
        v-else
        class="group peer hidden md:block"
        :data-state="state"
        :data-collapsible="state === 'collapsed' ? collapsible : ''"
        :data-variant="variant"
        :data-side="side"
    >
        <!-- This is what handles the sidebar gap on desktop  -->
        <div
            :class="
                cn(
                    'relative h-svh w-(--sidebar-width) bg-transparent transition-[width] duration-200 ease-linear',
                    'group-data-[collapsible=offcanvas]:w-0',
                    'group-data-[side=right]:rotate-180',
                    variant === 'floating' || variant === 'inset'
                        ? 'group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)_+_theme(spacing.4))]'
                        : 'group-data-[collapsible=icon]:w-(--sidebar-width-icon)',
                )
            "
        />
        <div
            :class="
                cn(
                    'fixed inset-y-0 z-10 hidden h-svh w-(--sidebar-width) transition-[left,right,width] duration-200 ease-linear md:flex',
                    side === 'left'
                        ? 'left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)]'
                        : 'right-0 group-data-[collapsible=offcanvas]:right-[calc(var(--sidebar-width)*-1)]',
                    // Adjust the padding for floating and inset variants.
                    variant === 'floating' || variant === 'inset'
                        ? 'p-2 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)_+_theme(spacing.4)_+2px)]'
                        : 'group-data-[collapsible=icon]:w-(--sidebar-width-icon) group-data-[side=left]:border-r group-data-[side=right]:border-l',
                    props.class,
                )
            "
            v-bind="$attrs"
        >
            <div
                data-sidebar="sidebar"
                class="flex h-full w-full flex-col bg-sidebar group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:border-sidebar-border group-data-[variant=floating]:shadow-sm"
            >
                <slot />
            </div>
        </div>
    </div>
</template>

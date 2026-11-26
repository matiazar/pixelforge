<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ImagePlus, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { index as credits } from '@/actions/App/Http/Controllers/CreditsController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Create',
        href: dashboard(),
        icon: ImagePlus,
    },
    {
        title: 'Credits',
        href: credits(),
        icon: Wallet,
    },
];

const page = usePage();
const balance = computed(() => page.props.balance);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <Link
                v-if="balance !== null"
                :href="credits()"
                class="mx-2 rounded-md px-2 py-1.5 text-sm group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent"
            >
                <span class="text-muted-foreground">Balance</span>
                <span
                    :class="[
                        'ml-1 font-semibold tabular-nums',
                        balance.startsWith('-') ? 'text-destructive' : '',
                    ]"
                    >${{ balance }}</span
                >
            </Link>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

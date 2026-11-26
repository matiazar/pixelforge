<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    ImagePlus,
    Scale,
    SlidersHorizontal,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { index as reconciliation } from '@/actions/App/Http/Controllers/Admin/ReconciliationController';
import { index as report } from '@/actions/App/Http/Controllers/Admin/ReportController';
import { edit as adminSettings } from '@/actions/App/Http/Controllers/Admin/SettingsController';
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

// Shown only to admins; the server enforces access on every admin route.
const adminNavItems: NavItem[] = [
    { title: 'Report', href: report(), icon: ChartColumn },
    { title: 'Reconciliation', href: reconciliation(), icon: Scale },
    { title: 'App settings', href: adminSettings(), icon: SlidersHorizontal },
];

const page = usePage();
const balance = computed(() => page.props.balance);
const isAdmin = computed(() => page.props.auth.user?.is_admin === true);
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
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
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

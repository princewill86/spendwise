<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, LayoutGrid, Menu, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';

const page = usePage<{ user?: { role: string }; platform?: { features: Record<string, boolean> } }>();
const main = [
    { href: '/dashboard', text: 'Overview', icon: LayoutGrid },
    { href: '/transactions', text: 'Transactions', icon: ArrowLeftRight },
    { href: '/accounts', text: 'Accounts', icon: Wallet },
];
const additional = [
    ['/budgets', 'Budgets'], ['/goals', 'Savings goals'], ['/reports', 'Reports'],
    ['/recurring', 'Recurring'], ['/notifications', 'Notifications'], ['/imports', 'Imports'],
    ['/categories', 'Categories'], ['/settings', 'Settings'],
];
const more = computed(() => additional.filter(([href]) => page.props.platform?.features[href.slice(1)] !== false));
const open = ref(false);
function current(href: string) { return page.url.split('?')[0] === href ? 'page' : undefined; }
</script>

<template>
    <nav class="workspace-nav desktop-workspace-nav" aria-label="Workspace">
        <Link v-for="item in main" :key="item.href" :href="item.href" :aria-current="current(item.href)">{{ item.text }}</Link>
        <Link v-for="[href, text] in more" :key="href" :href="href" :aria-current="current(href)">{{ text }}</Link>
        <Link v-if="page.props.user?.role === 'admin'" href="/admin">Platform admin</Link>
        <Link href="/logout" method="post" as="button">Sign out</Link>
    </nav>
    <nav class="workspace-nav mobile-workspace-nav" aria-label="Workspace" @keydown.esc="open = false">
        <Link v-for="item in main" :key="item.href" :href="item.href" :aria-current="current(item.href)">
            <component :is="item.icon" :size="17" aria-hidden="true" />{{ item.text }}
        </Link>
        <button type="button" aria-controls="workspace-mobile-more" :aria-expanded="open" @click="open = !open"><Menu :size="17" />More</button>
        <div v-if="open" id="workspace-mobile-more" class="workspace-mobile-more">
            <Link v-for="[href, text] in more" :key="href" :href="href" :aria-current="current(href)" @click="open = false">{{ text }}</Link>
            <Link v-if="page.props.user?.role === 'admin'" href="/admin">Platform admin</Link>
            <Link href="/logout" method="post" as="button">Sign out</Link>
        </div>
    </nav>
</template>

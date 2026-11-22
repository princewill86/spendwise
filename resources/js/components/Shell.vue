<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, ChartNoAxesColumnIncreasing, Menu, Moon, Sun } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

defineOptions({ name: 'SpendwiseShell' });

const page = usePage<{ user: { name: string } | null; platform?: { name: string; logo?: string | null; theme: string; registration: boolean; personalTheme?: string | null; navigation?: { announcement?: string; header?: NavLink[]; footer?: NavLink[] } } }>();
type NavLink = { label: string; href: string };
const header = computed(() => page.props.platform?.navigation?.header ?? [{ label: 'Features', href: '/features' }, { label: 'How it works', href: '/how-it-works' }, { label: 'Security', href: '/security' }, { label: 'About', href: '/about' }]);
const footer = computed(() => page.props.platform?.navigation?.footer ?? [{ label: 'About', href: '/about' }, { label: 'FAQ', href: '/faq' }, { label: 'Contact', href: '/contact' }, { label: 'Terms', href: '/terms' }, { label: 'Privacy', href: '/privacy' }, { label: 'Cookies', href: '/cookies' }, { label: 'Data deletion', href: '/data-deletion' }, { label: 'Acceptable use', href: '/acceptable-use' }]);
const legalPaths = new Set(['/terms', '/privacy', '/cookies', '/data-deletion', '/acceptable-use']);
const resourceLinks = computed(() => footer.value.filter(item => !legalPaths.has(item.href)));
const legalLinks = computed(() => footer.value.filter(item => legalPaths.has(item.href)));
const menuOpen = ref(false);
const theme = ref(document.documentElement.dataset.theme ?? 'system');
watch(() => page.props.platform?.personalTheme, (preference, previous) => {
    if (preference && previous === undefined) {
        theme.value = preference;
        document.documentElement.dataset.theme = preference;
    }
}, { immediate: true });
function changeTheme() {
    theme.value = ({ system: 'light', light: 'dark', dark: 'system' })[theme.value] ?? 'system';
    document.documentElement.dataset.theme = theme.value;
    try { localStorage.setItem('spendwise-theme', theme.value); } catch { /* Storage may be disabled. */ }
    if (page.props.user) router.patch('/settings/theme', { theme: theme.value }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <a class="skip" href="#main">Skip to content</a>
    <p v-if="!page.props.user && page.props.platform?.navigation?.announcement" class="announcement">{{ page.props.platform.navigation.announcement }}</p>
    <header class="site-header">
        <div class="site-width header-inner">
            <Link href="/" class="brand"><img v-if="page.props.platform?.logo" :src="page.props.platform.logo" alt="" class="brand-logo"><span v-else class="brand-mark"><ChartNoAxesColumnIncreasing :size="19" aria-hidden="true" /></span>{{ page.props.platform?.name ?? 'Spendwise' }}</Link>
            <nav id="public-navigation" :class="{ 'mobile-open': menuOpen }" aria-label="Main navigation">
                <component v-for="item in header" :is="item.href.startsWith('/') ? Link : 'a'" :key="item.href + item.label" :href="item.href" @click="menuOpen = false">{{ item.label }}</component>
            </nav>
            <div class="header-actions">
                <button class="theme-button mobile-menu" type="button" aria-label="Main navigation" aria-controls="public-navigation" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen"><Menu :size="18" aria-hidden="true" /></button>
                <button type="button" class="theme-button" :aria-label="`Change theme. Current theme: ${theme}`" :title="`Theme: ${theme}`" @click="changeTheme">
                    <Sun v-if="theme === 'dark'" :size="18" aria-hidden="true" />
                    <Moon v-else :size="18" aria-hidden="true" />
                </button>
                <Link v-if="page.props.user" href="/dashboard" class="button header-dashboard">Dashboard <ArrowUpRight :size="16" /></Link>
                <template v-else>
                    <Link href="/login" class="sign-in">Sign in</Link>
                    <Link v-if="page.props.platform?.registration !== false" href="/register" class="button desktop-get-started">Get started <ArrowUpRight :size="16" /></Link>
                    <Link href="/login" class="button mobile-get-started">Get started</Link>
                </template>
            </div>
        </div>
    </header>
    <main id="main"><slot /></main>
    <footer class="site-footer site-width">
        <div class="footer-brand">
            <Link href="/" class="brand">{{ page.props.platform?.name ?? 'Spendwise' }}</Link>
            <p>© {{ new Date().getFullYear() }} Spendwise</p>
        </div>
        <nav v-if="resourceLinks.length" aria-label="Footer resources">
            <h2>Resources</h2>
            <ul>
                <li v-for="item in resourceLinks" :key="item.href + item.label">
                    <component :is="item.href.startsWith('/') ? Link : 'a'" :href="item.href">{{ item.label }}</component>
                </li>
            </ul>
        </nav>
        <nav v-if="legalLinks.length" aria-label="Footer legal">
            <h2>Legal</h2>
            <ul>
                <li v-for="item in legalLinks" :key="item.href + item.label">
                    <component :is="item.href.startsWith('/') ? Link : 'a'" :href="item.href">{{ item.label }}</component>
                </li>
            </ul>
        </nav>
        <nav aria-label="Footer open source">
            <h2>Open source</h2>
            <ul>
                <li><a href="https://github.com/princewill86/spendwise">GitHub repository</a></li>
                <li><a href="https://github.com/princewill86">Author on GitHub</a></li>
                <li><a href="https://github.com/princewill86/spendwise/issues">Report an issue</a></li>
                <li><a href="https://github.com/princewill86/spendwise/blob/main/SECURITY.md">Security policy</a></li>
                <li><a href="https://github.com/princewill86/spendwise/blob/main/CONTRIBUTING.md">Contributing</a></li>
                <li><a href="https://github.com/princewill86/spendwise/blob/main/LICENSE">MIT license</a></li>
            </ul>
        </nav>
    </footer>
</template>

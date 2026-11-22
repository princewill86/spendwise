<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, ArrowUpRight, ArrowLeftRight, LayoutGrid, List, Wallet } from '@lucide/vue';
import PublicHead from '../components/PublicHead.vue';
import Shell from '../components/Shell.vue';

defineOptions({ name: 'HomePage' });

defineProps<{
    title: string; seoTitle: string; image?: string | null;
    description: string;
    canonical: string;
    content: { eyebrow: string; intro: string; features: { title: string; body: string }[]; steps: string[] };
}>();
const page = usePage<{ user?: { name: string }; platform?: { registration: boolean } }>();
const icons = [Wallet, List, ArrowLeftRight];
</script>

<template>
    <PublicHead :title="seoTitle" :description="description" :canonical="canonical" :image="image" />
    <Shell>
        <section class="hero">
            <div class="site-width hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow">{{ content.eyebrow }}</p>
                    <h1>{{ title }}</h1>
                    <p class="hero-description">{{ content.intro }}</p>
                    <div class="hero-actions">
                        <Link :href="page.props.user ? '/dashboard' : page.props.platform?.registration === false ? '/login' : '/register'" class="button">{{ page.props.user ? 'Open dashboard' : page.props.platform?.registration === false ? 'Sign in' : 'Create your account' }} <ArrowUpRight :size="18" /></Link>
                        <a href="#how-it-works">How it works <ArrowRight :size="17" /></a>
                    </div>
                </div>
                <div class="preview" aria-label="Spendwise workspace preview">
                    <div class="preview-head"><div class="preview-icon"><LayoutGrid :size="19" /></div><div><strong>Overview</strong><small>Your personal workspace</small></div><span>Preview</span></div>
                    <div class="preview-balance"><div><Wallet :size="17" /> Total balance</div><strong>—</strong><small>Add an account to see your balance</small></div>
                    <div class="preview-activity"><strong>Recent transactions</strong><div><List :size="19" /><span>Your activity will appear here.</span></div></div>
                </div>
            </div>
        </section>
        <section id="features" class="site-width feature-section">
            <h2>Your money, organized.</h2>
            <div class="feature-grid">
                <article v-for="(feature, index) in content.features" :key="feature.title">
                    <component :is="icons[index % icons.length]" :size="24" aria-hidden="true" />
                    <h3>{{ feature.title }}</h3><p>{{ feature.body }}</p>
                </article>
            </div>
        </section>
        <section id="how-it-works" class="site-width steps-section">
            <h2>Set up your workspace.</h2>
            <ol><li v-for="step in content.steps" :key="step">{{ step }}</li></ol>
            <Link :href="page.props.user ? '/dashboard' : page.props.platform?.registration === false ? '/login' : '/register'" class="button">{{ page.props.user ? 'Open dashboard' : page.props.platform?.registration === false ? 'Sign in' : 'Get started' }} <ArrowRight :size="18" /></Link>
        </section>
    </Shell>
</template>

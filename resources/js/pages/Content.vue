<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import PublicHead from '../components/PublicHead.vue';
import Shell from '../components/Shell.vue';

defineOptions({ name: 'ContentPage' });

defineProps<{ slug: string; title: string; seoTitle: string; description: string; canonical: string; image?: string | null; contactConfigured?: boolean; content: { sections: { title: string; body: string }[] } }>();
const form = useForm({ name: '', email: '', message: '' });
const page = usePage<{ status?: string }>();
</script>

<template>
    <PublicHead :title="seoTitle" :description="description" :canonical="canonical" :image="image" />
    <Shell>
        <article class="site-width content-page">
            <h1>{{ title }}</h1>
            <template v-if="slug === 'faq'">
                <details v-for="section in content.sections" :key="section.title" class="faq-item"><summary>{{ section.title }}</summary><p>{{ section.body }}</p></details>
            </template>
            <section v-for="section in slug === 'faq' ? [] : content.sections" :key="section.title"><h2>{{ section.title }}</h2><p>{{ section.body }}</p></section>
            <form v-if="contactConfigured" class="auth-form" @submit.prevent="form.post('/contact', { onSuccess: () => form.reset() })">
                <h2>Send a message</h2>
                <p v-if="page.props.status" role="status">{{ page.props.status }}</p>
                <label>Your name<input v-model="form.name" required maxlength="100"></label>
                <label>Email address<input v-model="form.email" type="email" required maxlength="254"></label>
                <label>Message<textarea v-model="form.message" required minlength="8" maxlength="3000"></textarea></label>
                <ul v-if="Object.keys(form.errors).length" class="errors" role="alert"><li v-for="(error, field) in form.errors" :key="field">{{ error }}</li></ul>
                <button class="button" :disabled="form.processing">Send message</button>
            </form>
            <a v-if="slug === 'contact'" href="https://github.com/princewill86/spendwise/discussions">Spendwise discussions on GitHub</a>
        </article>
    </Shell>
</template>

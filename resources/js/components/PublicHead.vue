<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
defineProps<{ title: string; description: string | null; canonical: string; image?: string | null }>();
</script>
<template>
    <Head :title="title">
        <meta head-key="description" name="description" :content="description ?? ''">
        <link head-key="canonical" rel="canonical" :href="canonical">
        <meta head-key="og-title" property="og:title" :content="title">
        <meta head-key="og-description" property="og:description" :content="description ?? ''">
        <meta head-key="og-url" property="og:url" :content="canonical">
        <meta v-if="image" head-key="og-image" property="og:image" :content="image">
    </Head>
</template>

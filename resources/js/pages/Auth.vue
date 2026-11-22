<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Shell from '../components/Shell.vue';

defineOptions({ name: 'AuthPage' });

const props = defineProps<{ mode: 'login' | 'register' | 'forgot' | 'reset' | 'verify' | 'confirm' | 'two-factor'; email?: string; token?: string }>();
const page = usePage<{ status?: string }>();
const flow = {
    'two-factor': ['Two-factor authentication', '/two-factor-challenge', 'Enter a code from your authenticator or use a recovery code.'],
    login: ['Sign in', '/login', 'Pick up where you left off.'],
    register: ['Create your account', '/register', 'Your finances, in one place.'],
    forgot: ['Reset your password', '/forgot-password', 'Enter the email address you use for Spendwise.'],
    reset: ['Set a new password', '/reset-password', 'Choose a password with at least 12 characters.'],
    verify: ['Verify your email', '/email/verification-notification', 'Check your inbox for a verification link.'],
    confirm: ['Confirm your password', '/user/confirm-password', 'Re-enter your password to continue.'],
};
const current = computed(() => flow[props.mode]);
const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '', name: '', email: props.email ?? '', token: props.token ?? '', password: '', password_confirmation: '', remember: false, terms: false });
function submit() {
    form.transform(data => props.mode === 'two-factor' ? (recovery.value ? { recovery_code: data.recovery_code } : { code: data.code }) : data).post(current.value[1], { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head :title="current[0]" />
    <Shell>
        <section class="auth-wrap site-width">
            <div class="auth-intro"><p class="eyebrow">Personal finance</p><h1>{{ current[0] }}</h1><p>{{ current[2] }}</p></div>
            <form class="auth-form" @submit.prevent="submit">
                <p v-if="page.props.status" role="status">{{ page.props.status === 'verification-link-sent' ? 'A new verification email was requested.' : page.props.status }}</p>
                <label v-if="mode === 'register'">Full name <input v-model="form.name" autocomplete="name" required></label>
                <label v-if="!['verify', 'confirm', 'two-factor'].includes(mode)">Email address <input v-model="form.email" type="email" autocomplete="email" required></label>
                <label v-if="!['verify', 'forgot', 'two-factor'].includes(mode)">Password <input v-model="form.password" type="password" :autocomplete="['login', 'confirm'].includes(mode) ? 'current-password' : 'new-password'" required></label>
                <label v-if="['register', 'reset'].includes(mode)">Confirm password <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required></label>
                <label v-if="mode === 'register'" class="check"><input v-model="form.terms" type="checkbox" required><span>I agree to the <Link href="/terms">terms of service</Link>.</span></label>
                <label v-if="mode === 'login'" class="check"><input v-model="form.remember" type="checkbox"> Keep me signed in</label>
                <template v-if="mode === 'two-factor'">
                    <label v-if="recovery">Recovery code<input v-model="form.recovery_code" required autocomplete="one-time-code"></label>
                    <label v-else>Authenticator code<input v-model="form.code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}"></label>
                    <button type="button" @click="recovery = !recovery">{{ recovery ? 'Use authenticator code' : 'Use recovery code' }}</button>
                </template>
                <ul v-if="Object.keys(form.errors).length" class="errors" role="alert"><li v-for="(error, field) in form.errors" :key="field">{{ error }}</li></ul>
                <button type="submit" class="button" :disabled="form.processing">{{ mode === 'verify' ? 'Resend verification link' : mode === 'forgot' ? 'Send reset link' : current[0] }}</button>
                <div class="auth-links" v-if="mode === 'login'"><Link href="/forgot-password">Forgot your password?</Link><Link href="/register">Create an account</Link></div>
                <Link v-else-if="mode === 'verify'" href="/logout" as="button" method="post">Sign out</Link>
                <Link v-else-if="mode !== 'confirm'" href="/login">Back to sign in</Link>
            </form>
        </section>
    </Shell>
</template>

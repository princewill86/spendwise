<script setup lang="ts">
import { ref } from 'vue';
const props = defineProps<{ available: boolean; enabled: boolean; pending: boolean }>();
const enabled = ref(props.enabled);
const pending = ref(props.pending);
const password = ref('');
const code = ref('');
const secret = ref('');
const codes = ref<string[]>([]);
const error = ref('');
const busy = ref(false);

async function request(path: string, method = 'GET', body?: Record<string, string>) {
    const cookie = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='));
    const response = await fetch(path, {
        method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.slice(11) ?? '') },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(response.status === 429 ? 'Please wait before trying again.' : data.message ?? 'The request could not be completed.');
    }
    const text = await response.text();
    return text ? JSON.parse(text) : null;
}

async function change(action: 'setup' | 'confirm' | 'codes' | 'disable') {
    busy.value = true;
    error.value = '';
    try {
        if (password.value) {
            await request('/user/confirm-password', 'POST', { password: password.value });
            password.value = '';
        }
        if (action === 'setup') {
            if (!pending.value) await request('/user/two-factor-authentication', 'POST');
            pending.value = true;
            secret.value = (await request('/user/two-factor-secret-key')).secretKey;
        } else if (action === 'confirm') {
            await request('/user/confirmed-two-factor-authentication', 'POST', { code: code.value });
            enabled.value = true;
            pending.value = false;
            code.value = '';
            secret.value = '';
            codes.value = await request('/user/two-factor-recovery-codes');
        } else if (action === 'codes') {
            codes.value = await request('/user/two-factor-recovery-codes');
        } else {
            await request('/user/two-factor-authentication', 'DELETE');
            enabled.value = false;
            pending.value = false;
            secret.value = '';
            codes.value = [];
        }
    } catch (reason) {
        error.value = reason instanceof Error ? reason.message : 'Unable to update two-factor authentication.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <section v-if="available || enabled || pending" class="workspace-panel">
        <h2>Two-factor authentication</h2>
        <p>{{ enabled ? 'Your account requires a second factor at sign-in.' : 'Use a time-based one-time password from an authenticator app.' }}</p>
        <form class="auth-form" @submit.prevent="change(enabled ? 'codes' : 'setup')">
            <label>Confirm current password<input v-model="password" type="password" required autocomplete="current-password"></label>
            <button v-if="enabled || available" class="button" :disabled="busy">{{ enabled ? 'Show recovery codes' : 'Set up authenticator' }}</button>
            <button v-if="enabled || pending" type="button" :disabled="busy || !password" @click="change('disable')">Disable two-factor authentication</button>
        </form>
        <form v-if="secret" class="auth-form" @submit.prevent="change('confirm')">
            <p>In your authenticator app, add a time-based account using this setup key.</p>
            <code class="break-all">{{ secret }}</code>
            <label>Six-digit code<input v-model="code" required inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></label>
            <button class="button" :disabled="busy">Verify and enable</button>
        </form>
        <div v-if="codes.length"><strong>Recovery codes</strong><p>Store these codes in a safe place. Each code works once.</p><ul class="record-list"><li v-for="value in codes" :key="value"><code>{{ value }}</code></li></ul><button type="button" @click="codes = []">Hide recovery codes</button></div>
        <p v-if="error" class="errors" role="alert">{{ error }}</p>
    </section>
</template>

<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Shell from '../components/Shell.vue';
import TwoFactorSettings from '../components/TwoFactorSettings.vue';
import WorkspaceNav from '../components/WorkspaceNav.vue';

type Options = { default_account_id: number | null; currency: string | null; theme: string; timezone: string; locale: string; date_format: string; number_format: string; reporting_period: string; hide_balances: boolean; budget_alerts: boolean; recurring_alerts: boolean; goal_alerts: boolean; email_alerts: boolean };
const props = defineProps<{ profile: { name: string; email: string; pending_deletion: boolean; avatar: string | null }; preferences: Options | null; defaults?: { currency?: string; theme?: string; timezone?: string }; accounts: { id: number; name: string; currency: string }[]; currencies: string[]; mfa: { available: boolean; enabled: boolean; pending: boolean }; timezones: string[]; sessions: { id: string | null; last_activity: number; ip_address: string | null }[] }>();
const page = usePage<{ status?: string }>();
const settings = useForm({
    name: props.profile.name, email: props.profile.email, current_password: '',
    currency: props.defaults?.currency ?? '', theme: props.defaults?.theme ?? 'system', timezone: props.defaults?.timezone ?? 'UTC',
    locale: 'en', date_format: 'Y-m-d', number_format: 'en-US', reporting_period: 'month',
    default_account_id: null as number | null, hide_balances: false, budget_alerts: true, recurring_alerts: true, goal_alerts: true, email_alerts: false,
    ...props.preferences,
});
const avatar = useForm({ image: null as File | null });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const session = useForm({ session: '', password: '' });
const deletion = useForm({ request_deletion: !props.profile.pending_deletion, password: '' });
const panel = ref<'profile' | 'display' | 'security' | 'privacy'>('profile');
function save() {
    settings.patch('/settings', { preserveScroll: true, onFinish: () => settings.reset('current_password'), onSuccess: () => {
        document.documentElement.dataset.theme = settings.theme;
        try { localStorage.setItem('spendwise-theme', settings.theme); } catch { /* Browser storage can be disabled. */ }
    } });
}
function image(event: Event) { avatar.image = (event.target as HTMLInputElement).files?.[0] ?? null; }
function requestDeletion() {
    deletion.request_deletion = !props.profile.pending_deletion;
    if (!deletion.request_deletion || window.confirm('Request deletion of your Spendwise account and financial records?')) {
        deletion.patch('/settings/deletion', { preserveScroll: true, onFinish: () => deletion.reset('password') });
    }
}

defineOptions({ name: 'PreferencesPage' });
</script>
<template>
    <Head title="Personal settings" />
    <Shell>
        <section class="workspace site-width">
            <WorkspaceNav />
            <header class="workspace-heading"><h1>Personal settings</h1></header>
            <nav class="workspace-nav" aria-label="Settings sections">
                <button v-for="tab in (['profile','display','security','privacy'] as const)" :key="tab" type="button" :aria-pressed="panel === tab" @click="panel = tab">{{ tab === 'display' ? 'Appearance and preferences' : tab }}</button>
            </nav>
            <p v-if="page.props.status" class="workspace-status" role="status">{{ page.props.status }}</p>
            <div class="workspace-grid settings-grid">
                <form v-if="panel === 'profile' || panel === 'display'" class="auth-form" @submit.prevent="save">
                    <template v-if="panel === 'profile'">
                        <h2>Profile details</h2>
                        <label>Full name<input v-model="settings.name" required maxlength="120" autocomplete="name"></label>
                        <label>Email address<input v-model="settings.email" type="email" required autocomplete="email"></label>
                        <label v-if="settings.email !== profile.email">Current password<input v-model="settings.current_password" type="password" required autocomplete="current-password"></label>
                    </template>
                    <template v-else>
                        <h2>Appearance and regional preferences</h2>
                        <label>Theme<select v-model="settings.theme"><option>system</option><option>light</option><option>dark</option></select></label>
                        <label>Default transaction account<select v-model="settings.default_account_id"><option :value="null">Choose each time</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} · {{ account.currency }}</option></select></label>
                        <label>Display currency<select v-model="settings.currency"><option value="">Platform default</option><option v-for="code in currencies" :key="code">{{ code }}</option></select></label>
                        <label>Time zone<select v-model="settings.timezone"><option v-for="zone in timezones" :key="zone">{{ zone }}</option></select></label>
                        <label>Language<select v-model="settings.locale"><option value="en">English</option></select></label>
                        <label>Date format<select v-model="settings.date_format"><option>Y-m-d</option><option>d/m/Y</option><option>m/d/Y</option></select></label>
                        <label>Number format<select v-model="settings.number_format"><option v-for="code in ['en-US','en-GB','de-DE','fr-FR','en-NG']" :key="code">{{ code }}</option></select></label>
                        <label>Default reporting period<select v-model="settings.reporting_period"><option value="week">This week</option><option value="month">This month</option><option value="previous">Previous month</option><option value="year">This year</option></select></label>
                        <label class="check"><input v-model="settings.hide_balances" type="checkbox"> Hide financial amounts on the dashboard</label>
                        <h2>Notifications</h2>
                        <label class="check"><input v-model="settings.budget_alerts" type="checkbox"> Budget alerts</label>
                        <label class="check"><input v-model="settings.recurring_alerts" type="checkbox"> Recurring activity alerts</label>
                        <label class="check"><input v-model="settings.goal_alerts" type="checkbox"> Savings goal alerts</label>
                        <label class="check"><input v-model="settings.email_alerts" type="checkbox"> Receive enabled alerts by email</label>
                    </template>
                    <ul v-if="Object.keys(settings.errors).length" class="errors" role="alert"><li v-for="(error, field) in settings.errors" :key="field">{{ error }}</li></ul>
                    <button class="button" :disabled="settings.processing">Save preferences</button>
                </form>
                <form v-if="panel === 'profile'" class="auth-form" @submit.prevent="avatar.post('/settings/avatar', { forceFormData: true, preserveScroll: true })">
                    <h2>Profile image</h2><img v-if="profile.avatar" :src="profile.avatar" class="profile-image" alt="Your profile picture">
                    <label>New image<input type="file" required accept="image/png,image/jpeg,image/webp" @change="image"></label>
                    <p v-if="avatar.errors.image" class="errors" role="alert">{{ avatar.errors.image }}</p>
                    <button class="button" :disabled="avatar.processing">Upload image</button>
                </form>
                <form v-if="panel === 'security'" class="auth-form" @submit.prevent="password.put('/user/password', { errorBag: 'updatePassword', preserveScroll: true, onFinish: () => password.reset() })">
                    <h2>Change password</h2>
                    <label>Current password<input v-model="password.current_password" autocomplete="current-password" type="password" required></label>
                    <label>New password<input v-model="password.password" autocomplete="new-password" type="password" required></label>
                    <label>Confirm new password<input v-model="password.password_confirmation" autocomplete="new-password" type="password" required></label>
                    <ul v-if="Object.keys(password.errors).length" class="errors" role="alert"><li v-for="(error, field) in password.errors" :key="field">{{ error }}</li></ul>
                    <button class="button" :disabled="password.processing">Change password</button>
                </form>
                <TwoFactorSettings v-if="panel === 'security'" v-bind="mfa" />
                <section v-if="panel === 'security'" class="workspace-panel">
                    <h2>Active sessions</h2><p v-if="!sessions.length">No sessions to display.</p>
                    <ul v-else class="record-list"><li v-for="item in sessions" :key="item.id ?? 'current'"><div><strong>{{ item.id ? (item.ip_address ?? 'Session') : 'Current session' }}</strong><small>{{ new Date(item.last_activity * 1000).toLocaleString() }}</small></div><button v-if="item.id" type="button" @click="session.session = item.id">End session</button></li></ul>
                    <form v-if="session.session" class="auth-form" @submit.prevent="session.delete('/settings/sessions', { preserveScroll: true, onSuccess: () => session.reset(), onFinish: () => session.reset('password') })">
                        <label>Confirm password<input v-model="session.password" autocomplete="current-password" type="password" required></label>
                        <p v-if="session.errors.password" class="errors" role="alert">{{ session.errors.password }}</p><button class="button" :disabled="session.processing">Confirm session removal</button>
                    </form>
                    <Link href="/notifications">View security alerts</Link>
                </section>
                <section v-if="panel === 'privacy'" class="workspace-panel"><h2>Export your data</h2><p>Download a JSON copy of your profile, preferences, and financial records.</p><a class="button" href="/settings/export">Download data</a><Link href="/privacy">Privacy policy</Link></section>
                <form v-if="panel === 'privacy'" class="auth-form" @submit.prevent="requestDeletion">
                    <h2>Account deletion</h2><p>{{ profile.pending_deletion ? 'Your account is awaiting review. You can withdraw the request until it is approved.' : 'An administrator will review your request before deleting your account and records.' }}</p>
                    <label>Current password<input v-model="deletion.password" type="password" autocomplete="current-password" required></label>
                    <p v-if="deletion.errors.password" class="errors" role="alert">{{ deletion.errors.password }}</p>
                    <button class="button" :disabled="deletion.processing">{{ profile.pending_deletion ? 'Withdraw request' : 'Request deletion' }}</button>
                    <Link href="/data-deletion">Data deletion policy</Link>
                </form>
            </div>
        </section>
    </Shell>
</template>

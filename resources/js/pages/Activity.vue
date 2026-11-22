<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Shell from '../components/Shell.vue';
import WorkspaceNav from '../components/WorkspaceNav.vue';
type Page<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };
type Rule = { id: number; account_id: number; category_id: number | null; description: string; type: string; amount: string; starts_on: string; ends_on: string | null; next_on: string; interval_unit: string; interval_count: number; status: string };
const props = defineProps<{ section: 'notifications' | 'calendar' | 'recurring'; rules: Page<Rule> | null; alerts: Page<{ id: number; message: string; read_at: string | null; created_at: string }> | null; accounts: { id: number; name: string; currency: string }[]; categories: { id: number; name: string; type: string }[]; today: string }>();
const page = usePage<{ status?: string }>();
const title = computed(() => ({ notifications: 'Notifications', calendar: 'Financial calendar', recurring: 'Recurring transactions' })[props.section]);
const edits = ref<number | null>(null);
const form = useForm({ account_id: null as number | null, category_id: null as number | null, description: '', type: 'expense', amount: '', starts_on: props.today, ends_on: '', interval_unit: 'months', interval_count: 1, status: 'active' });
const visible = computed(() => props.section === 'calendar' ? props.rules?.data.filter(rule => rule.status === 'active') : props.rules?.data);
function edit(item?: Rule) {
    edits.value = item?.id ?? null;
    form.clearErrors().reset();
    if (item) Object.assign(form, { account_id: item.account_id, category_id: item.category_id, description: item.description, type: item.type, amount: String(item.amount), starts_on: item.starts_on, ends_on: item.ends_on ?? '', interval_unit: item.interval_unit, interval_count: item.interval_count, status: item.status === 'completed' ? 'paused' : item.status });
}
function save() {
    form.submit(edits.value ? 'patch' : 'post', edits.value ? `/recurring/${edits.value}` : '/recurring', { preserveScroll: true, onSuccess: () => edit() });
}
function mark(action: 'read' | 'dismiss', id?: number) {
    router.patch(id ? `/notifications/${id}` : '/notifications', { action }, { preserveScroll: true });
}

defineOptions({ name: 'ActivityPage' });
</script>
<template>
    <Head :title="title" />
    <Shell>
        <section class="workspace site-width">
            <WorkspaceNav />
            <header class="workspace-heading"><h1>{{ title }}</h1><Link v-if="section === 'recurring'" href="/calendar">View calendar</Link><Link v-if="section === 'calendar'" href="/recurring">Manage recurring transactions</Link></header>
            <p v-if="page.props.status" class="workspace-status" role="status">{{ page.props.status }}</p>
            <div class="workspace-grid">
                <section v-if="rules" class="workspace-panel">
                    <h2>{{ section === 'calendar' ? 'Upcoming scheduled activity' : 'Your schedules' }}</h2>
                    <p v-if="!visible?.length">No scheduled activity to show.</p>
                    <ul v-else class="record-list">
                        <li v-for="item in visible" :key="item.id">
                            <div><strong>{{ item.description }}</strong><small>{{ item.type }} · {{ item.status }}</small></div>
                            <div>{{ accounts.find(account => account.id === item.account_id)?.currency }} {{ item.amount }}<small v-if="item.status === 'active'">Next {{ item.next_on }}</small></div>
                            <button v-if="section === 'recurring'" type="button" @click="edit(item)">Edit</button>
                        </li>
                    </ul>
                    <div class="row-actions"><Link v-if="rules.prev_page_url" :href="rules.prev_page_url">Previous</Link><Link v-if="rules.next_page_url" :href="rules.next_page_url">Next</Link></div>
                </section>
                <section v-if="alerts" class="workspace-panel">
                    <h2>Your updates</h2>
                    <button v-if="alerts.data.some(item => !item.read_at)" class="button" type="button" @click="mark('read')">Mark all as read</button>
                    <p v-if="!alerts.data.length">You’re caught up.</p>
                    <ul v-else class="record-list"><li v-for="item in alerts.data" :key="item.id"><div><strong>{{ item.message }}</strong><small>{{ item.created_at.slice(0, 10) }} · {{ item.read_at ? 'Read' : 'Unread' }}</small></div><div class="row-actions"><button v-if="!item.read_at" type="button" @click="mark('read', item.id)">Mark read</button><button type="button" @click="mark('dismiss', item.id)">Dismiss</button></div></li></ul>
                    <div class="row-actions"><Link v-if="alerts.prev_page_url" :href="alerts.prev_page_url">Previous</Link><Link v-if="alerts.next_page_url" :href="alerts.next_page_url">Next</Link></div>
                </section>
                <form v-if="section === 'recurring' && accounts.length" class="auth-form" @submit.prevent="save">
                    <h2>{{ edits ? 'Edit schedule' : 'New schedule' }}</h2>
                    <p>Active schedules post due entries to your ledger automatically.</p>
                    <label>Description<input v-model="form.description" required maxlength="160"></label>
                    <label>Type<select v-model="form.type" @change="form.category_id = null"><option>expense</option><option>income</option></select></label>
                    <label>Account<select v-model="form.account_id" required><option :value="null" disabled>Choose an account</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} · {{ account.currency }}</option></select></label>
                    <label>Category<select v-model="form.category_id"><option :value="null">Uncategorized</option><option v-for="category in categories.filter(item => item.type === form.type)" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                    <label>Amount<input v-model="form.amount" required inputmode="decimal"></label>
                    <label>Start date<input v-model="form.starts_on" required type="date"></label>
                    <label>End date<input v-model="form.ends_on" :min="form.starts_on" type="date"></label>
                    <label>Repeat every<input v-model.number="form.interval_count" required type="number" min="1" max="365"></label>
                    <label>Interval<select v-model="form.interval_unit"><option>days</option><option>weeks</option><option>months</option></select></label>
                    <label>Status<select v-model="form.status"><option>active</option><option>paused</option><option>cancelled</option></select></label>
                    <ul v-if="Object.keys(form.errors).length" class="errors" role="alert"><li v-for="(error, field) in form.errors" :key="field">{{ error }}</li></ul>
                    <button class="button" :disabled="form.processing">Save schedule</button>
                    <button v-if="edits" type="button" @click="edit()">Cancel edit</button>
                </form>
                <section v-else-if="section === 'recurring'" class="workspace-panel"><h2>New schedule</h2><Link class="button" href="/accounts">Add an active account</Link></section>
            </div>
        </section>
    </Shell>
</template>

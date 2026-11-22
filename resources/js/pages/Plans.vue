<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Shell from '../components/Shell.vue';
import WorkspaceNav from '../components/WorkspaceNav.vue';

defineOptions({ name: 'PlansPage' });

type Item = { id: number; name: string; currency: string; amount: string; percent: string };
type Budget = Item & { category_id: number | null; period: string; starts_on: string; ends_on: string; threshold: number; archived_at: string | null; spent: string; remaining: string };
type Goal = Item & { target_date: string | null; description: string | null; status: string; saved: string };
type Page<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };
const props = defineProps<{
    section: 'budgets' | 'goals'; currencies: string[]; categories: { id: number; name: string }[];
    budgets: Page<Budget> | null; goals: Page<Goal> | null; selected: Goal | null;
    history: Page<{ id: number; amount: string; note: string | null; created_at: string }> | null; today: string;
}>();
const page = usePage<{ status?: string }>();
const title = computed(() => props.section === 'budgets' ? 'Budgets' : 'Savings goals');
const editing = ref<number | null>(null);
const budget = useForm({ name: '', amount: '', currency: '', category_id: null as number | null, period: 'monthly', starts_on: props.today, ends_on: '', threshold: 80, archived: false });
const goal = useForm({ name: '', amount: '', currency: '', target_date: '', description: '', status: 'active' });
const contribution = useForm({ amount: '', direction: 'deposit', note: '' });

function editBudget(item?: Budget) {
    editing.value = item?.id ?? null;
    budget.clearErrors().reset();
    if (item) Object.assign(budget, { name: item.name, amount: String(item.amount), currency: item.currency, category_id: item.category_id, period: item.period, starts_on: item.starts_on, ends_on: item.ends_on, threshold: item.threshold, archived: Boolean(item.archived_at) });
}
function editGoal(item?: Goal) {
    editing.value = item?.id ?? null;
    goal.clearErrors().reset();
    if (item) Object.assign(goal, { name: item.name, amount: String(item.amount), currency: item.currency, target_date: item.target_date ?? '', description: item.description ?? '', status: item.status });
}
function saveBudget() {
    budget.transform(({ currency, ends_on, ...fields }) => ({ ...fields, ...(editing.value ? {} : { currency }), ends_on: fields.period === 'custom' ? ends_on : null }))
        .submit(editing.value ? 'patch' : 'post', editing.value ? `/budgets/${editing.value}` : '/budgets', { preserveScroll: true, onSuccess: () => editBudget() });
}
function saveGoal() {
    goal.transform(({ currency, ...fields }) => editing.value ? fields : { ...fields, currency })
        .submit(editing.value ? 'patch' : 'post', editing.value ? `/goals/${editing.value}` : '/goals', { preserveScroll: true, onSuccess: () => editGoal() });
}
watch(() => props.section, () => { editBudget(); editGoal(); });
function saveContribution() {
    contribution.post(`/goals/${props.selected?.id}/contributions`, { preserveScroll: true, onSuccess: () => contribution.reset() });
}
</script>

<template>
    <Head :title="title" />
    <Shell>
        <section class="workspace site-width">
            <WorkspaceNav />
            <header class="workspace-heading"><h1>{{ title }}</h1></header>
            <p v-if="page.props.status" class="workspace-status" role="status">{{ page.props.status }}</p>
            <div v-if="budgets" class="workspace-grid">
                <section class="workspace-panel">
                    <h2>Spending limits</h2>
                    <p v-if="!budgets.data.length">Create a budget to track spending against a limit.</p>
                    <ul v-else class="record-list plan-list">
                        <li v-for="item in budgets.data" :key="item.id">
                            <div><strong>{{ item.name }}</strong><small>{{ item.starts_on }} to {{ item.ends_on }}<template v-if="item.archived_at"> · Archived</template></small></div>
                            <button type="button" @click="editBudget(item)">Edit</button>
                            <div class="plan-progress"><progress :value="Math.min(100, Number(item.percent))" max="100" :aria-label="`${item.name} spending`"></progress><small>{{ item.currency }} {{ item.spent }} spent · {{ item.remaining }} remaining</small><small v-if="Number(item.percent) >= item.threshold && !item.archived_at">{{ Number(item.percent) > 100 ? 'Over budget' : Number(item.percent) === 100 ? 'Limit reached' : 'Approaching limit' }}</small></div>
                        </li>
                    </ul>
                    <div class="row-actions"><Link v-if="budgets.prev_page_url" :href="budgets.prev_page_url">Previous</Link><Link v-if="budgets.next_page_url" :href="budgets.next_page_url">Next</Link></div>
                </section>
                <form class="auth-form" @submit.prevent="saveBudget">
                    <h2>{{ editing ? 'Edit budget' : 'New budget' }}</h2>
                    <label>Name<input v-model="budget.name" required maxlength="100"></label>
                    <label v-if="!editing">Currency<select v-model="budget.currency" required><option value="" disabled>Choose a currency</option><option v-for="code in currencies" :key="code">{{ code }}</option></select></label>
                    <label>Spending limit<input v-model="budget.amount" inputmode="decimal" required></label>
                    <label>Category<select v-model="budget.category_id"><option :value="null">All spending</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label>Period<select v-model="budget.period"><option>weekly</option><option>monthly</option><option>custom</option></select></label>
                    <label>{{ budget.period === 'custom' ? 'Start date' : 'Date in period' }}<input v-model="budget.starts_on" type="date" required></label>
                    <label v-if="budget.period === 'custom'">End date<input v-model="budget.ends_on" type="date" required :min="budget.starts_on"></label>
                    <label>Alert threshold (%)<input v-model.number="budget.threshold" type="number" min="1" max="100" required></label>
                    <label v-if="editing" class="check"><input v-model="budget.archived" type="checkbox"> Archived</label>
                    <ul v-if="Object.keys(budget.errors).length" class="errors" role="alert"><li v-for="(error, field) in budget.errors" :key="field">{{ error }}</li></ul>
                    <button class="button" :disabled="budget.processing">Save budget</button>
                    <button v-if="editing" type="button" @click="editBudget()">Cancel edit</button>
                </form>
            </div>
            <div v-if="goals" class="workspace-grid">
                <div class="workspace-primary">
                    <section class="workspace-panel">
                        <h2>Your goals</h2>
                        <p>Goals track savings separately from your account balances.</p>
                        <ul class="record-list plan-list" v-if="goals.data.length">
                            <li v-for="item in goals.data" :key="item.id">
                                <div><strong><Link :href="`/goals?goal=${item.id}`">{{ item.name }}</Link></strong><small>{{ item.status }}<template v-if="item.target_date"> · {{ item.target_date }}</template></small></div>
                                <button type="button" @click="editGoal(item)">Edit</button>
                                <div class="plan-progress"><progress :value="Math.min(100, Number(item.percent))" max="100" :aria-label="`${item.name} savings`"></progress><small>{{ item.currency }} {{ item.saved }} of {{ item.amount }}</small></div>
                            </li>
                        </ul>
                        <div class="row-actions"><Link v-if="goals.prev_page_url" :href="goals.prev_page_url">Previous</Link><Link v-if="goals.next_page_url" :href="goals.next_page_url">Next</Link></div>
                    </section>
                    <section v-if="selected && history" class="workspace-panel">
                        <h2>{{ selected.name }} activity</h2>
                        <p v-if="selected.description">{{ selected.description }}</p>
                        <p v-if="!history.data.length">No contributions or withdrawals yet.</p>
                        <ul v-else class="record-list"><li v-for="entry in history.data" :key="entry.id"><div>{{ selected.currency }} {{ entry.amount }}<small>{{ entry.note }}</small></div><time>{{ entry.created_at.slice(0, 10) }}</time></li></ul>
                        <div class="row-actions"><Link v-if="history.prev_page_url" :href="history.prev_page_url">Previous</Link><Link v-if="history.next_page_url" :href="history.next_page_url">Next</Link></div>
                        <form v-if="selected.status === 'active'" class="auth-form" @submit.prevent="saveContribution">
                            <label>Activity<select v-model="contribution.direction"><option value="deposit">Contribution</option><option value="withdrawal">Withdrawal</option></select></label>
                            <label>Amount<input v-model="contribution.amount" inputmode="decimal" required></label>
                            <label>Note<input v-model="contribution.note" maxlength="240"></label>
                            <ul v-if="Object.keys(contribution.errors).length" class="errors" role="alert"><li v-for="(error, field) in contribution.errors" :key="field">{{ error }}</li></ul>
                            <button class="button" :disabled="contribution.processing">Record activity</button>
                        </form>
                    </section>
                </div>
                <form class="auth-form" @submit.prevent="saveGoal">
                    <h2>{{ editing ? 'Edit savings goal' : 'New savings goal' }}</h2>
                    <label>Name<input v-model="goal.name" required maxlength="100"></label>
                    <label v-if="!editing">Currency<select v-model="goal.currency" required><option value="" disabled>Choose a currency</option><option v-for="code in currencies" :key="code">{{ code }}</option></select></label>
                    <label>Target amount<input v-model="goal.amount" inputmode="decimal" required></label>
                    <label>Target date<input v-model="goal.target_date" type="date"></label>
                    <label>Description<textarea v-model="goal.description" maxlength="2000"></textarea></label>
                    <label v-if="editing">Status<select v-model="goal.status"><option>active</option><option>complete</option><option>archived</option></select></label>
                    <ul v-if="Object.keys(goal.errors).length" class="errors" role="alert"><li v-for="(error, field) in goal.errors" :key="field">{{ error }}</li></ul>
                    <button class="button" :disabled="goal.processing">Save goal</button>
                    <button v-if="editing" type="button" @click="editGoal()">Cancel edit</button>
                </form>
            </div>
        </section>
    </Shell>
</template>

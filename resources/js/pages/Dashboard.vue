<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, LayoutGrid, List, Tag, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import CashFlowChart from '../components/CashFlowChart.vue';
import ReportChart from '../components/ReportChart.vue';
import Shell from '../components/Shell.vue';
import WorkspaceNav from '../components/WorkspaceNav.vue';

defineOptions({ name: 'DashboardPage' });

type Account = { id: number; name: string; type: string; currency: string; description: string | null; opening_balance: string; balance: string; archived_at: string | null };
type Category = { icon: string | null; id: number; name: string; type: string; color: string | null; archived_at: string | null };
type Posting = { account_id: number; amount: string | number; currency: string; name: string };
type Entry = { payment_method: string | null; attachment: string | null; id: number; description: string; type: string; occurred_on: string; notes: string | null; category: string | null; category_id: number | null; postings: Posting[] };

const props = defineProps<{
    name: string; section: 'overview' | 'accounts' | 'transactions' | 'categories';
    accounts: Account[]; categories: Category[]; currencies: { code: string; name: string; decimal_places: number }[];
    estimate: { currency: string; total: string | null; quotes: { currency: string; rate: string; date: string; source: string }[]; missing: string[]; valued_on: string } | null;
    displayCurrency: string | null; defaultAccount: number | null;
    preferences: { hide_balances: boolean; reporting_period: string; date_format: string; number_format: string } | null;
    budgetHighlights: { id: number; name: string; currency: string; spent: string; amount: string }[];
    goalHighlights: { id: number; name: string; currency: string; saved: string; amount: string }[];
    upcoming: { id: number; description: string; next_on: string }[];
    monthlyTrend: { currency: string; labels: string[]; income: string[]; expenses: string[] }[];
    summary: { currency: string; balance: string; income: string; expense: string; cash_flow: string }[];
    entries: { data: Entry[]; prev_page_url: string | null; next_page_url: string | null };
    filters: { period?: string; q?: string; type?: string; category?: number; account?: number; from?: string; to?: string; order?: string }; today: string;
}>();
const page = usePage<{ status?: string }>();
const nav = [
    { title: 'Overview', key: 'overview', href: '/dashboard', icon: LayoutGrid },
    { title: 'Transactions', key: 'transactions', href: '/transactions', icon: ArrowLeftRight },
    { title: 'Accounts', key: 'accounts', href: '/accounts', icon: Wallet },
    { title: 'Categories', key: 'categories', href: '/categories', icon: Tag },
];
const title = computed(() => nav.find((item) => item.key === props.section)?.title ?? 'Overview');
const activeAccounts = computed(() => props.accounts.filter((account) => !account.archived_at));
const types = ['cash', 'bank', 'savings', 'wallet', 'credit', 'other'];
const entryTypes = ['income', 'expense', 'transfer', 'adjustment'];
const editingAccount = ref<number | null>(null);
const editingCategory = ref<number | null>(null);
const reconcileTarget = ref<Account | null>(null);
const attachTarget = ref<Entry | null>(null);
const reconciliation = useForm({ balance: '' });
const receipt = useForm({ file: null as File | null });
const editingEntry = ref<number | null>(null);
const hidden = ref(Boolean(props.preferences?.hide_balances));
const accountForm = useForm({ name: '', type: 'bank', currency: '', opening_balance: '0', description: '', archived: false });
const categoryForm = useForm({ name: '', type: 'expense', color: '', icon: '', archived: false });
const entryForm = useForm({
    description: '', type: 'expense', account_id: props.defaultAccount as number | null, destination_id: null as number | null,
    payment_method: '', amount: '', destination_amount: '', occurred_on: props.today, notes: '', category_id: null as number | null,
});
const deletion = useForm({ confirm: true });
const filters = useForm({ q: props.filters.q ?? '', type: props.filters.type ?? '', account: props.filters.account ?? '', category: props.filters.category ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '', order: props.filters.order ?? 'recent' });
const exportQuery = computed(() => new URLSearchParams(Object.entries(props.filters).filter(([, value]) => value !== null).map(([key, value]) => [key, String(value)])).toString());
const period = useForm({ period: props.filters.period ?? props.preferences?.reporting_period ?? 'month', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const source = computed(() => props.accounts.find((account) => account.id === entryForm.account_id));
const destination = computed(() => props.accounts.find((account) => account.id === entryForm.destination_id));
const crossCurrency = computed(() => Boolean(source.value && destination.value && source.value.currency !== destination.value.currency));
const matchingCategories = computed(() => props.categories.filter((item) => item.type === entryForm.type && !item.archived_at));

function showMoney(amount: string | number, currency: string) {
    if (hidden.value) return '••••';
    const places = props.currencies.find((item) => item.code === currency)?.decimal_places ?? 4;
    const [whole, fraction = ''] = String(amount).split('.');
    const locale = props.preferences?.number_format ?? 'en-US';
    const formatter = new Intl.NumberFormat(locale);
    const separator = formatter.formatToParts(0.1).find(part => part.type === 'decimal')?.value ?? '.';
    const grouped = (whole.startsWith('-') ? '-' : '') + formatter.format(BigInt(whole.replace(/^-/, '')));
    return `${currency} ${grouped}${places ? separator + fraction.padEnd(places, '0').slice(0, places) : ''}`;
}

function showDate(date: string) {
    const [year, month, day] = date.split('-');
    if (props.preferences?.date_format === 'd/m/Y') return `${day}/${month}/${year}`;
    if (props.preferences?.date_format === 'm/d/Y') return `${month}/${day}/${year}`;
    return date;
}

function editAccount(item?: Account) {
    editingAccount.value = item?.id ?? null;
    accountForm.clearErrors().reset();
    if (item) Object.assign(accountForm, { name: item.name, type: item.type, currency: item.currency, opening_balance: String(item.opening_balance), description: item.description ?? '', archived: Boolean(item.archived_at) });
}
function saveAccount() {
    accountForm.transform(({ currency, ...fields }) => editingAccount.value ? fields : { currency, ...fields })
        .submit(editingAccount.value ? 'patch' : 'post', editingAccount.value ? `/accounts/${editingAccount.value}` : '/accounts', { preserveScroll: true, onSuccess: () => editAccount() });
}
function editCategory(item?: Category) {
    editingCategory.value = item?.id ?? null;
    categoryForm.clearErrors().reset();
    if (item) Object.assign(categoryForm, { name: item.name, type: item.type, color: item.color ?? '', icon: item.icon ?? '', archived: Boolean(item.archived_at) });
}
function saveCategory() {
    categoryForm.transform(({ type, ...fields }) => editingCategory.value ? fields : { type, ...fields })
        .submit(editingCategory.value ? 'patch' : 'post', editingCategory.value ? `/categories/${editingCategory.value}` : '/categories', { preserveScroll: true, onSuccess: () => editCategory() });
}
function editEntry(item?: Entry, duplicate = false) {
    editingEntry.value = duplicate ? null : item?.id ?? null;
    entryForm.clearErrors().reset();
    if (!item) return;
    const [first, second] = item.postings;
    const amount = String(first.amount);
    Object.assign(entryForm, {
        description: item.description, type: item.type, account_id: first.account_id,
        destination_id: second?.account_id ?? null, amount: ['transfer', 'expense'].includes(item.type) ? amount.replace(/^-/, '') : amount,
        destination_amount: second ? String(second.amount) : '', occurred_on: duplicate ? props.today : item.occurred_on,
        notes: item.notes ?? '', payment_method: item.payment_method ?? '', category_id: item.category_id,
    });
}
function saveEntry() {
    entryForm.transform((data) => ({
        ...data,
        destination_id: data.type === 'transfer' ? data.destination_id : null,
        destination_amount: data.type === 'transfer' && crossCurrency.value ? data.destination_amount : null,
        category_id: ['income', 'expense'].includes(data.type) ? data.category_id : null,
    })).submit(editingEntry.value ? 'patch' : 'post', editingEntry.value ? `/transactions/${editingEntry.value}` : '/transactions', { preserveScroll: true, onSuccess: () => editEntry() });
}
function chooseReceipt(event: Event) { receipt.file = (event.target as HTMLInputElement).files?.[0] ?? null; }
function removeReceipt(id: number) {
    if (window.confirm('Remove this receipt?')) router.delete(`/transactions/${id}/attachment`, { preserveScroll: true, onSuccess: () => { attachTarget.value = null; } });
}
function deleteEntry(entry: Entry) {
    if (window.confirm(`Delete “${entry.description}” and remove its effect on account balances?`)) {
        deletion.delete(`/transactions/${entry.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="title" />
    <Shell>
        <section class="workspace site-width">
            <WorkspaceNav />
            <header class="workspace-heading">
                <div><h1>{{ title }}</h1><p v-if="section === 'overview'">Welcome, {{ name }}.</p></div>
                <button type="button" class="theme-button balance-toggle" :aria-pressed="hidden" @click="hidden = !hidden">{{ hidden ? 'Show amounts' : 'Hide amounts' }}</button>
            </header>
            <p v-if="page.props.status" role="status" class="workspace-status">{{ page.props.status }}</p>
            <section v-if="section === 'overview'" class="workspace-panel summary-panel">
                <h2>Cash flow</h2>
                <form class="filters" @submit.prevent="period.transform((data) => data.period === 'custom' ? data : { period: data.period }).get('/dashboard')">
                    <label>Reporting period<select v-model="period.period"><option value="week">This week</option><option value="month">This month</option><option value="previous">Previous month</option><option value="year">This year</option><option value="custom">Custom range</option></select></label>
                    <template v-if="period.period === 'custom'"><label>From<input v-model="period.from" required type="date"></label><label>To<input v-model="period.to" required type="date"></label></template>
                    <button class="button">Update period</button>
                </form>
                <p v-if="!summary.length">Create an account and record a transaction to see your cash flow.</p>
                <p v-if="displayCurrency && !estimate">Converted balances are unavailable. Amounts are shown in their original currency.</p>
                <div v-if="estimate && !hidden">
                    <strong v-if="estimate.total !== null">Estimated total · {{ showMoney(estimate.total, estimate.currency) }}</strong>
                    <p v-else>Exchange rates are missing for {{ estimate.missing.join(', ') }}.</p>
                    <details v-if="estimate.quotes.length"><summary>Exchange rate sources</summary><ul><li v-for="quote in estimate.quotes" :key="quote.currency">{{ quote.currency }} → {{ estimate.currency }} · {{ quote.rate }} · {{ quote.date }} · {{ quote.source }}</li></ul></details>
                </div>
                <div v-for="item in summary"  :key="item.currency" class="currency-summary">
                    <strong>{{ item.currency }}</strong>
                    <dl><div><dt>Current balance</dt><dd>{{ showMoney(item.balance, item.currency) }}</dd></div><div><dt>Income</dt><dd>{{ showMoney(item.income, item.currency) }}</dd></div><div><dt>Expenses</dt><dd>{{ showMoney(item.expense, item.currency) }}</dd></div><div><dt>Net cash flow</dt><dd>{{ showMoney(item.cash_flow, item.currency) }}</dd></div></dl>
                    <CashFlowChart v-if="!hidden && (item.income !== '0.0000' || item.expense !== '0.0000')" :currency="item.currency" :income="item.income" :expense="item.expense" />
                </div>
            </section>
            <section v-if="section === 'overview' && !hidden" class="workspace-panel summary-panel">
                <h2>Cash-flow trend</h2>
                <p v-if="!monthlyTrend.length">Record income or expenses to see a six-month trend.</p>
                <p v-else>Last six months, including the current month</p>
                <div v-for="trend in monthlyTrend" :key="trend.currency" class="report-chart-group">
                    <h3>{{ trend.currency }}</h3><div class="chart-legend"><span>Income</span><span>Expenses</span></div>
                    <ReportChart kind="line" :labels="trend.labels" :series="[{ label: 'Income', color: '#397c66', values: trend.income.map(Number) }, { label: 'Expenses', color: '#ae596f', values: trend.expenses.map(Number) }]" />
                    <details><summary>Monthly totals</summary><ul class="record-list"><li v-for="(month, index) in trend.labels" :key="month"><strong>{{ month }}</strong><span>Income {{ showMoney(trend.income[index], trend.currency) }}</span><span>Expenses {{ showMoney(trend.expenses[index], trend.currency) }}</span></li></ul></details>
                </div>
            </section>
            <div class="workspace-grid">
                <div class="workspace-primary">
                    <section v-if="section === 'overview' || section === 'accounts'" class="workspace-panel">
                        <h2>Account balances</h2>
                        <p v-if="accounts.length === 0">Add an account and enter its opening balance to start your ledger.</p>
                        <Link v-if="section === 'overview' && accounts.length === 0" class="button" href="/accounts">Add an account</Link>
                        <ul v-else class="record-list">
                            <li v-for="item in accounts" :key="item.id"><div><strong>{{ item.name }}</strong><small>{{ item.type }}<template v-if="item.archived_at"> · Archived</template></small></div><span>{{ showMoney(item.balance, item.currency) }}</span><button v-if="section === 'accounts'" type="button" @click="editAccount(item)">Edit</button><button v-if="section === 'accounts' && !item.archived_at" type="button" @click="reconcileTarget = item; reconciliation.clearErrors().reset()">Reconcile</button><Link v-if="section === 'accounts'" :href="`/transactions?account=${item.id}`">Activity</Link></li>
                        </ul>
                        <a v-if="section === 'accounts' && accounts.length" href="/exports/accounts">Export accounts CSV</a>
                        <form v-if="section === 'accounts' && reconcileTarget" class="auth-form" @submit.prevent="reconciliation.post(`/accounts/${reconcileTarget.id}/reconcile`, { preserveScroll: true, onSuccess: () => { reconcileTarget = null; } })">
                            <h2>Reconcile {{ reconcileTarget.name }}</h2><label>Actual balance in {{ reconcileTarget.currency }}<input v-model="reconciliation.balance" required inputmode="decimal"></label>
                            <p v-if="reconciliation.errors.balance" role="alert" class="errors">{{ reconciliation.errors.balance }}</p>
                            <button class="button" :disabled="reconciliation.processing">Record adjustment</button><button type="button" @click="reconcileTarget = null">Cancel</button>
                        </form>
                    </section>
                    <section v-if="section === 'overview' || section === 'transactions'" class="workspace-panel">
                        <h2>{{ section === 'overview' ? 'Recent transactions' : 'Transaction history' }}</h2>
                        <form v-if="section === 'transactions'" class="filters" @submit.prevent="filters.get('/transactions')">
                            <label>Search<input v-model="filters.q" type="search"></label>
                            <label>Type<select v-model="filters.type"><option value="">All types</option><option v-for="type in entryTypes" :key="type">{{ type }}</option></select></label>
                            <label>Category<select v-model="filters.category"><option value="">All categories</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                            <label>Account<select v-model="filters.account"><option value="">All accounts</option><option v-for="item in accounts" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                            <label>From<input v-model="filters.from" type="date"></label>
                            <label>To<input v-model="filters.to" type="date"></label>
                            <label>Sort<select v-model="filters.order"><option value="recent">Newest first</option><option value="oldest">Oldest first</option></select></label>
                            <button class="button">Apply filters</button>
                        </form>
                        <p v-if="!entries.data.length"><List :size="17" class="inline-block" /> No transactions to show.</p>
                        <ul v-else class="record-list">
                            <li v-for="item in (section === 'overview' ? entries.data.slice(0, 5) : entries.data)" :key="item.id">
                                <div><strong>{{ item.description }}</strong><small>{{ showDate(item.occurred_on) }} · {{ item.type }}<template v-if="item.category"> · {{ item.category }}</template><template v-if="item.payment_method"> · {{ item.payment_method }}</template></small></div>
                                <div><small v-for="posting in item.postings" :key="posting.account_id">{{ posting.name }} · {{ showMoney(posting.amount, posting.currency) }}</small></div>
                                <div v-if="section === 'transactions'" class="row-actions"><button type="button" @click="editEntry(item)">Edit</button><button type="button" @click="editEntry(item, true)">Duplicate</button><button type="button" @click="attachTarget = item; receipt.clearErrors().reset()">Receipt</button><button type="button" @click="deleteEntry(item)">Delete</button></div>
                            </li>
                        </ul>
                        <form v-if="section === 'transactions' && attachTarget" class="auth-form" @submit.prevent="receipt.post(`/transactions/${attachTarget.id}/attachment`, { forceFormData: true, preserveScroll: true, onSuccess: () => { attachTarget = null; } })">
                            <h2>Receipt for {{ attachTarget.description }}</h2>
                            <div class="row-actions" v-if="attachTarget.attachment"><a :href="`/transactions/${attachTarget.id}/attachment`">{{ attachTarget.attachment }}</a><button type="button" @click="removeReceipt(attachTarget.id)">Remove</button></div>
                            <label>Upload receipt<input type="file" required accept=".pdf,.png,.jpg,.jpeg,.webp" @change="chooseReceipt"></label>
                            <p v-if="receipt.errors.file" role="alert" class="errors">{{ receipt.errors.file }}</p>
                            <button class="button" :disabled="receipt.processing">Save receipt</button><button type="button" @click="attachTarget = null">Cancel</button>
                        </form>
                        <div class="row-actions" v-if="section === 'transactions'"><a :href="`/exports/transactions?${exportQuery}`">Export transactions CSV</a><Link v-if="entries.prev_page_url" :href="entries.prev_page_url">Previous</Link><Link v-if="entries.next_page_url" :href="entries.next_page_url">Next</Link></div>
                        <Link v-else href="/transactions">View transactions</Link>
                    </section>
                    <section v-if="section === 'categories'" class="workspace-panel">
                        <h2>Your categories</h2>
                        <p v-if="!categories.length">Create categories for the income and expenses you want to track.</p>
                        <ul v-else class="record-list">
                            <li v-for="item in categories" :key="item.id"><div><strong><span v-if="item.color" class="category-color" :style="{ backgroundColor: item.color }" aria-hidden="true"></span>{{ item.name }}</strong><small>{{ item.type }}<template v-if="item.icon"> · {{ item.icon }}</template><template v-if="item.archived_at"> · Archived</template></small></div><button type="button" @click="editCategory(item)">Edit</button></li>
                        </ul>
                    </section>
                </div>
                <form v-if="section === 'accounts'" class="auth-form" @submit.prevent="saveAccount">
                    <h2>{{ editingAccount ? 'Edit account' : 'New account' }}</h2>
                    <label>Name<input v-model="accountForm.name" required maxlength="100"></label>
                    <label>Type<select v-model="accountForm.type"><option v-for="type in types" :key="type">{{ type }}</option></select></label>
                    <label v-if="!editingAccount">Currency<select v-model="accountForm.currency" required><option disabled value="">Choose a currency</option><option v-for="item in currencies" :key="item.code" :value="item.code">{{ item.code }} · {{ item.name }}</option></select></label>
                    <label>Opening balance<input v-model="accountForm.opening_balance" inputmode="decimal" required></label>
                    <label>Description<textarea v-model="accountForm.description" maxlength="2000"></textarea></label>
                    <label v-if="editingAccount" class="check"><input v-model="accountForm.archived" type="checkbox"> Archived</label>
                    <ul v-if="Object.keys(accountForm.errors).length" class="errors" role="alert"><li v-for="error in accountForm.errors" :key="error">{{ error }}</li></ul>
                    <button class="button" :disabled="accountForm.processing">Save account</button>
                    <button v-if="editingAccount" type="button" @click="editAccount()">Cancel edit</button>
                </form>
                <form v-if="section === 'categories'" class="auth-form" @submit.prevent="saveCategory">
                    <h2>{{ editingCategory ? 'Edit category' : 'New category' }}</h2>
                    <label>Name<input v-model="categoryForm.name" required maxlength="100"></label>
                    <label v-if="!editingCategory">Type<select v-model="categoryForm.type"><option>expense</option><option>income</option></select></label>
                    <label>Icon label<input v-model="categoryForm.icon" maxlength="32" pattern="[a-z0-9-]+"></label>
                    <label>Color <input v-model="categoryForm.color" placeholder="#387050" maxlength="7"></label>
                    <label v-if="editingCategory" class="check"><input v-model="categoryForm.archived" type="checkbox"> Archived</label>
                    <ul v-if="Object.keys(categoryForm.errors).length" class="errors" role="alert"><li v-for="error in categoryForm.errors" :key="error">{{ error }}</li></ul>
                    <button class="button" :disabled="categoryForm.processing">Save category</button>
                    <button v-if="editingCategory" type="button" @click="editCategory()">Cancel edit</button>
                </form>
                <form v-if="section === 'transactions' && activeAccounts.length" class="auth-form" @submit.prevent="saveEntry">
                    <h2>{{ editingEntry ? 'Edit transaction' : 'New transaction' }}</h2>
                    <label>Description<input v-model="entryForm.description" required maxlength="160"></label>
                    <label>Type<select v-model="entryForm.type" @change="entryForm.category_id = null"><option v-for="type in entryTypes" :key="type">{{ type }}</option></select></label>
                    <label>Account<select v-model="entryForm.account_id" required><option disabled :value="null">Choose an account</option><option v-for="item in activeAccounts" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }}</option></select></label>
                    <label>Amount<input v-model="entryForm.amount" inputmode="decimal" required></label>
                    <label v-if="entryForm.type === 'transfer'">Destination account<select v-model="entryForm.destination_id" required><option disabled :value="null">Choose an account</option><option v-for="item in activeAccounts.filter((item) => item.id !== entryForm.account_id)" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }}</option></select></label>
                    <label v-if="entryForm.type === 'transfer' && crossCurrency">Received amount<input v-model="entryForm.destination_amount" inputmode="decimal" required></label>
                    <label v-if="matchingCategories.length">Category<select v-model="entryForm.category_id"><option :value="null">Uncategorized</option><option v-for="item in matchingCategories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label>Date<input v-model="entryForm.occurred_on" type="date" :max="today" required></label>
                    <label v-if="entryForm.type !== 'transfer'">Payment method<input v-model="entryForm.payment_method" maxlength="48"></label>
                    <label>Notes<textarea v-model="entryForm.notes" maxlength="4000"></textarea></label>
                    <ul v-if="Object.keys(entryForm.errors).length" class="errors" role="alert"><li v-for="error in entryForm.errors" :key="error">{{ error }}</li></ul>
                    <button class="button" :disabled="entryForm.processing">Save transaction</button>
                    <button v-if="editingEntry" type="button" @click="editEntry()">Cancel edit</button>
                </form>
                <div v-if="section === 'transactions' && !activeAccounts.length" class="workspace-panel"><h2>New transaction</h2><p>Add an active account to record a transaction.</p><Link class="button" href="/accounts">Go to accounts</Link></div>
            </div>
            <div v-if="section === 'overview' && (budgetHighlights.length || goalHighlights.length || upcoming.length)" class="workspace-grid report-grid summary-panel plan-highlights">
                <section v-if="budgetHighlights.length" class="workspace-panel"><h2>Budget progress</h2><ul class="record-list"><li v-for="item in budgetHighlights" :key="item.id"><strong>{{ item.name }}</strong><span>{{ showMoney(item.spent, item.currency) }} of {{ showMoney(item.amount, item.currency) }}</span></li></ul><Link href="/budgets">View budgets</Link></section>
                <section v-if="goalHighlights.length" class="workspace-panel"><h2>Savings goals</h2><ul class="record-list"><li v-for="item in goalHighlights" :key="item.id"><strong>{{ item.name }}</strong><span>{{ showMoney(item.saved, item.currency) }} of {{ showMoney(item.amount, item.currency) }}</span></li></ul><Link href="/goals">View goals</Link></section>
                <section v-if="upcoming.length" class="workspace-panel"><h2>Upcoming recurring payments</h2><ul class="record-list"><li v-for="item in upcoming" :key="item.id"><strong>{{ item.description }}</strong><time>{{ showDate(item.next_on) }}</time></li></ul><Link href="/calendar">View calendar</Link></section>
            </div>
        </section>
    </Shell>
</template>

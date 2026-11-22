<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ReportChart from '../components/ReportChart.vue';
import Shell from '../components/Shell.vue';
import WorkspaceNav from '../components/WorkspaceNav.vue';
type NumberText = string | number;
type Filters = { from?: string; to?: string; account?: string | number; category?: string | number; type?: string; currency?: string };
type ImportSummary = { id: number; filename: string; accepted_count: number; rejected_count: number; confirmed_at: string | null };
const props = defineProps<{
    section: 'reports' | 'imports'; printable?: boolean; accounts: { id: number; currency: string; name: string; balance?: string }[];
    categories: { id: number; name: string }[]; currencies?: string[]; filters?: Filters;
    totals?: { currency: string; type: string; total: NumberText }[];
    cashFlow?: { currency: string; total: NumberText }[];
    spending?: { currency: string; name: string; total: NumberText }[];
    months?: { month: string; currency: string; type: string; total: NumberText }[];
    budgets?: { id: number; name: string; currency: string; spent: string; amount: string }[];
    goals?: { id: number; name: string; currency: string; saved: string; amount: string }[];
    recurring?: { description: string; amount: NumberText; next_on: string }[];
    imports?: { data: ImportSummary[]; prev_page_url: string | null; next_page_url: string | null };
    draft?: ImportSummary & { rows: { line: number; error: string | null; entry?: { description: string; type: string; amount: string; occurred_on: string } }[] };
}>();
const page = usePage<{ status?: string }>();
const title = computed(() => props.section === 'reports' ? 'Reports' : 'Transaction imports');
const fileForm = useForm({ file: null as File | null, account_id: '' as number | '', category_id: '' as number | '', date_format: 'Y-m-d', date_column: 'date', description_column: 'description', amount_column: 'amount' });
const confirmation = useForm({ confirm: false });
const report = useForm({ from: props.filters?.from ?? '', to: props.filters?.to ?? '', account: props.filters?.account ?? '', category: props.filters?.category ?? '', type: props.filters?.type ?? '', currency: props.filters?.currency ?? '' });
const spendingCharts = computed(() => (props.currencies ?? []).map(code => ({
    code, rows: (props.spending ?? []).filter(row => row.currency === code).slice(0, 8),
})).filter(item => item.rows.length));
const trendCharts = computed(() => (props.currencies ?? []).map(code => {
    const records = (props.months ?? []).filter(row => row.currency === code);
    const labels = [...new Set(records.map(row => row.month))].slice(-12);
    return { code, labels, series: [{ type: 'income', color: '#397c66' }, { type: 'expense', color: '#ae596f' }].map(({ type, color }) => ({
        label: type, color, values: labels.map(date => Math.abs(Number(records.find(row => row.month === date && row.type === type)?.total ?? 0))),
    })) };
}).filter(item => item.labels.length));
const query = computed(() => new URLSearchParams(Object.entries(props.filters ?? {}).filter(([,value]) => value != null).map(([key,value]) => [key, String(value)])).toString());
watch(() => props.draft?.id, () => confirmation.reset());
function pickFile(event: Event) { fileForm.file = (event.target as HTMLInputElement).files?.[0] ?? null; }

defineOptions({ name: 'DataPage' });
</script>
<template>
    <Head :title="title" />
    <Shell>
        <section class="workspace site-width">
            <WorkspaceNav />
            <header class="workspace-heading"><h1>{{ title }}</h1><div v-if="section === 'reports'" class="row-actions"><a v-if="printable" :href="`/reports/print?${query}`" target="_blank" rel="noopener">Print or save as PDF</a><a class="button" :href="`/exports/reports?${query}`">Export CSV</a></div></header>
            <p v-if="page.props.status" class="workspace-status" role="status">{{ page.props.status }}</p>
            <div v-if="section === 'reports'" class="workspace-primary">
                <section class="workspace-panel">
                    <h2>Filter activity</h2>
                    <form class="filters" @submit.prevent="report.get('/reports')">
                        <label>From<input v-model="report.from" type="date"></label><label>To<input v-model="report.to" type="date"></label>
                        <label>Account<select v-model="report.account"><option value="">All accounts</option><option v-for="item in accounts" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                        <label>Category<select v-model="report.category"><option value="">All categories</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                        <label>Type<select v-model="report.type"><option value="">All types</option><option>income</option><option>expense</option><option>transfer</option><option>adjustment</option></select></label>
                        <label>Currency<select v-model="report.currency"><option value="">All currencies</option><option v-for="code in currencies" :key="code">{{ code }}</option></select></label>
                        <button class="button">Apply filters</button>
                    </form>
                    <ul v-if="Object.keys(report.errors).length" class="errors" role="alert"><li v-for="(error, field) in report.errors" :key="field">{{ error }}</li></ul>
                </section>
                <div class="workspace-grid report-grid">
                    <section class="workspace-panel"><h2>Transaction totals</h2><dl v-if="cashFlow?.length" class="record-list"><div v-for="item in cashFlow" :key="item.currency"><dt>Net cash flow · {{ item.currency }}</dt><dd class="font-semibold">{{ item.total }}</dd></div></dl><p v-if="!totals?.length">No transactions match the filters.</p><ul v-else class="record-list"><li v-for="item in totals" :key="`${item.currency}-${item.type}`"><strong>{{ item.type }}</strong><span>{{ item.currency }} {{ item.total }}</span></li></ul></section>
                    <section class="workspace-panel"><h2>Spending by category</h2><div v-for="chart in spendingCharts" :key="chart.code" class="report-chart-group"><strong>{{ chart.code }} · Top categories</strong><ReportChart kind="doughnut" :labels="chart.rows.map(row => row.name)" :series="[{ label: chart.code, color: '#397c66', values: chart.rows.map(row => Number(row.total)) }]" /></div><p v-if="!spending?.length">No spending matches the filters.</p><ul v-else class="record-list"><li v-for="(item, index) in spending" :key="index"><strong>{{ item.name }}</strong><span>{{ item.currency }} {{ item.total }}</span></li></ul></section>
                    <section class="workspace-panel"><h2>Monthly activity</h2><div v-for="chart in trendCharts" :key="chart.code" class="report-chart-group"><strong>{{ chart.code }}</strong><div class="chart-legend"><span>Income</span><span>Expenses</span></div><ReportChart kind="line" :labels="chart.labels" :series="chart.series" /></div><p v-if="!months?.length">No income or spending matches the filters.</p><ul v-else class="record-list"><li v-for="item in months" :key="`${item.month}-${item.currency}-${item.type}`"><strong>{{ item.month }} · {{ item.type }}</strong><span>{{ item.currency }} {{ item.total }}</span></li></ul></section>
                    <section class="workspace-panel"><h2>Account balances</h2><ul class="record-list"><li v-for="item in accounts" :key="item.id"><strong>{{ item.name }}</strong><span>{{ item.currency }} {{ item.balance }}</span></li></ul><a href="/exports/accounts">Export accounts</a></section>
                    <section class="workspace-panel"><h2>Budget performance</h2><p v-if="!budgets?.length">No active budgets.</p><ul v-else class="record-list"><li v-for="item in budgets" :key="item.id"><strong>{{ item.name }}</strong><span>{{ item.currency }} {{ item.spent }} of {{ item.amount }}</span></li></ul><a href="/exports/budgets">Export budgets</a></section>
                    <section class="workspace-panel"><h2>Savings progress</h2><p v-if="!goals?.length">No active goals.</p><ul v-else class="record-list"><li v-for="item in goals" :key="item.id"><strong>{{ item.name }}</strong><span>{{ item.currency }} {{ item.saved }} of {{ item.amount }}</span></li></ul></section>
                    <section class="workspace-panel"><h2>Upcoming recurring expenses</h2><p v-if="!recurring?.length">No upcoming recurring expenses.</p><ul v-else class="record-list"><li v-for="(item, index) in recurring" :key="index"><strong>{{ item.description }}</strong><span>{{ item.next_on }}</span></li></ul></section>
                </div>
            </div>
            <div v-else class="workspace-grid">
                <div class="workspace-primary">
                    <section v-if="draft && !draft.confirmed_at" class="workspace-panel">
                        <h2>Review import</h2><p>{{ draft.accepted_count }} ready · {{ draft.rejected_count }} rejected</p>
                        <ul class="record-list"><li v-for="row in draft.rows" :key="row.line"><strong>Row {{ row.line }}</strong><span v-if="row.error">{{ row.error }}</span><span v-else>{{ row.entry?.occurred_on }} · {{ row.entry?.description }} · {{ row.entry?.type }} {{ row.entry?.amount }}</span></li></ul>
                        <form v-if="draft.accepted_count" class="auth-form" @submit.prevent="confirmation.post(`/imports/${draft.id}/confirm`)">
                            <label class="check"><input v-model="confirmation.confirm" required type="checkbox"> Import only the valid rows shown above</label>
                            <p v-if="confirmation.errors.confirm" class="errors" role="alert">{{ confirmation.errors.confirm }}</p>
                            <button class="button" :disabled="confirmation.processing">Confirm import</button>
                        </form>
                    </section>
                    <section class="workspace-panel">
                        <h2>Import history</h2><p v-if="!imports?.data.length">No imports yet.</p>
                        <ul v-else class="record-list"><li v-for="item in imports.data" :key="item.id"><div><strong>{{ item.filename }}</strong><small>{{ item.accepted_count }} {{ item.confirmed_at ? 'recorded' : 'ready' }} · {{ item.rejected_count }} skipped</small></div><Link v-if="!item.confirmed_at" :href="`/imports?draft=${item.id}`">Review</Link><span v-else>{{ item.confirmed_at.slice(0, 10) }}</span></li></ul>
                        <div class="row-actions"><Link v-if="imports?.prev_page_url" :href="imports.prev_page_url">Previous</Link><Link v-if="imports?.next_page_url" :href="imports.next_page_url">Next</Link></div>
                        <a href="/exports/transactions">Export transactions</a>
                    </section>
                </div>
                <form v-if="accounts.length" class="auth-form" @submit.prevent="fileForm.post('/imports', { forceFormData: true })">
                    <h2>Upload CSV</h2><p>Amounts use the account currency. Positive rows are income and negative rows are expenses.</p>
                    <label>CSV file<input required type="file" accept=".csv,text/csv" @change="pickFile"></label>
                    <label>Account<select v-model="fileForm.account_id" required><option disabled value="">Choose an account</option><option v-for="item in accounts" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }}</option></select></label>
                    <label>Expense category<select v-model="fileForm.category_id"><option value="">Uncategorized</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label>Date column<input v-model="fileForm.date_column" required maxlength="100"></label>
                    <label>Description column<input v-model="fileForm.description_column" required maxlength="100"></label>
                    <label>Amount column<input v-model="fileForm.amount_column" required maxlength="100"></label>
                    <label>Date format<select v-model="fileForm.date_format"><option value="Y-m-d">YYYY-MM-DD</option><option value="d/m/Y">DD/MM/YYYY</option><option value="m/d/Y">MM/DD/YYYY</option></select></label>
                    <ul v-if="Object.keys(fileForm.errors).length" class="errors" role="alert"><li v-for="(error, field) in fileForm.errors" :key="field">{{ error }}</li></ul>
                    <button class="button" :disabled="fileForm.processing">Preview file</button>
                </form>
                <section v-else class="workspace-panel"><h2>Upload CSV</h2><Link class="button" href="/accounts">Add an active account</Link></section>
            </div>
        </section>
    </Shell>
</template>

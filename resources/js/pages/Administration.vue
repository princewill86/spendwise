<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ReportChart from '../components/ReportChart.vue';
import Shell from '../components/Shell.vue';

defineOptions({ name: 'AdministrationPage' });

type Page<T> = { data: T[]; next_page_url: string | null; prev_page_url: string | null };
type User = { id: number; name: string; email: string; role: string; created_at: string; suspended_at: string | null; deletion_requested_at: string | null; email_verified_at: string | null };
type Entry = { id: number; action: string; target: string; actor_id: number | null; ip: string | null; created_at: string };
type Currency = { code: string; name: string; decimal_places: number; enabled: boolean };
type NavigationLink = { label: string; href: string };
type Navigation = { announcement: string; header: NavigationLink[]; footer: NavigationLink[] };
type Section = { title: string; body: string };
type Field = { key: string; label: string; kind?: string; options?: string[] };
type Value = string | number | boolean | null;
const props = defineProps<{
    section: string; viewer: number; filters: { q?: string; state?: string }; values?: Record<string, Value>;
    navigation?: Partial<Navigation>; counts?: Record<string, number>; registrationTrend?: { labels: string[]; counts: number[] }; audit?: Entry[]; logs?: Page<Entry>; users?: Page<User>;
    sharingImage?: string | null; pages?: { url: string; slug: string; title: string; meta_description: string | null; published: boolean; content: string | object }[];
    exchange?: { provider?: string }; currencies?: Currency[]; rates?: { id: number; from_currency: string; to_currency: string; rate: string; quoted_on: string; source: string }[];
    templates?: { key: string; subject: string; body: string; enabled: boolean }[];
    mailLogs?: Page<{ id: number; template: string; status: string; created_at: string; alert_id: number | null }>;
}>();
const page = usePage<{ platform?: { logo?: string | null; favicon?: string | null }; status?: string; errors: Record<string, string> }>();
const sections = { overview: 'Overview', users: 'Members', general: 'General', security: 'Security', seo: 'SEO', content: 'Content', navigation: 'Navigation', finance: 'Data', mail: 'Email', currency: 'Currencies', features: 'Features', audit: 'Audit log' };
const defaults: Record<string, Record<string, Value>> = {
    general: { name: 'Spendwise', description: '', contact: '', support: '', currency: '', theme: 'system', timezone: 'UTC', public_site: true, maintenance: false, site_url: '' },
    finance: { import_rows: 500, draft_days: 14 },
    security: { password_min: 12, login_limit: 5, session_lifetime: 120, upload_limit: 5120 },
    seo: { title: '', description: '', canonical: '', indexing: true, sitemap: true },
    mail: { driver: 'log', host: '', port: '', scheme: 'smtp', username: '', password: '', clear_password: false, from_address: '', from_name: 'Spendwise', reply_to: '' },
};
const fields: Record<string, Field[]> = {
    general: [{key:'name',label:'Platform name'},{key:'description',label:'Description'},{key:'contact',label:'Contact email',kind:'email'},{key:'support',label:'Support email',kind:'email'},{key:'currency',label:'Default currency',options:props.currencies?.map(item => item.code) ?? []},{key:'theme',label:'Default theme',options:['system','light','dark']},{key:'timezone',label:'Default time zone'},{key:'site_url',label:'Public website URL',kind:'url'},{key:'public_site',label:'Publish public website',kind:'checkbox'},{key:'maintenance',label:'Platform maintenance mode',kind:'checkbox'}],
    finance: [{key:'import_rows',label:'Maximum CSV rows',kind:'number'},{key:'draft_days',label:'Days to keep unconfirmed import previews',kind:'number'}],
    security: [{key:'password_min',label:'Minimum password length',kind:'number'},{key:'login_limit',label:'Login attempts per minute',kind:'number'},{key:'session_lifetime',label:'Session lifetime in minutes',kind:'number'},{key:'upload_limit',label:'Receipt upload limit in KB',kind:'number'}],
    seo: [{key:'title',label:'Default title'},{key:'description',label:'Default description'},{key:'canonical',label:'Canonical homepage URL',kind:'url'},{key:'indexing',label:'Allow search indexing',kind:'checkbox'},{key:'sitemap',label:'Publish sitemap',kind:'checkbox'}],
    mail: [{key:'driver',label:'Mail transport',options:['log','smtp','sendmail']},{key:'host',label:'SMTP host'},{key:'port',label:'SMTP port',kind:'number'},{key:'scheme',label:'SMTP mode',options:['smtp','smtps']},{key:'username',label:'SMTP username'},{key:'password',label:'New SMTP password',kind:'password'},{key:'clear_password',label:'Clear saved password',kind:'checkbox'},{key:'from_address',label:'Sender email',kind:'email'},{key:'from_name',label:'Sender name'},{key:'reply_to',label:'Reply-to address',kind:'email'}],
};
const settings = useForm({ values: {} as Record<string, Value> });
const search = useForm({ q: props.filters.q ?? '', state: props.filters.state ?? '' });
const editing = ref('');
const creating = ref(false);
const editor = useForm({ slug: '', title: '', meta_description: '', published: false, eyebrow: '', intro: '', sections: [] as Section[], steps: [] as string[] });
const defaultLinks: Navigation = {
    announcement: '',
    header: [{ label: 'Features', href: '/features' }, { label: 'How it works', href: '/how-it-works' }, { label: 'Security', href: '/security' }, { label: 'About', href: '/about' }],
    footer: [{ label: 'About', href: '/about' }, { label: 'FAQ', href: '/faq' }, { label: 'Contact', href: '/contact' }, { label: 'Terms', href: '/terms' }, { label: 'Privacy', href: '/privacy' }, { label: 'Cookies', href: '/cookies' }, { label: 'Data deletion', href: '/data-deletion' }, { label: 'Acceptable use', href: '/acceptable-use' }],
};
function navigationValues(): Navigation { return JSON.parse(JSON.stringify({ ...defaultLinks, ...props.navigation, announcement: props.navigation?.announcement ?? '' })); }
const navigationForm = useForm(navigationValues());
const currency = useForm({ code: '', name: '', decimal_places: 2, enabled: true });
const exchange = useForm({ provider: props.exchange?.provider ?? 'manual', credential: '' });
const rate = useForm({ from_currency: '', to_currency: '', rate: '', quoted_on: new Date().toISOString().slice(0, 10), source: '' });
const mailKey = ref('verify');
const template = useForm({ subject: '', body: '', enabled: true });
const test = useForm({});
const image = useForm({ image: null as File | null });
const imageType = ref<'logo' | 'favicon'>('logo');
function brandingFile(event: Event) { image.image = (event.target as HTMLInputElement).files?.[0] ?? null; }
function removeImage() {
    if (window.confirm(`Remove the platform ${imageType.value}?`)) router.delete(`/admin/branding/${imageType.value}`, { preserveScroll: true });
}
const currentFields = computed(() => props.section === 'features' ? Object.keys(props.values ?? {}).map(key => ({ key, label: key.replaceAll('_', ' '), kind: 'checkbox' } as Field)) : fields[props.section] ?? []);
watch(() => props.section, () => {
    settings.defaults({ values: { ...defaults[props.section], ...props.values } }).reset().clearErrors();
    search.defaults({ q: props.filters.q ?? '', state: props.filters.state ?? '' }).reset();
    editing.value = '';
    creating.value = false;
    navigationForm.defaults(navigationValues()).reset();
    exchange.defaults({ provider: props.exchange?.provider ?? 'manual', credential: '' }).reset();
}, { immediate: true });
watch(() => [mailKey.value, props.templates], () => {
    const item = props.templates?.find(item => item.key === mailKey.value);
    template.defaults({ subject: item?.subject ?? '', body: item?.body ?? '', enabled: item?.enabled ?? true }).reset().clearErrors();
}, { immediate: true });
function saveSettings() {
    settings.transform(data => data.values).patch(`/admin/settings/${props.section}`, { preserveScroll: true, onSuccess: () => { if (props.section === 'mail') { settings.values.password = ''; settings.values.clear_password = false; } } });
}
function newPage() {
    creating.value = true;
    editing.value = '';
    editor.reset().clearErrors();
    editor.sections = [{ title: '', body: '' }];
}
function openPage(item: NonNullable<typeof props.pages>[number]) {
    creating.value = false;
    editing.value = item.slug;
    editor.clearErrors();
    const contents = JSON.parse(typeof item.content === 'string' ? item.content : JSON.stringify(item.content));
    Object.assign(editor, { title: item.title, meta_description: item.meta_description ?? '', published: Boolean(item.published), eyebrow: contents.eyebrow ?? '', intro: contents.intro ?? '', sections: contents.sections ?? contents.features ?? [], steps: contents.steps ?? [] });
}
function savePage() {
    editor.transform(({ slug, eyebrow, intro, sections, steps, ...data }) => ({
        ...data, ...(creating.value ? { slug } : {}),
        content: editing.value === 'home' ? { eyebrow, intro, features: sections, steps } : { sections },
    })).submit(creating.value ? 'post' : 'patch', creating.value ? '/admin/content' : `/admin/content/${editing.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (creating.value) {
                editing.value = editor.slug;
                creating.value = false;
            }
        },
    });
}
function member(item: User, action: string) {
    if (['delete', 'suspend', 'demote', 'promote'].includes(action) && !window.confirm(`${action[0].toUpperCase() + action.slice(1)} ${item.email}?`)) return;
    router.patch(`/admin/users/${item.id}`, { action }, { preserveScroll: true });
}
function value(key: string) { return settings.values[key] ?? ''; }
function change(key: string, event: Event) {
    const control = event.target as HTMLInputElement;
    settings.values[key] = control.type === 'checkbox' ? control.checked : control.value;
}
</script>

<template>
    <Head :title="`Platform · ${sections[section as keyof typeof sections]}`" />
    <Shell>
        <section class="workspace site-width admin">
            <nav class="workspace-nav" aria-label="Platform administration"><Link href="/dashboard">Personal workspace</Link><Link v-for="(label, key) in sections" :key="key" :href="`/admin/${key}`" :aria-current="section === key ? 'page' : undefined">{{ label }}</Link></nav>
            <header class="workspace-heading"><div><p class="eyebrow">Platform administration</p><h1>{{ sections[section as keyof typeof sections] }}</h1></div></header>
            <p v-if="page.props.status" class="workspace-status" role="status">{{ page.props.status }}</p>
            <ul v-if="Object.keys(page.props.errors ?? {}).length" role="alert" class="errors"><li v-for="(error, key) in page.props.errors" :key="key">{{ error }}</li></ul>

            <div v-if="counts" class="workspace-primary">
                <section class="workspace-panel"><h2>Platform activity</h2><dl class="admin-metrics"><div v-for="(count, name) in counts" :key="name"><dt>{{ name }}</dt><dd>{{ count }}</dd></div></dl></section>
                <section v-if="registrationTrend" class="workspace-panel">
                    <h2>Member registrations</h2><p>Daily registrations over the last 30 days</p>
                    <div class="report-chart-group"><ReportChart kind="line" :labels="registrationTrend.labels" :series="[{ label: 'Registrations', color: '#8871a3', values: registrationTrend.counts }]" :whole-numbers="true" /></div>
                    <details><summary>Daily totals</summary><ul class="record-list"><li v-for="(day, index) in registrationTrend.labels" :key="day"><time :datetime="day">{{ day }}</time><span>{{ registrationTrend.counts[index] }}</span></li></ul></details>
                </section>
                <section class="workspace-panel"><h2>Recent administrative activity</h2><p v-if="!audit?.length">No actions recorded.</p><ul v-else class="record-list"><li v-for="item in audit" :key="item.id"><strong>{{ item.action }}</strong><span>{{ item.target }}</span><time>{{ item.created_at }}</time></li></ul></section>
            </div>

            <section v-if="users || logs" class="workspace-panel">
                <form class="filters" @submit.prevent="search.get(`/admin/${section}`)">
                    <label>Search<input v-model="search.q" maxlength="120" type="search"></label>
                    <label v-if="users">Status<select v-model="search.state"><option value="">All statuses</option><option>active</option><option>suspended</option><option>deletion</option></select></label>
                    <button class="button">Search</button>
                </form>
                <ul v-if="users" class="record-list"><li v-for="item in users.data" :key="item.id">
                    <div><strong>{{ item.name }}</strong><small>{{ item.email }} · {{ item.role }} · Joined {{ item.created_at.slice(0, 10) }}</small><small>{{ item.email_verified_at ? 'Verified' : 'Unverified' }} · {{ item.suspended_at ? 'Suspended' : 'Active' }}<template v-if="item.deletion_requested_at"> · Deletion requested</template></small></div>
                    <div v-if="item.id !== viewer" class="row-actions">
                        <button v-if="!item.email_verified_at" type="button" @click="member(item, 'verify')">Verify</button>
                        <button type="button" @click="member(item, item.suspended_at ? 'activate' : 'suspend')">{{ item.suspended_at ? 'Reactivate' : 'Suspend' }}</button>
                        <button v-if="item.role === 'admin' || (!!item.email_verified_at && !item.suspended_at)" type="button" @click="member(item, item.role === 'admin' ? 'demote' : 'promote')">{{ item.role === 'admin' ? 'Remove admin role' : 'Make admin' }}</button>
                        <button v-if="item.deletion_requested_at" type="button" @click="member(item, 'delete')">Approve deletion</button>
                    </div>
                </li></ul>
                <ul v-if="logs" class="record-list"><li v-for="item in logs.data" :key="item.id"><div><strong>{{ item.action }}</strong><small>{{ item.target }} · Administrator {{ item.actor_id ?? 'removed' }} · {{ item.ip }}</small></div><time>{{ item.created_at }}</time></li></ul>
                <p v-if="!(users ?? logs)?.data.length">No results.</p>
                <div class="row-actions"><Link v-if="(users ?? logs)?.prev_page_url" :href="(users ?? logs)!.prev_page_url!">Previous</Link><Link v-if="(users ?? logs)?.next_page_url" :href="(users ?? logs)!.next_page_url!">Next</Link></div>
            </section>

            <div v-if="currentFields.length" class="workspace-grid">
                <form class="auth-form" @submit.prevent="saveSettings">
                    <h2>{{ section === 'mail' ? 'Delivery configuration' : 'Configuration' }}</h2>
                    <label v-for="field in currentFields" :key="field.key" :class="{ check: field.kind === 'checkbox' }">
                        <input v-if="field.kind === 'checkbox'" type="checkbox" :checked="Boolean(value(field.key))" @change="change(field.key, $event)">
                        {{ field.label }}
                        <select v-if="field.options" :value="value(field.key)" @change="change(field.key, $event)"><option v-if="value(field.key) === ''" value="">Choose an option</option><option v-for="option in field.options" :key="option">{{ option }}</option></select>
                        <input v-else-if="field.kind !== 'checkbox'" :value="String(value(field.key))" :type="field.kind ?? 'text'" :autocomplete="field.kind === 'password' ? 'new-password' : undefined" @input="change(field.key, $event)">
                    </label>
                    <button class="button" :disabled="settings.processing">Save configuration</button>
                </form>
                <form v-if="section === 'general'" class="auth-form" @submit.prevent="image.post(`/admin/branding/${imageType}`, { forceFormData: true, preserveScroll: true, onSuccess: () => image.reset() })">
                    <h2>Brand images</h2><label>Image type<select v-model="imageType"><option value="logo">Logo</option><option value="favicon">Favicon</option></select></label>
                    <img v-if="page.props.platform?.[imageType]" :src="page.props.platform[imageType]!" :alt="imageType" class="profile-image">
                    <label>PNG, JPEG or WebP image<input type="file" required accept="image/png,image/jpeg,image/webp" @change="brandingFile"></label>
                    <button class="button" :disabled="image.processing">Upload image</button>
                    <button v-if="page.props.platform?.[imageType]" type="button" @click="removeImage">Remove image</button>
                </form>
                <form v-if="section === 'seo'" class="auth-form" @submit.prevent="image.post('/admin/branding/sharing', { forceFormData: true, preserveScroll: true, onSuccess: () => image.reset() })">
                    <h2>Social sharing image</h2>
                    <img v-if="sharingImage" :src="sharingImage" alt="Current sharing image">
                    <label>PNG, JPEG or WebP image<input type="file" required accept="image/png,image/jpeg,image/webp" @change="brandingFile"></label>
                    <button class="button" :disabled="image.processing">Upload sharing image</button>
                    <button v-if="sharingImage" type="button" @click="router.delete('/admin/branding/sharing', { preserveScroll: true })">Remove sharing image</button>
                </form>
                <div v-if="section === 'mail'" class="workspace-primary">
                    <section class="workspace-panel"><h2>Test delivery</h2><p>The test message goes to your administrator email address. Log transport records the message in the application log.</p><button class="button" :disabled="test.processing" @click="test.post('/admin/mail/test')">Send test message</button></section>
                    <form class="auth-form" @submit.prevent="template.patch(`/admin/templates/${mailKey}`, { preserveScroll: true })">
                        <h2>Email templates</h2>
                        <label>Template<select v-model="mailKey"><option v-for="key in ['verify', 'reset', 'budget', 'recurring', 'goal', 'security']" :key="key">{{ key }}</option></select></label>
                        <label>Subject<input v-model="template.subject" required maxlength="180"></label>
                        <label>Body<textarea v-model="template.body" required maxlength="5000"></textarea></label>
                        <label v-if="!['verify', 'reset'].includes(mailKey)" class="check"><input v-model="template.enabled" type="checkbox"> Enabled</label>
                        <details><summary>Preview</summary><strong>{{ template.subject }}</strong><p class="whitespace-pre-line">{{ template.body }}</p></details>
                        <button class="button" :disabled="template.processing">Save template</button>
                    </form>
                    <section class="workspace-panel"><h2>Delivery log</h2><p v-if="!mailLogs?.data.length">No recorded attempts.</p><ul v-else class="record-list"><li v-for="item in mailLogs.data" :key="item.id"><strong>{{ item.template }}</strong><span>{{ item.status }} · {{ item.created_at }}</span><button v-if="item.status === 'failed' && item.alert_id" type="button" @click="router.post(`/admin/mail/\${item.id}/retry`)">Retry</button></li></ul><div class="row-actions"><Link v-if="mailLogs?.prev_page_url" :href="mailLogs.prev_page_url">Previous</Link><Link v-if="mailLogs?.next_page_url" :href="mailLogs.next_page_url">Next</Link></div></section>
                </div>
            </div>

            <div v-if="pages" class="workspace-grid">
                <section class="workspace-panel"><h2>Public pages</h2><button type="button" class="button" @click="newPage">New page</button><ul class="record-list"><li v-for="item in pages" :key="item.slug"><div><strong>{{ item.title }}</strong><small>{{ item.slug }} · {{ item.published ? 'Published' : 'Draft' }}</small></div><div class="row-actions"><Link v-if="item.published" :href="item.url">View</Link><button type="button" @click="openPage(item)">Edit</button></div></li></ul></section>
                <form v-if="editing || creating" class="auth-form" @submit.prevent="savePage">
                    <h2>{{ creating ? 'New page' : `Edit ${editing}` }}</h2><label v-if="creating">Page URL slug<input v-model="editor.slug" required maxlength="70" pattern="[a-z0-9]+(-[a-z0-9]+)*"><small>/pages/{{ editor.slug || 'your-page' }}</small></label><label>Title<input v-model="editor.title" required maxlength="160"></label><label>Search description<input v-model="editor.meta_description" maxlength="200"></label>
                    <template v-if="editing === 'home'"><label>Hero label<input v-model="editor.eyebrow" required maxlength="100"></label><label>Hero introduction<textarea v-model="editor.intro" required maxlength="500"></textarea></label></template>
                    <fieldset v-for="(item, index) in editor.sections" :key="index" class="admin-fields"><legend>{{ editing === 'home' ? 'Feature' : 'Section' }} {{ index + 1 }}</legend><label>Heading<input v-model="item.title" required maxlength="160"></label><label>Body<textarea v-model="item.body" required rows="4"></textarea></label><button type="button" @click="editor.sections.splice(index, 1)">Remove section</button></fieldset>
                    <button type="button" @click="editor.sections.push({ title: '', body: '' })">Add section</button>
                    <template v-if="editing === 'home'"><fieldset v-for="(_, index) in editor.steps" :key="index" class="admin-fields"><legend>Step {{ index + 1 }}</legend><label>Description<input v-model="editor.steps[index]" required maxlength="250"></label><button type="button" @click="editor.steps.splice(index, 1)">Remove step</button></fieldset><button type="button" @click="editor.steps.push('')">Add step</button></template>
                    <label class="check"><input v-model="editor.published" type="checkbox"> Published</label><button class="button" :disabled="editor.processing">Save page</button>
                </form>
            </div>

            <form v-if="section === 'navigation'" class="auth-form navigation-editor" @submit.prevent="navigationForm.patch('/admin/navigation', { preserveScroll: true })">
                <h2>Website navigation</h2>
                <label>Announcement banner<input v-model="navigationForm.announcement" maxlength="200"></label>
                <template v-for="area in (['header', 'footer'] as const)" :key="area">
                    <fieldset class="admin-fields"><legend>{{ area === 'header' ? 'Header links' : 'Footer links' }}</legend>
                        <div v-for="(item, index) in navigationForm[area]" :key="index" class="admin-fields">
                            <label>Label<input v-model="item.label" required maxlength="55"></label><label>Public path or HTTPS URL<input v-model="item.href" required maxlength="255" placeholder="/pages/your-page"></label>
                            <button type="button" @click="navigationForm[area].splice(index, 1)">Remove link</button>
                        </div>
                        <button type="button" @click="navigationForm[area].push({ label: '', href: '' })">Add link</button>
                    </fieldset>
                </template>
                <button class="button" :disabled="navigationForm.processing">Save navigation</button>
            </form>

            <div v-if="section === 'currency'" class="workspace-grid">
                <div class="workspace-primary">
                    <section class="workspace-panel"><h2>Supported currencies</h2><ul class="record-list"><li v-for="item in currencies" :key="item.code"><div><strong>{{ item.code }} · {{ item.name }}</strong><small>{{ item.decimal_places }} decimal places · {{ item.enabled ? 'Enabled' : 'Disabled' }}</small></div><button type="button" @click="Object.assign(currency, item)">Edit</button></li></ul></section>
                    <section class="workspace-panel"><h2>Recorded exchange rates</h2><p v-if="!rates?.length">No exchange rates recorded.</p><ul v-else class="record-list"><li v-for="item in rates" :key="item.id"><div><strong>{{ item.from_currency }} → {{ item.to_currency }} · {{ item.rate }}</strong><small>{{ item.quoted_on }} · {{ item.source }}</small></div></li></ul></section>
                </div>
                <div class="workspace-primary">
                    <form class="auth-form" @submit.prevent="currency.post('/admin/currencies', { preserveScroll: true })"><h2>Currency settings</h2><label>Code<input v-model="currency.code" required minlength="3" maxlength="3"></label><label>Name<input v-model="currency.name" required maxlength="100"></label><label>Decimal places<input v-model.number="currency.decimal_places" required type="number" min="0" max="4"></label><label class="check"><input v-model="currency.enabled" type="checkbox"> Enabled</label><button class="button" :disabled="currency.processing">Save currency</button></form>
                    <form class="auth-form" @submit.prevent="exchange.patch('/admin/exchange-provider', { preserveScroll: true, onFinish: () => exchange.reset('credential') })">
                        <h2>Exchange-rate provider</h2><label>Provider<select v-model="exchange.provider"><option value="manual">Manually recorded rates</option><option value="open_exchange_rates">Open Exchange Rates</option></select></label>
                        <label v-if="exchange.provider === 'open_exchange_rates'">App ID<input v-model="exchange.credential" autocomplete="new-password" type="password" maxlength="32"></label>
                        <button class="button" :disabled="exchange.processing">Save provider</button>
                        <button v-if="props.exchange?.provider === 'open_exchange_rates'" type="button" @click="router.post('/admin/exchange-rates/refresh', {}, { preserveScroll: true })">Refresh rates now</button>
                    </form>
                    <form class="auth-form" @submit.prevent="rate.post('/admin/exchange-rates', { preserveScroll: true })"><h2>Record an exchange rate</h2><label>From<select v-model="rate.from_currency" required><option value="" disabled>Choose a currency</option><option v-for="item in currencies" :key="item.code">{{ item.code }}</option></select></label><label>To<select v-model="rate.to_currency" required><option value="" disabled>Choose a currency</option><option v-for="item in currencies" :key="item.code">{{ item.code }}</option></select></label><label>Rate<input v-model="rate.rate" inputmode="decimal" required></label><label>Quoted date<input v-model="rate.quoted_on" required type="date"></label><label>Source<input v-model="rate.source" required maxlength="120"></label><button class="button" :disabled="rate.processing">Save rate</button></form>
                </div>
            </div>
        </section>
    </Shell>
</template>

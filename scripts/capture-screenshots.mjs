import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium, devices } from 'playwright';

const base = process.env.SPENDWISE_BROWSER_URL ?? 'http://127.0.0.1:8000';
const output = new URL('../docs/screenshots/', import.meta.url);
const readme = new URL('../README.md', import.meta.url);
const start = '<!-- spendwise-screenshots:start -->';
const end = '<!-- spendwise-screenshots:end -->';
const original = await readFile(readme, 'utf8');
const startIndex = original.indexOf(start);
const endIndex = original.indexOf(end);
if (startIndex < 0 || endIndex <= startIndex) throw new Error('The README screenshot markers are missing.');
let email = process.env.SPENDWISE_DEMO_EMAIL ?? 'demo@spendwise.example';
let password = process.env.SPENDWISE_DEMO_PASSWORD;
if (!password) {
    const preview = await readFile(new URL('../.local/preview-demo-login.txt', import.meta.url), 'utf8').catch(() => '');
    email = preview.match(/Local demo login: (\S+)/)?.[1] ?? email;
    password = preview.match(/Generated demo password: (\S+)/)?.[1];
}
if (!password) throw new Error('Set SPENDWISE_DEMO_PASSWORD for a seeded demo account.');

const browser = await chromium.launch();
try {
    await mkdir(output, { recursive: true });
    const desktop = await browser.newPage({ viewport: { width: 1440, height: 1050 }, colorScheme: 'light' });
    await desktop.goto(new URL('/', base).href);
    await desktop.getByRole('heading', { level: 1 }).waitFor();
    await desktop.screenshot({ path: fileURLToPath(new URL('landing.png', output)), fullPage: true });

    await desktop.goto(new URL('/login', base).href);
    await desktop.getByLabel('Email address').fill(email);
    await desktop.getByLabel('Password', { exact: true }).fill(password);
    await desktop.getByRole('button', { name: 'Sign in', exact: true }).click();
    await desktop.getByRole('heading', { name: 'Cash-flow trend' }).waitFor();
    await desktop.screenshot({ path: fileURLToPath(new URL('dashboard.png', output)), fullPage: true });

    const mobile = await browser.newPage({ ...devices['iPhone 13'], colorScheme: 'dark' });
    await mobile.goto(new URL('/', base).href);
    await mobile.getByRole('heading', { level: 1 }).waitFor();
    await mobile.screenshot({ path: fileURLToPath(new URL('mobile.png', output)), fullPage: true });
} finally {
    await browser.close();
}

const screenshots = [
    '![Spendwise landing page](docs/screenshots/landing.png)',
    '![Spendwise dashboard with demo transactions and cash-flow charts](docs/screenshots/dashboard.png)',
    '![Spendwise mobile homepage in dark mode](docs/screenshots/mobile.png)',
].join('\n\n');
await writeFile(readme, original.slice(0, startIndex + start.length) + '\n\n' + screenshots + '\n\n' + original.slice(endIndex));

import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import test from 'node:test';
import { chromium } from 'playwright';

const execute = promisify(execFile);
const url = process.env.SPENDWISE_BROWSER_URL ?? 'http://127.0.0.1:8074';

test('a verified member records income and a budget through the browser', async () => {
    const browser = await chromium.launch();
    const email = `e2e-${randomUUID()}@spendwise.example`;
    const password = 'E2E test account only 123!';

    try {
        const page = await browser.newPage({ viewport: { width: 1360, height: 900 }, colorScheme: 'dark' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${url}/register`);
        await page.getByLabel('Full name').fill('Spendwise E2E');
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByLabel('Confirm password').fill(password);
        await page.getByRole('checkbox', { name: /agree to the terms/i }).check();
        await page.getByRole('button', { name: 'Create your account' }).click();
        await page.getByRole('heading', { name: 'Verify your email' }).waitFor();

        const { stdout } = await execute('php', ['-r', [
            'require "vendor/autoload.php";',
            '$app = require "bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            '$user = App\\Models\\User::query()->where("email", $argv[1])->firstOrFail();',
            'echo Illuminate\\Support\\Facades\\URL::temporarySignedRoute("verification.verify", now()->addMinutes(5), ["id" => $user->id, "hash" => sha1($user->email)]);',
        ].join(' '), email], { env: { ...process.env, APP_URL: url } });
        await page.goto(stdout.trim());
        await page.getByRole('heading', { name: 'Overview' }).waitFor();
        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Accounts' }).click();
        await page.getByLabel('Name', { exact: true }).fill('Main wallet');
        await page.getByLabel('Currency', { exact: true }).selectOption('USD');
        await page.getByLabel('Opening balance').fill('100');
        await page.getByRole('button', { name: 'Save account' }).click();
        await page.getByText('Account saved.').waitFor();

        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Transactions' }).click();
        const form = page.locator('form').filter({ has: page.getByRole('heading', { name: 'New transaction' }) });
        await form.getByLabel('Description').fill('Freelance work');
        await form.getByLabel('Type', { exact: true }).selectOption('income');
        await form.getByLabel('Account', { exact: true }).selectOption({ label: 'Main wallet · USD' });
        await form.getByLabel('Amount', { exact: true }).fill('42.15');
        await form.getByRole('button', { name: 'Save transaction' }).click();
        await page.getByText('Transaction saved.').waitFor();

        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Overview' }).click();
        await page.getByRole('heading', { name: 'Cash flow' }).waitFor();
        assert.ok((await page.getByRole('main').textContent()).includes('USD 142.15'));
        assert.ok((await page.getByRole('main').textContent()).includes('Freelance work'));
        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Budgets' }).click();
        await page.getByLabel('Name', { exact: true }).fill('Groceries');
        await page.getByLabel('Currency', { exact: true }).selectOption('USD');
        await page.getByLabel('Spending limit').fill('40');
        await page.getByRole('button', { name: 'Save budget' }).click();
        await page.getByText('Budget saved.').waitFor();
        await page.getByRole('progressbar', { name: 'Groceries spending' }).waitFor();
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

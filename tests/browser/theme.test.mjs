import assert from 'node:assert/strict';
import test from 'node:test';
import { chromium, devices } from 'playwright';

const url = process.env.SPENDWISE_BROWSER_URL ?? 'http://127.0.0.1:8074';

test('public pages hydrate and navigation works on a phone in both themes', async () => {
    const browser = await chromium.launch();

    try {
        const context = await browser.newContext({ ...devices['iPhone 13'], colorScheme: 'light' });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));

        await page.goto(url);
        await page.getByRole('heading', { name: 'See where your money goes.' }).waitFor();
        const theme = page.getByRole('button', { name: /change theme/i });
        await theme.click();
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'light');
        await theme.click();
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
        assert.equal(await page.locator('html').evaluate((el) => getComputedStyle(el).colorScheme), 'dark');

        await page.getByRole('button', { name: 'Main navigation' }).click();
        await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Security' }).click();
        await page.getByRole('heading', { name: 'Security and privacy' }).waitFor();
        await page.reload();
        await page.getByRole('heading', { name: 'Security and privacy' }).waitFor();
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
        assert.ok(await page.locator('body').evaluate((el) => el.scrollWidth <= document.documentElement.clientWidth));

        const header = page.getByRole('banner');
        assert.equal(await header.getByRole('link', { name: 'Sign in', exact: true }).count(), 0);
        const getStarted = header.getByRole('link', { name: 'Get started', exact: true });
        assert.equal(await getStarted.getAttribute('href'), '/login');
        await getStarted.click();
        await page.getByRole('heading', { name: 'Sign in' }).waitFor();
        assert.equal(await page.getByLabel('Email address').count(), 1);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

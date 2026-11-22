const { expect, test } = require('@playwright/test');

async function login(page) {
    await page.goto('/login');
    await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
    await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL('**/home');
}

test('cross-business contact and expense-category previews open on standalone pages', async ({ page }) => {
    await login(page);

    await page.goto('/contacts?type=customer');
    await page.getByRole('button', { name: 'Import dari Bisnis' }).click();
    await page.locator('#business_contact_import_source').selectOption({ label: 'CI Import Source Business' });
    await page.getByRole('button', { name: 'Lanjutkan ke preview' }).click();
    await expect(page).toHaveURL(/\/contacts\/import\/business\/preview-page\?/);
    await expect(page.getByRole('heading', { name: 'Preview import customer dari bisnis' })).toBeVisible();
    await expect(page.locator('#business-copy-preview-table tbody tr')).toContainText('CI Source Customer');
    await page.getByRole('link', { name: 'Kembali ke daftar' }).first().click();
    await expect(page).toHaveURL(/\/contacts\?type=customer$/);

    await page.goto('/expense-categories');
    await page.getByRole('button', { name: 'Import dari Bisnis' }).click();
    await page.locator('#business_expense_category_import_source').selectOption({ label: 'CI Import Source Business' });
    await page.getByRole('button', { name: 'Lanjutkan ke preview' }).click();
    await expect(page).toHaveURL(/\/expense-categories\/import\/business\/preview-page\?/);
    await expect(page.getByRole('heading', { name: 'Preview import kategori biaya dari bisnis' })).toBeVisible();
    await expect(page.locator('#business-copy-preview-table tbody tr')).toContainText('CI Source Expense Category');
    await page.getByRole('link', { name: 'Kembali ke daftar' }).first().click();
    await expect(page).toHaveURL(/\/expense-categories$/);
});

const { expect, test } = require('@playwright/test');

async function loginAsCiUser(page) {
    await page.goto('/login');
    await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
    await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
    await page.locator('#login-form button[type="submit"]').click();
}

test('reconciliation findings inbox filters and searches stored anomalies', async ({ page }) => {
    await loginAsCiUser(page);
    const response = await page.goto('/accounting/reports/reconciliation-findings');
    expect(response).not.toBeNull();
    expect(response.status()).toBe(200);

    await expect(page.getByRole('heading', { name: 'Temuan rekonsiliasi' })).toBeVisible();
    await expect(page.locator('#finding_open_count')).not.toHaveText('0');

    await page.locator('#finding_filter_year').selectOption('2022');
    await page.locator('#finding_filter_type').selectOption('unbalanced_journal');
    await page.locator('#finding_filter_search').fill('CI-JRN-UNBALANCED');

    const row = page.locator('#finding_rows');
    await expect(row).toContainText('CI-JRN-UNBALANCED');
    await expect(row).toContainText('Journal tidak seimbang');
    await expect(row).toContainText('Debit vs kredit');
    await expect(row).toContainText('Terbuka');
    await expect(page.locator('#finding_filtered_count')).toHaveText('1');
});

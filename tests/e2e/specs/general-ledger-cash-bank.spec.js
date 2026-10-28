const { expect, test } = require('@playwright/test');

async function loginAsCiUser(page) {
    await page.goto('/login');
    await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
    await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
    await page.locator('#login-form button[type="submit"]').click();
}

test('cash and bank book combines mapped accounts, filters rows, and exports XLSX', async ({ page }) => {
    await loginAsCiUser(page);
    await page.goto('/accounting/reports/general-ledger?start_date=2022-01-01&end_date=2022-01-31');

    await expect(page.getByRole('heading', { name: 'Buku Besar' })).toBeVisible();
    await expect(page.getByText('BUKU KAS/BANK GABUNGAN', { exact: false })).toBeVisible();
    const table = page.locator('.general-ledger-table');
    const openingRow = table.locator('tbody tr.opening-balance-row');
    await expect(openingRow).toContainText('Saldo Awal');
    await expect(openingRow).toContainText('Kas/Bank Gabungan');
    await expect(table).toContainText('CI GL cash movement');
    await expect(table).toContainText('CI GL bank movement');
    await expect(table).not.toContainText('CI GL excluded movement');

    const cashOption = page.locator('#account_id option').filter({ hasText: 'CI Test Cash' }).first();
    const cashAccountId = await cashOption.getAttribute('value');
    await page.locator('#account_id').selectOption(cashAccountId);
    await page.getByRole('button', { name: 'Tampilkan' }).click();

    await expect(table).toContainText('CI GL cash movement');
    await expect(table).not.toContainText('CI GL bank movement');
    await expect(table).not.toContainText('CI GL excluded movement');

    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: /Ekspor Excel/ }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('buku-besar-2022-01-01-2022-01-31.xlsx');
});

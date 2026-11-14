const { expect, test } = require('@playwright/test');

const username = process.env.E2E_EXPENSE_IMPORT_USERNAME;
const password = process.env.E2E_EXPENSE_IMPORT_PASSWORD;
const baseUrl = process.env.PLAYWRIGHT_TEST_BASE_URL || 'http://127.0.0.1:8000';

function appUrl(path) {
    return new URL(path.replace(/^\/+/, ''), baseUrl.replace(/\/?$/, '/')).toString();
}

test('Expense import shows both histories in one responsive, paginated table', async ({ page }) => {
    test.skip(!username || !password, 'Set an Expense-capable local test account to run this browser check.');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(appUrl('login'));
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL('**/home');
    await page.goto(appUrl('import-expense'));

    const historyTable = page.locator('#expense_import_history_table');
    await expect(historyTable).toBeVisible();
    await expect(page.locator('#expense_import_batches_table')).toHaveCount(0);
    await expect(page.locator('#expense_import_legacy_history_table')).toHaveCount(0);
    await expect(page.locator('#expense_import_history_table_wrapper .dataTables_length')).toBeVisible();
    await expect(page.locator('#expense_import_history_table_wrapper .dataTables_paginate')).toBeVisible();

    const addedRows = await historyTable.evaluate(table => {
        const dataTable = window.jQuery(table).DataTable();
        const originalCount = dataTable.rows().count();
        const rows = Array.from({ length: 12 }, (_, index) => [
            'page-' + index,
            'pagination-check-' + index + '.xlsx',
            '2026-11-15 10:' + String(index).padStart(2, '0'),
            'Test',
            'Selesai',
            '100%',
            '12 transaksi',
            ''
        ]);
        const addedIndexes = dataTable.rows.add(rows).indexes().toArray();
        dataTable.draw();
        return { originalCount, addedIndexes };
    });
    await expect(page.locator('#expense_import_history_table_info')).toContainText('Menampilkan 1');
    await page.getByRole('link', { name: 'Berikutnya' }).click();
    await expect(page.locator('#expense_import_history_table_info')).toContainText('Menampilkan 11');
    const remainingRows = await historyTable.evaluate((table, addedIndexes) => {
        const dataTable = window.jQuery(table).DataTable();
        dataTable.rows(addedIndexes).remove().draw();
        dataTable.page('first').draw('page');
        return dataTable.rows().count();
    }, addedRows.addedIndexes);
    expect(remainingRows).toBe(addedRows.originalCount);

    const instructionsModal = page.locator('#expense_import_instructions_modal');
    await expect(instructionsModal).toBeHidden();
    await page.locator('#expense_import_instructions_button').click();
    await expect(instructionsModal).toBeVisible();
    expect(await instructionsModal.locator('table tr').count()).toBeGreaterThan(2);
    await page.locator('#expense_import_instructions_modal [data-dismiss="modal"]').first().click();
    await expect(instructionsModal).toBeHidden();

    const batchId = 'b1200000-0000-4000-8000-000000000001';
    await page.route('**/import-expense/upload', route => route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, batch_id: batchId, message: 'Upload diterima.' }),
    }));
    await page.route('**/import-expense/' + batchId + '/status', route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            state: 'completed',
            operation: 'preview',
            domain_status: 'preview',
            percent: 100,
            current: 2,
            total: 2,
            message: 'Preview selesai.',
            summary: { total: 2, valid: 2, errors: 0, skipped: 0 },
        }),
    }));
    await page.locator('#expense_import_file').setInputFiles({
        name: 'expense-ui-check.xlsx',
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: Buffer.from('mock workbook; upload route is intercepted'),
    });
    await page.locator('#expense_import_submit').click();

    const batchRow = historyTable.locator('[data-expense-import-batch="' + batchId + '"]');
    await expect(batchRow).toBeVisible();
    await expect(batchRow.locator('.expense-import-open-preview')).toBeVisible();
    await expect(page.locator('#expense_import_upload_message')).toHaveText('Upload diterima.');
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect.poll(async () => historyTable.evaluate(table => {
        const top = table.getBoundingClientRect().top;
        return top >= -16 && top < window.innerHeight;
    })).toBe(true);
});

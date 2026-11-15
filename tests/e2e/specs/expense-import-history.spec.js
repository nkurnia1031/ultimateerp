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
    await page.goto(appUrl('import-expense'), { waitUntil: 'domcontentloaded' });

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

test('completed Expense import updates its actions and opens searchable references', async ({ page }) => {
    test.skip(!username || !password, 'Set an Expense-capable local test account to run this browser check.');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(appUrl('login'));
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL('**/home');
    await page.goto(appUrl('import-expense'), { waitUntil: 'domcontentloaded' });

    const batchId = 'b1200000-0000-4000-8000-000000000002';
    const referencesUrl = appUrl('import-expense/' + batchId + '/references');
    const expenseUrl = appUrl('import-expense/' + batchId + '/transactions/502/show');
    const downloadUrl = appUrl('import-expense/' + batchId + '/restore-workbook');

    await page.route('**/import-expense/upload', route => route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, batch_id: batchId, message: 'Upload diterima.' }),
    }));
    await page.route('**/import-expense/' + batchId + '/status', route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            batch_id: batchId,
            state: 'completed',
            operation: 'import',
            domain_status: 'imported',
            percent: 100,
            current: 1,
            total: 1,
            batch_number: 42,
            reference_count: 1,
            message: 'Import selesai: 1 transaksi.',
            summary: { transactions: 1, rows: 2, legacy_import_batch_number: 42 },
            references_url: referencesUrl,
            restore_url: downloadUrl,
            revert_url: appUrl('revert-expense-import/42'),
        }),
    }));
    await page.route('**/import-expense/' + batchId + '/references**', async route => {
        const search = new URL(route.request().url()).searchParams.get('search') || '';
        const references = ['REF-MOCK-001', 'REF-MOCK-002']
            .filter(reference => reference.toLowerCase().includes(search.toLowerCase()))
            .map(reference => ({ id: reference.endsWith('002') ? 502 : 501, reference_no: reference, show_url: expenseUrl.replace('/502/show', reference.endsWith('002') ? '/502/show' : '/501/show') }));
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ data: references, total: references.length, current_page: 1, last_page: 1 }),
        });
    });
    await page.route('**/import-expense/' + batchId + '/transactions/502/show', route => route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<div class="modal-dialog"><div class="modal-content"><div class="modal-body">Expense REF-MOCK-002</div></div></div>',
    }));
    await page.route('**/import-expense/' + batchId + '/restore-workbook', route => route.fulfill({
        status: 200,
        contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        headers: { 'content-disposition': 'attachment; filename="expense-ui-check.xlsx"' },
        body: Buffer.from('mock rebuilt workbook'),
    }));

    await page.locator('#expense_import_file').setInputFiles({
        name: 'expense-ui-check.xlsx',
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: Buffer.from('mock workbook; upload route is intercepted'),
    });
    await page.locator('#expense_import_submit').click();

    const batchRow = page.locator('#expense_import_history_table [data-expense-import-batch="' + batchId + '"]');
    await expect(batchRow).toBeVisible();
    await expect(batchRow.locator('[data-role="status"]')).toHaveText('Import selesai');
    await expect(batchRow.locator('td').first()).toContainText('42');
    await expect(batchRow.locator('[data-role="summary"]')).toHaveText('1 transaksi · 2 baris workbook');
    await expect(batchRow.locator('.expense-import-open-preview')).toBeHidden();
    await expect(batchRow.locator('.expense-import-open-references')).toBeVisible();
    await expect(batchRow.locator('.expense-import-restore-workbook')).toBeVisible();
    await expect(batchRow.locator('a.revert_import')).toBeVisible();

    await batchRow.locator('.expense-import-open-references').click();
    const referencesModal = page.locator('#expense_import_references_modal');
    await expect(referencesModal).toBeVisible();
    await page.locator('#expense_import_reference_search').fill('002');
    await expect(referencesModal.locator('.expense-import-reference-item')).toHaveCount(1);
    await expect(referencesModal.locator('.expense-import-reference-item')).toHaveText('REF-MOCK-002');
    await referencesModal.locator('.expense-import-reference-item').click();
    await expect(page.locator('.payment_modal')).toContainText('Expense REF-MOCK-002');
    await expect(page.locator('.payment_modal')).toBeVisible();
    await page.locator('.payment_modal').evaluate(element => window.jQuery(element).modal('hide'));
    await expect(page.locator('.payment_modal')).toBeHidden();

    const downloadPromise = page.waitForEvent('download');
    await batchRow.locator('.expense-import-restore-workbook').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('expense-ui-check.xlsx');
});

const { expect, test } = require('@playwright/test');

const username = process.env.E2E_USERNAME || 'journal-ci';
const password = process.env.E2E_PASSWORD || 'ci-journal-import-only';

function historyRow(id, overrides = {}) {
    return {
        id,
        file_name: 'legacy-rate-' + id + '.xlsx',
        status: 'imported',
        stage: 'imported',
        progress_current: 1,
        progress_total: 1,
        progress_percent: 100,
        summary: {},
        last_message: 'Batch tarif lama sudah aktif.',
        error_message: null,
        created_at: '2025-01-01 10:00:00',
        updated_at: '2025-01-01 10:00:00',
        has_snapshot: false,
        can_preview: false,
        can_revert: false,
        can_retry: false,
        active: false,
        domain_batch_id: id + 100,
        domain_status: 'active',
        effective_from: '2025-01-01',
        can_download: false,
        can_confirm: false,
        can_cancel: false,
        can_details: false,
        result_summary: '1 tarif / 1 unit / 1 baris sumber',
        ...overrides,
    };
}

test('Heavy Equipment rate upload shows queued progress, standalone preview, filtered history, and restored workbook', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/login');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL('**/home');

    const batchId = 76001;
    const fileName = 'rate-ui-check.xlsx';
    let uploaded = false;
    let analysisPolls = 0;
    let lastStatusFilter = '';
    await page.route('**/asset/fleet/rates/data**', route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ draw: 1, recordsTotal: 0, recordsFiltered: 0, data: [] }),
    }));
    await page.route('**/asset/fleet/rates/import/batches**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.endsWith('/preview')) {
            await route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!doctype html><html lang="id"><head><title>Preview Import Tarif</title></head><body><main><a href="/asset/fleet/rates#fleet-rate-batches-table">Kembali ke daftar import</a><h1>Preview Import Tarif</h1><p>Rute Cikande</p><p>BM 9133</p></main></body></html>',
            });
            return;
        }
        if (url.pathname.endsWith('/details')) {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    batch: { id: batchId, domain_batch_id: 88001, file_name: fileName, status: 'preview_ready', message: 'Preview tarif siap.', summary: {} },
                    rows: [{ source_row: 2, route: 'Rute Cikande', units: [{ label: 'BM 9133', asset_id: 44, asset: 'BM 9133' }], driver_rate_per_ton: 25000, travel_allowance: 50000, sale_rate_per_ton: 75000, status: 'draft', errors: [] }],
                }),
            });
            return;
        }
        if (url.pathname.endsWith('/download')) {
            await route.fulfill({
                status: 200,
                contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                headers: { 'content-disposition': 'attachment; filename="rate-ui-check_dipulihkan.xlsx"' },
                body: Buffer.from('reconstructed workbook'),
            });
            return;
        }

        lastStatusFilter = url.searchParams.get('status') || '';
        const start = Number(url.searchParams.get('start') || 0);
        const length = Number(url.searchParams.get('length') || 25);
        let rows = Array.from({ length: 26 }, (_, index) => historyRow(700 + index));
        if (uploaded) {
            analysisPolls += 1;
            const ready = analysisPolls > 1 || lastStatusFilter === 'preview_ready';
            const currentBatch = historyRow(batchId, {
                file_name: fileName,
                status: ready ? 'preview_ready' : 'analyzing',
                stage: ready ? 'preview_ready' : 'reading_workbook',
                progress_current: ready ? 4 : 1,
                progress_total: 4,
                progress_percent: ready ? 100 : 25,
                last_message: ready ? 'Preview tarif siap.' : 'Memeriksa baris tarif dan unit.',
                has_snapshot: ready,
                active: !ready,
                domain_batch_id: 88001,
                domain_status: 'draft',
                effective_from: '2026-11-01',
                can_download: ready,
                can_preview: ready,
                can_confirm: ready,
                can_cancel: ready,
                can_details: ready,
                result_summary: ready ? '1 tarif / 1 unit / 1 baris sumber' : null,
            });
            rows.unshift(currentBatch);
        }
        if (lastStatusFilter) rows = rows.filter(row => row.status === lastStatusFilter);
        const total = rows.length;
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ draw: Number(url.searchParams.get('draw') || 1), recordsTotal: total, recordsFiltered: total, data: rows.slice(start, start + length) }),
        });
    });
    await page.route('**/asset/fleet/rates/upload', route => {
        uploaded = true;
        return route.fulfill({
            status: 202,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, queued: true, batch_id: batchId, status: 'queued_preview', msg: 'Upload diterima. Analisis workbook berjalan.' }),
        });
    });

    await page.goto('/asset/fleet/rates');
    const table = page.locator('#fleet-rate-batches-table');
    await expect(table).toBeVisible();
    await expect(page.locator('#fleet-rate-batches-table_wrapper .dataTables_paginate')).toBeVisible();
    await page.getByRole('link', { name: 'Berikutnya' }).click();
    await expect(page.locator('#fleet-rate-batches-table_info')).toContainText('Menampilkan 26');

    await page.locator('#fleet-rate-file').setInputFiles({
        name: fileName,
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: Buffer.from('mock workbook; upload is intercepted'),
    });
    await page.locator('#rate_effective_from_display').fill('01/11/2026');
    const uploadResponsePromise = page.waitForResponse(response =>
        response.request().method() === 'POST' && response.url().endsWith('/asset/fleet/rates/upload'),
    );
    await page.getByRole('button', { name: 'Upload & Preview' }).click();
    const uploadResponse = await uploadResponsePromise;
    expect(uploadResponse.status()).toBe(202);

    const batchRow = table.locator('tbody tr').filter({ hasText: fileName });
    await expect(batchRow).toBeVisible();
    await expect(batchRow.locator('td').nth(5)).toContainText('Menganalisis');
    await expect.poll(() => table.evaluate(element => {
        const top = element.getBoundingClientRect().top;
        return top >= -16 && top < window.innerHeight;
    })).toBe(true);
    await expect(batchRow.locator('td').nth(5)).toContainText('Preview siap', { timeout: 15_000 });

    await batchRow.getByRole('link', { name: 'Tinjau preview' }).click();
    await expect(page).toHaveURL(/\/asset\/fleet\/rates\/import\/batches\/76001\/preview$/);
    await expect(page.getByRole('heading', { name: 'Preview Import Tarif' })).toBeVisible();
    await expect(page.getByRole('main')).toContainText('Rute Cikande');
    await expect(page.getByRole('main')).toContainText('BM 9133');
    await page.getByRole('link', { name: 'Kembali ke daftar import' }).click();
    await expect(page).toHaveURL(/\/asset\/fleet\/rates(?:#fleet-rate-batches-table)?$/);
    await expect(table).toBeVisible();

    const downloadPromise = page.waitForEvent('download');
    await batchRow.getByRole('button', { name: 'Unduh ulang' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('rate-ui-check_dipulihkan.xlsx');

    await page.locator('#fleet-rate-batch-status').selectOption('preview_ready');
    await expect.poll(() => lastStatusFilter).toBe('preview_ready');
    await expect(batchRow).toBeVisible();
    await page.locator('#fleet-rate-batch-status').selectOption('');
    await expect(page.locator('#fleet-rate-batches-table_wrapper .dataTables_paginate')).toBeVisible();
    await page.getByRole('link', { name: 'Berikutnya' }).click();
    await expect(page.locator('#fleet-rate-batches-table_info')).toContainText('Menampilkan 26');
});

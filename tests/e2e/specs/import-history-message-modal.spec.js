const { expect, test } = require('@playwright/test');

const username = process.env.E2E_USERNAME || 'journal-ci';
const password = process.env.E2E_PASSWORD || 'ci-journal-import-only';

test('long import history messages are shortened and can be opened in a modal', async ({ page }) => {
    const message = 'Sheet BMT baris 64: Rute, unit, dan sopir wajib cocok sebelum import. '
        + '<img src=x onerror=window.__importMessageXss=true> AKHIR-PESAN-UNIK';
    const batchId = 78123;

    await page.goto('/login');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL('**/home');

    await page.route('**/asset/fleet/rates/data**', route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ draw: 1, recordsTotal: 0, recordsFiltered: 0, data: [] }),
    }));
    await page.route('**/asset/fleet/rates/import/batches**', route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            draw: Number(new URL(route.request().url()).searchParams.get('draw') || 1),
            recordsTotal: 1,
            recordsFiltered: 1,
            data: [{
                id: batchId,
                file_name: 'long-import-error.xlsx',
                status: 'failed',
                stage: 'failed',
                progress_current: 1,
                progress_total: 1,
                progress_percent: 100,
                last_message: 'Analisis workbook gagal.',
                error_message: message,
                created_at: '2026-11-22 10:00:00',
                updated_at: '2026-11-22 10:00:00',
                result_summary: '1 baris perlu ditinjau',
                summary: {},
                has_snapshot: false,
                can_preview: false,
                can_revert: false,
                can_retry: false,
                active: false,
                domain_batch_id: batchId + 100,
                domain_status: 'failed',
                effective_from: '2026-11-22',
                can_download: false,
                can_confirm: false,
                can_cancel: false,
                can_details: false,
            }],
        }),
    }));

    await page.goto('/asset/fleet/rates');

    const row = page.locator('#fleet-rate-batches-table tbody tr').filter({ hasText: 'long-import-error.xlsx' });
    const openMessage = row.locator('.import-history-message-open');
    await expect(openMessage).toBeVisible();
    await expect(row).not.toContainText('AKHIR-PESAN-UNIK');
    await openMessage.click();

    const modal = page.locator('#import-history-message-modal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('.import-history-message-body')).toHaveText(message);
    await expect(modal.locator('img')).toHaveCount(0);
    expect(await page.evaluate(() => window.__importMessageXss)).toBeUndefined();
});

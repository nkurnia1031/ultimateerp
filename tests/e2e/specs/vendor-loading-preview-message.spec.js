const { expect, test } = require('@playwright/test');

const batchId = 900001;
const message = 'Sheet BMT baris 64: Rute, unit, dan sopir wajib cocok sebelum import. '
    + '<img src=x onerror=window.__vendorMessageXss=true> AKHIR-PESAN-UNIK';

test('queued Vendor Loading preview shortens row issues and opens the full message safely', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
    await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL('**/home');

    await page.route(`**/asset/fleet/vendor-loadings/import/batches/${batchId}/status`, route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            success: true,
            batch: {
                id: batchId,
                file_name: 'ci-vendor-loading-preview.xlsx',
                status: 'preview_ready',
                progress: 100,
                processed_sheets: 1,
                total_sheets: 1,
                processed_rows: 1,
                total_rows: 1,
                ready_rows: 0,
                issue_rows: 1,
                missing_date_rows: 0,
                message: 'Preview siap.',
            },
        }),
    }));
    await page.route(`**/asset/fleet/vendor-loadings/import/batches/${batchId}/preview`, route => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            success: true,
            batch_id: batchId,
            preview: {
                summary: { sheets: 1, source_rows: 1, ready_rows: 0, issue_rows: 1, date_missing_rows: 0 },
                sheets: [{
                    sheet_key: 'BMT',
                    title: 'BMT',
                    metadata: {
                        supplier_match_status: 'matched',
                        supplier_id: 1,
                        supplier_label: 'CI Supplier',
                        customer_match_status: 'matched',
                        customer_id: 2,
                        customer_label: 'CI Customer',
                        route_match_status: 'not_set',
                    },
                    rows: [{
                        row_key: 'BMT:64',
                        source_row: 64,
                        ticket_no: 'CI-VL-64',
                        loading_date: '2026-11-20',
                        date_missing: false,
                        vendor_unit_name: 'CI Unit',
                        vendor_driver_name: 'CI Driver',
                        gross_tons: 10,
                        tare_tons: 2,
                        net_tons: 8,
                        vendor_rate_per_ton: 1,
                        vendor_total_amount: 8,
                        row_errors: [message],
                        row_warnings: [],
                    }],
                }],
            },
        }),
    }));

    await page.setViewportSize({ width: 2000, height: 1000 });
    await page.goto(`/asset/fleet/vendor-loadings/import?batch_id=${batchId}`);
    await expect(page.locator('#vendor-import-preview-box')).toBeVisible();
    const issueCell = page.locator('#vendor-import-grid .ag-cell[col-id="row_issue"]');
    const openMessage = issueCell.locator('.import-history-message-open');
    await expect(openMessage).toBeVisible();
    await expect(issueCell).not.toContainText('AKHIR-PESAN-UNIK');
    await openMessage.click();

    const modal = page.locator('#import-history-message-modal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('.import-history-message-body')).toHaveText(message);
    await expect(modal.locator('img')).toHaveCount(0);
    expect(await page.evaluate(() => window.__vendorMessageXss)).toBeUndefined();
});

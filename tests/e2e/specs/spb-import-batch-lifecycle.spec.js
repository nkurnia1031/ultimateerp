const { spawn } = require('node:child_process');
const path = require('node:path');
const { expect, test } = require('@playwright/test');

const repoRoot = path.resolve(__dirname, '../../..');

test('SPB upload flows through preview, queued import, restore, details, and revert', async ({ page }) => {
    const sourceId = `CI-SPB-${Date.now()}-${test.info().retry}`;
    const fileName = `spb-e2e-${sourceId}.csv`;
    const businessDate = new Date().toISOString().slice(0, 10);
    let queueWorker;

    try {
        await page.goto('/login');
        await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
        await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL('**/home');

        await page.goto('/asset/trips/import');
        await expect(page.getByRole('heading', { name: 'Import SPB Angkutan' })).toBeVisible();
        queueWorker = spawn(
            'php',
            ['artisan', 'queue:work', '--queue=imports,default', '--tries=1', '--timeout=1800', '--sleep=1'],
            { cwd: repoRoot, stdio: 'ignore' },
        );

        const csv = [
            'No Polisi,No Tiket,Tanggal muat,Tanggal bongkar,Pks,Pabrik,Diterima,OA,Driver',
            `CI-SPB-TRUCK,${sourceId},${businessDate},${businessDate},CI PKS,CI Pabrik,10,1000,CI SPB Driver`,
        ].join('\n');
        await page.locator('#spb-import-file').setInputFiles({
            name: fileName,
            mimeType: 'text/csv',
            buffer: Buffer.from(csv),
        });
        await page.getByRole('button', { name: 'Preview Workbook' }).click();

        const historyRow = page.locator('#fleet-spb-import-history-table tbody tr').filter({ hasText: fileName });
        await expect(historyRow).toBeVisible();
        await expect.poll(async () => historyRow.locator('td').nth(2).innerText(), {
            timeout: 90_000,
            intervals: [500, 1000, 2000],
        }).toContain('Preview siap');
        await historyRow.getByRole('link', { name: 'Preview' }).click();
        await expect(page.locator('#spb-import-preview')).toBeVisible();
        await expect(page.locator('#fleet-spb-import-preview-table tbody tr')).toContainText('Siap');

        await page.locator('#spb-import-submit').click();
        await expect.poll(async () => {
            const currentRow = page.locator('#fleet-spb-import-history-table tbody tr').filter({ hasText: fileName });
            return currentRow.locator('td').nth(2).innerText();
        }, { timeout: 120_000, intervals: [500, 1000, 2000] }).toContain('Selesai');

        const completedRow = page.locator('#fleet-spb-import-history-table tbody tr').filter({ hasText: fileName });
        await completedRow.getByRole('button', { name: 'Detail' }).click();
        const detailModal = page.locator('#spb-import-batch-details-modal');
        await expect(detailModal).toBeVisible();
        await expect(detailModal).toContainText(sourceId);
        await expect(detailModal).toContainText('SPB-');
        await page.locator('#spb-import-batch-details-modal [data-dismiss="modal"]').first().click();

        const downloadPromise = page.waitForEvent('download');
        await completedRow.getByRole('link', { name: 'Unduh' }).click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe(`${path.parse(fileName).name}_dipulihkan.xlsx`);

        const confirmRevert = page.waitForEvent('dialog');
        await completedRow.getByRole('button', { name: 'Revert' }).click();
        (await confirmRevert).accept();
        await expect.poll(async () => {
            const currentRow = page.locator('#fleet-spb-import-history-table tbody tr').filter({ hasText: fileName });
            return currentRow.locator('td').nth(2).innerText();
        }, { timeout: 30_000, intervals: [500, 1000, 2000] }).toContain('Di-revert');
    } finally {
        if (queueWorker && !queueWorker.killed) {
            queueWorker.kill('SIGTERM');
        }
    }
});

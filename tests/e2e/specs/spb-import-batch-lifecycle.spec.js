const { execFileSync, spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { expect, test } = require('@playwright/test');

const repoRoot = path.resolve(__dirname, '../../..');

test('SPB upload flows through preview, queued import, restore, details, and revert', async ({ page }) => {
    const sourceId = `CI-SPB-${Date.now()}-${test.info().retry}`;
    const fixturePath = execFileSync(
        'php',
        ['tests/e2e/fixtures/create-spb-import-workbook.php', sourceId],
        { cwd: repoRoot, encoding: 'utf8' },
    ).trim();
    const fileName = path.basename(fixturePath);
    let queueWorker;

    try {
        await page.goto('/login');
        await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
        await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL('**/home');

        await page.goto('/asset/fleet/trips/import');
        await expect(page.getByRole('heading', { name: 'Import SPB Angkutan' })).toBeVisible();
        queueWorker = spawn(
            'php',
            ['artisan', 'queue:work', '--queue=imports,default', '--tries=1', '--timeout=1800', '--sleep=1'],
            { cwd: repoRoot, stdio: 'ignore' },
        );

        await page.locator('#spb-import-file').setInputFiles(fixturePath);
        const uploadResponsePromise = page.waitForResponse((response) =>
            response.request().method() === 'POST' && response.url().endsWith('/asset/fleet/trips/import/preview'),
        );
        await page.getByRole('button', { name: 'Preview Workbook' }).click();
        const uploadResponse = await uploadResponsePromise;
        const uploadPayload = await uploadResponse.json();
        expect(uploadResponse.status(), JSON.stringify(uploadPayload)).toBe(202);
        expect(uploadPayload.batch.id).toBeTruthy();

        const historyRow = page.locator('#fleet-spb-import-history-table tbody tr').filter({ hasText: fileName });
        await expect(historyRow).toBeVisible({ timeout: 30_000 });
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

        const confirmRevertPromise = page.waitForEvent('dialog');
        const revertClickPromise = completedRow.getByRole('button', { name: 'Revert' }).click();
        const confirmRevert = await confirmRevertPromise;
        await confirmRevert.accept();
        await revertClickPromise;
        await expect.poll(async () => {
            const currentRow = page.locator('#fleet-spb-import-history-table tbody tr').filter({ hasText: fileName });
            return currentRow.locator('td').nth(2).innerText();
        }, { timeout: 30_000, intervals: [500, 1000, 2000] }).toContain('Di-revert');
    } finally {
        if (queueWorker && !queueWorker.killed) {
            queueWorker.kill('SIGTERM');
        }
        fs.rmSync(fixturePath, { force: true });
    }
});

const { spawn, execFileSync } = require('node:child_process');
const path = require('node:path');
const { expect, test } = require('@playwright/test');

const repoRoot = path.resolve(__dirname, '../../..');

async function batchStatus(page, importId) {
    return page.evaluate(async (id) => {
        const response = await fetch(`/accounting/journal-entry/import/${id}/status`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`Journal Import status returned HTTP ${response.status}`);
        }

        return response.json();
    }, importId);
}

test('a queued Journal Import can stop, resume, and post once', async ({ page }) => {
    const sourceNumber = `CI-${Date.now()}-${test.info().retry}`;
    const fixturePath = execFileSync(
        'php',
        ['tests/e2e/fixtures/create-journal-workbook.php', sourceNumber],
        { cwd: repoRoot, encoding: 'utf8' },
    ).trim();
    const fileName = path.basename(fixturePath);
    let queueWorker;

    try {
        await page.goto('/login');
        await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
        await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
        await page.locator('#login-form button[type="submit"]').click();

        await page.goto('/accounting/journal-entry/import');
        await expect(page.getByRole('heading', { name: 'Impor Jurnal dari Workbook' })).toBeVisible();

        await page.locator('input[name="journal_file"]').setInputFiles(fixturePath);
        await page.getByRole('button', { name: 'Unggah & Analisa di Background' }).click();

        const row = page.locator('tr').filter({ hasText: fileName });
        await expect(row).toBeVisible();
        const terminateButton = row.locator('.journal-import-terminate');
        await expect(terminateButton).toBeVisible();
        const statusId = await row.locator('[id^="journal-import-status-"]').getAttribute('id');
        const importId = statusId.replace('journal-import-status-', '');

        // The server renders the row before the Accounting JavaScript finishes loading.
        // Wait for the control to become active after its delegated handler is registered.
        await expect(terminateButton).toBeEnabled();
        const stopRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'POST' &&
                request.url().endsWith(`/accounting/journal-entry/import/${importId}/terminate`),
            { timeout: 30_000 },
        );
        const stopResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith(`/accounting/journal-entry/import/${importId}/terminate`),
            { timeout: 30_000 },
        );
        const stopDialogPromise = page.waitForEvent('dialog', { timeout: 15_000 });
        const stopClickPromise = terminateButton.click();
        const stopDialog = await stopDialogPromise;
        expect(stopDialog.type()).toBe('confirm');
        expect(stopDialog.message()).toContain('Hentikan import');
        await stopDialog.accept();
        await stopClickPromise;
        const stopRequest = await stopRequestPromise;
        expect(stopRequest.method()).toBe('POST');
        const stopResponse = await stopResponsePromise;
        expect(stopResponse.ok()).toBeTruthy();
        await expect.poll(async () => (await batchStatus(page, importId)).status).toBe('cancelled');

        const resumeLink = row.getByRole('link', { name: 'Lanjutkan import' });
        await expect(resumeLink).toBeVisible();
        await resumeLink.click();
        queueWorker = spawn(
            'php',
            ['artisan', 'queue:work', '--queue=imports,default', '--tries=1', '--timeout=1800', '--sleep=1'],
            { cwd: repoRoot, stdio: 'ignore' },
        );

        await expect.poll(async () => (await batchStatus(page, importId)).status, {
            timeout: 120_000,
            intervals: [500, 1000, 2000],
        }).toBe('analyzed');

        const importRow = page.locator('tr').filter({ hasText: fileName });
        await importRow.getByRole('link', { name: 'Review & Import' }).click();
        await expect(page.getByText('Total Entry', { exact: true })).toBeVisible();
        await expect(page.locator('#journal_import_form input[name="import_id"]')).toHaveValue(importId);
        const csrfToken = await page.locator('#journal_import_form input[name="_token"]').inputValue();

        const startRequestPromise = page.waitForRequest(
            (request) =>
                request.method() === 'POST' &&
                request.url().endsWith('/accounting/journal-entry/import/start'),
            { timeout: 30_000 },
        );
        const startResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith('/accounting/journal-entry/import/start'),
            { timeout: 30_000 },
        );
        const startDialogPromise = page.waitForEvent('dialog', { timeout: 15_000 });
        const startClickPromise = page.getByRole('button', { name: 'Proses Baris Valid' }).click();
        const startDialog = await startDialogPromise;
        expect(startDialog.type()).toBe('confirm');
        expect(startDialog.message()).toContain('Proses baris Journal Entry');
        await startDialog.accept();
        await startClickPromise;
        const startRequest = await startRequestPromise;
        expect(startRequest.method()).toBe('POST');
        const startResponse = await startResponsePromise;
        expect(startResponse.ok()).toBeTruthy();
        await expect.poll(async () => (await batchStatus(page, importId)).status, {
            timeout: 120_000,
            intervals: [500, 1000, 2000],
        }).toBe('imported');

        const completedBatch = await batchStatus(page, importId);
        expect(completedBatch.imported_entries).toBe(1);
        expect(completedBatch.skipped_entries).toBe(0);

        const repeatedStart = await page.evaluate(async ({ id, token }) => {
            const response = await fetch('/accounting/journal-entry/import/start', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': token,
                },
                body: new URLSearchParams({ import_id: id }).toString(),
            });

            return { status: response.status, body: await response.json() };
        }, { id: importId, token: csrfToken });

        expect(repeatedStart.status).toBe(409);
        expect((await batchStatus(page, importId)).imported_entries).toBe(1);
    } finally {
        if (queueWorker && !queueWorker.killed) {
            queueWorker.kill('SIGTERM');
        }
    }
});

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

function diagnosticText(value) {
    return String(value)
        .replace(/Bearer\s+\S+/gi, 'Bearer <REDACTED>')
        .replace(/([?&][^=&#]*(?:token|password|secret|csrf)[^=&#]*=)[^&#\s]*/gi, '$1<REDACTED>')
        .replace(/((?:password|token|secret|authorization|csrf)[^=:\s]*[=:]\s*)[^&\s,;]+/gi, '$1<REDACTED>')
        .slice(0, 300);
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
    const browserDiagnostics = {
        consoleErrors: [],
        pageErrors: [],
        terminateRequests: [],
        terminateResponses: [],
        failedTerminateRequests: [],
    };
    page.on('dialog', (dialog) => dialog.accept());
    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserDiagnostics.consoleErrors.push(diagnosticText(message.text()));
        }
    });
    page.on('pageerror', (error) => {
        browserDiagnostics.pageErrors.push(diagnosticText(error.message || error));
    });
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.pathname.endsWith('/terminate')) {
            browserDiagnostics.terminateRequests.push({ method: request.method(), path: url.pathname });
        }
    });
    page.on('response', (response) => {
        const url = new URL(response.url());
        if (url.pathname.endsWith('/terminate')) {
            browserDiagnostics.terminateResponses.push({ status: response.status(), path: url.pathname });
        }
    });
    page.on('requestfailed', (request) => {
        const url = new URL(request.url());
        if (url.pathname.endsWith('/terminate')) {
            browserDiagnostics.failedTerminateRequests.push({
                method: request.method(),
                path: url.pathname,
                error: request.failure()?.errorText || 'unknown request failure',
            });
        }
    });

    try {
        await page.goto('/login');
        await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
        await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
        await page.locator('#login-form button[type="submit"]').click();

        await page.goto('/accounting/journal-entry/import');
        await expect(page.getByRole('heading', { name: 'Import Journal Entry' })).toBeVisible();

        await page.locator('input[name="journal_file"]').setInputFiles(fixturePath);
        await page.getByRole('button', { name: 'Analisa File' }).click();

        const row = page.locator('tr').filter({ hasText: fileName });
        await expect(row).toBeVisible();
        await expect(row.locator('.journal-import-terminate')).toBeVisible();
        const statusId = await row.locator('[id^="journal-import-status-"]').getAttribute('id');
        const importId = statusId.replace('journal-import-status-', '');

        await page.evaluate(() => {
            window.__journalImportTerminateDiagnostics = {
                confirmMessage: null,
                captureClicks: 0,
                bubbleClicks: 0,
            };
            const isTerminateClick = (event) => event.target instanceof Element
                && event.target.closest('.journal-import-terminate') !== null;
            document.addEventListener('click', (event) => {
                if (isTerminateClick(event)) {
                    window.__journalImportTerminateDiagnostics.captureClicks++;
                }
            }, true);
            document.addEventListener('click', (event) => {
                if (isTerminateClick(event)) {
                    window.__journalImportTerminateDiagnostics.bubbleClicks++;
                }
            });
            window.confirm = (message) => {
                window.__journalImportTerminateDiagnostics.confirmMessage = String(message);
                return true;
            };
        });
        const terminateHandlerRegistered = await page.evaluate(() => {
            const clickHandlers = window.jQuery
                ? (window.jQuery._data(document, 'events')?.click || [])
                : [];

            return clickHandlers.some((handler) => handler.selector === '.journal-import-terminate');
        });
        const stopResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith(`/accounting/journal-entry/import/${importId}/terminate`),
            { timeout: 30_000 },
        );
        await row.locator('.journal-import-terminate').click();
        let stopResponse;
        try {
            stopResponse = await stopResponsePromise;
        } catch (error) {
            const pageState = await page.evaluate((id) => {
                const button = document.querySelector(`#journal-import-action-${id} .journal-import-terminate`);
                const actionCell = document.querySelector(`#journal-import-action-${id}`);

                return {
                    terminateHandlerRegistered: (window.jQuery?._data(document, 'events')?.click || [])
                        .some((handler) => handler.selector === '.journal-import-terminate'),
                    clickDiagnostics: window.__journalImportTerminateDiagnostics,
                    button: button ? {
                        text: button.textContent.trim(),
                        disabled: button.disabled,
                        dataId: button.getAttribute('data-id'),
                    } : null,
                    terminateUrlPath: actionCell?.dataset.terminateUrl
                        ? new URL(actionCell.dataset.terminateUrl, window.location.origin).pathname
                        : null,
                };
            }, importId);

            await test.info().attach('terminate-click-diagnostics.json', {
                body: JSON.stringify({
                    terminateHandlerRegistered,
                    pageState,
                    browserDiagnostics,
                    failure: diagnosticText(error.message || error),
                }, null, 2),
                contentType: 'application/json',
            });
            throw error;
        }
        expect(stopResponse.ok()).toBeTruthy();
        expect(await page.evaluate(() => window.__journalImportTerminateDiagnostics.confirmMessage)).toContain('Hentikan import');
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

        const startResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith('/accounting/journal-entry/import/start'),
            { timeout: 30_000 },
        );
        await page.getByRole('button', { name: 'Proses Baris Valid' }).click();
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

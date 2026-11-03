const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { expect, test } = require('@playwright/test');

test('reviewers can bulk review only visible differences without reloading the page', async ({ page }) => {
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=JournalImportE2ESeeder'], {
        cwd: path.resolve(__dirname, '../../..'),
        stdio: 'ignore',
    });

    await page.goto('/login');
    await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
    await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
    await page.locator('#login-form button[type="submit"]').click();

    await page.goto('/accounting/journal-entry/import');
    const importRow = page.locator('tr').filter({ hasText: 'journal-import-bulk-review-fixture.xlsx' });
    await expect(importRow).toBeVisible();
    await importRow.getByRole('link', { name: 'Review & Import' }).click();

    const draft = page.locator('#journal_import_draft');
    await expect(draft).toBeVisible();
    const importId = await draft.getAttribute('data-import-id');
    const explainedRow = page.locator('#journal_import_draft_table tr').filter({ hasText: 'CI-BULK-EXPLAINED' });
    const unexplainedRow = page.locator('#journal_import_draft_table tr').filter({ hasText: 'CI-BULK-UNEXPLAINED' });
    const unchangedRow = page.locator('#journal_import_draft_table tr').filter({ hasText: 'CI-BULK-NO-DIFFERENCE' });
    const explainedCheckbox = explainedRow.locator('.journal-draft-difference-select');
    const unexplainedCheckbox = unexplainedRow.locator('.journal-draft-difference-select');
    const unchangedCheckbox = unchangedRow.locator('.journal-draft-difference-select');

    await expect(explainedCheckbox).toBeEnabled();
    await expect(unexplainedCheckbox).toBeEnabled();
    await expect(unchangedCheckbox).toBeDisabled();
    await explainedCheckbox.check();
    await expect(page.locator('#journal_import_bulk_review_reason_wrap')).toBeHidden();

    await page.locator('#journal_import_select_page_differences').check();
    await expect(explainedCheckbox).toBeChecked();
    await expect(unexplainedCheckbox).toBeChecked();
    await expect(unchangedCheckbox).not.toBeChecked();
    await expect(page.locator('#journal_import_bulk_review_reason_wrap')).toBeVisible();
    await expect(page.locator('#journal_import_review_selected_differences')).toHaveText('Tinjau 2 selisih terpilih');
    await page.locator('#journal_import_bulk_review_reason').fill('Selisih telah dicocokkan dengan sumber jurnal.');

    const reviewResponsePromise = page.waitForResponse((response) =>
        response.request().method() === 'PATCH' &&
        response.url().endsWith(`/accounting/journal-entry/import/${importId}/draft-rows/approve-differences`),
    );
    const reviewPageUrl = page.url();
    await page.locator('#journal_import_review_selected_differences').click();
    const reviewResponse = await reviewResponsePromise;
    expect(reviewResponse.ok()).toBeTruthy();
    const reviewPayload = await reviewResponse.json();
    expect(reviewPayload.reviewed_rows).toBe(2);
    expect(reviewPayload.approved_rows).toBe(1);
    await expect(page).toHaveURL(reviewPageUrl);
    await expect(explainedRow.locator('.journal-draft-reviewed')).toBeChecked();
    await expect(unexplainedRow.locator('.journal-draft-reviewed')).toBeChecked();

    const storedRows = await page.evaluate(async (id) => {
        const response = await fetch(`/accounting/journal-entry/import/${id}/draft-rows?type=Expense&processing_filter=all`);
        return response.json();
    }, importId);
    const explained = storedRows.rows.find((row) => row.source_number === 'CI-BULK-EXPLAINED');
    const unexplained = storedRows.rows.find((row) => row.source_number === 'CI-BULK-UNEXPLAINED');
    const unchanged = storedRows.rows.find((row) => row.source_number === 'CI-BULK-NO-DIFFERENCE');
    expect(explained).toMatchObject({ reconciliation_status: 'auto_approved', is_reviewed: true });
    expect(unexplained).toMatchObject({ reconciliation_status: 'approved', is_reviewed: true });
    expect(unexplained.difference_approval_reason).toBe('Selisih telah dicocokkan dengan sumber jurnal.');
    expect(unchanged).toMatchObject({ reconciliation_status: 'not_checked', is_reviewed: false });
});

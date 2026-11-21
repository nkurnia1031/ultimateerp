const { expect, test } = require('@playwright/test');

test('product checkboxes toggle when their labels are tapped in WebKit', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#username').fill(process.env.E2E_USERNAME || 'journal-ci');
    await page.locator('#password').fill(process.env.E2E_PASSWORD || 'ci-journal-import-only');
    await page.locator('#login-form button[type="submit"]').click();

    const response = await page.goto('/products/create');
    expect(response).not.toBeNull();
    expect(response.status()).toBe(200);
    await expect(page.locator('#product_add_form')).toBeVisible();

    const checkboxes = [
        { name: 'enable_stock', initiallyChecked: true },
        { name: 'enable_sr_no', initiallyChecked: false },
        { name: 'not_for_selling', initiallyChecked: false },
    ];
    const labels = page.locator('#product_add_form label.product-form-checkbox');
    await expect(labels).toHaveCount(checkboxes.length);

    for (const { name, initiallyChecked } of checkboxes) {
        const checkbox = page.locator(`#product_add_form input[name="${name}"]`);
        const label = checkbox.locator('xpath=ancestor::label[1]');
        const bounds = await label.boundingBox();

        expect(bounds).not.toBeNull();
        expect(bounds.height).toBeGreaterThanOrEqual(44);
        expect(await checkbox.isChecked()).toBe(initiallyChecked);

        await label.tap({ position: { x: bounds.width - 15, y: bounds.height / 2 } });
        await expect(checkbox).toHaveJSProperty('checked', !initiallyChecked);

        if (name === 'enable_stock') {
            await expect(page.locator('#alert_quantity_div')).toBeHidden();
            await expect(page.locator('#opening_stock_button')).toBeDisabled();

            await label.tap({ position: { x: bounds.width - 15, y: bounds.height / 2 } });
            await expect(checkbox).toBeChecked();
            await expect(page.locator('#alert_quantity_div')).toBeVisible();
            await expect(page.locator('#opening_stock_button')).toBeEnabled();
        }
    }
});

import { expect, test } from '@playwright/test';
import { createProject, signUp } from './helpers';

test('the Free plan limit shows an upgrade prompt and subscribing lifts it', async ({ page }) => {
    await signUp(page, 'Fran Free');

    for (const name of ['One', 'Two', 'Three']) {
        await createProject(page, `Project ${name}`);
        await expect(page.getByRole('heading', { name: `Project ${name}` })).toBeVisible();
    }

    // The fourth project hits the Free limit (3): opening the form sends us to the plans with a prompt.
    await page.goto('/projects/create');
    await expect(page).toHaveURL(/\/billing/);
    const prompt = page.getByRole('alert').filter({ hasText: 'Free plan includes up to 3 projects' });
    await expect(prompt).toBeVisible();
    await expect(prompt).toContainText('Upgrade to Pro');

    // Subscribe to Pro through the billing page (fake gateway, no keys needed).
    await page.getByRole('button', { name: 'Subscribe' }).first().click();
    await expect(page.getByText("You're now on the Pro plan.")).toBeVisible();

    await createProject(page, 'Project Four');
    await expect(page.getByRole('heading', { name: 'Project Four' })).toBeVisible();
});

test('cancelling keeps the plan until the period ends and can be resumed', async ({ page }) => {
    await signUp(page, 'Cam Canceller');
    await page.goto('/billing');
    await page.getByRole('button', { name: 'Subscribe' }).first().click();
    await expect(page.getByText("You're now on the Pro plan.")).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Cancel subscription' }).click();
    await expect(page.getByText(/Cancelled: your plan ends on/)).toBeVisible();

    await page.getByRole('button', { name: 'Resume subscription' }).click();
    await expect(page.getByText('Subscription resumed.')).toBeVisible();
});

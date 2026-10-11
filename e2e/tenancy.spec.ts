import { expect, test } from '@playwright/test';
import { createProject, signUp } from './helpers';

test('one team can never open another teams project', async ({ browser }) => {
    const alice = await (await browser.newContext()).newPage();
    const bob = await (await browser.newContext()).newPage();

    await signUp(alice, 'Alice Owner');
    await createProject(alice, 'Alice secret roadmap');
    await expect(alice.getByRole('heading', { name: 'Alice secret roadmap' })).toBeVisible();
    const projectUrl = alice.url();

    await signUp(bob, 'Bob Owner');
    const response = await bob.goto(projectUrl);
    expect(response?.status()).toBe(404);
    await expect(bob.getByText('Alice secret roadmap')).toHaveCount(0);

    await bob.goto('/projects');
    await expect(bob.getByText('Alice secret roadmap')).toHaveCount(0);
    await expect(bob.getByText('No projects found.')).toBeVisible();

    await alice.context().close();
    await bob.context().close();
});

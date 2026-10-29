import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { createProject, signUp } from './helpers';

test('a team member attaches, downloads and removes a file, and other teams cannot get it', async ({ browser }) => {
    const owner = await (await browser.newContext()).newPage();
    const stranger = await (await browser.newContext()).newPage();

    await signUp(owner, 'Faye Filer');
    await createProject(owner, 'Launch files');
    await owner.getByLabel('New task title').fill('Write the brief');
    await owner.getByRole('button', { name: 'Add task' }).click();
    await expect(owner.getByText('Write the brief')).toBeVisible();

    await owner.getByRole('button', { name: 'Files for Write the brief' }).click();
    const panel = owner.locator('[data-testid^="files-"]');
    await expect(panel.getByText('No files yet.')).toBeVisible();

    // Attach a PDF.
    const content = Buffer.from('%PDF-1.4\nthe launch brief\n');
    await panel.locator('input[type=file]').setInputFiles({ name: 'brief.pdf', mimeType: 'application/pdf', buffer: content });
    await expect(owner.getByText('File attached.')).toBeVisible();
    await expect(panel.getByRole('link', { name: 'brief.pdf' })).toBeVisible();
    await expect(owner.getByRole('button', { name: 'Files for Write the brief' })).toContainText('Files (1)');
    await expect(owner.getByTestId('storage-used')).toContainText('File storage: 1 MB used of 25 MB.');

    // Download it: always a download, with its own name and the same bytes.
    const link = panel.getByRole('link', { name: 'brief.pdf' });
    const href = (await link.getAttribute('href'))!;
    const download = owner.waitForEvent('download');
    await link.click();
    const file = await download;
    expect(file.suggestedFilename()).toBe('brief.pdf');
    expect(await readFile((await file.path()) as string)).toEqual(content);

    // Something that could run in a browser is refused by the server, whatever the file picker allowed.
    await panel.locator('input[type=file]').setInputFiles({ name: 'page.html', mimeType: 'text/html', buffer: Buffer.from('<script>alert(1)</script>') });
    await expect(panel.getByText(/following extensions/i)).toBeVisible();
    await expect(owner.getByRole('button', { name: 'Files for Write the brief' })).toContainText('Files (1)');

    // An obviously too big file is stopped before it is sent.
    await panel.locator('input[type=file]').setInputFiles({ name: 'huge.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(11 * 1024 * 1024, 1) });
    await expect(panel.getByText('That file is larger than 10 MB.')).toBeVisible();

    // Another team gets nothing, not even a hint that it exists.
    await signUp(stranger, 'Sam Stranger');
    const response = await stranger.goto(href);
    expect(response?.status()).toBe(404);

    // Remove it.
    owner.once('dialog', (dialog) => dialog.accept());
    await panel.getByRole('button', { name: 'Remove brief.pdf' }).click();
    await expect(owner.getByText('File removed.')).toBeVisible();
    await expect(panel.getByText('No files yet.')).toBeVisible();
    await expect(owner.getByTestId('storage-used')).toContainText('0 MB used');

    const gone = await owner.request.get(href);
    expect(gone.status()).toBe(404);

    await owner.context().close();
    await stranger.context().close();
});

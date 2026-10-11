import { expect, type Page } from '@playwright/test';

const MAILPIT = process.env.MAILPIT_URL ?? 'http://localhost:8025';

export const PASSWORD = 'password-123';

export function uniqueEmail(prefix: string): string {
    return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.com`;
}

interface MailpitMessage { ID: string; Subject: string }

/** Polls Mailpit until a message for the address (optionally matching a subject) arrives. */
export async function waitForMail(to: string, subject?: RegExp): Promise<MailpitMessage> {
    for (let attempt = 0; attempt < 30; attempt++) {
        const response = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`);
        const body = (await response.json()) as { messages?: MailpitMessage[] };
        const match = body.messages?.find((message) => !subject || subject.test(message.Subject));
        if (match) return match;
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error(`No mail for ${to} arrived in Mailpit`);
}

/** Returns the first link in the mail whose href matches the pattern, HTML entities decoded. */
export async function linkFromMail(message: MailpitMessage, pattern: RegExp): Promise<string> {
    const full = (await (await fetch(`${MAILPIT}/api/v1/message/${message.ID}`)).json()) as { HTML: string };
    const hrefs = [...full.HTML.matchAll(/href="([^"]+)"/g)].map((match) => match[1].replaceAll('&amp;', '&'));
    const link = hrefs.find((href) => pattern.test(href));
    if (!link) throw new Error(`No link matching ${pattern} in "${message.Subject}"`);
    return link;
}

export async function register(page: Page, name: string, email: string): Promise<void> {
    await page.goto('/register');
    await page.fill('#name', name);
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await page.fill('#password_confirmation', PASSWORD);
    await page.click('form button');
    await page.waitForURL('**/verify-email');
}

export async function verifyEmail(page: Page, email: string): Promise<void> {
    const message = await waitForMail(email, /verify/i);
    await page.goto(await linkFromMail(message, /verify-email/));
    await page.waitForURL('**/dashboard');
}

/** Registers, verifies the email and lands on the dashboard of the user's personal team. */
export async function signUp(page: Page, name: string, email = uniqueEmail('user')): Promise<string> {
    await register(page, name, email);
    await verifyEmail(page, email);
    return email;
}

export async function logIn(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.click('form button');
    await page.waitForURL('**/dashboard');
}

export async function createProject(page: Page, name: string): Promise<void> {
    await page.goto('/projects/create');
    await page.fill('#name', name);
    await page.getByRole('button', { name: 'Save' }).click();
}

export { expect };

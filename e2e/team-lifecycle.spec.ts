import { expect, test } from '@playwright/test';
import { PASSWORD, linkFromMail, waitForMailCount, register, signUp, uniqueEmail, verifyEmail, waitForMail } from './helpers';

/** Gives the owner's personal team room for a second member (Free allows 2 seats, including pending invitations). */
async function upgradeToPro(page: import('@playwright/test').Page): Promise<void> {
    await page.goto('/billing');
    await page.getByRole('button', { name: 'Subscribe' }).first().click();
    await expect(page.getByText("You're now on the Pro plan.")).toBeVisible();
}

test('an owner can send an invitation again', async ({ page }) => {
    await signUp(page, 'Rita Resender');
    await upgradeToPro(page);

    const inviteeEmail = uniqueEmail('again');
    await page.goto('/team');
    await page.fill('#invite-email', inviteeEmail);
    await page.getByRole('button', { name: 'Send invite' }).click();
    await expect(page.getByText(`${inviteeEmail} · member · pending`)).toBeVisible();
    await waitForMail(inviteeEmail, /invited/i);

    await page.getByRole('button', { name: 'Resend', exact: true }).click();

    await expect(page.getByText(`Invitation sent again to ${inviteeEmail}.`)).toBeVisible();
    await expect(page.getByText(`${inviteeEmail} · member · pending`)).toBeVisible();
    // Two emails, and no more than two: a resend sends one, not several.
    expect(await waitForMailCount(inviteeEmail, /invited/i, 2)).toBe(2);
});

test('an owner deletes a team and its members are told by email', async ({ browser }) => {
    const owner = await (await browser.newContext()).newPage();
    const member = await (await browser.newContext()).newPage();

    await signUp(owner, 'Dora Deleter');
    await upgradeToPro(owner);

    const memberEmail = uniqueEmail('member');
    await owner.goto('/team');
    await owner.fill('#invite-email', memberEmail);
    await owner.getByRole('button', { name: 'Send invite' }).click();
    const invitation = await waitForMail(memberEmail, /invited/i);
    const acceptUrl = await linkFromMail(invitation, /invitations\//);

    await register(member, 'Mick Member', memberEmail);
    await verifyEmail(member, memberEmail);
    await member.goto(acceptUrl);
    await expect(member.getByText(/You joined Dora Deleter's Team/)).toBeVisible();

    // A member sees no way to delete the team.
    await member.goto('/team');
    await expect(member.getByRole('button', { name: 'Delete team', exact: true })).toHaveCount(0);

    // The owner has to type the name and the password.
    await owner.goto('/team');
    await owner.getByRole('button', { name: 'Delete team', exact: true }).click();
    await owner.fill('#delete-name', 'Some other name');
    await owner.fill('#delete-password', PASSWORD);
    await owner.getByRole('button', { name: 'Delete team permanently' }).click();
    await expect(owner.getByText('Type the team name exactly to confirm.')).toBeVisible();

    await owner.fill('#delete-name', "Dora Deleter's Team");
    await owner.fill('#delete-password', 'not-the-password');
    await owner.getByRole('button', { name: 'Delete team permanently' }).click();
    await expect(owner.getByText('The password is incorrect.')).toBeVisible();

    await owner.fill('#delete-password', PASSWORD);
    await owner.getByRole('button', { name: 'Delete team permanently' }).click();

    // Their only team is gone, so the owner is asked to create one.
    await owner.waitForURL('**/teams/create');
    await expect(owner.getByText(/Dora Deleter's Team.* was deleted\./)).toBeVisible();

    // The member is told, and falls back to their own team: the deleted one is gone from their team list.
    await waitForMail(memberEmail, /was deleted/i);
    await member.goto('/dashboard');
    await expect(member.getByLabel('Switch team')).toContainText("Mick Member's Team");
    await expect(member.getByLabel('Switch team')).not.toContainText("Dora Deleter's Team");

    await owner.context().close();
    await member.context().close();
});

import { expect, test } from '@playwright/test';
import { linkFromMail, register, signUp, uniqueEmail, verifyEmail, waitForMail } from './helpers';

test('an owner invites someone by email and they join through the signed link', async ({ browser }) => {
    const owner = await (await browser.newContext()).newPage();
    const invitee = await (await browser.newContext()).newPage();

    // Free allows 2 members, so the owner needs room for an invitation: upgrade to Pro first.
    await signUp(owner, 'Olive Owner');
    await owner.goto('/billing');
    await owner.getByRole('button', { name: 'Subscribe' }).first().click();
    await expect(owner.getByText("You're now on the Pro plan.")).toBeVisible();

    const inviteeEmail = uniqueEmail('invitee');
    await owner.goto('/team');
    await owner.fill('#invite-email', inviteeEmail);
    await owner.getByRole('button', { name: 'Send invite' }).click();
    await expect(owner.getByText(`Invitation sent to ${inviteeEmail}.`)).toBeVisible();
    await expect(owner.getByText(`${inviteeEmail} · member · pending`)).toBeVisible();

    // The email is queued; it arrives in Mailpit with a signed link.
    const message = await waitForMail(inviteeEmail, /invited/i);
    const acceptUrl = await linkFromMail(message, /invitations\//);
    expect(acceptUrl).toContain('signature=');

    // The invitee signs up with the invited address, then follows the link.
    await register(invitee, 'Ivy Invitee', inviteeEmail);
    await verifyEmail(invitee, inviteeEmail);
    await invitee.goto(acceptUrl);
    await expect(invitee.getByText(/You joined Olive Owner's Team/)).toBeVisible();

    await invitee.goto('/team');
    await expect(invitee.getByRole('main').getByText('Ivy Invitee')).toBeVisible();
    // A Member cannot manage the team.
    await expect(invitee.getByText('Invite someone')).toHaveCount(0);

    // The owner now sees the member and the used invitation is gone.
    await owner.reload();
    await expect(owner.getByRole('main').getByText('Ivy Invitee')).toBeVisible();
    await expect(owner.getByText(`${inviteeEmail} · member · pending`)).toHaveCount(0);

    // The signed link is single use.
    const reuse = await invitee.goto(acceptUrl);
    expect(reuse?.status()).toBe(410);

    await owner.context().close();
    await invitee.context().close();
});

test('an invitation link does not work for a different account', async ({ browser }) => {
    const owner = await (await browser.newContext()).newPage();
    const stranger = await (await browser.newContext()).newPage();

    await signUp(owner, 'Ola Owner');
    await owner.goto('/billing');
    await owner.getByRole('button', { name: 'Subscribe' }).first().click();
    await expect(owner.getByText("You're now on the Pro plan.")).toBeVisible();

    const invited = uniqueEmail('invited');
    await owner.goto('/team');
    await owner.fill('#invite-email', invited);
    await owner.getByRole('button', { name: 'Send invite' }).click();
    const acceptUrl = await linkFromMail(await waitForMail(invited, /invited/i), /invitations\//);

    await signUp(stranger, 'Sam Stranger');
    const response = await stranger.goto(acceptUrl);
    expect(response?.status()).toBe(403);

    await owner.context().close();
    await stranger.context().close();
});

# Security policy

This is a demo starter project, but it is written to be run for real, so security reports are welcome.

## Reporting a vulnerability

Please **do not open a public issue** with the details.

Use GitHub's private reporting: on the repository, open the **Security** tab and choose **Report a vulnerability**. It reaches
the maintainer privately and keeps the discussion hidden until there is a fix.

If that button is not available, open an issue titled "Security contact request" **without any details** and a private channel
will be arranged.

Helpful in a report: what you found, the steps or request that show it, the version or commit, and what an attacker gains.

What to expect: an acknowledgement within a few days, a fix or an explanation as soon as it can be made, and credit in the
changelog if you want it. There is no bug bounty.

## Supported versions

Only the latest commit on `main` (and the latest tagged release, once there is one) gets fixes.

## What is in scope

The application code in this repository: tenant isolation, authentication (passwords, two-factor, Google and GitHub sign-in,
API tokens), authorization, file uploads and downloads, the billing webhook, and the HTTP security headers.

Out of scope: vulnerabilities in third-party packages with no way to reach them through this code (report those upstream;
Dependabot and `composer audit` / `npm audit` in CI watch for known ones), and anything that needs access to the server or its
`.env` file.

## What the project already does

So reports can start from what is known:

- Tenant data is isolated by a global scope that fails closed, and route model binding goes through it (another tenant's record is a 404).
- Passwords are hashed, sign-in and sensitive actions are rate limited, and sensitive actions ask for the password again.
- Two-factor codes cannot be replayed, recovery codes are single use, and secrets are encrypted at rest.
- API tokens are stored as hashes, shown once, tied to one team, and expire.
- Uploaded files are stored on a private disk under random names, checked by name and content against an allowlist, and only served as downloads.
- Stripe webhooks are verified by signature and processed once per event.
- Responses carry a strict Content-Security-Policy with a per-request nonce, HSTS over HTTPS, and the other standard headers.

## For people running it

Never commit `.env`. Set real values for `APP_KEY`, the database and Redis passwords, and the Stripe and OAuth secrets. Set
`TRUSTED_PROXIES` when behind a load balancer. `docs/deploy.md` has the production checklist.

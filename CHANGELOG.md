# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
aims to follow [Semantic Versioning](https://semver.org/) once it is tagged.

## [Unreleased]

The first release, `1.0.0`, is this state of `main`. Rename this heading and add the date when the tag is made.

### Added

**Foundation**
- Laravel 12, Inertia 2, Vue 3 and TypeScript, with Tailwind. Teams as tenants: one database, a global tenant scope that fails closed, and route model binding that returns 404 across tenants.
- Owner, Admin and Member roles with policies. Signed, expiring, single-use invitations that can be resent.
- An audit log (searchable, exportable as CSV), CSV export of a project's tasks, and a personal data download.
- Rate limits on registration, invitations, email changes, exports and billing; email address changes that need the password and a mailed link; team deletion with a name-and-password check; account deletion.

**Billing**
- Free, Pro and Business plans with project, member and storage limits and feature flags. Going over a limit shows an upgrade prompt, and a downgrade never deletes anything.
- A `BillingGateway` with a fake gateway for local use and a Stripe gateway (Cashier 16): Checkout, plan changes, cancel and resume, free trials, yearly billing, invoices, and the Customer Portal.
- A signature-verified, idempotent Stripe webhook pipeline on a Redis queue, with retry of stuck events, and payment-failed and trial-ending emails with a grace period.

**Security**
- Two-factor authentication (TOTP) with recovery codes and replay protection.
- Sign in with Google and GitHub, matched by the provider's id or an email address the provider has verified.
- A strict Content-Security-Policy with a nonce, HSTS and the other security headers.

**Product**
- Projects and tasks with search, filters and pagination. Task attachments on a private disk with a per-plan storage quota.
- In-app notifications with a header bell. A bearer-token JSON API for projects and tasks, tied to one team (see `docs/api.md`).
- Dark mode, and English, Spanish and German (screens, server messages, emails and notifications).

**Operations and quality**
- A production Docker image (web, Horizon worker and scheduler roles), Sentry hooks, JSON logs with request ids, and Horizon.
- Pest tests, Playwright browser tests, Larastan level 8, Pint, a contrast scan in both themes, a phone-width scan, and a translation completeness test.
- GitHub Actions CI (tests with a coverage gate, browser tests, image build and smoke test), a release workflow for the image, and Dependabot.
- A README with a Stripe sandbox walkthrough, `docs/api.md` and `docs/deploy.md`.

### Security
- Updated `symfony/mime` to clear CVE-2026-45070.

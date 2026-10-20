# Deploying and operating

How to run the production image, what to configure, and what to do on each release. The image is built from the
[Dockerfile](../Dockerfile); CI builds and smoke-tests it on every push, and pushing a `v*` tag publishes it to
`ghcr.io/kevinmakwana/saas-starter-laravel` (see `.github/workflows/release.yml`).

## What runs

One image, three roles. Run each as its own container or process so they scale and restart independently.

| Role | Command | Notes |
| --- | --- | --- |
| web | image default (nginx + PHP-FPM, port 8080, non-root) | Health check: `GET /up` |
| worker | `php artisan horizon` | Redis queues `default`, `webhooks`, `mail` (see `config/horizon.php`) |
| scheduler | `php artisan schedule:work` | Pruning, webhook retry sweeper, Horizon snapshots |

Dependencies: MySQL 8, Redis 7, an SMTP provider, and (for billing) Stripe. TLS terminates in front of the web
container; set `TRUSTED_PROXIES` so the app then sends HSTS on https requests.

## Configuration

Set these in the environment (never bake secrets into the image). Everything else is in `.env.example`.

| Variable | Why |
| --- | --- |
| `APP_KEY`, `APP_URL` | Required. `APP_URL` must be the public https URL (signed invitation links use it). |
| `DB_*`, `REDIS_*`, `MAIL_*` | Backing services. Use `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`. |
| `LOG_STACK=json` | The image default. One JSON object per line on stderr, with `request_id`, `user_id` and `team_id`. |
| `SENTRY_LARAVEL_DSN` | Turns Sentry on. Release is set from the image (`SENTRY_RELEASE` = git sha). `SENTRY_ENVIRONMENT=production`. |
| `ALERT_EMAIL` | Gets an email when a queued job fails for good (max one per job class per 15 min) and on long queue waits. |
| `TRUSTED_PROXIES` | Required behind a load balancer that ends TLS: its IPs or CIDR ranges (comma separated), or `*` if the web container is only reachable through it. Without it the app sees plain http behind the balancer: no HSTS header, and every visitor shares the balancer's IP, so the login, registration and webhook rate limits (which key on the client IP) throttle everyone together. |
| `HORIZON_ALLOWED_EMAILS` | Comma separated emails allowed to open `/horizon`, once they have verified the address. Empty means nobody. |
| `STRIPE_*`, `BILLING_GATEWAY=stripe` | Billing, including `STRIPE_PRICE_*_YEARLY` and `BILLING_TRIAL_DAYS`. See the README. |
| `CSP_REPORT_ONLY=true` | Optional: log CSP violations in the browser without blocking while you add a third party. |

Sentry only receives ids (request, user, team), never names or emails (`send_default_pii` stays off).
In Stripe, point one endpoint at `https://<APP_URL>/webhooks/stripe` and send `customer.subscription.created`, `.updated`, `.deleted`,
`customer.subscription.trial_will_end`, `invoice.payment_failed` and `invoice.paid`. Cashier's own `/stripe/webhook` route is disabled on purpose.
Every response carries an `X-Request-Id`; quote it when investigating an error to find the exact log lines and Sentry event.

## Releasing

Run these in order for each new image. Steps 1 and 2 must finish before the new web containers receive traffic.

1. **Migrate**, once per release, not per container: `php artisan migrate --force`
2. **Cache**: `php artisan optimize` (config, routes, views, events)
3. **Roll the web containers** to the new image and wait for `GET /up` to return 200.
4. **Restart workers gracefully**: `php artisan horizon:terminate`. Horizon finishes running jobs, exits and your
   orchestrator starts it on the new code. A worker that keeps old code runs jobs against the new schema.
5. Verify: `/up`, a login, and the Horizon dashboard (jobs flowing, no failed jobs).

### Rolling back

Redeploy the previous image tag and run `php artisan horizon:terminate`. Do **not** run `migrate:rollback` in production:
because of the rules below, the previous code works with the new schema, so a code rollback is enough.

## Migration safety

Web containers of the old and new version run side by side during a deploy, and queued jobs written by old code run on
new code. A migration must therefore work with **both** versions. Split risky changes over two releases (expand, then contract):

| Change | Do |
| --- | --- |
| Add a column | Make it nullable or give it a default. Release the code that uses it afterwards or together. |
| Rename a column or table | Don't. Add the new one, write to both, backfill, switch reads, and drop the old one in a later release. |
| Drop a column or table | Only in a release after the last code that reads it is gone. |
| Change a column type or add a `NOT NULL` | Add a new column, backfill in batches, switch, drop the old one later. |
| Add an index to a big table | Use a separate migration, off-peak, and check `EXPLAIN` first. MySQL 8 builds most indexes online. |
| Backfill data | In a queued job or command in batches, never inside the schema migration. |

Before merging a migration, review the SQL it will run with `php artisan migrate --pretend`.

## Backups

The database is the only source of truth. Redis holds the queues, cache and sessions: losing it logs people out and loses
pending jobs (a Stripe event that was stored but not processed is re-run by `webhooks:retry-stuck`), but no business data.

- Take a **daily full backup** of MySQL and keep **point-in-time recovery** (binary logs) so you can restore to just before a bad deploy. Managed MySQL services offer both.
- Keep backups in a different account or region from the database, encrypted, for at least 30 days.
- **Rehearse a restore** into a scratch database each quarter. A backup you have not restored is not a backup.
- `APP_KEY` is part of the backup: encrypted columns and signed links cannot be read without it. Store it in your secret manager.
- `RETENTION_AUDIT_LOGS_DAYS` deletes audit entries permanently. Backups hold older entries until they expire; set the two to match your policy.

## Incidents: where to look

| Symptom | Look at |
| --- | --- |
| A user reports an error | Their `X-Request-Id` (browser network tab) in the logs and Sentry |
| Emails or Stripe changes are late | Horizon dashboard: queue wait times, failed jobs; `php artisan queue:failed` |
| A Stripe event did not apply | `processed_webhook_events` rows with `processed_at` null; `php artisan webhooks:retry-stuck` or `webhooks:replay evt_...` |
| Alert email: "Queued job failed" | Fix the cause, then `php artisan queue:retry <id>` |

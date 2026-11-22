# Operating Spendwise

## Release and deployment

1. Provision PostgreSQL, a TLS endpoint, and a persistent private file volume for `storage/app/private`.
2. Build the frontend assets from the checked-in lockfile. The supplied Dockerfile builds an Apache/PHP application image with the Vite assets bundled.
3. Set `APP_URL` to the public HTTPS URL. Set `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_SECURE_COOKIE=true`. Generate a unique `APP_KEY` and keep it in the deployment secret store. The key is required for encrypted settings, session cookies, and authenticator secrets. Configure database access with a dedicated role.
4. Run `php artisan migrate --force` and `php artisan db:seed --force` for an initial installation. The standard seeder inserts missing public content, currencies, and email templates while preserving existing administrator edits.
5. Run a supervised `php artisan queue:work --tries=3` worker and execute `php artisan schedule:run` every minute, or run a supervised `php artisan schedule:work` process. Restart workers after deployments. Serve only `public/` and verify `/up`.
6. Register a member and verify their email address, then run `php artisan spendwise:admin admin@example.org` against the intended installation to grant the first administrator access.
7. Configure a live mail transport, send a test message, and test the real verification and reset flows on the published hostname.

Back up PostgreSQL and private uploads on a schedule and exercise restores on an isolated installation. Restrict access to backups and logs. Configure the production mail transport before enabling registration on a public instance. Log transport is intended for local development and records message contents, including temporary account links.

## Container profile

For local container development, create `.env`, configure the database password, and generate `APP_KEY` with `php artisan key:generate` or set a generated key in the environment. Set `APP_URL=http://localhost:8000` for the default local port.

```sh
docker compose --profile container build app
docker compose --profile container run --rm app php artisan migrate --seed --force
docker compose --profile container up -d
```

The profile shares the private upload volume across the web, queue, and scheduler processes. The web and database ports bind to localhost. In production, publish the app through the TLS endpoint.

## Service workers and scheduled activity

The recurring and budget command runs every 15 minutes. An active recurring rule writes each due occurrence through the same ledger used for manual transactions. The rule row is locked while an occurrence is posted. A unique rule/date key prevents duplicate postings after retries. The scheduler catches up up to 20 dated occurrences per rule per run, stops after the inclusive end date, and pauses processing if the expected active account or category is unavailable. Scheduled transactions appear in the calendar before posting.

Monthly rules use the start day as an anchor and clamp to the last day of shorter months. Quarterly and yearly rules are intervals of three or twelve months. Paused rules retain their next occurrence. Resuming a past-due rule can post missed occurrences. Change the start date to a later date before resuming if those occurrences should be skipped.

Budget alerts count posted expenses within an inclusive budget range in its original currency. The configured percentage produces an approaching alert. Reaching and exceeding the limit are distinct events. Savings milestones reflect the separate contribution ledger. Optional alert emails are queued, filtered by account preferences, and listed with their delivery status. Eligible failed alerts can be retried from the console.

## Imports and exports

CSV import accepts a UTF-8 file, a destination account, explicit column names and date format, and an optional expense category. Positive amounts are income and negative amounts are expenses, in the destination account currency. The preview identifies invalid rows and duplicates. The confirmation records only valid rows that still pass duplicate checks. Unconfirmed previews expire according to the retention setting. Confirmed import history retains row counts and discards the raw preview.

Transaction exports contain one row per posting, so transfers have two rows. Reports can be exported as CSV or printed to PDF using the browser. The printable report is bounded to 1,000 posting rows. Personal data export is JSON and contains receipt metadata. Original receipt files can be downloaded from their corresponding transactions.

## Exchange rates

Spendwise preserves each posting's original currency and amount. It calculates original-currency subtotals before estimating a display balance. The estimate uses explicitly recorded quotes, shows the date and source, and is unavailable when any required currency lacks a quote. Conversion rounds after summing with exact decimals. Changing settings or refreshing quotes never rewrites transaction values. Dashboard estimates use the latest recorded eligible quotes at view time.

Administrators can enter timestamped currency pairs manually or configure Open Exchange Rates under Currencies using a server-side App ID. The active conversion flag controls display and the scheduled feed refresh. Refresh rates manually in the console to verify setup before relying on the scheduler. No live provider is required for original-currency reports.

## Account deletion

A member requests deletion from personal settings after confirming their password. An administrator reviews requests in Members and approves eligible accounts. Approval removes account records, sessions, and dependent financial records, with private files removed after database commit. Adjust published privacy and retention policies to the hosting operator’s actual backup and support practices before opening the service to users.

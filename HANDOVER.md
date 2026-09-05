# HomeLedger MVP handover

## Product summary

HomeLedger is a household financial tracker. Each household has its own ledger. The primary flow is to record income and expenses, review a monthly overview, and automate regular entries such as salary, rent, mortgage, subscriptions and utility bills.

## Technical baseline

- PHP 8.1+ with strict types
- MySQL 8 or MariaDB 10.6+
- PDO with native prepared statements
- Server-rendered HTML with small, framework-free JavaScript enhancements
- CSS custom properties for the complete light and dark theme
- PWA manifest and service worker for desktop installation
- Apache/Docker local development path

There are no Composer or Node dependencies in the MVP.

## Project map

| Path | Responsibility |
| --- | --- |
| `public/index.php` | Front controller, auth gate and route selection. Unknown `?page=` values return HTTP 404 when signed in. Unauthenticated requests go to login or register. |
| `app/bootstrap.php` | Runtime configuration and session setup |
| `app/Database.php` | PDO connection |
| `app/actions.php` | Validated POST actions and CRUD operations |
| `app/Recurrence.php` | Date-safe recurrence calculation |
| `app/Auth.php` | Household registration, session login, lockout, invite acceptance, profile and password updates |
| `app/Invites.php` | Invite create, resend, hash lookup, mail, revoke |
| `app/Mailer.php` | SMTP STARTTLS sender for invite email (no Composer). Credentials come from env. |
| `app/helpers.php` | Rendering, CSRF, household-scoped data access and recurring materialisation |
| `templates/auth-layout.php` | Slim login and register layout (no sidebar, no Add entry) |
| `templates/pages/` | Dashboard, transaction, recurring-entry, statement, household, login, register and not-found views |
| `app/StatementExport.php` | SpreadsheetML Excel and a small library-free PDF for the Statement page |
| `templates/partials/` | Entry dialogs and the profile settings dialog |
| `public/assets/` | Theme, interactions, icons and `brand/` logos (light and dark wordmarks, favicons, PWA icons) |
| `database/schema.sql` | Tables, indexes, constraints. Starter categories are seeded per household on register |
| `database/migrations/` | Numbered SQL for live volumes. `002` adds auth tables. `003` adds household tenancy. `003b` finishes transaction unique keys if `003` stopped mid-run. `004` adds `household_invites`. `005` adds nullable `households.public_code`; `scripts/backfill_household_codes.php` fills codes and applies `005b` (NOT NULL UNIQUE) |
| `scripts/process_recurring.php` | Optional scheduled recurrence processor for all households |
| `scripts/backfill_household_codes.php` | One-off fill of `households.public_code` on a live volume |
| `tests/household_code_test.php` | Public household code format checks |
| `tests/recurrence_test.php` | Boundary tests for repeat dates |
| `tests/statement_export_test.php` | Excel and PDF export content checks |
| `tests/invite_mail_test.php` | Invite HTML theme, escaped copy, multipart MIME and plaintext URL |
| `docker-compose.yml` | App on port 8080, phpMyAdmin on port 8081, MySQL published on host port 3306 |

## Data model

`households` is the tenant. `users` belong to one household (email is unique across the app). `categories`, `transactions` and `recurring_entries` all include `household_id`. The numeric `households.id` is the internal primary key and all foreign keys stay on that BIGINT. The Household page and profile dialog show `households.public_code` (16 uppercase letters and digits in four groups of four, no `0/O` or `1/I`) as Household ID. `household_invites` stores a SHA-256 hex hash of the raw join token, never the raw token. `login_attempts` is global (IP and email), because the household is not known until after a successful sign-in.

Every SELECT, INSERT, UPDATE and DELETE on money tables is filtered by the signed-in user's `household_id`. Category ids from another household are rejected. Missing rows are reported as not found.

`transactions.household_id + recurring_entry_id + transaction_date` is unique. This keeps recurring generation idempotent inside a household.

Deleting a recurring schedule keeps the historical transactions by setting their recurrence reference to null. Editing a schedule affects future occurrences only.

The first account created on a live volume that already has transactions, while `users` is still empty, is attached to that existing household. After any user exists, uninvited registration always creates a new household and a fresh starter category set. Invited registration attaches the new user to the inviting household and does not seed categories.

The Statement page is a household period report. It totals income and expenses between a from date and a to date (inclusive). The default range is the first day of the current month through today. It does not show bank or account balances. Account balances remain a later roadmap item. Signed-in users can download the same range as PDF or Excel. The old `?page=balance-sheet` address redirects to `?page=statement`.

## Theme direction

The interface adapts the supplied Kokoszone design system rather than reproducing its marketing-page layout. Dark mode uses near-black ink, deep raised panels, lime as the primary action colour, violet for comparison data, coral for expenses and cyan for secondary categorisation. Light mode reverses the paper and ink relationship while preserving the accent hierarchy.

The first viewport is a working financial surface, with no marketing hero. Theme preference is stored on the device.

## Product decisions

HomeLedger is multi-household. Each household sees only its own ledger. A second person joins the same ledger with a 24-hour invite from a current member. Roles are not built. Everyone in a household has the same access.

Keep the current stack: PHP, MySQL, PDO, server-rendered HTML, and the Kokoszone theme. Do not swap frameworks. Do not add Composer or Node unless a later task truly requires them.

## Security decisions

- All state-changing requests require a CSRF token. A missing or stale token returns HTTP 403.
- Database values use prepared statements.
- Output is escaped at the rendering boundary.
- Server validation checks type, category ownership, amount, dates and text length.
- Session cookies are HTTP-only, SameSite Lax and secure on HTTPS.
- The included Apache rules add basic browser security headers.
- Money queries always include `household_id` from the signed-in user.

phpMyAdmin on port 8081 and MySQL on port 3306 are still separate services and are not covered by app login. Change the Compose example passwords before any LAN or internet use. Recurring materialisation on the web runs only after sign-in, for that household. The CLI processor walks every household.

## Authentication

- Email is stored lowercased in `users.login` and is unique globally. Passwords use `password_hash(..., PASSWORD_DEFAULT)` and `password_verify`.
- Public pages: login and register. Every other page and every money, invite or settings POST requires `$_SESSION['user_id']`. Session id is regenerated on login, register, logout and password change. CSRF is required on those POSTs.
- The first user on a database with no `users` rows, when a household already has transactions, keeps that ledger. Later uninvited users get a new household and seeded categories. Invitees join the inviting household.
- Invite tokens are 32 random bytes, stored as SHA-256 hex (`token_hash`). Lookup uses `hash_equals`. Links expire after 24 hours. Used tokens cannot be reused. Invite email must match the register email. Households are limited to 10 new invites per day. Resend replaces the hash, resets expiry to 24 hours, and invalidates the previous URL. Resend waits 60 seconds if the link was just issued. Invites live on `?page=household`. `?page=invite` redirects there.
- Invite email uses SMTP STARTTLS when `MAIL_HOST`, `MAIL_USERNAME` and `MAIL_PASSWORD` are set in the environment (Compose reads the local `.env`). Messages are `multipart/alternative` (quoted-printable `text/plain` plus Kokoszone-themed `text/html`). The URL is always shown. Failures are logged without the SMTP password. Do not put real mail passwords in git, `schema.sql`, Compose, README or this file. Copy `.env.example` to `.env`. Set `APP_URL` to `https://homeledger.koptechnology.co.uk` so invite links and public mail assets use that origin; local browse stays `http://localhost:8080`. Recreate the app container after changing mail or `APP_URL` env: `docker compose up -d --force-recreate --no-deps app`. Do not use `docker compose down -v`.
- Settings: the top-right profile icon opens a `<dialog>` on any signed-in page (`templates/partials/profile-popover.php`). Display name, household name and password can be changed. Email and the public household code are read-only. Password change requires the current password and uses `password_hash`. `?page=settings` redirects to Overview with the dialog open. The Household page is the full household view: name, public ID, members (earliest user labelled Owner, display only) and invites.
- Lockout: five failed passwords lock the account for 15 minutes. About 20 attempts from one IP in 15 minutes are also blocked.
- There is no remember-me cookie. There is no password in git, `schema.sql` or Compose.
- Live Docker volumes do not re-run `schema.sql`. Apply `002_create_users.sql`, `003_household_tenancy.sql`, then `004_household_invites.sql`, then `005_household_public_code.sql` and `scripts/backfill_household_codes.php` (see file headers). Use `003b` only if `003` stopped mid-run.
- Service worker cache is `homeledger-shell-v15`. Navigations are not cached.

## Recommended next phases

1. Optional: use the stored `BREVO_API_KEY` for a later HTTP mailer if SMTP is not enough.
2. Add budgets by category with threshold alerts.
3. Add CSV import/export and encrypted backup/restore.
4. Add account balances and transfer transactions.
5. Add monthly reports, comparisons and printable summaries.
6. Add notifications for upcoming or unusually high bills.
7. Add automated integration and browser tests around CRUD, recurring generation and household isolation.

## Acceptance checklist

- A one-time income or expense can be added, edited and deleted.
- Transactions can be filtered by month, type and search text.
- A recurring entry can be added, edited, paused, resumed and deleted.
- The Statement page totals income, expenses and net for a chosen date range and can download PDF or Excel.
- Due recurring items produce transactions only once.
- January 31 and leap-year recurrence boundaries are calculated correctly.
- Dashboard totals reflect the selected month.
- Light and dark themes remain readable on desktop and mobile widths.
- The app can be installed from a supported browser when served on localhost or HTTPS.
- First run creates a household account. Later visits require sign-in. An uninvited second registration is a separate household. An invited user joins the existing household.
- Signed-in users can open Household to edit the name, copy the public ID, see members, and send or resend a 24-hour invite. Display name and password stay on the profile icon.

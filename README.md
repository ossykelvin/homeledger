# HomeLedger

HomeLedger is a household financial tracker built with PHP and MySQL. Each signed-in household has its own ledger. It supports one-time transactions, recurring income and bills, monthly summaries, spending breakdowns, light and dark themes, and desktop installation as a Progressive Web App (PWA).

## Included in the MVP

- Dashboard with monthly income, expenses, net balance and savings rate
- Statement page with from/to dates, category breakdown, a capped entry list, and PDF or Excel download
- Six-month cash-flow chart and top spending categories
- Create, edit, search, filter and delete one-time transactions
- Daily, weekly, monthly and yearly recurring entries
- Pause, resume, edit and delete recurring schedules
- Automatic creation of transactions when recurring items become due
- GBP defaults, with currency and timezone controlled through environment variables
- Responsive light and dark modes based on the supplied Kokoszone theme
- Installable desktop experience for Chrome and Microsoft Edge
- Household sign-in and registration. New households confirm email before first sign-in. Invite joins skip that extra email. Every money row is scoped to a `household_id`.
- Household hub (`?page=household`): name, public household ID, members, and 24-hour invites with resend. `?page=invite` redirects here.
- Settings: signed-in users open the profile icon in the top bar to change display name, household name, and password, or to permanently delete their account. Email and household ID are read-only.
- Prepared statements, output escaping, CSRF protection and server-side validation

## Fastest setup with Docker

Requirements: Docker Desktop with Docker Compose.

1. Open a terminal in this project folder.
2. Change the example passwords in `docker-compose.yml` if the app will be used outside your own computer.
3. Start the application:

   ```bash
   docker compose up --build
   ```

4. If this Docker volume already had data, apply new migrations in order (PowerShell):

   ```powershell
   Get-Content -Raw .\database\migrations\002_create_users.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   Get-Content -Raw .\database\migrations\003_household_tenancy.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   Get-Content -Raw .\database\migrations\004_household_invites.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   Get-Content -Raw .\database\migrations\005_household_public_code.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   docker compose exec -T app php scripts/backfill_household_codes.php
   Get-Content -Raw .\database\migrations\006_household_state_version.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   Get-Content -Raw .\database\migrations\007_email_confirmation.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   Get-Content -Raw .\database\migrations\008_household_owner_user.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
   ```

   If `003` stopped mid-run, apply `003b_finish_transaction_household.sql` before `004`. Do not run `docker compose down -v`.

5. Open `http://localhost:8080`. Sign in, or choose **Create a household** to register. The first account on an empty-user volume that already has transactions keeps that ledger. Later uninvited registrations get a separate empty ledger. To add someone to an existing household, sign in and open **Household**.
6. Open the database GUI at `http://localhost:8081` (phpMyAdmin). It signs in with the Compose user `homeledger` and password `change-this-password`. The database name is `homeledger`. phpMyAdmin is not protected by the HomeLedger login.

Desktop clients such as MySQL Workbench, DBeaver or TablePlus can use host `127.0.0.1`, port `3306`, database `homeledger`, username `homeledger` and password `change-this-password`. If XAMPP MySQL is already using port 3306, change the `db` ports mapping in `docker-compose.yml` to `"3307:3306"` and connect the desktop client on port 3307.

Do not use XAMPP phpMyAdmin at `http://localhost/phpmyadmin` for this Docker database. That talks to a different MySQL instance.

The database schema and starter categories are imported automatically on first start. Data is stored in the `homeledger_mysql` Docker volume.

To stop the application:

```bash
docker compose down
```

Do not add `-v` unless you intentionally want to delete the stored database volume.

## Setup with XAMPP on Windows

Requirements: PHP 8.1 or later, MySQL 8 or MariaDB 10.6 or later, and the PDO MySQL PHP extension.

1. Copy the `homeledger` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`.
4. Use the Import tab to import `database/schema.sql`.
5. Set these environment variables for Apache, or update the fallback values in `app/bootstrap.php` for local use:

   ```text
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=homeledger
   DB_USERNAME=root
   DB_PASSWORD=
   ```

6. Point Apache at the `public` folder for the safest configuration. For a quick local check, open `http://localhost/homeledger/public/`. Create the owner account on first visit.

## Standard Apache or cPanel setup

1. Create a MySQL database and database user.
2. Give the user full privileges on that database.
3. Import `database/schema.sql`. If your provider does not allow `CREATE DATABASE` or `USE`, remove the first six lines and import the remaining tables into the database you created.
4. Upload the project outside `public_html` where possible.
5. Set the site document root to the project's `public` directory.
6. Configure the variables shown in `.env.example` through the hosting control panel or Apache environment configuration.
7. Use HTTPS before installing the PWA or making the site available beyond localhost. Create the owner account on first visit.

For Namecheap cPanel Git Version Control, keep the clone at `~/homeledger` and the document root at `~/homeledger/public` (not `public_html`). `.cpanel.yml` copies `app`, `templates`, `public`, `scripts`, and `database` into `$HOME/homeledger` when the Git clone is a separate folder. If the clone is already that path, deploy is a no-op after pull. It never copies `.env` and never imports `schema.sql` or runs migrations. After a GitHub push: cPanel → Files → Git Version Control → Manage → **Update from Remote**, then **Deploy HEAD Commit**. If cPanel still reports uncommitted changes, those are local edits on the server checkout (often leftover File Manager uploads). Discard tracked files there and leave `.env` untracked. Do not commit `.env` from the server. Apply `database/migrations/007_email_confirmation.sql` and `008_household_owner_user.sql` in phpMyAdmin yourself.

The `.env.example` file documents the expected values. The application reads real operating-system environment variables and does not load `.env` files by itself.

## Install it as a desktop application

After HomeLedger is running over localhost or HTTPS:

1. Open it in Chrome or Microsoft Edge.
2. Select the install icon in the address bar, or use the browser menu and choose **Install HomeLedger** or **Apps > Install this site as an app**.
3. Launch HomeLedger from the Start menu or taskbar like a desktop application.

The interface assets are cached, but financial pages still require the PHP server and MySQL database to be running. This prevents stale financial data from being shown while offline.

## How recurring entries work

HomeLedger checks for due recurring entries whenever a signed-in household loads a page. Only that household's schedules are generated. Each missed due date is created once, protected by a database unique key. Month-end schedules are clamped correctly, so an entry starting on 31 January becomes due on 28 or 29 February.

For a server that may not receive regular visits, schedule this command daily. It processes **all** households:

```bash
php scripts/process_recurring.php
```

An optional date can be passed for testing:

```bash
php scripts/process_recurring.php 2026-12-31
```

## Household

Signed-in members open **Household** to see the household name (editable), the public household ID (read-only, `A3K9-M2PQ-7X2B-Q8NL` format), the member list, and invites. The owner is stored as `households.owner_user_id` (the household creator, or whoever later received ownership). That is a display and invite-permission label; everyone still has the same access to the ledger. `?page=invite` redirects to Household.

Send a 24-hour join link from the same page. When `MAIL_*` is set, HomeLedger sends the invite over SMTP (STARTTLS). The page always shows the URL to copy. **Resend** on a pending or expired unused invite issues a new token, resets expiry to 24 hours, and retires the old link.

The invitee opens `/?page=register&invite=TOKEN`, signs up with that email, and is attached to the same household. No new household and no extra category seed. Used, expired or unknown tokens show an error and offer a normal register (which creates a different household).

Uninvited register still creates a new household. The only exception is the first user on a volume with no `users` rows when an existing household already has transactions: that account keeps the existing ledger.

Set `APP_URL` to `https://homeledger.koptechnology.co.uk` so invite emails and join links use the public origin. The app itself still listens on `http://localhost:8080` in Compose; the fallback `APP_URL` in `docker-compose.yml` is only used if `.env` omits it. If `APP_URL` is empty, the app uses the current request host.

## Invite email (SMTP)

Copy `.env.example` to `.env` and fill `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM`, `MAIL_FROM_NAME` and `MAIL_ENCRYPTION=tls`. `MAIL_FROM` must be a sender verified in Brevo (kokoszone.com). Compose passes those into the `app` service. Do not put the real password in `docker-compose.yml` or in git. `.env` is gitignored.

After changing mail settings, recreate the app container only:

```bash
docker compose up -d --force-recreate --no-deps app
```

Do not run `docker compose down -v`. `BREVO_API_KEY` can sit in `.env` for a later API mailer; v1 sends with SMTP on port 587.

## Settings

Signed-in members click the profile icon in the top right to open a dialog. They can change display name, household name, or password (current password, new password, confirm, at least 12 characters). Email and the household ID (a 16-character public code such as `A3K9-M2PQ-7X2B-Q8NL`) are shown read-only. The numeric `households.id` stays internal and is not shown. Account deletion is also in this dialog: it is permanent, requires the household ID and current password, and transfers ownership when the owner is not the last member. `?page=settings` still opens the same dialog on Overview.

## Run the checks

```bash
php tests/recurrence_test.php
php tests/statement_export_test.php
php tests/household_code_test.php
php tests/invite_mail_test.php
php tests/email_confirm_test.php
php tests/household_state_test.php
php tests/account_delete_test.php
php tests/categories_test.php
find app public scripts tests templates -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Privacy and production note

This MVP can host more than one household on the same server. App pages require a signed-in user. Queries only return rows for that user's `household_id`. Uninvited registration creates a new household (the first account on a live volume with existing transactions keeps that ledger). Extra people join through a 24-hour invite.

App login does not protect phpMyAdmin on port 8081 or MySQL on port 3306. Change the example Compose passwords before any LAN or internet use. Before a public launch, also add encrypted backups, secret management and a tested recovery process.

See `HANDOVER.md` for architecture, product decisions and recommended next steps.

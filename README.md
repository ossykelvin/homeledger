# HomeLedger

HomeLedger is a private home income and expense tracker built with PHP and MySQL. It supports one-time transactions, recurring income and bills, monthly summaries, spending breakdowns, light and dark themes, and desktop installation as a Progressive Web App (PWA).

## Included in the MVP

- Dashboard with monthly income, expenses, net balance and savings rate
- Six-month cash-flow chart and top spending categories
- Create, edit, search, filter and delete one-time transactions
- Daily, weekly, monthly and yearly recurring entries
- Pause, resume, edit and delete recurring schedules
- Automatic creation of transactions when recurring items become due
- GBP defaults, with currency and timezone controlled through environment variables
- Responsive light and dark modes based on the supplied Kokoszone theme
- Installable desktop experience for Chrome and Microsoft Edge
- Prepared statements, output escaping, CSRF protection and server-side validation

## Fastest setup with Docker

Requirements: Docker Desktop with Docker Compose.

1. Open a terminal in this project folder.
2. Change the example passwords in `docker-compose.yml` if the app will be used outside your own computer.
3. Start the application:

   ```bash
   docker compose up --build
   ```

4. Open `http://localhost:8080`.

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

6. Point Apache at the `public` folder for the safest configuration. For a quick local check, open `http://localhost/homeledger/public/`.

## Standard Apache or cPanel setup

1. Create a MySQL database and database user.
2. Give the user full privileges on that database.
3. Import `database/schema.sql`. If your provider does not allow `CREATE DATABASE` or `USE`, remove the first six lines and import the remaining tables into the database you created.
4. Upload the project outside `public_html` where possible.
5. Set the site document root to the project's `public` directory.
6. Configure the variables shown in `.env.example` through the hosting control panel or Apache environment configuration.
7. Use HTTPS before installing the PWA or making the site available beyond localhost.

The `.env.example` file documents the expected values. The application reads real operating-system environment variables and does not load `.env` files by itself.

## Install it as a desktop application

After HomeLedger is running over localhost or HTTPS:

1. Open it in Chrome or Microsoft Edge.
2. Select the install icon in the address bar, or use the browser menu and choose **Install HomeLedger** or **Apps > Install this site as an app**.
3. Launch HomeLedger from the Start menu or taskbar like a desktop application.

The interface assets are cached, but financial pages still require the PHP server and MySQL database to be running. This prevents stale financial data from being shown while offline.

## How recurring entries work

HomeLedger checks for due recurring entries whenever a page loads. Each missed due date is created once, protected by a database unique key. Month-end schedules are clamped correctly, so an entry starting on 31 January becomes due on 28 or 29 February.

For a server that may not receive regular visits, schedule this command daily:

```bash
php scripts/process_recurring.php
```

An optional date can be passed for testing:

```bash
php scripts/process_recurring.php 2026-12-31
```

## Run the checks

```bash
php tests/recurrence_test.php
find app public scripts tests templates -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Privacy and production note

This MVP is a single-household tracker for a private computer or private server. It does not include user accounts. Do not expose it on a LAN or the public internet in this form.

Authentication and household-level data separation are on the roadmap in `HANDOVER.md`. They are required before any public, LAN, or internet deployment. They are not implemented in this MVP. Before a public launch, also add rate limiting, encrypted backups, secure secret management and a tested recovery process.

See `HANDOVER.md` for architecture, product decisions and recommended next steps.

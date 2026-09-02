# HomeLedger MVP handover

## Product summary

HomeLedger is a single-household financial tracker. The primary flow is to record income and expenses, review a monthly overview, and automate regular entries such as salary, rent, mortgage, subscriptions and utility bills.

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
| `public/index.php` | Front controller and route selection |
| `app/bootstrap.php` | Runtime configuration and session setup |
| `app/Database.php` | PDO connection |
| `app/actions.php` | Validated POST actions and CRUD operations |
| `app/Recurrence.php` | Date-safe recurrence calculation |
| `app/helpers.php` | Rendering, CSRF, data access and recurring materialisation |
| `templates/pages/` | Dashboard, transaction and recurring-entry views |
| `templates/partials/` | Reusable entry dialogs |
| `public/assets/` | Theme, interactions and icons |
| `database/schema.sql` | Tables, indexes, constraints and starter categories |
| `scripts/process_recurring.php` | Optional scheduled recurrence processor |
| `tests/recurrence_test.php` | Boundary tests for repeat dates |

## Data model

`categories` separates income and expense classifications. `transactions` stores both manual and generated entries. `recurring_entries` stores the schedule and the next unprocessed due date.

`transactions.recurring_entry_id + transaction_date` is unique. This makes recurring generation idempotent and prevents a page refresh or repeated scheduled task from inserting the same occurrence twice.

Deleting a recurring schedule keeps the historical transactions by setting their recurrence reference to null. Editing a schedule affects future occurrences only.

## Theme direction

The interface adapts the supplied Kokoszone design system rather than reproducing its marketing-page layout. Dark mode uses near-black ink, deep raised panels, lime as the primary action colour, violet for comparison data, coral for expenses and cyan for secondary categorisation. Light mode reverses the paper and ink relationship while preserving the accent hierarchy.

The first viewport is a working financial surface, with no marketing hero. Theme preference is stored on the device.

## Product decisions

HomeLedger stays a single-household private tracker. It is for one household on a private computer or private server. Multi-household use and shared family access are out of scope for now.

Keep the current stack: PHP, MySQL, PDO, server-rendered HTML, and the Kokoszone theme. Do not swap frameworks. Do not add Composer or Node unless a later task truly requires them.

Authentication and household-level data separation are on the roadmap only. Do not implement login, users, or household accounts until that work is explicitly started. They are required before any public, LAN, or internet deployment.

## Security decisions

- All state-changing requests require a CSRF token.
- Database values use prepared statements.
- Output is escaped at the rendering boundary.
- Server validation checks type, category ownership, amount, dates and text length.
- Session cookies are HTTP-only, SameSite Lax and secure on HTTPS.
- The included Apache rules add basic browser security headers.

The current product has no login. Anyone who can reach the site can read and change household money data. Do not expose it on a LAN or the public internet until authentication and household-level data separation are in place.

## Recommended next phases

1. Add authentication and household-level data separation. Keep the product single-household. This is required before any public, LAN, or internet deployment. Do not add multi-household or family sharing in that phase.
2. Add budgets by category with threshold alerts.
3. Add CSV import/export and encrypted backup/restore.
4. Add account balances and transfer transactions.
5. Add monthly reports, comparisons and printable summaries.
6. Add notifications for upcoming or unusually high bills.
7. Add automated integration and browser tests around CRUD and recurring generation.

## Acceptance checklist

- A one-time income or expense can be added, edited and deleted.
- Transactions can be filtered by month, type and search text.
- A recurring entry can be added, edited, paused, resumed and deleted.
- Due recurring items produce transactions only once.
- January 31 and leap-year recurrence boundaries are calculated correctly.
- Dashboard totals reflect the selected month.
- Light and dark themes remain readable on desktop and mobile widths.
- The app can be installed from a supported browser when served on localhost or HTTPS.

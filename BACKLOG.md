# HomeLedger product backlog

Shipped MVP: household ledger, auth (email confirm, Google, lockout), owner-only invites and categories, recurring materialisation, Statement PDF/Excel, account delete with owner transfer, PWA, cPanel deploy.

Shipped in later batches: monthly category budgets with on-screen overspend warnings and unusual-spend email (A); owner member removal and household activity log (B, except B3); CSV export/import and encrypted household backup/restore (C).

Items below are **not started** unless marked done. Treat each **batch** as one unit of work (one PR unless a batch is too large, then split inside the batch). Do not start a new batch until the current one is merged.

Out of scope unless we reopen it: full RBAC, remember-me, putting phpMyAdmin behind app login, Composer/Node, framework swap.

---

## Batch A — Spend control

Budgets, on-screen warnings, and spend emails share category totals. **Done.**

| ID | Item |
| --- | --- |
| A1 | Done. Budgets by category (monthly, household-scoped, owner can edit) |
| A2 | Done. Overspend / threshold warning on Dashboard and Statement |
| A3 | Done. Email when a category or the month is unusually high vs last month |

---

## Batch B — Household membership

Same Household page, same owner rules, same confirm-dialog pattern as account delete. **A1/A2/B1/B2 done. B3 skipped.**

| ID | Item |
| --- | --- |
| B1 | Done. Owner can remove a member (household ID confirm; cannot remove last owner without transfer) |
| B2 | Done. Activity log on Household (invited, joined, left, recategorised, deleted) |
| B3 | Skipped. Optional two-person confirm for large deletes (only if we still want it after B1) |

---

## Batch C — Take data in and out

Import, export, and backup are one data-portability surface. **Done.**

| ID | Item |
| --- | --- |
| C1 | Done. CSV export of transactions (and optionally recurring) |
| C2 | Done. CSV import with category matching and dry-run errors |
| C3 | Done. Encrypted household backup / restore |

---

## Batch D — Where the money sits

Accounts change how transactions are stored. Do not mix with Batch A.

| ID | Item |
| --- | --- |
| D1 | Accounts (current, savings, cash) and balances |
| D2 | Transfer transactions between accounts |
| D3 | Split / shared amount on a transaction (who paid vs household share) |

---

## Batch E — Reports

Statement already exists. This batch is comparison and print, not a second ledger.

| ID | Item |
| --- | --- |
| E1 | This month vs last month and year-to-date |
| E2 | Printable / annual summary |
| E3 | Optional receipt photo on a transaction (files stored outside the web root) |

---

## Batch F — Sign-in recovery

Uses the existing Brevo mailer and confirm-email patterns.

| ID | Item |
| --- | --- |
| F1 | Forgot password (email link; Google accounts skip or say use Google) |

---

## Batch G — Live ops and hygiene

No product UI. Do when convenient; can run beside any batch.

| ID | Item |
| --- | --- |
| G1 | Rotate Brevo SMTP key and Google client secret (pasted in chat) |
| G2 | Confirm cPanel daily cron for `scripts/process_recurring.php` |
| G3 | Change Docker Compose example DB passwords before any LAN use |
| G4 | Optional Brevo HTTP mailer if SMTP is not enough |

---

## Batch H — Tests

After a product batch, extend coverage for that batch. Also:

| ID | Item |
| --- | --- |
| H1 | Broader automated tests: CRUD, recurring generation, household isolation, Google OAuth (no live secrets) |

---

## Suggested order

1. **A** — daily value while recording salary and bills  
2. **B** — household is already live with two people  
3. **C** — so the ledger is not a trap  
4. **F** — password recovery before more users  
5. **D** then **E** — bigger model and reporting  
6. **G** anytime; **H** with each batch  

Batch **G** can start in parallel with **A**.

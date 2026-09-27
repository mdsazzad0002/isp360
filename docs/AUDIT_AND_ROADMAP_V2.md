# ISP360 — Audit and Roadmap v2

Audit date: 2026-09-30 · Branch audited: `claude/global-isp-roadmap-arxkcu` · Supersedes the *ordering* of
[GLOBAL_ISP_ROADMAP.md](GLOBAL_ISP_ROADMAP.md) (that file stays the detailed checklist; this one says what to do next and why).

## 1. Where the product stands

| Area | State |
|---|---|
| Global-readiness blockers (currency, timezone, tax, gateways, RADIUS, compliance, billing rules, country packs, i18n) | Done in core. Leftovers listed in §4. |
| Phase A (global foundation) | Done, except translating the remaining pages. |
| Phase B (network and compliance) | Done: RADIUS + CoA, session/NAT log, KYC / consent / erasure, IPAM + CGNAT, notifications (e-mail, WhatsApp, Twilio / Vonage / Infobip, reminders). |
| Phase C (higher-level management) | Item 1 done (company dashboard, regions, regional managers). Items 2–4 open. |
| Phases D, E | Not started. |
| Roadmap checklist | 75 items done, 136 open. |
| Automated tests | 118 feature tests, 1,774 assertions, all passing — but they cover the new ISP modules only (see §2, H3). |

The new ISP modules (`app/Http/Controllers/Isp/*`, `app/Services/Isp/*`) are in good shape: permission-checked,
branch-scoped, audited and tested. **The risk sits in the older generic modules the product was built on**
(users, roles, branches, customers, accounts, banks, payments), which the global work never touched.

## 2. Audit findings

Method: read every controller write method, checked for a permission check and branch scoping, then proved the
worst cases with a throw-away feature test inside a rolled-back transaction (not committed). Dependency and
config checks as noted.

### Critical — fix before any real customer uses a multi-user install

**C1. Most older write endpoints have no permission check — any logged-in staff user can become Superadmin.**
- 14 controllers, ~45 write methods (`store` / `update` / `destroy` in User, Role, Branch, Company, Customer,
  AccountHead, Bank, BankTransaction, Payment, Receive, Transaction, Reseller, SmsGateway; `updatecompanyProfile`,
  `updateBranchManage`, `saveRoleAccess`) only check the *page* permission, never the *action*. There is no
  route-level permission middleware.
- **Proven:** a staff user whose role allows only `connection` called `POST /update-user` on themselves with
  `role=Superadmin` → HTTP 200, role changed. The same user created a branch (`POST /branch` → 200).
- Fix: an `access:<name>` route middleware applied per route group, plus `checkAccess` in each write method;
  forbid changing `role`, `branch_id`, `region_id`, `switchable_branches` unless the actor has `user` + head-office
  rights and never above their own role. Regression test per endpoint.

**C2. Cross-branch record access and mass assignment.**
- The same controllers load records with `Model::find($request->id)` without `where('branch_id', …)`, and then
  copy every request field onto the model (`foreach ($request->except('id') …)`).
- **Proven:** the same low-permission staff user in branch 1 edited a customer of branch 32 → HTTP 200, the
  customer was renamed **and moved into branch 1** (`CustomerController::update` forces the current branch).
- Deletes (`destroy`) in 12 controllers have neither permission nor branch checks.
- Fix: a `BelongsToBranch` scope / `findForBranch()` helper used everywhere; replace the generic loops with
  explicit `$request->validated()` field lists (`$fillable` on the models).

**C3. File uploads can place executable files in `public/`.**
- `imageUpload()` (`app/Helpers/Functions.php`) keeps the client's file extension and writes into
  `public/uploads/...`. User, customer, reseller, logo and favicon uploads have **no file validation at all**.
  Ticket attachments (reachable from the customer portal) validate the *content* type (`mimes:`), but a file whose
  content is a valid JPEG and whose name ends in `.php` passes and is stored as `.php`.
- On a typical Apache / nginx + PHP-FPM setup, a `.php` file under `public/` executes → remote code execution.
- Found by reading the code; not exploited in a test.
- Fix: take the extension from `guessExtension()` against an allow-list, random file names, private disk with a
  controller that streams the file (KYC documents already work this way), `image|mimes|max` rules on every upload,
  and a server rule denying script execution under `/uploads`.

### High

| # | Finding | Evidence | Fix |
|---|---|---|---|
| H1 | No CI: tests only run when someone runs them by hand. | No `.github/workflows`. | GitHub Actions: MariaDB service, `migrate:fresh`, `php artisan test`, `vite build`, `npm audit --omit=dev --audit-level=high`. |
| H2 | Front-end dependencies have known vulnerabilities. | `npm audit --omit=dev`: 33 (31 moderate, 2 high — `@tiptap/core <= 3.30.4`). `composer audit` could not reach Packagist from the audit box — run it in CI. | Upgrade tiptap; add both audits to CI. |
| H3 | Older modules (users, roles, accounts, banks, payments/receives, POS reports, balance sheet) have zero tests and no audit log. | 0 of 25 test files touch them. | Tests with the C1/C2 fixes; route them through `AuditLogger`. |
| H4 | Schema and code drift apart. | `company_profiles.multi_branch_status` was read by the branch switcher since the first commit but never created (fixed 2026-09-30) — branch switching could never have worked. | CI job that runs `migrate:fresh` on an empty DB and smoke-tests every page route (catches missing columns). |
| H5 | UI is only partly translated. | 6 of 94 Vue pages use i18n; `bn`/`hi`/`ar` each miss 5 keys that exist in `en`. | Translate page by page, starting with the customer portal and billing screens; a key-parity check in CI. |

### Medium / low

- `GET /branchset/{id}` routes to a `protected` method (dead route; remove it).
- `.env.example` ships `APP_DEBUG=true` — document the production values (`APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`).
- No backup / restore or full data export (roadmap 3.1) — an operational risk for any paying customer.
- Legacy balance sheet (`ReportController::getBalanceSheet`) is a reduced sheet with retained earnings as the plug figure; it cannot be trusted until the general ledger (4.10) exists.
- Branch deletion checks every `branch_id` table dynamically (good); region deletion clears references (good).

## 3. New roadmap (priority order)

Estimates assume one developer. Every step keeps the rules in GLOBAL_ISP_ROADMAP §6 (tests with every change,
money only through the ledger, per-branch scoping, audit log for money and permissions).

### Phase 0 — Security hardening (P0, ~1–2 weeks) — **do this next**
1. Route permission middleware + action checks on every older write endpoint (C1), with a test that walks all `POST` routes as a no-permission user and expects 403.
2. Branch scoping helper and explicit field lists replacing the generic assignment loops (C2).
3. Safe uploads: allow-listed extensions, random names, private storage, validation on every upload (C3).
4. Privilege rules for user management: cannot grant a role above your own; only head office changes branches / regions / switch lists.
5. Audit log for users, roles, branches, company profile and accounting entries.
6. Upgrade `@tiptap/core`; run `composer audit`.

### Phase 1 — Quality foundation (P0, ~1–2 weeks)
1. CI pipeline (H1) with fresh-migration smoke test of every page route (H4).
2. Tests for the older modules touched in Phase 0 (H3).
3. Production config guide: `.env` values, queue workers / Horizon, scheduler, RADIUS (see RADIUS_SETUP.md), HTTPS, deny PHP in uploads.
4. Backups: scheduled DB + uploads backup, restore command, full data export (3.1).

### Phase 2 — Finish Phase C, higher-level management (P1, ~5–6 weeks)
1. Multi-level reseller tree, per-level commission, credit limit (3.3).
2. Staff role templates and scopes (3.4) — builds on the Phase 0 permission middleware.
3. Maker-checker approval workflows for refunds, write-offs, price changes, deletions (3.5).
4. Company vs branch settings split; inter-branch transfers as ledger entries (3.1, 3.2).

### Phase 3 — Operations (P1, ~8–10 weeks) — unchanged from Phase D
1. Technicians / work orders (4.4). 2. Inventory and assets (4.5). 3. Collector flow, dunning, aging (4.3).
4. Support SLA and escalation (4.7). 5. Customer app / portal upgrades, OTP login, auto-renew (4.9).
6. Leads, coverage map, corporate accounts (4.1).

### Phase 4 — Scale and ecosystem (P1/P2, ongoing) — unchanged from Phase E
1. OLT drivers, TR-069, monitoring, outage management (4.6).
2. Full general ledger, deferred revenue, bank reconciliation, accounting exports (4.10) — then a real consolidated balance sheet.
3. Public API, webhooks, API docs (4.14).
4. Regional payment gateways and e-invoicing per target market (2.4, 4.14).
5. KPI and regulatory reports (4.11). 6. Load testing, read replicas (4.13).

### Continuous (alongside every phase)
- Translate remaining pages (H5), portal first.
- Notification leftovers: Telegram, web push, MessageBird, India DLT template IDs, delivery receipts and cost, more events (expiry day, tickets, outage, OTP).
- Gateway leftovers: more regional gateways as target countries are chosen.

## 4. Leftovers inside sections marked "done"

| Section | Still open |
|---|---|
| 2.1 Currency | Old POS modules (sales, purchases, non-ISP reports) still round to 2 decimals. |
| 2.4 Gateways | Regional drivers per market; refund through the gateway API; saved card / auto-debit; chargeback handling. |
| 2.6 Compliance | Data residency (host in the country its law requires) — a deployment decision per market. |
| 2.9 i18n | Remaining pages: customer portal Connections / Profile / Tickets, reseller portal, admin pages (88 of 94 pages). |
| 3.1 / 3.2 | Backups and export, settings split, inter-branch transfers, consolidated balance sheet. |
| 4.8 Notifications | DLT, Telegram / web push, MessageBird, delivery receipts, more events. |
| 4.12 Security | Phase 0 above, plus password policy + session list, signed webhooks for every gateway, CSP / HSTS headers, dependency scanning in CI, external penetration test. |

## 5. Global launch readiness checklist

A market can go live when all of these are true:

- [ ] Phase 0 and Phase 1 finished; CI green on every push.
- [ ] A country pack exists for the market (currency, tax, phone format, gateways, retention).
- [ ] At least one local payment gateway working end to end with webhooks.
- [ ] Customer portal and SMS / e-mail templates translated into the market's language.
- [ ] Session / NAT log retention set to the local legal minimum and the export tested.
- [ ] Backups running and a restore rehearsed.
- [ ] Production config reviewed (debug off, HTTPS, secure cookies, uploads not executable).

# ISP360 — Audit and Roadmap v2

Audit date: 2026-09-30 · Branch audited: `claude/global-isp-roadmap-arxkcu` · Supersedes the *ordering* of
[GLOBAL_ISP_ROADMAP.md](GLOBAL_ISP_ROADMAP.md) (that file is the detailed list of open tasks; this one says what to do next and why).

## 1. Where the product stands

| Area | State |
|---|---|
| Global blockers, Phases A and B | Finished. Small leftovers are in GLOBAL_ISP_ROADMAP §2. |
| Phase C (higher-level management) | Reseller tree, staff roles, approvals, settings split open. |
| Phases D, E | Not started. |
| Security audit (§2) | C1–C3, H1 (CI), H2 (dependencies: npm and composer audits clean) and H4 (fresh-database run + page smoke test) fixed 2026-09-28. H3, H5 and the medium items still open. |
| Automated tests | 118 feature tests, 1,774 assertions, all passing — but they cover the new ISP modules only (see §2, H3). |

The new ISP modules (`app/Http/Controllers/Isp/*`, `app/Services/Isp/*`) are in good shape: permission-checked,
branch-scoped, audited and tested. **The risk sits in the older generic modules the product was built on**
(users, roles, branches, customers, accounts, banks, payments), which the global work never touched.

## 2. Audit findings

Method: read every controller write method, checked for a permission check and branch scoping, then proved the
worst cases with a throw-away feature test inside a rolled-back transaction (not committed). Dependency and
config checks as noted.

### Critical — fix before any real customer uses a multi-user install

All three critical findings (C1 permissions, C2 branch isolation, C3 uploads) were fixed on 2026-09-28; see git history.

### High

| # | Finding | Evidence | Fix |
|---|---|---|---|
| H3 | Older modules (users, roles, accounts, banks, payments/receives, POS reports, balance sheet) have zero tests and no audit log. | 0 of 25 test files touch them. | Tests with the C1/C2 fixes; route them through `AuditLogger`. |
| H5 | UI is only partly translated. | 6 of 94 Vue pages use i18n; `bn`/`hi`/`ar` each miss 5 keys that exist in `en`. | Translate page by page, starting with the customer portal and billing screens; a key-parity check in CI. |

### Medium / low

- Legacy balance sheet (`ReportController::getBalanceSheet`) is a reduced sheet with retained earnings as the plug figure; it cannot be trusted until the general ledger (4.10) exists.

## 3. New roadmap (priority order)

Estimates assume one developer. Every step keeps the rules in GLOBAL_ISP_ROADMAP §6 (tests with every change,
money only through the ledger, per-branch scoping, audit log for money and permissions).

Phase 0 (security hardening) finished 2026-09-28.

### Phase 1 — Quality foundation (P0, ~1–2 weeks) — **do this next**
CI (H1, H4) is in place. Still open:
1. Tests for the older modules beyond permissions and branch isolation (H3): POS reports, balance sheet, cash / bank ledgers.

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

## 4. Global launch readiness checklist

A market can go live when all of these are true:

- [ ] Phase 0 and Phase 1 finished; CI green on every push.
- [ ] A country pack exists for the market (currency, tax, phone format, gateways, retention).
- [ ] At least one local payment gateway working end to end with webhooks.
- [ ] Customer portal and SMS / e-mail templates translated into the market's language.
- [ ] Session / NAT log retention set to the local legal minimum and the export tested.
- [ ] Backups running and a restore rehearsed.
- [ ] Production config reviewed with `docs/PRODUCTION.md` (debug off, HTTPS, secure cookies, uploads not executable).

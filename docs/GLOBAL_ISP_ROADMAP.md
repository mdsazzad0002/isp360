# isp306: Global ISP Platform — Open Work

> Priority order and the security audit are in [AUDIT_AND_ROADMAP_V2.md](AUDIT_AND_ROADMAP_V2.md) (Phase 0 security hardening first).
> This file lists **only what is still open**. Finished work is in git history.

**Updated:** 2026-09-28 · **Branch:** `multibank`
**Goal:** run isp306 as an ISP business platform in any country, for any size of ISP, from one codebase.

**Deployment model:** one installation = **one company, many branches, one country**. No multi-tenant SaaS: a second ISP gets its own installation. Country, currency, timezone, tax and country pack are set once for the company and apply to every branch.

Priority tags: **P0** = must have before the first customer outside Bangladesh. **P1** = needed to compete in most markets. **P2** = market-specific or scale features.
Effort tags: **S** ≈ 1–3 days, **M** ≈ 1–2 weeks, **L** ≈ 3+ weeks.

**Already built (not repeated below):** company currency + `Money` (0–3 decimals), company timezone + DST, sales tax + tax report, country packs (BD IN PK NP NG KE PH ID BR US GB), billing rules (postpaid, proration, grace, notice, late fee, deposit), Stripe + PayPal + webhook idempotency, RADIUS + CoA, session/NAT log, KYC / consent / erasure, IPAM + CGNAT, e-mail / WhatsApp / Twilio / Vonage / Infobip + renewal reminder, 2FA + lockout + encrypted secrets, queues + Horizon, RTL / phones / addresses / number formats, company dashboard + regions + regional managers.

---

## 2. Leftovers of the global blockers (P0 areas)

### 2.1–2.2 Currency and timezone
- [ ] Old POS modules (sales, purchases, reports outside ISP) still round to 2 decimals.
- [ ] Old POS pages (Account/*, Report/*) build "today" from the browser clock; move them to `today()` from `lib/isp.js`.
- [ ] Countries with several zones (US, CA, AU, BR, MX): a per-branch zone would need UTC storage — only if a customer asks.
- [ ] Fall-back hour: a wall-clock time inside the repeated DST hour is ambiguous (at most one hour a year, DST countries only).

### 2.3 Tax
- [ ] Customer tax ID (corporate customers' VAT/GST number) on the customer and invoice.
- [ ] Separate tax per fee line (installation/activation fees use the default rates today).
- [ ] Compound taxes (tax on tax) — not needed by any listed country yet.
- [ ] P2: withholding tax on corporate customers' payments.
- [ ] P2: e-invoicing connectors per country (see 4.14).

### 2.4 Payment gateways
- [ ] Regional drivers, built per market demand:
  - India: Razorpay, PayU, UPI (Cashfree/PhonePe)
  - Pakistan: JazzCash, Easypaisa
  - Nepal: eSewa, Khalti
  - Africa: M-Pesa (Daraja), Paystack, Flutterwave, MTN MoMo
  - SE Asia: Xendit, Midtrans, GCash/PayMongo, 2C2P
  - LatAm: Mercado Pago, PIX (Brazil), OXXO
  - Middle East: Tap, PayTabs, HyperPay
- [ ] Refund through the gateway API (today refunds are only recorded).
- [ ] P1: saved card / auto-debit (Stripe customer + mandate) for auto-renewal.
- [ ] P1: chargeback/dispute handling — reverse the payment through `CollectionService`, keep the audit trail.

### 2.5 Network: multi-vendor routers
RADIUS is the common path for every vendor: billing writes FreeRADIUS, the NAS asks it. Per-vendor
differences are data in `config/nas_vendors.php` (`NasVendor`); how a router is managed is
`config('isp.router_drivers')` (`RouterDriver`). A new vendor is a config entry, not code.

| Vendor (`nas_type`) | Speed attributes | Live speed change | Suspension | Checked on a device |
|---|---|---|---|---|
| MikroTik RouterOS (`mikrotik`) | Mikrotik-Rate-Limit | CoA | Disconnect | yes (FreeRADIUS 3) |
| Huawei (`huawei`) | Huawei-Input/Output-Average-Rate | CoA, disconnect if refused | Disconnect | no |
| Cisco IOS-XE/XR (`cisco`) | Cisco-AVPair QoS policy names | disconnect | Disconnect | no |
| Juniper MX (`juniper`) | ERX-Ingress/Egress-Policy-Name | disconnect | Disconnect | no |
| VyOS / Linux accel-ppp / FRR (`accel-ppp`) | Filter-Id (own group) | CoA, disconnect if refused | Disconnect | no |
| pfSense / OPNsense (`pfsense`) | WISPr-Bandwidth-Max-Up/Down | reconnect | next re-authentication | no |
| Other (`other`) | none (set on the NAS) | — | Disconnect | — |

- [ ] Lab-check each vendor against a real device or its virtual image (vMX, CSR1000v/XRv, VyOS, pfSense, Huawei NE40E sim) and fix the attribute formats found.
- [ ] Cisco and Juniper live speed change (service activation CoA) instead of a disconnect.
- [ ] Setup guide per vendor in `docs/RADIUS_SETUP.md` (RADIUS client, accounting, CoA / dynamic-author, QoS policy names).
- [ ] Direct API drivers where RADIUS is not enough (site / IP blocks, live traffic, terminal, config backup): Juniper NETCONF, Cisco RESTCONF, VyOS HTTP API, pfSense / OPNsense REST API, Huawei NETCONF. Each is a new `router_drivers` entry.
- [ ] Driver capabilities (`blocks`, `traffic`, `terminal`, `sessions`) on `NetworkDriver`, so the UI and terminal stop checking `isRadius()` / MikroTik directly.
- [ ] Static IP / DHCP (IPoE) users over RADIUS (today only PPPoE and Hotspot).
- [ ] Data caps / FUP on top of `radacct` (see 4.2).

### 2.6 Compliance
- [ ] Data residency: host the installation in the country (or region) its law requires — a deployment decision per market.

### 2.7 Billing rules
- [ ] Postpaid billed in arrears (after the period) — today a postpaid bill is issued at the start of its period and due later.
- [ ] Grace / notice / late-fee defaults in the country packs, once confirmed per country.

### 2.8 Country packs
- [ ] Pack date format in printed dates and invoices; invoice template/numbering per country.
- [ ] Full installation wizard (4.16) on top of `CountryPack::apply()`.
- [ ] Check each pack's suggested tax rates with a local accountant before the first customer in that country.

### 2.9 UI translation
- [ ] Remaining pages: customer portal Connections / Profile / Tickets, reseller portal, admin pages (88 of 94 pages), and PHP validation / flash messages (`resources/lang` has only `en`). `bn` / `hi` / `ar` each miss 5 keys that exist in `en`. Translations by a native speaker before shipping.

---

## 3. Higher-level management

### 3.1 Company level — P1, M
- [ ] Company-wide settings (country, currency, timezone, tax, country pack) vs branch settings (prefixes, SMS, billing rules) kept clearly apart.
- [ ] Installation backups and restore; full data export.
- [ ] If one owner runs ISPs in two countries: two installations and a group report on top, not one mixed database.

### 3.2 Region level — P1, M
- [ ] Inter-branch transfers (bandwidth sold between branches, shared upstream) as inter-branch ledger entries.
- [ ] Consolidated balance sheet — waits for the general ledger (4.10).

### 3.3 Multi-level reseller network — P1, L
Today: company → reseller → customer (one level).
- [ ] Tree: Master distributor → Distributor → Reseller → Sub-reseller/Franchise (`resellers.parent_id`, configurable max depth).
- [ ] Each level customises the package from its parent's price (same base_price review rule), margin per level computed from the chain.
- [ ] Wallet per level; a parent sees and settles its children; withdrawals approved by the parent or the company.
- [ ] Commission models: margin (current), percent of collection, fixed per new connection, per-level override.
- [ ] Reseller credit limit and auto-block when over the limit.
- [ ] Reseller branding in the customer portal (logo, support phone).
- [ ] Franchise model: franchise owns its own routers and pays a revenue share or a per-subscriber fee.

### 3.4 Staff hierarchy and roles — P1, M
- [ ] Role templates: Owner, Manager, Accountant, Billing officer, NOC engineer, Technician, Collector, Support agent, Sales/lead agent, Reseller staff.
- [ ] Scope per user: organisation / region / branch / zone / area (a collector sees only their areas).
- [ ] Reporting line (`users.manager_id`) for approvals and escalations.
- [ ] Per-role 2FA rules once role templates exist; optional login IP allow-list.

### 3.5 Approval workflows (maker-checker) — P1, M
- [ ] Generic `approvals` table: subject type/id, requested_by, approver role/level, status, reason.
- [ ] Rules configurable by the company: discount above X %, refund above X, write-off, "start on due" credit, package price change, manual ledger adjustment, reseller withdrawal, connection termination.
- [ ] Multi-step approval (manager → finance) and limits per role.
- [ ] Notifications and an "Awaiting my approval" inbox.

---

## 4. Feature gaps by module

### 4.1 Customer lifecycle and CRM — P1
- [ ] Leads / pre-sales: lead source, coverage check by address/map, quote, convert to customer.
- [ ] Coverage map: boxes already have lat/long; Leaflet + OpenStreetMap, radius around a box, free ports.
- [ ] Customer types: home, SME, corporate (contracts, SLA, PO numbers, many connections under one account, consolidated invoice).
- [ ] Contract term / lock-in period and early-termination fee.
- [ ] Move/transfer: change address, transfer ownership to another customer, keep history.
- [ ] Churn reason on termination; win-back campaigns.
- [ ] Installation photos on the customer (KYC and contract already exist).

### 4.2 Billing and products — P1
- [ ] Discounts and coupons (percent/fixed, first N cycles, promo codes, validity).
- [ ] Bundles and add-ons (static IP, IPTV, VoIP, router rental, public IP block) as recurring add-on lines.
- [ ] Equipment sale / rental / instalments (ONU, router) linked to inventory (4.5).
- [ ] Data caps and FUP: speed drops after X GB (RADIUS accounting).
- [ ] Time-of-day packages (night unlimited), burst settings.
- [ ] Hotspot vouchers: batch generate, print, sell through resellers, one-time use.
- [ ] Bad-debt write-off with approval, posted to the ledger.
- [ ] Customer statement (period, opening/closing) as PDF, e-mail and portal download.
- [ ] Branded invoice PDF in the country's format (server-side PDF, e.g. `barryvdh/laravel-dompdf` or Browsershot).
- [ ] Bulk actions: package change, suspend/reactivate, SMS, price increase with notice.

### 4.3 Collections and dunning — P1
- [ ] Collector app/flow: daily route list by area, collect cash, print/SMS receipt, GPS stamp, end-of-day deposit (same "cash in hand" model as resellers).
- [ ] Dunning schedule after expiry / after suspension, channel per step (the before-expiry reminder exists).
- [ ] Promise-to-pay with a date (auto reminder, optional temporary reactivation).
- [ ] Auto-renew from saved card or wallet balance.
- [ ] Aging report (0–30/31–60/61–90/90+) per branch/zone/collector/reseller.

### 4.4 Field operations (technicians / work orders) — P1, L
- [ ] Work order types: new installation, shifting, repair, disconnection, equipment pickup.
- [ ] Created from a new connection, a ticket, or manually; assigned to a technician/team; schedule, SLA, status flow.
- [ ] Mobile-friendly technician view (PWA): job list, map, photos, customer signature, materials used (deducts inventory), ONU serial.
- [ ] Activation only after the installation work order is closed (optional rule).
- [ ] Technician performance report.

### 4.5 Inventory and assets — P1, M
- [ ] Items: ONU, routers, switches, SFP, cable (by metre), splitters, boxes.
- [ ] Warehouses per branch; stock in, transfer, issue to technician, install at customer, return, faulty/RMA.
- [ ] Serial / MAC tracking; which device is at which customer.
- [ ] Purchase orders and supplier bills posted to the ledger.
- [ ] P2: asset register with depreciation.

### 4.6 Network: OLT / ONU / monitoring — P1/P2, L
- [ ] OLT drivers (SNMP/Telnet/SSH/API): Huawei, ZTE, VSOL, BDCOM, Nokia — ONU list, optical power, status, auto-authorise, map ONU to connection.
- [ ] TR-069/ACS (GenieACS): remote Wi-Fi name/password change, reboot, firmware.
- [ ] Monitoring: router/OLT/uplink up/down (ping/SNMP), traffic graphs, NOC alerts.
- [ ] Outage management: mark an outage for a zone/box/OLT port → notify affected customers, link tickets, optional compensation days.
- [ ] Topology view: POP → OLT → PON port → splitter → box → customer.
- [ ] Router config backup and multi-router failover per branch.

### 4.7 Support and SLA — P1, M
- [ ] Ticket categories, priorities, SLA timers (first response, resolution), escalation (3.4).
- [ ] Assignment rules (by zone/category), agent workload.
- [ ] Canned replies, ticket from SMS/e-mail/WhatsApp.
- [ ] Customer satisfaction (CSAT) after closing.
- [ ] Knowledge base / FAQ in the customer portal (multi-language).

### 4.8 Notifications — P1
- [ ] Channels: Telegram, web push.
- [ ] SMS providers: MessageBird and more local providers.
- [ ] Events: expiry day, ticket updates, technician visit time, outage, OTP.
- [ ] Separate template text per channel.
- [ ] Sender ID / DLT registration (India needs DLT template IDs).
- [ ] Delivery receipts (DLR webhooks) and cost per message.

### 4.9 Customer self-service — P1, M
- [ ] Mobile app (or polished PWA): renew, upgrade/downgrade with the day-wise quote, usage graph, speed test, Wi-Fi password change (TR-069).
- [ ] OTP login by phone/e-mail.
- [ ] Auto-renew toggle, saved payment method.
- [ ] Invoice/receipt PDF download, statement.
- [ ] Referral link sharing (referral logic exists).

### 4.10 Accounting and finance — P1, L
- [ ] Full double-entry general ledger with a chart of accounts driven by `ledger_entries`; unify the mirrored POS books (`receives`/`payments`) into one GL.
- [ ] Accounts: revenue per product, tax payable, customer advances, reseller payable, deposits, bad debt, FX gain/loss.
- [ ] P2: deferred revenue for prepaid time (IFRS 15 / ASC 606).
- [ ] Period close / lock date.
- [ ] Expenses and vendor bills (post upstream bandwidth purchases).
- [ ] Bank reconciliation (import CSV/MT940, match payments).
- [ ] Export to QuickBooks, Xero, Tally, Zoho Books.
- [ ] P2: payroll.

### 4.11 Reports and analytics — P1, M
- [ ] KPIs: MRR, net adds, collection efficiency, DSO, active vs suspended, reseller performance (ARPU/churn exist in the company dashboard).
- [ ] Cohorts and churn reasons, package mix, bandwidth utilisation.
- [ ] Regulatory subscriber reports per country pack (BTRC, TRAI, FCC BDC, Ofcom) — template + export.
- [ ] Aging report (4.3).
- [ ] Scheduled reports by e-mail; XLSX export (PhpSpreadsheet is installed).

### 4.12 Security — P0, M
Phase 0 of the audit comes first (permission middleware, branch scoping, safe uploads). Then:
- [ ] Password policy (length/complexity), session list with remote logout.
- [ ] Audit log for every admin action (settings, roles, users, branches, package changes, logins).
- [ ] Signed webhook verification for every gateway.
- [ ] Security headers (CSP, HSTS); `composer audit` / `npm audit` in CI.
- [ ] External penetration test before the first large installation.

### 4.13 Platform, scale and operations — P0/P1, L
- [ ] Redis for cache and session (`CACHE_DRIVER`/`SESSION_DRIVER=redis`); per-branch jobs for the every-minute billing work on very large installations.
- [ ] Idempotency for every new job.
- [ ] Docker images, CI/CD, zero-downtime deploys, migrations tested on big tables.
- [ ] Error tracking (Sentry), metrics, uptime monitoring, structured logs.
- [ ] Automated offsite backups with restore tests; point-in-time recovery.
- [ ] Read replica for reports; long reports as queued exports.
- [ ] Load test: 100k connections per installation, month-end payment spikes.

### 4.14 Public API and integrations — P1, M
- [ ] REST API with tokens (Laravel Sanctum) and scopes: customers, connections, invoices, payments, tickets.
- [ ] Outbound webhooks (payment received, connection suspended, ticket created) with signing and retries.
- [ ] API documentation (OpenAPI).
- [ ] Integrations: accounting (4.10), maps, e-invoicing per country (India GST IRP, Saudi ZATCA, Mexico CFDI, Brazil NF-e/NFCom, EU Peppol, Bangladesh NBR Mushak 6.3).

### 4.15 Testing and quality — P0, M
- [ ] Tests for the older modules (users, roles, branches, customers, accounts, banks, payments, POS reports).
- [ ] Branch isolation tests: a branch user can never read or change another branch's data (every controller).
- [ ] Gateway driver contract tests with recorded sandbox responses.
- [ ] RADIUS driver tests against a FreeRADIUS container in CI.
- [ ] `isp:ledger-check` in CI and as a nightly job with an alert.

### 4.16 Documentation and onboarding — P1, S/M
- [ ] Installation wizard: country pack → company → branch → router/RADIUS → packages → import customers.
- [ ] Data import from Excel/CSV and from common competitor systems (customers, connections, opening balances).
- [ ] Admin manual, reseller manual, customer help, in the country pack languages.
- [ ] Demo installation with sample data for sales.

---

## 5. Phases still open

Phases A and B are finished; Phase C item 1 is finished.

### Phase C — Higher-level management (P1)
1. Multi-level reseller tree, per-level commission, credit limit (3.3)
2. Staff role templates and scopes (3.4)
3. Approval workflows (3.5)
4. Company vs branch settings split, inter-branch transfers, backups (3.1, 3.2)

### Phase D — Operations (P1) ≈ 8–10 weeks
1. Technicians / work orders (4.4)
2. Inventory and assets (4.5)
3. Collector flow + dunning + aging (4.3)
4. Support SLA and escalation (4.7)
5. Customer app / portal upgrades (4.9)
6. Leads, coverage map, corporate accounts (4.1)

### Phase E — Scale and ecosystem (P1/P2) ongoing
1. OLT drivers, TR-069, monitoring, outage management (4.6)
2. Full GL, deferred revenue, bank reconciliation, accounting exports (4.10)
3. Public API + webhooks + docs (4.14)
4. Regional payment gateways and e-invoicing per target market (2.4, 4.14)
5. KPI and regulatory reports (4.11)
6. Load testing, read replicas (4.13)

---

## 6. Rules to keep while building

- Money only moves through `app/Services/Isp/*Service`; `ledger_entries` stays append-only; every new feature (write-off, FX, …) posts entries and passes `isp:ledger-check`.
- Wallets and balances stay computed, never stored as a mutable number.
- Billing status ≠ router state: keep `network_sync_status`, and only a real push (MikroTik or RADIUS) may set "synced".
- Every country difference is data (country pack / settings), never an `if ($country === 'BD')` in code.
- Every new branch-scoped table carries `branch_id` from day one; company-wide values (country, currency, timezone, tax) live on the company.
- Default settings must reproduce today's BD behaviour exactly, so current customers see no change.

# isp306: Global ISP Platform Roadmap and Gap Analysis

**Date:** 2026-09-27
**Branch reviewed:** `multibank`
**Goal:** run isp306 as an ISP business platform in any country, for any size of ISP, from one codebase.

**Deployment model (decided 2026-09-27):** one installation = **one company, many branches, one country**. There is no multi-tenant SaaS: a second ISP gets its own installation. Country, currency (and later timezone, tax and country pack) are set once for the company and apply to every branch.

This report has five parts:

1. What the system already does
2. What blocks global use (the hard blockers)
3. Higher-level management that is missing (platform, group, country, multi-level resellers, staff, approvals)
4. A full feature gap list, by module, with tasks
5. A phased plan with priorities

Priority tags: **P0** = must have before the first customer outside Bangladesh. **P1** = needed to compete in most markets. **P2** = market-specific or scale features.
Effort tags: **S** ≈ 1–3 days, **M** ≈ 1–2 weeks, **L** ≈ 3+ weeks.

---

## 1. Current state

| Area | What exists | Where |
|---|---|---|
| Stack | Laravel 12, Vue 3 + Inertia, MySQL, own role system (`checkAccess()`) | `composer.json`, `app/Models/Role.php` |
| Location | Branch → Zone → Area → Box; the box has lat/long | `create_isp_network_tables` |
| Packages | Company packages (universal/hidden), reseller copies with base-price review, price history | `PackageController`, `ResellerPackageService` |
| Connections | PPPoE / static / DHCP / hotspot, status history, bonus days, referral, package change with day-wise adjustment | `ConnectionService`, `PackageChangeService` |
| Billing | Prepaid, time-based (`expire_at` datetime), renewal invoices, "start on due" credit, instant suspend, no grace | `BillingService`, `OverdueService` |
| Money | Append-only `ledger_entries` + `isp:ledger-check`; payments, allocations, refunds, credit/debit notes; mirrored into cash/bank books | `LedgerService`, `CollectionService` |
| Online pay | bKash, Nagad, SSLCommerz, manual TrxID (Rocket) | `app/Services/Isp/Payments/*` |
| Reseller | Wallet (computed), margin earnings, cash-in-hand, deposits, withdrawals, pay from wallet, reseller statement | `ResellerWalletService`, `ResellerLedgerService` |
| Network | MikroTik REST driver, sync status, live verify, terminal | `app/Services/Network/*` |
| Bandwidth | Upstream purchases, sold vs bought usage, profit report | `BandwidthService` |
| Support | Tickets for customers, resellers, admin; internal notes | `TicketService` |
| Portals | Admin, reseller portal, customer portal, PWA | `resources/js/Pages/*` |
| Notify | SMS on invoice/payment/suspend/reactivate through the branch SMS gateway | `IspNotifier` |
| Languages | UI JSON: en, bn, ar, hi | `resources/js/lang` |
| Scheduler | `isp:generate-invoices`, `isp:process-overdue` every minute | `app/Console/Kernel.php` |
| Tests | Feature tests for billing, bandwidth, online payment, reseller, tickets, terminal | `tests/Feature` |

The billing and ledger core is strong. Most of the gaps are about **country independence**, **higher-level management**, **network standards (RADIUS/OLT)**, and **scale/operations**.

---

## 2. Hard blockers for global use (P0)

These are Bangladesh assumptions built into the code. Each one must go before selling outside BD.

### 2.1 Currency is hardcoded — P0, M — **step 1 done**
- "Tk" appears ~78 times, "৳" 12 times, "BDT" 6 times across `app/` and `resources/js/`.
- All amounts use `decimal(14,2)`. JPY/KRW/VND have 0 decimals, KWD/BHD/OMR/TND have 3.

**Tasks**
- [x] `company_profiles.country_code` + `currency_code`, `config/countries.php`, `config/currencies.php` (33 currencies, 0–3 decimals). Managed from ISP → Settings ("Country & currency", company-wide); picking a country pre-selects its currency.
- [x] `App\Support\Money` (PHP) and `currency()/cur()/money()/fmtMoney()` in `resources/js/lib/isp.js`, currency shared to all panels as the `currency` Inertia prop. Every hardcoded "Tk"/"৳"/"BDT" in the ISP pages, portals and server messages replaced.
- [x] SMS templates get a `{currency}` placeholder (new defaults use it; saved templates keep their text).
- [x] Currency locks once any invoice, payment or ledger row exists.
- [x] bKash/Nagad/Rocket/SSLCommerz declare `currencies => ['BDT']`; a gateway is hidden from customers and can't be switched on when the company bills in another currency.
- [x] All ISP money rounding goes through `Money::round()` (currency decimals), including the ledger check, reseller wallet SQL, reseller statement and the day-wise package-change quote; thresholds use `Money::unit()` / `Money::equals()`. Models use `App\Casts\MoneyCast` (prints "600.00" / "600" / "12.345"). JS: `moneyStep()`, `roundMoney()`.
- [x] Money columns widened to `decimal(18,3)` (ISP tables + the `receives`/`payments`/`bank_transactions` cash-book mirrors, which were `decimal(8,2)` and capped at 999,999.99).
- [x] 0-decimal (JPY, KRW, VND, IDR) and 3-decimal (KWD, BHD, OMR, JOD) currencies, tested end to end (bill, package change, payment, ledger check).
- [ ] Old POS modules (sales, purchases, reports outside ISP) still round to 2 decimals.

### 2.2 Timezone is hardcoded to Asia/Dhaka — P0, M — **done**
- `config/app.php` → `'timezone' => 'Asia/Dhaka'`. Prepaid billing depends on exact datetimes (`expire_at`, `paid_at`, `credit_at`). In countries with DST (US, EU, AU, Brazil…) a wrong timezone moves expiry by an hour, and a day boundary can move a whole day.

**Tasks**
- [x] `company_profiles.timezone` (IANA), set in ISP → Settings next to country and currency; the zone list is the chosen country's zones, main zone first (`config/countries.php`).
- [x] `App\Support\Region::apply()` at boot runs PHP/Carbon, `config('app.timezone')` and the scheduler in the company's timezone. Stored DATETIMEs are the company's wall-clock time.
- [x] Decision: **no UTC conversion**. One installation = one country, so wall-clock storage is simpler and safe; the MySQL session zone is left alone so values round-trip unchanged. Tables that used MySQL's `CURRENT_TIMESTAMP` default (ledger, audit, histories) are stamped from PHP (`StampsCreatedAt`).
- [x] Timezone locks with the currency once money is recorded (changing it would move every stored time).
- [x] JS `today()`, `nowString()` and the expiry colours use the company's timezone, not the browser's.
- [x] DST test (America/New_York): paid 20 Oct 10:00 → expires 20 Nov 10:00 local, suspended exactly then.
- [ ] Countries with several zones (US, CA, AU, BR, MX): the company picks one zone; a per-branch zone would need UTC storage — only if a customer asks.
- [ ] Old POS pages (Account/*, Report/*) still build "today" from the browser clock; move them to `today()` from `lib/isp.js`.
- [ ] Fall-back hour: a wall-clock time inside the repeated DST hour is ambiguous (at most one hour a year, DST countries only).

### 2.3 Tax (VAT / GST / sales tax) — P0, L — **done (core)**
Built 2026-09-27. With no rates set, nothing changes.

**Tasks**
- [x] `tax_rates` (name, %, default, active), company-wide; edited in ISP → Settings → Sales tax. Rates are switched off, never deleted.
- [x] Company settings: tax name (VAT/GST), company tax number, "prices include tax" (exclusive or inclusive pricing).
- [x] Package tax: company default rates, its own rates (e.g. CGST 9% + SGST 9%), or exempt (`packages.tax_rate_ids`). A reseller copy is taxed like its base package.
- [x] Every invoice line keeps a snapshot of its taxes (`invoice_items.taxes`); invoices have `tax`, `tax_total`, `tax_inclusive`. Invoice discount is shared over lines before tax. Opening balances and "No tax" manual invoices are untaxed.
- [x] Reseller margin, company share, referral %, package-change credit/cost and bandwidth profit are all net of tax.
- [x] Credit/debit notes carry the invoice's share of tax; a package downgrade credit gives back the tax on the unused days (`customer_payments.tax_amount`).
- [x] Invoice screen and print show tax per rate ("Tax Invoice" with the company tax number).
- [x] Tax report (ISP Reports → Tax Report): tax by rate on invoices issued, less voids (in the void's period), ± notes, less downgrade credits; company-wide with a branch split.
- [ ] Customer tax ID (corporate customers' VAT/GST number) on the customer and invoice.
- [ ] Separate tax per fee line (installation/activation fees use the default rates today).
- [ ] Compound taxes (tax on tax) — not needed by any listed country yet.
- [ ] P2: withholding tax on corporate customers' payments.
- [ ] P2: e-invoicing connectors per country (see 4.14).

### 2.4 Payment gateways are BD-only — P0, M — **Stripe and PayPal done**
`GatewayDriver` is already pluggable; only drivers are missing.

**Tasks**
- [x] Stripe Checkout (`StripeDriver`, no SDK): hosted page, any company currency (Stripe minor units incl. IDR 2-dp, KWD/BHD/OMR/JOD 3-dp in steps of 0.010), idempotent session create (`Idempotency-Key`), result always re-read from Stripe's API, cancel expires the session, bank debits stay open until `async_payment_succeeded`, test/live key must match sandbox mode, recorded as a `card` payment.
- [x] PayPal Checkout (`PaypalDriver`, Orders v2, no SDK): approve on PayPal, capture server side (idempotent `PayPal-Request-Id` on create and capture), result read back from PayPal, pending captures (eCheck/review) stay open until `PAYMENT.CAPTURE.COMPLETED`; currencies USD EUR GBP CAD AUD MYR SGD PHP THB BRL MXN JPY. Webhooks verified by PayPal's verify-webhook-signature API per gateway row, deduplicated in `gateway_events`; an approved order whose customer never came back is captured from the webhook.
- [ ] Regional driver list (build per market demand):
  - India: Razorpay, PayU, UPI (Cashfree/PhonePe)
  - Pakistan: JazzCash, Easypaisa
  - Nepal: eSewa, Khalti
  - Africa: M-Pesa (Daraja), Paystack, Flutterwave, MTN MoMo
  - SE Asia: Xendit, Midtrans, GCash/PayMongo, 2C2P
  - LatAm: Mercado Pago, PIX (Brazil), OXXO
  - Middle East: Tap, PayTabs, HyperPay
- [x] Gateway currency check: a gateway is hidden and can't be switched on unless it takes the company currency (2.1); Stripe also refuses a session charged in another currency.
- [x] Webhook idempotency: `gateway_events` stores the provider event id (unique per gateway); a processed event is acknowledged and skipped. Stripe webhooks are signature-checked (HMAC-SHA256, 5-minute tolerance), one URL per gateway row; an event that can't be confirmed yet answers 500 so Stripe resends it. SSLCommerz IPNs are deduplicated by `val_id`.
- [ ] Refund through the gateway API (today refunds are only recorded).
- [ ] Saved card / auto-debit (Stripe customer + mandate) for auto-renewal — P1.
- [ ] Chargeback/dispute handling: reverse the payment through `CollectionService`, keep the audit trail — P1.

### 2.5 Network control is MikroTik-only — P0, L — **RADIUS done**
Most ISPs outside small BD markets use **RADIUS** (FreeRADIUS) with Cisco/Juniper/Huawei/MikroTik BRAS/BNG. A MikroTik-only driver blocks medium and large ISPs.

**Tasks**
- [x] `RadiusDriver` implementing `NetworkDriver`: writes `radcheck`/`radreply`/`radusergroup`/`radgroupreply` and the `nas` client list (FreeRADIUS SQL schema, own `radius` DB connection, tables created if missing); speed via vendor attributes per NAS type (Mikrotik-Rate-Limit, Huawei-Input/Output-Average-Rate, Cisco-AVPair); suspension = `Auth-Type := Reject`; static IP = `Framed-IP-Address`; hotspot MAC lock = `Calling-Station-Id`. Checked end to end against FreeRADIUS 3 (`radtest` Accept with the rate / Reject when suspended). Setup guide: `docs/RADIUS_SETUP.md`.
- [x] CoA / Disconnect-Message (RFC 5176, `RadiusClient`, signed and answer-verified with the NAS secret): suspension, rename and IP/MAC changes disconnect the live session; a package change on a MikroTik NAS is a live CoA with the new rate, else a disconnect. A NAS that doesn't answer fails the sync, which is retried.
- [x] Read `radacct` for online status (connection panel), session history with IP / MAC / data used, and a NAS's online users; `radpostauth` explains a failed login.
- [x] Driver per router/NAS (`routers.driver`, `RouterDriver`), so one ISP mixes MikroTik API routers and RADIUS NAS devices; `ISP_NETWORK_DRIVER` = MikroTik keeps meaning "per router".
- [ ] CoA speed change for Huawei / Cisco (vendor CoA attributes); today they get a disconnect.
- [ ] Usage-based features on top of `radacct` (data caps / FUP 4.2, lawful session-log export 2.6).
- [x] Remove the lab router credentials (admin/admin) from any seed/default; router passwords encrypted at rest. (No default credentials in seeds/config; `routers.password` uses the `encrypted` cast.)

### 2.6 Legal compliance (data and logs) — P0, M
- [ ] **Lawful-intercept / retention logs**: most regulators (BTRC, India DoT, EU, Pakistan PTA, many African regulators) require session logs: username, assigned IP, NAT/CGNAT port range, start/stop, MAC, for 1–2 years. Store from `radacct` / MikroTik logs; make retention configurable per country; export on request.
- [ ] **Data protection (GDPR, India DPDP, Brazil LGPD, etc.)**: consent record, privacy notice, data export for a customer, and erasure. The ledger is append-only, so erasure must **pseudonymise** the customer (name/phone/address replaced) while keeping the money rows.
- [ ] **KYC**: ID type per country (NID, Aadhaar, passport, CNIC, SSN-last-4…), document upload, verification status; block activation until verified where the law requires it.
- [ ] Customer contract / terms acceptance with version and timestamp (e-signature in the portal).
- [ ] Data residency: host the installation in the country (or region) its law requires.

### 2.7 Configurable billing rules per market — P0, M — **done**
The current rules (prepaid, no grace, instant suspend) fit BD home broadband. Other markets need other rules, and some are legal limits (for example, some countries forbid disconnecting without notice).

**Tasks**
- [x] Billing mode per package (`packages.billing_mode`, inherited by reseller copies): `prepaid` (default, unchanged) or `postpaid`: each period's bill starts its time at once on credit, falls due `postpaid_due_days` into the period, the next period is billed even if earlier bills are unpaid, and the line is suspended ("Overdue") only when a bill is past due plus the grace days; paying the overdue bills brings it back.
- [ ] Postpaid billed in arrears (after the period) — today a postpaid bill is issued at the start of its period and due later.
- [x] Proration: on package change (day-wise, existed); on start with a branch billing day (`bill_day` 1–28: a new line's first bill = one cycle + pro-rata days to that day, `invoices.service_days`, so lines renew on the same day); on stop (`terminate_credit_unused` or per termination: unused paid days back to the customer's balance with their tax).
- [x] Grace period (`grace_days`): the line stays on that many days after its paid time; staff can still switch it on inside the grace.
- [x] Notice before suspension (`notice_days` + `sms_tpl_notice` with `{expire_date}` `{suspend_date}`): one SMS per paid time, recorded on the connection and in its history. With `notice_required` a line is never suspended sooner than `notice_days` after its notice (for countries that forbid disconnecting without notice).
- [x] Late fee (fixed or percent of the unpaid amount before earlier fees, once or every 30 days up to a cap, N days after the due date), posted as an untaxed debit note; charged under a row lock so it can't double; credit notes waive it.
- [ ] Grace/notice/late-fee defaults in the country packs, once confirmed per country.
- [x] Security deposit (`customer_deposits`, `DepositService`): held apart from the customer ledger (never pays a bill by itself), in the cash/bank book when received and refunded, can be applied to dues (a `deposit` payment, no second cash entry) or refunded in part or whole; shown on the customer profile (Deposits tab, deposit held).
- [ ] Keep all of these as settings, never forks in code. Current BD behaviour = the default "country pack" (see 2.8).

### 2.8 Country packs — P0, M — **done (structure + packs)**
One place that bundles a country's defaults, so onboarding a new ISP is picking a country, not editing code.

A country pack holds: currency, timezone(s), language, date/number format, phone format (E.164 + national format), address fields (state/province/postcode), ID types, tax rates, invoice template and numbering rules, allowed payment gateways, SMS providers, billing rule defaults (grace/notice), log retention period, regulatory report templates.

- [x] `config/country_packs/{CODE}.php`, one file per country, resolved by `App\Support\CountryPack` over a generic pack and `config/countries.php`. Keys: language, date format, phone (calling code, trunk prefix, national lengths, example), address labels, ID types, tax (label, inclusive pricing, suggested rates), payment gateways (only built ones that take the currency are offered), SMS providers, branch billing defaults (IspSettings keys), log retention days, regulatory reports.
- [x] Packs: BD (reproduces today's behaviour exactly: BDT, Asia/Dhaka, no tax rates, bKash/Nagad/Rocket/SSLCommerz, default billing), IN (GST as CGST 9% + SGST 9%), PK, NP (VAT 13%), NG (VAT 7.5%), KE (VAT 16%), PH (VAT 12%), ID (PPN 11%), BR (tax-inclusive, rates left to the ISP), US (no tax on internet access), GB (VAT 20%, tax-inclusive). Every other listed country gets the generic pack (currency + timezone).
- [x] Apply a pack: ISP → Settings shows the chosen country's pack and "Apply country pack" (options: tax name/pricing/suggested rates; billing defaults on every branch). Nothing recorded changes: currency/timezone stay once money exists, tax rates are added only when there are none, a multi-zone country keeps the zone already picked. Audited as `company.country_pack_applied`.
- [x] Installation from the command line: `php artisan isp:country-pack` (list), `isp:country-pack IN` (show), `isp:country-pack IN --apply --tax --billing`.
- [x] `company_profiles.language`: the pack's UI language is the default for users who haven't picked one (`defaultLocale` Inertia prop).
- [ ] Use the pack's phone, address and ID-type data in the customer forms (with 2.9 E.164 phones / generic address and 2.6 KYC).
- [ ] Use the pack's date format in printed dates and invoices; invoice template/numbering per country.
- [ ] Grace/notice/late-fee defaults in the packs once 2.7 exists (billing keys are limited to real `IspSettings` keys).
- [ ] Full installation wizard (4.16) on top of `CountryPack::apply()`.
- [ ] Check each pack's suggested tax rates with a local accountant before the first customer in that country.

### 2.9 Internationalisation of the UI — P0, M
- [ ] Move every visible string to the lang files (check pages and PHP validation/flash messages; `resources/lang` has only `en`).
- [x] RTL layout for Arabic: every physical left/right Tailwind utility in the UI converted to its logical form (ms/me, ps/pe, start/end, text-start/end, border-s/e, rounded-s/e; 737 class tokens), `lang`/`dir` set at load for the language in use, sidebars and slide-in panels mirrored, centered badges kept physical. Checked with screenshots in both directions.
- [ ] Per-user language and per-customer language (SMS/email templates in the customer's language).
- [ ] Locale-aware dates and numbers (`Intl.NumberFormat`, `Intl.DateTimeFormat`).
- [x] Phone numbers (`App\Support\Phone`, libphonenumber-lite): customer / reseller forms, portal profiles and imports accept any valid number of the company's country (any format) or any international `+` number; stored as national digits for the home country (BD unchanged: `01712345678`) and E.164 for others, so one number can't be entered twice in two spellings; `Phone::e164()` for providers that need it.
- [x] Address: city, state and postcode on customers next to Zone/Area/Box, labelled from the country pack (Division / State / County, Post code / PIN / ZIP / CEP); postcode required where the pack says so.

---

## 3. Higher-level management (hierarchy) — what is missing

Today the top of the tree is **Company → Branch**. One installation serves one company in one country (see the deployment model at the top), so there is no tenant/SaaS level. The levels to add sit inside the company.

### 3.1 Company level (owner / head office) — P1, M
- [ ] Owner dashboard across all branches: subscribers, collection, due, profit per branch.
- [ ] Head-office users who see every branch; branch users locked to their branch (switchable branches already exist).
- [ ] Company-wide settings (country, currency, timezone, tax, country pack) vs branch settings (prefixes, SMS, billing rules) kept clearly apart.
- [ ] Installation backups and restore; full data export.
- [ ] Separate installations per ISP; if one owner later runs ISPs in two countries, that is two installations (one per country) and a group report on top, not one mixed database.

### 3.2 Region level inside the country — P1, M
A larger ISP groups branches by division/state/city.

- [ ] **Company → Region → Branch (POP) → Zone → Area → Box.** Add `regions` between company and branch.
- [ ] Regional managers scoped to their region's branches.
- [ ] Inter-branch transfers (bandwidth sold between branches, shared upstream) as inter-branch ledger entries.
- [ ] Consolidated P&L, balance sheet, subscriber count, ARPU, churn by region and branch (one currency, so no conversion needed).

### 3.3 Multi-level reseller network — P1, L
Today: company → reseller → customer (one level).

- [ ] Tree: Master distributor → Distributor → Reseller → Sub-reseller/Franchise (`resellers.parent_id`, configurable max depth).
- [ ] Each level customises the package from its parent's price (same base_price review rule already used), margin per level computed from the chain.
- [ ] Wallet per level; a parent sees and settles its children; withdrawals approved by the parent or the company.
- [ ] Commission models: margin (current), percent of collection, fixed per new connection, per-level override.
- [ ] Reseller credit limit and auto-block when over the limit.
- [ ] Reseller branding in the customer portal (logo, support phone).
- [ ] Franchise model: franchise owns its own routers and pays a revenue share or a per-subscriber fee.

### 3.4 Staff hierarchy and roles — P1, M
- [ ] Role templates: Owner, Manager, Accountant, Billing officer, NOC engineer, Technician, Collector, Support agent, Sales/lead agent, Reseller staff.
- [ ] Scope per user: organisation / region / branch / zone / area (a collector sees only their areas).
- [ ] Reporting line (`users.manager_id`) for approvals and escalations.
- [x] 2FA (TOTP) mandatory by company policy (see 4.12): off / Superadmin+admin / all staff / all staff + resellers.
- [ ] Per-role 2FA rules (e.g. accountant) once role templates exist; login IP allow-list optional.

### 3.5 Approval workflows (maker-checker) — P1, M
Money must be safe when many people and levels are involved.

- [ ] Generic `approvals` table: subject type/id, requested_by, approver role/level, status, reason.
- [ ] Rules configurable by the company, e.g.: discount above X %, refund above X, write-off, "start on due" credit, package price change, manual ledger adjustment, reseller withdrawal, connection termination.
- [ ] Multi-step approval (manager → finance) and limits per role.
- [ ] Notifications and an "Awaiting my approval" inbox.

---

## 4. Full feature gap list by module

### 4.1 Customer lifecycle and CRM — P1
- [ ] Leads / pre-sales: lead source, coverage check by address/map, quote, convert to customer.
- [ ] Coverage map: boxes already have lat/long; show on a map (Leaflet + OpenStreetMap, no licence cost), radius around a box, free ports.
- [ ] Customer types: home, SME, corporate (corporate needs contracts, SLA, PO numbers, multiple connections under one account, consolidated invoice).
- [ ] Contract term / lock-in period and early-termination fee.
- [ ] Move/transfer: change address, transfer ownership to another customer, keep history.
- [ ] Churn reason on termination; win-back campaigns.
- [ ] Customer documents (KYC, contract, installation photos).

### 4.2 Billing and products — P1
- [ ] Postpaid mode, proration, grace, late fee, deposit (see 2.7).
- [ ] Discounts and coupons (percent/fixed, first N cycles, promo codes, validity).
- [ ] Bundles and add-ons: static IP, IPTV, VoIP, extra router rental, public IP block, as recurring add-on lines on the service invoice.
- [ ] Equipment sale / rental / instalments (ONU, router) linked to inventory (4.5).
- [ ] Data caps and FUP: speed drops after X GB (needs RADIUS accounting, 2.5).
- [ ] Time-of-day packages (night unlimited), burst settings.
- [ ] Hotspot vouchers: batch generate, print, sell through resellers, one-time use.
- [ ] Bad-debt write-off with approval, posted to the ledger.
- [ ] Customer statement (period, opening/closing) as PDF, e-mail and portal download.
- [ ] Branded invoice PDF template for the company in its country format (server-side PDF, e.g. `barryvdh/laravel-dompdf` or Browsershot).
- [ ] Bulk actions: bulk package change, bulk suspend/reactivate, bulk SMS, bulk price increase with notice.

### 4.3 Collections and dunning — P1
- [ ] Collector app/flow: daily route list by area, collect cash, print/SMS receipt, GPS stamp, end-of-day deposit to office (same "cash in hand" model as resellers).
- [ ] Dunning schedule: reminders before expiry (X days), on expiry, after suspension; channel per step (SMS/email/WhatsApp).
- [ ] Promise-to-pay with a date (auto reminder, optional temporary reactivation).
- [ ] Auto-renew from saved card or wallet balance.
- [ ] Aging report (0–30/31–60/61–90/90+) per branch/zone/collector/reseller.

### 4.4 Field operations (technicians / work orders) — P1, L
No technician module exists today.
- [ ] Work order types: new installation, shifting, repair, disconnection, equipment pickup.
- [ ] Created from a new connection, a ticket, or manually; assigned to a technician/team; schedule, SLA, status flow.
- [ ] Mobile-friendly technician view (PWA): job list, map/navigation, photos, customer signature, materials used (deducts inventory), ONU serial captured.
- [ ] Activation only after the installation work order is closed (optional rule).
- [ ] Technician performance report.

### 4.5 Inventory and assets — P1, M
- [ ] Items: ONU, routers, switches, SFP, cable (by metre), splitters, boxes.
- [ ] Warehouses/stores per branch; stock in (purchase), transfer, issue to technician, install at customer, return, faulty/RMA.
- [ ] Serial / MAC tracking; which device is at which customer.
- [ ] Purchase orders and supplier bills posted to the ledger.
- [ ] Asset register for network equipment with depreciation (P2).

### 4.6 Network: OLT / ONU / monitoring — P1/P2, L
- [ ] OLT drivers (SNMP/Telnet/SSH/API): Huawei, ZTE, VSOL, BDCOM, Nokia — read ONU list, optical power (Rx/Tx), status, auto-authorise ONU, map ONU to connection.
- [ ] TR-069/ACS (GenieACS) for CPE: remote Wi-Fi name/password change, reboot, firmware.
- [ ] IPAM: IP pools, static IP assignment, IPv6 prefix delegation, CGNAT port blocks (needed for the lawful logs in 2.6).
- [ ] Monitoring: router/OLT/uplink up/down (ping/SNMP), interface traffic graphs, alerts to NOC.
- [ ] Outage management: mark an outage for a zone/box/OLT port → auto-notify affected customers, auto-open/link tickets, optional compensation days.
- [ ] Topology view: POP → OLT → PON port → splitter → box → customer.
- [ ] Router config backup and multi-router failover per branch.

### 4.7 Support and SLA — P1, M
- [ ] Ticket categories, priorities, SLA timers (first response, resolution), escalation to the next level (3.4).
- [ ] Assignment rules (by zone/category), agent workload.
- [ ] Canned replies, attachments, ticket from SMS/email/WhatsApp.
- [ ] Customer satisfaction (CSAT) after closing.
- [ ] Knowledge base / FAQ in the customer portal (multi-language).

### 4.8 Notifications and communication — P0/P1, M
Today: SMS only, 4 events.
- [ ] Channels: e-mail (queued), WhatsApp Business API, Telegram, web push (PWA exists).
- [ ] Global SMS providers: Twilio, Vonage, Infobip, MessageBird + local ones per country pack.
- [ ] More events: renewal reminder (X days before expiry), expiry today, ticket updates, work order visit time, outage, OTP.
- [ ] Templates per language per channel; customer's preferred channel and opt-out (legal in many countries).
- [ ] Sender ID / DLT registration support (India needs DLT template IDs).
- [ ] Notification log with delivery status and cost.

### 4.9 Customer self-service — P1, M
- [ ] Mobile app (or polished PWA): pay, renew, upgrade/downgrade (with the day-wise quote you already have), tickets, usage graph, speed test, Wi-Fi password change (TR-069).
- [ ] OTP login by phone/e-mail.
- [ ] Auto-renew toggle, saved payment method.
- [ ] Invoice/receipt PDF download, statement.
- [ ] Referral link sharing (referral logic already exists).
- [ ] Multi-language and RTL.

### 4.10 Accounting and finance — P1, L
- [ ] Full double-entry general ledger with a chart of accounts (assets, liabilities, equity, income, expense) driven by `ledger_entries` postings; today the POS books (`receives`/`payments`) are mirrored — unify into one GL.
- [ ] Accounts: revenue per product, tax payable, customer advances (liability), reseller payable, deposits, bad debt, FX gain/loss.
- [ ] Deferred revenue for prepaid time (revenue recognised day by day over the paid window) — required by IFRS 15 / ASC 606 for audited companies. P2 for small ISPs.
- [ ] Period close / lock date (no postings before the lock).
- [ ] Expenses and vendor bills (upstream bandwidth purchase already exists — post it).
- [ ] Bank reconciliation (import CSV/MT940 statement, match payments).
- [ ] Export to QuickBooks, Xero, Tally, Zoho Books.
- [ ] Payroll for staff (optional, P2).

### 4.11 Reports and analytics — P1, M
- [ ] KPIs: MRR, ARPU, churn rate, net adds, collection efficiency, DSO, active vs suspended, reseller performance.
- [ ] Cohort and churn reasons, package mix, bandwidth utilisation (extend `BandwidthService`).
- [ ] Regulatory subscriber reports per country pack (e.g. BTRC monthly subscriber report, India TRAI, US FCC BDC, UK Ofcom) — template + export.
- [ ] Tax report (2.3), aging (4.3), log export (2.6).
- [ ] Scheduled reports by e-mail; CSV/XLSX export (PhpSpreadsheet is already installed).

### 4.12 Security — P0, M — **login, 2FA and secrets done**
- [x] 2FA (TOTP, RFC 6238, no extra package: `App\Support\Totp`) for staff and resellers: set up from My profile with a QR code, confirmed with a code, 8 one-time recovery codes, turn off / new codes need the password. Secret and codes encrypted at rest; a code can't be replayed (`two_factor_last_step`).
- [x] Login second step: after the right password an account with 2FA must enter an app code or a recovery code within 5 minutes.
- [x] Company policy (ISP → Settings → Login security): off (default) / Superadmin + admin / all staff / all staff + resellers. Anyone covered without 2FA is sent to a setup page (`RequireTwoFactor` middleware) and can't turn it off; "Login as" sessions are exempt. Saving a policy that covers yourself needs your own 2FA first.
- [x] Lockout: 5 wrong passwords per username + IP lock that username for 15 minutes (`auth.login_locked` audited); 5 wrong codes lock the code step.
- [x] Rate limiting: `/login` and `/login/two-factor` 30/min per IP; 2FA management 20/min; payment callbacks/IPN already on the `api` limiter (60/min per IP).
- [x] Secrets at rest: SMS gateway API keys and URL templates now encrypted (existing rows encrypted by the migration), the key is never sent back to the browser (blank on edit keeps it), and the gateway list needs SMS settings access. Router passwords, PPPoE passwords and payment gateway credentials were already encrypted.
- [x] Audit: 2FA on/off, new recovery codes, recovery code used, lockouts, policy changes.
- [ ] Password policy (length/complexity), session list with remote logout.
- [ ] Audit log for every admin action (exists for money — extend to settings, roles, package changes, logins).
- [ ] Signed webhook verification for every gateway.
- [ ] Security headers (CSP, HSTS), dependency scanning (`composer audit`, `npm audit`) in CI.
- [ ] External penetration test before the first large installation.

### 4.13 Platform, scale and operations — P0/P1, L — **queues done**
Before: `QUEUE_CONNECTION=sync`, `CACHE_DRIVER=file`, two every-minute commands that scan the whole branch.
- [x] Queues `network` (router pushes), `sms`, `default`, with three setups (`config/isp.php`, `.env.example`): **Redis + Laravel Horizon** (own server; `/horizon` dashboard, gated by the `queueMonitor` access), **database queue worked by the scheduler** (`ISP_QUEUE_IN_SCHEDULER=true`: shared hosting with only the cron entry, jobs start within a minute), or **sync** (unchanged default, runs in the request).
- [x] SMS off the web request: billing SMS (`IspNotifier` → `SendSms`, after commit, text built at the event) and promotions (`SendSmsBatch`, 100 numbers per gateway call). SMS jobs are never retried (a gateway may have delivered before failing; every attempt is in the SMS log).
- [x] Router sync (`SyncConnectionToNetwork`, already a job) on the `network` queue, unique per connection while waiting (it pushes the state at run time) and locked per connection so two pushes never interleave; "Sync all" answers at once when queued.
- [x] ISP → Logs & Audits → **Background Jobs**: waiting jobs per queue, failed jobs with retry/delete (every driver); failed jobs pruned after 30 days.
- [x] Composite indexes for the every-minute jobs: `connections(branch_id, status, expire_at)`, `invoices(branch_id, status, due_date)`, `invoices(connection_id, status)`. The jobs already select only rows that crossed a boundary and work in chunks of 200.
- [ ] Redis for cache and session (config only; `CACHE_DRIVER`/`SESSION_DRIVER=redis`), and dispatching the every-minute billing work per branch as jobs for very large installations.
- [ ] Idempotency for the remaining jobs added later (invoice generation already skips connections with an open invoice; `invoices.period_key` is unique).
- [ ] Docker images, CI/CD (tests on every push), zero-downtime deploys, migrations tested for big tables.
- [ ] Error tracking (Sentry), metrics and uptime monitoring, structured logs.
- [ ] Automated offsite backups with restore tests (today: `mysqldump` feature); point-in-time recovery for the database.
- [ ] Read replica for reports; long reports run as queued exports.
- [ ] Load test: 100k connections per installation, month-end payment spikes.

### 4.14 Public API and integrations — P1, M
- [ ] REST API with tokens (Laravel Sanctum) and scopes: customers, connections, invoices, payments, tickets.
- [ ] Outbound webhooks (payment received, connection suspended, ticket created) with signing and retries.
- [ ] API documentation (OpenAPI).
- [ ] Integrations: accounting (4.10), maps, WhatsApp, e-invoicing per country (India GST IRP, Saudi ZATCA, Mexico CFDI, Brazil NF-e/NFCom, EU Peppol, Bangladesh NBR Mushak 6.3).

### 4.15 Testing and quality — P0, M
- [ ] Unit tests for `Money` rounding per currency (0/2/3 decimals).
- [ ] Timezone and DST tests for prepaid expiry and package change.
- [ ] Tax tests (inclusive/exclusive, compound, refunds, package change).
- [ ] Branch isolation tests: a branch user can never read another branch's data (every controller).
- [ ] Gateway driver contract tests with recorded sandbox responses.
- [ ] RADIUS driver tests against a FreeRADIUS container in CI.
- [ ] Keep `isp:ledger-check` in CI and as a nightly job with an alert.

### 4.16 Documentation and onboarding — P1, S/M
- [ ] Installation wizard: country pack → company → branch → router/RADIUS → packages → import customers.
- [ ] Data import from Excel/CSV and from common competitor systems (customers, connections, balances as opening entries).
- [ ] Admin manual, reseller manual, customer help, in the country pack languages.
- [ ] Demo installation with sample data for sales.

---

## 5. Phased plan

### Phase A — Global foundation (P0) ≈ 8–10 weeks
Goal: the same code runs a BD ISP and a non-BD ISP safely.

1. ~~Company currency, Money helper, remove hardcoded Tk/৳/BDT (2.1)~~ — done 2026-09-27
2. ~~Company timezone + DST tests (2.2)~~ — done 2026-09-27
3. ~~Per-currency rounding for 0/3-decimal currencies (2.1)~~ — done 2026-09-27
4. ~~Country packs, BD first (2.8)~~ — done 2026-09-27
5. ~~Tax engine on invoices (2.3)~~ — done 2026-09-27
6. ~~Configurable billing rules: grace, notice, late fee, postpaid, proration, deposit (2.7)~~ — done 2026-09-28
7. ~~Stripe + PayPal drivers, webhook idempotency (2.4)~~ — done 2026-09-28
8. ~~Security basics: 2FA, rate limit, secret encryption (4.12)~~ — done 2026-09-28
9. ~~Redis + queue + Horizon, incremental scheduler (4.13)~~ — done 2026-09-28
10. i18n cleanup, RTL, E.164 phones, generic address (2.9)

**Exit check:** a test installation in USD/America/New_York with 8% sales tax and Stripe passes the full billing test suite and `isp:ledger-check`.

### Phase B — Network and compliance (P0/P1) ≈ 6–8 weeks
1. ~~RADIUS driver + CoA + accounting (2.5)~~ — done 2026-09-28
2. Session/NAT log retention and export (2.6)
3. KYC, consent, pseudonymised erasure, terms acceptance (2.6)
4. IPAM + CGNAT port blocks (4.6)
5. Notifications: e-mail, WhatsApp, reminders, templates per language (4.8)

### Phase C — Higher-level management (P1) ≈ 6–8 weeks
1. Company owner dashboard + region level (3.1, 3.2)
2. Multi-level reseller tree, per-level commission, credit limit (3.3)
3. Staff role templates and scopes (3.4)
4. Approval workflows (3.5)

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

These protect what already works:

- Money only moves through `app/Services/Isp/*Service`; `ledger_entries` stays append-only; every new feature (tax, late fee, write-off, FX) posts entries and passes `isp:ledger-check`.
- Wallets and balances stay computed, never stored as a mutable number.
- Billing status ≠ router state: keep `network_sync_status`, and only a real push (MikroTik or RADIUS) may set "synced".
- Every country difference is data (country pack / settings), never an `if ($country === 'BD')` in code.
- Every new branch-scoped table carries `branch_id` from day one; company-wide values (country, currency, timezone, tax) live on the company, never per branch.
- Default settings must reproduce today's BD behaviour exactly, so current customers see no change.

---

## 7. Top 10 next tasks (start here)

| # | Task | Priority | Effort |
|---|---|---|---|
| 1 | ~~Company currency + `Money` helper + replace hardcoded Tk/৳/BDT~~ (done) | P0 | M |
| 2 | ~~Company timezone + DST tests~~ (done) | P0 | M |
| 3 | ~~Per-currency rounding (0/3 decimals) + `decimal(18,3)` storage~~ (done) | P0 | M |
| 4 | ~~Tax rates on invoice items + tax report~~ (done) | P0 | L |
| 5 | ~~Country pack structure + BD pack~~ (done) | P0 | M |
| 6 | ~~2FA + rate limiting + encrypted router/gateway secrets~~ (done) | P0 | M |
| 7 | ~~Redis queue + Horizon; move SMS/router sync to jobs~~ (done) | P0 | M |
| 8 | ~~Stripe driver + webhook idempotency~~ (done) | P0 | M |
| 9 | ~~Grace/notice/late-fee settings (default off)~~ (done) | P0 | M |
| 10 | ~~RADIUS driver (FreeRADIUS SQL + CoA)~~ (done) | P0 | L |

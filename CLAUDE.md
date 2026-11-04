# RestoPro ERP Web — session notes

Read README.md and docs/SETUP.md first. If CLAUDE.local.md exists (maintainer's machine), read it too — it points
to the private plan/TODO and the live-server notes.

## Local tooling
System PHP has no php.ini, so every PHP/Composer command needs the project-local ini:
```
export PHPRC="$PWD/.tools"          # bash
php .tools/composer.phar <cmd>       # composer is not installed globally
```
`.tools/` is gitignored (php.ini + composer.phar). Local DB = SQLite (`database/database.sqlite`).

## Run locally
- Preview server: `restopro` entry in `D:\My Project\.claude\launch.json` → `php -S 127.0.0.1:8090` with `.tools/router.php`
  (`artisan serve` fails here because its child PHP process loses the project ini). Open http://127.0.0.1:8090.
- Local owner login: username `admin`, password = `ADMIN_PASSWORD` in `.env` (local only, gitignored).
- After CSS/Blade class changes: `npm run build`.
- Demo data: `php artisan db:seed --class=DemoSeeder` (45 days of collections; refuses in production).

## Conventions
- Money: `money($v)` / `inr_group($v)` helpers (Indian grouping, ₹ from settings). Amount columns are decimal(12,2).
- Dates: models `use StoresPlainDate` and list their date columns in `plainDateColumns()` (default ['date']) so they are
  stored as bare `Y-m-d` — the plain `date` cast writes "Y-m-d 00:00:00" on SQLite and breaks range/equality checks.
- Master Ledger is a computed UNION (`App\Services\MasterLedger`), not a table — nothing to keep in sync, no double-posting.
  Adding a new money-moving module = add one more SELECT to `MasterLedger::union()`.
- Owner-only deletes: `Gate delete-records`. Charts: Alpine `apexChart(type, data)` in resources/js/app.js (presets trend/split/bars);
  give the chart element a `wire:key` that changes with its data so Livewire re-creates it.
- Expenses: set only `direct_paid` (paid on the bill itself). `paid_amount` = direct_paid + khata payments allocated FIFO,
  and `status` derives from it — both maintained by `App\Services\VendorLedger::rebalance()`, which model events on
  Expense / VendorPayment / Vendor(opening_balance) call. Rebalance rebuilds allocations from scratch (idempotent).
  Vendor balance = opening_balance + SUM(amount - direct_paid) - SUM(payments); negative = advance.
- Staff pay: `App\Services\StaffLedger` (months/month/balance/totals). Monthly = salary ÷ days-in-month per day, unmarked
  days paid, A −1 day, H −½; daily = rate × (P + ½H). Work value rounded to whole rupees, accrues up to today.
  Bonus = earned + paid (net 0); deduction = lowers earned, no payment_mode. Closing carries to next month's opening.
- Staff share link: `staff.share_token` (32 chars) → public Livewire `StaffShare` at /s/{token} (layout `public`, NoIndex
  middleware, throttle). `share_pin` is hashed; unlock is session-scoped to token+pin hash and rate-limited per staff+IP.
  Never render id_number/address/mobile/emergency_contact or other staff on that page (tests assert this).
- Reports: `App\Services\ReportBuilder::build($type, $params)` returns {title, subtitle, kpis, sections[columns,rows,totals], notes};
  the same array drives the Livewire preview, `resources/views/reports/pdf.blade.php` (dompdf, keep DejaVu Sans for ₹)
  and `App\Exports\ReportExport` (one sheet per section). Format cells with `report_cell($v, $type)`.
  Profit = sales − all bills by bill date − staff cost (work value + bonus − deductions earned that month).
- Backups: `App\Services\Backup\BackupService` (create/upload/prune/inspect/restore). New business table → add it to
  `BackupService::TABLES` in parent-first order, or it won't be backed up. Settings starting `backup_` are server-local
  and never exported/overwritten. Cloud drivers implement `Destination`; secrets via `Setting::putSecret/secret`.
- Containers with decorative absolute shapes poking outside must use `overflow-clip`, not `overflow-hidden`
  (hidden is still scrollable and shifts sideways when a child gets focus).
- API: controllers in `app/Http/Controllers/Api`, JSON shapes only via `Api\Fmt` (the Android app depends on them —
  change = bump/document in docs/API.md). Business logic stays in models/services shared with Livewire.
- Name uniqueness (categories, vendors) uses `App\Rules\UniqueCi` — SQLite compares case-sensitively, MySQL doesn't.
- Bill photos: private `local` disk under bills/YYYY-MM, served by route `expenses.bill` (auth only).
- Sidebar items light up automatically once their route name exists (config/restopro.php); an item's optional `can`
  (Gate name) hides it from users without that permission.
- v2 sales: `App\Support\Sales` — `advanced()` (Settings key app_mode simple|advanced), `on('tables'|'delivery'|'advance'|'stock')`,
  `gst()`, `gstInclusive()`. Sales routes use middleware `advanced` (404 in Simple mode). Nav groups/items take `mode`,
  `feature` and `pos` (only `pos` items are shown to the Cashier role). Gate `back-office` = everyone except cashiers.
- Day sales: `cash_collections.cash/upi/online` are DAY TOTALS (all v1 reports read them); `pos_*` is the part from bills.
  Never write those columns directly from POS code — call `App\Services\DaySales::setPos($date, [...])`. The Cash
  Collection page edits only the hand-entered share (`fillManual`), and delete keeps the bill share (`deleteManual`).
- Bills: `App\Services\Billing` (save/hold/cancel/syncDay) + `BillCalculator` (GST incl/excl, proportional discount,
  CGST/SGST split, round-off). Prices are re-read from items on save — never trust the screen. Bill no. = bill_prefix +
  FY label (`Billing::fyLabel()`, e.g. 2026-27) + /0001, unique per (fy, seq); held bills have no number; cancelled
  bills keep it. Payments map card/online → Cash Collection `online`. POS cart lines are addressed by `key`, not index
  (index-bound wire:model inputs showed the wrong qty after lines moved).
- Printing: `App\Services\BillView::data($order)` feeds bills/receipt (58/80 mm via @page, browser print), bills/kot,
  bills/pdf (dompdf A5, logo as data URI) and bills/public (/b/{token}, no login, NoIndex, works in Simple mode).
  The POS auto-prints through hidden iframes inside `wire:ignore` (Livewire morph would strip a JS-set src).
  Settings keys print_width/print_footer/print_logo/print_auto/print_kot. Silent printing = Chrome `--kiosk-printing`.
- Business details live in Settings keys `business_*` (logo path = `business_logo` on the public disk, logos/).
  Use `<x-brand-mark />` for the logo-or-initial badge.

## Rules
- Commit + push after every finished step. No Claude co-author lines in commits.
- Master Ledger rows are owned by their source (source_type/source_id) — update, never re-insert, on edit.
- Never commit `.env`, `deploy/deploy.env` or any credentials. This repo is PUBLIC — no server addresses, account
  names, real business data or personal paths in tracked files.

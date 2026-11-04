# RestoPro ERP — REST API v1 (for the Android app)

Base URL: `https://<domain>/api/v1` (local: `http://127.0.0.1:8090/api/v1`)

* Send `Accept: application/json` on every request.
* Authenticate with `Authorization: Bearer <token>` (from `/auth/login`). Tokens don't expire; they're revoked by
  logout, or by changing the password on the website (signs out every device).
* Money = number in rupees (2 decimals). Dates = `YYYY-MM-DD`. Months = `YYYY-MM`. Timestamps = ISO-8601.
* Errors are always JSON: `401` not signed in · `403` not allowed (managers can't delete or back up) ·
  `404` not found · `422` validation → `{"message": "...", "errors": {"field": ["..."]}}` · `429` too many requests.
* File uploads (bill photo, staff photo) use `multipart/form-data`. To **edit** with a file, send
  `POST` to the item URL with `_method=PUT` (PHP can't read files from a real PUT).
* Rate limits: login 20/min per IP (and 5 wrong passwords → 15 min lock per identifier); everything else 240/min.

---

## Auth

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/auth/login` | `identifier` (mobile / username / email), `password`, `device_name` | `{token, token_type, user, meta}` |
| POST | `/auth/logout` | — | revokes this device's token |
| GET | `/me` | — | `{user, meta}` |
| GET | `/meta` | — | business name, currency, FY start month, payment modes, attendance codes, staff payment types |

`user.can.delete` / `user.can.backups` tell the app which buttons to show (owner only).

**v2:** users with role `cashier` get `403` on every v1 endpoint below (they only use the sales endpoints, coming with the POS).
Collections carry `from_bills` `{cash, upi, online}` — the part of the day's totals that came from POS bills. `PUT /collections/{id}`
still takes **day totals**; a total lower than its `from_bills` amount is rejected with `422`.

## Dashboard

`GET /dashboard` → today's collection & expenses, attendance marked, month sales/expenses/profit,
`owed_to_vendors`, `salary_owed`, `last_14_days {labels, values}`, `by_category`.

## Cash collection

| Method | Path | Notes |
|---|---|---|
| GET | `/collections?month=2026-11` | `{month, totals{cash,upi,online,total,days}, data[]}` |
| POST | `/collections` | `date`, `cash`, `upi`, `online`, `note` — one entry per date (422 if it exists) |
| PUT | `/collections/{id}` | same body |
| DELETE | `/collections/{id}` | owner only |

## Expenses

| Method | Path | Notes |
|---|---|---|
| GET | `/expenses?month=&category_id=&vendor_id=(id\|none)&status=(paid\|due)&q=` | `{totals, by_category, data[]}` |
| GET | `/expenses/{id}` | |
| POST | `/expenses` | `date`, `category_id`, `vendor_id?`, `amount`, `paid` (true/false), `payment_mode` (if paid: cash/upi/bank/card), `note?`, `bill?` (jpg/png/webp/pdf ≤ 5 MB) |
| PUT | `/expenses/{id}` | same + `remove_bill` |
| POST | `/expenses/{id}/mark-paid` | `payment_mode` — pays what's still open on the bill |
| DELETE | `/expenses/{id}` | owner only |
| GET | `/expenses/{id}/bill` | the photo/PDF (needs the token) |
| GET / POST | `/expense-categories` | POST `name` |
| PUT | `/expense-categories/{id}` | `name?`, `color?` (#rrggbb), `is_active?` |

Expense fields: `amount`, `paid_at_purchase`, `paid_total` (incl. vendor khata payments), `balance`, `status` (paid/partial/unpaid).

## Vendors & khata

| Method | Path | Notes |
|---|---|---|
| GET | `/vendors?show=active\|due\|all&q=` | `{total_owed, data[]}`; `balance` negative = advance |
| POST / PUT | `/vendors`, `/vendors/{id}` | `name`, `mobile?`, `address?`, `default_category_id?`, `opening_balance?`, `note?`, `is_active?` |
| GET | `/vendors/{id}` | vendor + `open_bills[]` |
| GET | `/vendors/{id}/statement?from=&to=` | `{balance, opening, rows[{type: bill\|payment, date, title, debit, credit, balance, status?}], closing, billed, paid}` |
| POST | `/vendors/{id}/payments` | `date`, `amount`, `payment_mode`, `note?` → settles oldest bills first; returns new `balance` |
| PUT / DELETE | `/vendor-payments/{id}` | DELETE owner only |
| DELETE | `/vendors/{id}` | owner; vendors with history are deactivated (`deactivated: true`) |

## Staff, attendance, salary

| Method | Path | Notes |
|---|---|---|
| GET | `/staff?show=active\|left\|all` | each with `balance` (+ to pay / − advance) and `today` status |
| POST / PUT | `/staff`, `/staff/{id}` | `name`, `mobile?`, `designation?`, `joining_date`, `leaving_date?`, `salary_type` (monthly/daily), `salary_amount`, `opening_balance?` (− = they owe you), `id_type?`, `id_number?`, `address?`, `emergency_contact?`, `note?`, `is_active?`, `photo?` |
| GET | `/staff/{id}?month=` | profile + month breakdown (`counts`, `paid_days`, `work_value`, `opening`, `closing`, `payments[]`) |
| GET | `/staff/{id}/ledger?fy=2026` | monthly carry-forward rows + full-year `totals` |
| GET | `/staff/{id}/attendance?month=` | `{"2026-11-01": "P", …}` |
| GET / POST | `/staff/{id}/payments` | `date`, `type` (salary/advance/bonus/deduction), `amount`, `payment_mode` (not for deduction), `for_month?`, `note?` |
| PUT / DELETE | `/staff-payments/{id}` | DELETE owner only |
| POST | `/staff/{id}/share` | `regenerate?`, `pin?` ("1234" sets), `remove_pin?` (true clears) → `{share_url, has_pin}` |
| DELETE | `/staff/{id}/share` | turns the link off |
| GET | `/attendance?date=` | daily register: `[{staff_id, name, designation, photo_url, status}]`, `unmarked` |
| PUT | `/attendance` | `staff_id`, `date`, `status` (P/H/A/L/O or null to clear) |
| POST | `/attendance/mark-all` | `date` → marks everyone unmarked as P |

## Insights

| Method | Path | Notes |
|---|---|---|
| GET | `/ledger?from=&to=&source=&account=&direction=` | master ledger: `opening, closing, summary, accounts{cash,bank,online}, data[]` |
| GET | `/reports/{type}` | `monthly?month=` · `yearly?fy=` · `sales?from&to` · `expenses?from&to` · `vendor_dues` · `salary?month=` → `{title, subtitle, kpis, sections, notes, downloads{pdf,xlsx}}` |
| GET | `/reports/{type}.pdf` / `.xlsx` | same params; file download |
| GET | `/analytics?fy=` | annual summary: months, totals, compare, mom, cumulative, weekday, categories, best/worst days, insights |
| GET / POST | `/backups` | owner only; POST = back up now (restore is website-only on purpose) |

## Example

```bash
curl -X POST https://<domain>/api/v1/auth/login -H "Accept: application/json" \
     -d identifier=9876543210 -d password=•••• -d device_name="Pixel 8"
# → {"token":"3|Zx…","token_type":"Bearer","user":{…},"meta":{…}}

curl https://<domain>/api/v1/dashboard -H "Accept: application/json" -H "Authorization: Bearer 3|Zx…"
```

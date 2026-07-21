# Stage 4 — Unmapped Accounts Report (for Finance)

The workbook is format-only: it contains no account mappings, so **no ledger
line was bound to any chart-of-account account**. Nothing was guessed. Every
line below renders as `-` until Finance binds accounts in the Report Designer:

> **Accounting → Reporting → Report Templates → (template) → Lines → edit line
> → "Account Mapping"** — add one or more accounts with a `+`/`-` sign each. A
> parent account automatically includes its descendants.

The same list is always available live (and stays current as mappings are
completed) at **Accounting → Reporting → Mapping Review**.

## Sign convention reminder

Ledger balances are debit-positive. Credit-natured lines (revenue, equity,
liabilities) will show negative unless either the account binding's sign or
the line's sign is set to `-`. Decide the convention per line while mapping —
the workbook displays all of these as positive numbers.

## 1. BS Group — 12 unmapped lines

| Line | Notes |
|---|---|
| Property & Equipment | |
| Long term advances and deposits | |
| Investment - Openport / SPA Pay | Intercompany — also relevant to the consolidation policy (see unresolved item U7) |
| Trade Debts | |
| Advances & Deposits | |
| Cash & Bank | Should agree with the Cashflow "Beginning/Ending cash" account set |
| Un-appropriated (Loss) | Aureus never closes P&L to equity: bind **all income + expense accounts** (cumulative closing balance = retained + current earnings), matching how the legacy Balance Sheet page computes earnings |
| Issued, subscribed and paid-up capital | Credit-natured — sign |
| Share premium | Credit-natured — sign |
| Share Deposit Money | Credit-natured — sign |
| FX Gain / Loss | |
| Creditors, Accrued & Other Liabilities | Credit-natured — sign |

## 2. Cashflow Group — 7 unmapped lines

| Line | Notes |
|---|---|
| Beginning cash & cash equivalents | Opening balance of the Cash & Bank account set |
| Net income w/ FX | Definition needs confirmation (unresolved item U8); candidate: movement of all P&L accounts incl. FX |
| -- Receivables | Movement of the Trade Debts account set; **sign convention U5** |
| -- Advances | Movement of the Advances & Deposits set; sign U5 |
| -- Creditors and other liabilties | Movement of the Creditors set; sign U5 |
| Capex | Movement of PPE-related accounts; sign U5 |
| Advance against issue of share capital | Movement of Share Deposit Money set |

## 3. RiderShipline PNL — 11 unmapped lines

Revenue · Delivery Cost · Pickup Cost · Transportation · Salaries & Benefits ·
Utilities · Other Cost · Depreciation · Ammortization · Interest Expense ·
Income Tax

## 4. OP PNL — 7 unmapped lines

Revenue · Total Direct Cost · Salaries & Benefits · Utilities · Other Cost ·
Depreciation · Income Tax

## 5. TIN PNL — 23 unmapped lines

GMV · GST · Trucker's Commission · Fleet Subsidy · Customer Subsidy ·
Offline Mktg & Channels · Digital Mktg. · Financial Charges · Tech ·
Call Center & Support · Returns & Waivers · People · Real Estate ·
Travel & Entertainment · Professional Services · Misc. · Depreciation ·
Ammortization · Interest · Income Tax · Cost of Compliance ·
**OpenPort NI** · **Rider NI**

"OpenPort NI" and "Rider NI" are cross-entity lines: each carries a
**company override** (set automatically if a matching company exists — see
§7) and needs the counterpart entity's P&L account set bound.

## 6. Manual-value lines — need data entry, not mapping

| Template | Line |
|---|---|
| RiderShipline PNL | No. of Parcels, USD Rate |
| OP PNL | Volume, USD Rate |
| TIN PNL | USD Rate |

Enter monthly values in the designer under the line's **Manual Values**
section, or wire an external provider later (see External Providers page).

## 7. Entity → company resolution

The importer matches entity columns to ERP companies by name (exact
`TIN` / `Truck It In`, `Rider`, `OP` / `Openport` — never fuzzier). In this
environment **no companies matched**, so all entity columns and the two
cross-entity TIN PNL lines were imported without a company scope (they fall
back to the report run scope until set). Once the TIN / Rider / OpenPort
companies exist, set them on:

- BS Group + Cashflow Group columns (Columns tab → Company), and
- TIN PNL "OpenPort NI" / "Rider NI" lines (line form → Company Override).

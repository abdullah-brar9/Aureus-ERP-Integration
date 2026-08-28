# Stage 4 — Finance Review Report

The "Copy of Accounts 2025 Format.xlsx" workbook has been imported into
Aureus ERP **verbatim**: all six worksheets are now editable report templates
(rows, blank rows, bold, hierarchy, subtotals, check rows, entity/period
columns — nothing renamed, merged or simplified). The workbook copy contains
no formulas, values or account mappings, so those require Finance sign-off
before the reports are published.

## What Finance needs to do, in order

1. **Confirm the entity companies** (TIN / Rider / OpenPort) exist in the ERP
   and set them on the BS + Cashflow columns and the two cross-entity TIN PNL
   lines — see STAGE4_UNMAPPED_ACCOUNTS.md §7.
2. **Bind accounts** to the 60 unmapped ledger lines —
   STAGE4_UNMAPPED_ACCOUNTS.md (live progress: *Accounting → Reporting →
   Mapping Review*).
3. **Sign off the inferred formulas and the open questions U1–U10** —
   STAGE4_UNRESOLVED_FORMULAS.md.
4. **Enter manual series** (No. of Parcels, Volume, USD Rate) or nominate a
   data source for each.
5. Run **Validate** on each template (template edit page), preview it in
   *Financial Reports*, confirm the BS "Check" row is green, then set the
   template's status to **Published**.

## Where things are in the ERP

| Task | Location |
|---|---|
| Edit layout, rows, columns | Accounting → Reporting → Report Templates |
| Bind accounts / edit formulas / manual values | Report Templates → template → Lines → edit a line |
| Entity & consolidated columns | Report Templates → template → Columns |
| Render any report | Accounting → Reporting → Financial Reports |
| Outstanding mapping/formula issues (live) | Accounting → Reporting → Mapping Review |
| KPI/external feeds | Accounting → Reporting → External Providers |

## "Notes" sheet — owner assignments (workbook column B, preserved here)

| Area | Owner |
|---|---|
| Revenue | Ali/Hania |
| Sales Tax | Hania/Khurram |
| Direct Cost - Vendors | Hania |
| Expenses / Admin / Professional Services / People / Misc / Other Income / Income Tax / Finance Costs | Khurram |
| PPE | Khurram |
| Advance & deposits | Hania/Khurram |
| Long Term Adv & Dep | Hania/Khurram |
| Trade Debts (Receivables) | Hania/Khurram |
| Taxation | Khurram |
| Cash & Bank | Khurram |
| Share Capital / Share Premium / Retained Earnings / Advance Against Equity / Accrued and other Liab | Khurram |
| Workings (Sales/Income Tax, Bank Reconciliations, Costings, Variance Analysis, Monthly Tax Reporting, MIS Reconciliation) | Khurram |

## Guarantees

- Captions are byte-identical to the workbook (including "Ammortization",
  "liabilties" and the `-` / `--` prefixes). Blank rows and the spacer column
  between the Jun/Dec groups are preserved.
- Everything above is editable in the Report Designer — publishing, layout
  changes, mappings and formulas never require code changes.
- Reports stay red-flagged (draft banner + validation issues) until the
  review is complete, so nothing half-configured can be mistaken for final.

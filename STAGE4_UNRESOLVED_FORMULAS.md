# Stage 4 — Unresolved / Inferred Formulas Report (for Finance)

The workbook holds **no live formulas** (it is a format-only copy), so no
formula could be *imported*. Every subtotal below was therefore created from
the arithmetic chain implied by the row layout. **These are placeholders for
sign-off, not confirmed financial logic.** Edit any of them in the Report
Designer (line → Formula section); each operand is one row folded
left-to-right.

## A. Inferred subtotal chains — confirm or correct each one

### TIN PNL
| Line | Imported formula |
|---|---|
| GM | GMV − GST − Trucker's Commission |
| NR | GM − Fleet Subsidy − Customer Subsidy |
| CM1 | NR − Offline Mktg & Channels − Digital Mktg. |
| CM 2 | CM1 − Financial Charges − Tech − Call Center & Support − Returns & Waivers |
| EBITDA | CM 2 − People − Real Estate − Travel & Entertainment − Professional Services − Misc. |
| EBIT | EBITDA − Depreciation − Ammortization |
| EBTDA | EBIT − Interest |
| TIN NI | EBTDA − Income Tax − Cost of Compliance |
| Total NI | TIN NI + OpenPort NI + Rider NI |

### RiderShipline PNL
| Line | Imported formula |
|---|---|
| RPS | Revenue ÷ No. of Parcels |
| Total Direct Cost | Delivery Cost + Pickup Cost + Transportation |
| GP | Revenue − Total Direct Cost |
| Ebitda | GP − Salaries & Benefits − Utilities − Other Cost |
| NI | Ebitda − Depreciation − Ammortization − Interest Expense − Income Tax |

### OP PNL
| Line | Imported formula |
|---|---|
| GP | Revenue − Total Direct Cost |
| Ebitda | GP − Salaries & Benefits − Utilities − Other Cost |
| NI | Ebitda − Depreciation − Income Tax |

### BS Group
| Line | Imported formula |
|---|---|
| Total Assets | sum of the six asset detail lines |
| Total Equity and Liabilities | sum of the six equity + liability detail lines |
| Check | Total Assets − Total Equity and Liabilities *(flagged as a check row: highlighted red unless ≈ 0)* |

### Cashflow Group
| Line | Imported formula |
|---|---|
| - (Increase) / decrease in current assets | -- Receivables + -- Advances |
| - Increase / (decrease) in current liabilties | -- Creditors and other liabilties |
| Working capital changes | the two lines above |
| Cash from Operations | Net income w/ FX + Working capital changes |
| Cash from Investing | Capex |
| Cash from Financing | Advance against issue of share capital |
| Net change | CFO + CFI + CFF |
| Ending cash & cash equivalents | Beginning cash + Net change |

## B. Open questions requiring a Finance decision

- **U1 — Cashflow column periods.** The workbook's "June"/"Dec" cashflow
  columns were imported as **Jan–Jun and Jul–Dec** (half-year split, so the
  Dec BS reconciles from the Jun BS). If they are instead YTD (Jan–Jun and
  Jan–Dec), change each Dec-group column's range start from 7 to 1 in the
  designer.
- **U2 — Ending cash.** Imported as *Beginning cash + Net change*. The
  alternative — closing balance of the Cash & Bank account set — turns the
  line into an independent control; you could also add a second (check) row
  computing the difference.
- **U3 — OP "Total Direct Cost".** In the OP sheet this row has no component
  rows, so it was imported as a **ledger detail line**, not a subtotal.
  Confirm.
- **U4 — RPS.** Interpreted as Revenue ÷ No. of Parcels. Confirm the
  numerator (Revenue vs NR vs GMV) and that a division-by-zero month should
  display "-" (engine returns 0 on division by zero).
- **U5 — Cashflow sign conventions.** All working-capital and capex lines are
  imported as raw movements with sign `+`. Indirect-method presentation
  usually flips asset movements (an increase in receivables shows as an
  outflow). Set line/binding signs during mapping so the workbook's
  "(Increase) / decrease" presentation is reproduced. **Not assumed.**
- **U6 — USD Rate "Total" column.** Manual values sum into range columns, so
  the yearly Total of a monthly *rate* series is meaningless. Options: leave
  the Total unused for that row, replace with an average via a formula line,
  or feed rates from an external provider that defines its own totalling.
- **U7 — Consolidation / eliminations.** Consolidated columns are currently
  the plain multi-entity sum (the workbook's stated default). Where
  eliminations are required (e.g. "Investment - Openport / SPA Pay" vs the
  counterpart equity), add **Consolidation Override** operands on the
  affected line — they replace the default only in consolidated columns.
  No elimination amounts were invented.
- **U8 — "Net income w/ FX".** Needs a definition: movement of all P&L
  accounts including FX, or TIN NI ± FX line? Currently an unmapped ledger
  line.
- **U9 — Header cells.** BS sheet title cell A1 "TRUCK IT IN" is kept in the
  template description (the ERP shows the template name as the heading). The
  cashflow title cell A3 "Cash Flow Statement USD (Consolidated)" is imported
  as the report's first (header) line.
- **U10 — "Notes" sheet.** Imported verbatim as a caption-only template.
  Owner names from column B are recorded in STAGE4_FINANCE_REVIEW.md — the
  report model has no owner field (deliberately: it is not a financial
  statement).

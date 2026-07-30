# Configurable Import Platform

## Purpose

The accounting plugin now has a company-scoped import platform for CSV, XLS, and XLSX files. It preserves the existing Partner, Employee, Move, MoveLine, BankStatement, BankStatementLine, Account, Journal, Payment, ledger, currency, and reporting engines. The import layer is configuration and orchestration; it is not a replacement accounting engine.

## Workflow

1. Create an inactive Import Profile under Accounting > Configuration.
2. Configure the sheet/header/data rows, blank-row behavior, source columns, canonical fields, transformations, and validation checks.
3. Optionally configure prioritized Import Rules. Conditions and actions use a fixed whitelist; executable code is not accepted.
4. Activate one version of a named profile.
5. Upload a source file from the profile action. The system creates an Import Run and stores PASS, WARNING, or ERROR for every source row.
6. Review raw values, transformed values, messages, source row numbers, profile version, filename, SHA-256 hash, and eventual ERP record links.
7. Confirm only a preview with no errors. Confirmation runs in a database transaction and never auto-posts accounting documents.

Profile definitions can be versioned, exported to JSON, and imported into the active company as a new inactive profile. Active versions are protected from direct editing.

## Supported canonical targets

- Vendors and customers create or reuse company-owned Partner records. Supplier/customer rank is set without creating a GL per party. Payment terms are linked when an existing company term matches. Classification, sector, and category are normalized in the party classification master.
- Employees create or reuse company-owned Employee records and reuse/create canonical Department records.
- Invoices, bills, claims, and miscellaneous documents create draft Move records with two balanced MoveLine records using explicit company-owned debit and credit GL mappings. Foreign-currency amounts require an existing approved exchange rate. Nothing is posted automatically.
- Bank statement rows are grouped into one NormalizedBankStatement and passed through the existing BankStatementImportService, exchange-rate, validation, duplicate, reconciliation, and mapping controls.

## FS Tags and bank matching

FS Tags are company-scoped, normalized, unique labels that can map to an active postable GL. An FS Tag can link an existing GL or create a canonical GL through the existing account creation service. Blank tag and GL codes use lock-protected structured numbering.

The Bank Transaction Mapping form shows initial valid options, supports search and selected-label resolution, and can create/select an FS Tag inline. Approval resolves the FS Tag to its mapped GL and inherits cash-flow/tax metadata. Free-text IDs are never accepted.

Priority matching runs in this order:

1. exact open-document reference plus amount;
2. exact registered-payment reference plus amount;
3. internal bank-transfer detection;
4. active company/currency mapping rules;
5. manual review.

Suggestions remain reviewable and do not bypass approval or posting permissions.

## Security and data integrity

- Every profile, rule, run, row, classification, and FS Tag carries company scope.
- Selected journals, accounts, parties, and bank targets are checked against the run company.
- Import source rows retain raw and transformed data plus canonical lineage.
- Configuration changes are appended to `accounting_configuration_audits`.
- Fixed-precision decimal operations use Brick Math in transformation and bank/document adapters.
- Database uniqueness and transactions protect profile versions, source rows, FS Tags, and import confirmation.
- Failed previews cannot be confirmed. Reconfirming a completed run is rejected.
- Imported accounting documents stay draft; the canonical approval/posting lifecycle remains authoritative.

## Current operational boundary

Payments continue to use the canonical Payment Register and Payment workflow rather than being created directly by the generic row adapter. AR/AP aging, partner ledger, open invoices/bills, residual balances, and settlement status continue to come from canonical posted Move/MoveLine/Payment data. This avoids a parallel receivables, payables, or payment engine.

## Migration and recovery

The forward-only migration is `2026_07_28_000002_implement_configurable_import_platform`. It was first applied to `aureuserp_testing`, then to the primary database after the isolated migration and focused tests passed.

Pre-migration backup:

`storage/app/backups/pre-data-platform-20260728-222706/aureuserp.sql`

SHA-256:

`7DB99FE9EC09F1AD70180313AC1689A5B443CA5442A636137EFF867D7C79BD70`

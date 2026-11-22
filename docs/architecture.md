# Spendwise architecture

## Application boundaries

- Laravel and Fortify own session authentication, verified routes, request validation, authorization, and private file downloads.
- The finance domain owns ledger postings, budget calculations, savings activity, recurring execution, import validation, alerts, and exact currency conversion.
- Administrative routes use a separate gate and return platform counts and member metadata. Financial rows are scoped to the authenticated workspace.
- Inertia passes validated application data to Vue. Charts receive numeric projections for visualization while financial calculations and authoritative values remain server-side.
- PostgreSQL is the deployment database. CI runs backend feature tests against it. In-memory SQLite is the fast local test default.

## Product design

Spendwise uses aubergine, warm paper and restrained mint. Large tabular balances, concise labels and visible ledger detail anchor the financial workspace. Themes use semantic tokens and respect the device preference. The public site and application share typography and identity.

## Financial model

Every account has an owner and currency. A transaction records its business context and owns signed ledger postings. Account balance is opening balance plus posted movements. Transfers write both postings inside one database transaction, with an explicit destination amount for a cross-currency transfer. Income and expense reporting counts transaction types explicitly. Currency totals remain separate unless an explicit rate is available. Amounts are stored as decimal strings and calculations use exact decimal arithmetic. Budgets count eligible expenses in their own period and currency. Savings contributions use a separate ledger.

## Sensitive operations

Authorize record access on the server and scope all related lookups to the same owner. Validate money and currency precision before writing. Lock affected rows in deterministic order during multi-record operations. Scheduled occurrences need database-level idempotency. Store uploaded financial documents privately and authorize downloads. Keep administration separate from users' financial records. Encrypt persisted provider credentials and keep them out of frontend payloads and audit metadata.

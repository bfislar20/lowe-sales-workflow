# Lowe Sales Workflow Dependency Audit

Branch: `reporting-architecture-v1`

## Purpose

This document records the runtime dependencies found in the current Lowe Sales Workflow source. Production credentials are intentionally excluded from GitHub.

## Shared reporting stack

### Excel / Lowe Master

- `predictive-order-engine.php`
  - dependency-free XLSX reader
  - invoice/open-order forecast engine
- `lowe-dashboard-common.php`
  - shared Lowe Master locator
  - shared worksheet reader wrappers
  - common report header/footer/navigation
  - common purchase-cost helpers
- `inventory-model-v2.php`
  - authoritative shared inventory model
  - uses Inventory, Invoices, Purchases, Open Sales Orders, Open Purchase Orders, and Part Master

### Inventory pages using Inventory Model V2

- `management-dashboard.php`
- `inventory-dashboard.php`
- `inventory-risk.php`
- `slow-inventory.php`
- `open-po-dashboard.php`
- `open-orders.php`

`inventory-detail.php` intentionally retains specialized native-UOM quantity and lot-level detail logic, but now uses the shared Lowe Master locator/reader.

## Sales Intelligence

These reports use the shared Lowe Master source and include both Invoiced and Credit rows where they represent net activity:

- `ytd-dashboard.php`
- `customer-13.php`
- `ytd-variance.php`
- `profitability.php`
- `sales-risk.php`

## Purchasing

These reports now use the shared purchase-cost conventions:

- `supplier-scorecard.php`
- `vendor-summary.php`
- `vendor-purchase-report.php`
- `cost-trend.php`
- `cdn.php`

Shared rule:
- purchase pounds = LBs Received
- purchase spend = Total Item Cost
- Cost/LB = valid direct Cost/LB when present; otherwise Total Item Cost / LBs Received

## Database-driven applications

### Quotes

Files currently in repository:
- `pricequote.php`
- `quotes.php`
- `opportunity-quote-sync.php`
- `api/customer_add.php`

Production-only dependency:
- `config/database.php` exposing `db(): PDO`

Safe template:
- `config/database.example.php`

Database tables referenced:
- `quote_records`
- `quote_record_items`

### Samples

Files currently in repository:
- `samples.php`
- `sampletracking.php`
- `samplerequest.php`

Production-only dependency:
- `config/database.php` exposing `db(): PDO`

Database table:
- `sample_records`

Important architecture note:
The current sample pages execute CREATE TABLE / ALTER TABLE schema updates during normal page requests. A future cleanup should move database migrations into a separate setup/migration process so ordinary report traffic does not modify schema.

### Opportunities

Files currently in repository:
- `opportunities.php`
- `opportunity-new.php`
- `opportunity-tasks.php`
- `opportunity-dashboard.php`
- `opportunity-report.php`
- `opportunity-funnel.php`
- `opportunity-closed.php`
- `opportunity.php`
- `opportunity-create-quote.php`
- `opportunity-quote-sync.php`

Production-only dependencies:
- `opportunity-config/app.php`
- `opportunity-config/database.php`

Safe templates:
- `opportunity-config/app.example.php`
- `opportunity-config/database.example.php`

Database tables referenced:
- `opportunities`
- `opportunity_tasks`
- `opportunity_quotes`
- `opportunity_activities`

### Sourcing / RFQs

Files currently in repository:
- `vendor-pricing-request.php`
- `rfq-list.php`
- `save-rfq.php`
- `download-rfqs-xlsx.php`
- `email-rfqs-xlsx.php`
- `rfq-view.php`
- `rfq-responses.php`
- `rfq-edit.php`
- `delete-rfq.php`
- `send-rfq.php`
- `rfq-comparison.php`
- `update-rfq.php`
- `clone-rfq.php`
- `sourcing-functions.php`

Production-only dependency:
- `config/db.php` exposing `db()`

Safe template:
- `config/db.example.php`

Database tables referenced:
- `rfqs`
- `rfq_suppliers`
- `rfq_supplier_responses`

## Credential policy

The following paths must remain outside GitHub:

- `config/database.php`
- `config/db.php`
- `opportunity-config/app.php`
- `opportunity-config/database.php`
- `.env`
- `.env.*`

Only `.example.php` templates belong in source control.

## Current unresolved runtime files

No non-secret PHP application files are currently unresolved. The production-only configuration files listed above remain intentionally absent from source control.

## Recommended next phase

1. Leave credentials on SiteGround only.
2. Audit database schema ownership and move runtime schema changes out of Samples.
3. Standardize navigation/header helpers across Quotes, Samples, Opportunities, and RFQs.
4. Add syntax/static checks before merging the development branch into main.


## Cross-reference audit status

A repository-wide pass over PHP page links, form actions, script sources, and JavaScript fetch calls found no remaining references to non-secret PHP application files outside the repository.

The only intentionally absent runtime PHP files are production configuration/credential files:

- `config/database.php`
- `config/db.php`
- `opportunity-config/app.php`
- `opportunity-config/database.php`

Safe `.example.php` templates exist in the development branch for these configuration families.

`api/customer_add.php` is present at the path expected by `pricequote.php`.

The legacy `inventory-model.php` remains in the development branch for rollback/reference, but current inventory reports have been standardized on `inventory-model-v2.php` or the shared Lowe Master reader as documented above.

At this point, the development branch is structurally complete enough for code cleanup and testing without requiring additional non-secret PHP files from production.

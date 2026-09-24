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

Production-only dependency:
- `config/database.php` exposing `db(): PDO`

Safe template:
- `config/database.example.php`

Additional missing runtime dependency referenced by Price Quote:
- `api/customer_add.php`

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
- `opportunity-quote-sync.php`

Production-only dependencies:
- `opportunity-config/app.php`
- `opportunity-config/database.php`

Safe templates:
- `opportunity-config/app.example.php`
- `opportunity-config/database.example.php`

Additional application pages referenced by `opportunities.php` but not yet in GitHub:
- `opportunity-new.php`
- `opportunity-tasks.php`
- `opportunity-dashboard.php`
- `opportunity-report.php`
- `opportunity-funnel.php`
- `opportunity-closed.php`
- `opportunity.php`

Database tables referenced:
- `opportunities`
- `opportunity_tasks`
- `opportunity_quotes`
- `opportunity_activities`

### Sourcing / RFQs

Files currently in repository:
- `vendor-pricing-request.php`
- `rfq-list.php`

Production-only dependency:
- `config/db.php` exposing `db()`

Safe template:
- `config/db.example.php`

Additional runtime pages referenced but not yet in GitHub:
- `save-rfq.php`
- `download-rfqs-xlsx.php`
- `email-rfqs-xlsx.php`
- `rfq-view.php`
- `rfq-responses.php`
- `rfq-edit.php`
- `delete-rfq.php`

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

The codebase cannot yet be treated as a complete deployable copy because these non-secret application files are still missing from GitHub:

- `api/customer_add.php`
- `opportunity-new.php`
- `opportunity-tasks.php`
- `opportunity-dashboard.php`
- `opportunity-report.php`
- `opportunity-funnel.php`
- `opportunity-closed.php`
- `opportunity.php`
- `save-rfq.php`
- `download-rfqs-xlsx.php`
- `email-rfqs-xlsx.php`
- `rfq-view.php`
- `rfq-responses.php`
- `rfq-edit.php`
- `delete-rfq.php`

## Recommended next phase

1. Import the missing non-secret application files listed above.
2. Leave credentials on SiteGround only.
3. Audit database schema ownership and move runtime schema changes out of Samples.
4. Standardize navigation/header helpers across Quotes, Samples, Opportunities, and RFQs.
5. Add syntax/static checks before merging the development branch into main.

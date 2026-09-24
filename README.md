# Lowe Sales Workflow

Private source repository for Lowe Chemical Company's internal sales, purchasing, inventory, forecasting, quoting, sampling, and sourcing tools.

## Purpose

This repository is the source of truth for the PHP pages linked from `salesworkflow.php`.

The reporting system is designed around the Lowe Master workbook and shared PHP components so changes can be made consistently across all reports.

## Core principles

- Keep the Lowe Master workbook as the operating data source.
- Keep report logic in source control.
- Reuse shared helpers for navigation, filters, Excel exports, detail views, and styling.
- Every internal report must include a visible **Back to Sales Workflow** link.
- Reports must work on desktop and mobile.
- Customer, product, and supplier selectors should support partial-name search and multiple selections where useful.
- Detail actions should open in a new tab when the detail is a separate analytical view.
- Credits should be netted where the report represents sales volume or sales dollars.
- Product-description reporting should group by Product Name unless Product Number is explicitly required.
- Production changes should be reviewed before deployment to SiteGround.

## Repository layout

```
/
  README.md
  LOWE-REPORTING-STANDARDS.md
  report-registry.php
  config/
    lowe-reporting.php
  includes/
    README.md
  reports/
    README.md
```

## Workflow categories

1. Predict
2. Sales Intelligence & Customer Reports
3. Purchasing Reports
4. Inventory Management
5. Quotes
6. Samples
7. Sourcing / RFQs

## Data flow

`Lowe Master.xlsx -> shared data/helpers -> PHP reports -> salesworkflow.php`

## Deployment

GitHub is the master source copy. SiteGround is production. Changes should be tested before upload or deployment.

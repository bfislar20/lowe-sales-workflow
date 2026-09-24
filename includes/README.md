# Shared Includes

Place reusable PHP components here rather than copying the same implementation into individual reports.

Planned components include:

- common report header/footer and Sales Workflow navigation
- workbook/schema helpers
- searchable multi-select controls
- Excel/CSV export helpers
- detail-view helpers
- reusable KPI and chart components

Existing production helpers such as `lowe-dashboard-common.php` and the inventory model should be imported here only after their current live versions are reviewed.

# Lowe Reporting Standards

## 1. Scope

These standards apply to the internal PHP pages linked from `salesworkflow.php` and to shared reporting components.

## 2. Navigation

- Every internal page must have a clearly visible **Back to Sales Workflow** button or link to `/salesworkflow.php`.
- Keep the seven workflow categories in this order:
  1. Predict
  2. Sales Intelligence & Customer Reports
  3. Purchasing Reports
  4. Inventory Management
  5. Quotes
  6. Samples
  7. Sourcing / RFQs
- Opportunities belongs under Sales Intelligence & Customer Reports.
- Sample Database, Sample Tracking, and Customer Sample Request belong under Samples.

## 3. Device support

- Reports must be responsive.
- Tables may become cards or horizontally scroll on narrow screens.
- Important controls must remain usable on a phone.
- Do not rely on hover-only interactions.

## 4. Filters

Where relevant:
- Customer, product, supplier, and sales-rep filters should allow partial-name matching.
- Product and supplier filters should support multiple selections when analysis commonly spans several values.
- Provide a clear/reset action.
- Preserve selected filters during sorting and pagination.

## 5. Detail views

- Use a clearly labeled **Detail** or **View Details** action.
- Separate detail views should open in a new browser tab.
- PO detail should show supplier, receipt date, PO number, release, product number, quantities, UOM, pounds, cost/lb, and total cost when available.
- Invoice detail should show invoice date/number, customer, product, pounds, selling price/lb, sales, profit, and GP where available.

## 6. Sales calculations

- Sales and volume reporting should include Invoiced and Credit document types and net credits using their signed values.
- YTD comparisons use the latest invoiced date as the current-year cutoff and the corresponding calendar date in the prior year.
- Product-description analysis groups by Product Name unless the report explicitly needs Product Number.

## 7. Inventory model

Current Inventory is lot-level. Use:
- Product Name
- Product Number
- CAS Number
- Product UOM
- Lot Number
- Qty
- Cost Per LB
- Unit Cost
- Total Cost
- LB per Unit
- Total LBs
- Receipt Date
- Expire Date
- MIN
- MAX
- Lead Time (Days)

Derived conventions:
- On Hand pounds = sum Inventory Total LBs.
- On Hand quantity = sum Inventory Qty when the report explicitly asks for units/quantity.
- Inventory value = sum actual Total Cost.
- Inventory aging = actual lot Receipt Date.
- Expiration = actual Expire Date.
- Allocated/open demand = Open Sales Order pounds when using pounds.
- Available = On Hand - Open Sales Orders.
- Open Purchase Orders are inbound supply.
- Projected available = On Hand + Open PO - Open SO.
- Use MIN/MAX and Lead Time only when populated; do not invent missing policy values.

## 8. Purchases

Current purchase reporting should prefer the current Lowe Master column names:
- PO Number
- Release Number
- Supplier Name
- Supplier Number
- Receipt Date
- Product Name
- Product Number
- Purchasing UOM
- Qty Ordered
- Qty Received
- LBs Received
- Total Item Cost
- LBs Per Stocking Unit

When Cost/LB is unavailable, calculate it as Total Item Cost divided by LBs Received when pounds are nonzero.

## 9. Visual standards

- Use clear section separators and restrained category color coding.
- Positive variances may be green; negative variances red; warnings orange.
- Use charts only when they improve interpretation.
- Bar charts are preferred for YTD vs PYTD comparisons.
- Line charts are preferred for monthly trends.
- Pie/donut charts should be limited to true part-to-whole measures.
- Avoid external chart-library dependencies when a native PHP/CSS implementation is practical.

## 10. Exports

- Excel/CSV export should reflect the active report filters unless explicitly labeled otherwise.
- Full filtered exports should not be limited to the current pagination page.

## 11. Code organization

Prefer shared components over duplicated logic:
- common header/footer/navigation
- workbook/schema helpers
- filter controls
- export helpers
- detail-view helpers
- chart/visual helpers

Do not use customer-order forecast JSON as inventory purchasing data.

## 12. Testing

Before a PHP revision is considered ready:
- run PHP syntax validation;
- verify required includes;
- verify workbook sheet/column names;
- verify navigation;
- check mobile behavior conceptually or in a browser when available;
- avoid stale caches after schema/model changes.

## 13. Change safety

- GitHub is the master source copy.
- SiteGround is production.
- Do not silently deploy unreviewed changes to production.
- Preserve existing working functionality unless the requested change explicitly replaces it.

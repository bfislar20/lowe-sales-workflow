<?php
declare(strict_types=1);

/**
 * Lowe Sales Workflow report registry.
 *
 * This is the single source of truth for the report cards shown on
 * salesworkflow.php. Keep categories in workflow order.
 */
return [
 'Predict' => [
  [
   'title'=>'Predictive Customer Order Report',
   'description'=>'Forecast which customers are likely to order next based on their actual purchase history, typical order quantity, buying interval, open sales orders, and prediction confidence.',
   'url'=>'predictive-orders.php',
   'label'=>'Open Predictive Order Report'
  ],
  [
   'title'=>'Update Predictive Order Data',
   'description'=>'Upload the latest Lowe Master Excel workbook and rebuild the customer order predictions used by the live report.',
   'url'=>'predictive-orders-admin.php',
   'label'=>'Upload Master Excel File'
  ]
 ],

 'Sales Intelligence & Customer Reports' => [
  [
   'title'=>'YTD Performance Dashboard',
   'description'=>'Quick year-to-date comparison of pounds, sales, profit, customer count, and unique products versus the same period last year, with a sales rep filter.',
   'url'=>'ytd-dashboard.php',
   'label'=>'Open YTD Dashboard'
  ],
  [
   'title'=>'Customer Monthly Volume Report',
   'description'=>'Review customer volume by product description for a selectable 1 to 13 month period, with monthly pounds and total pounds for the selected period.',
   'url'=>'customer-13.php',
   'label'=>'Open Customer Volume Report'
  ],
  [
   'title'=>'YTD vs PYTD Variance',
   'description'=>'Compare current year-to-date customer and product volume against the same period last year, with variance and Excel export.',
   'url'=>'ytd-variance.php',
   'label'=>'Open YTD Variance Report'
  ],
  [
   'title'=>'Customer / Product Profitability',
   'description'=>'Analyze volume, sales, gross profit dollars, GP percent, sales per pound, and profit per pound by customer and product.',
   'url'=>'profitability.php',
   'label'=>'Open Profitability Report'
  ],
  [
   'title'=>'Sales Risk / Lost Business',
   'description'=>'Identify lost, declining, growing, and new customer-product business using YTD versus prior-YTD volume and sales variance.',
   'url'=>'sales-risk.php',
   'label'=>'Open Sales Risk Report'
  ],
  [
   'title'=>'Sales Opportunities',
   'description'=>'Create a new opportunity or review and update existing opportunities.',
   'url'=>'opportunities.php',
   'label'=>'Open Opportunities'
  ]
 ],

 'Purchasing Reports' => [
  [
   'title'=>'Purchasing & Supplier Scorecard',
   'description'=>'Compare supplier spend YTD vs PYTD, purchase price movement, supplier concentration, single-source product exposure, and receipt fill rate.',
   'url'=>'supplier-scorecard.php',
   'label'=>'Open Supplier Scorecard'
  ],
  [
   'title'=>'Vendor Purchase Summary',
   'description'=>'Review supplier purchase volume and spend by product across a selectable 1 to 13 month period, with monthly pounds, total pounds, and total spend.',
   'url'=>'vendor-summary.php',
   'label'=>'Open Vendor Purchase Summary'
  ],
  [
   'title'=>'Vendor Purchase Report',
   'description'=>'Review calendar-year supplier and product purchase volume by month, filter by multiple suppliers or products, and drill into received and open purchase order detail.',
   'url'=>'vendor-purchase-report.php',
   'label'=>'Open Vendor Purchase Report'
  ],
  [
   'title'=>'Purchase Cost Trend',
   'description'=>'Track weighted average cost per pound by supplier and product over the last 13 months, including latest cost and change versus trailing averages.',
   'url'=>'cost-trend.php',
   'label'=>'Open Purchase Cost Trend'
  ],
  [
   'title'=>'CDN Member Activity',
   'description'=>'Review Lowe purchases from and sales to Chemical Distribution Network members, with drill-down access to PO, receipt, invoice, and transaction detail.',
   'url'=>'cdn.php',
   'label'=>'Open CDN Report'
  ]
 ],

 'Inventory Management' => [
  [
   'title'=>'Management Dashboard',
   'description'=>'Executive view of YTD sales, profit, purchasing, inventory, open sales orders, open purchase orders, negative inventory positions, and the largest sales declines.',
   'url'=>'management-dashboard.php',
   'label'=>'Open Management Dashboard'
  ],
  [
   'title'=>'Inventory Action Dashboard',
   'description'=>'Daily purchasing command center showing recommended buys, days supply, available inventory, open sales orders, inbound purchase orders, forecast demand, and supplier.',
   'url'=>'inventory-dashboard.php',
   'label'=>'Open Inventory Dashboard'
  ],
  [
   'title'=>'Inventory Detail Dashboard',
   'description'=>'Review current inventory by product with weighted cost per pound, lot count, quantity on hand, open sales orders, open purchase orders, projected quantity, and transaction-level drill-down.',
   'url'=>'inventory-detail.php',
   'label'=>'Open Inventory Detail'
  ],
  [
   'title'=>'Inventory Risk / Shortage',
   'description'=>'Focus on products where available and inbound supply may not cover committed orders and near-term forecast demand.',
   'url'=>'inventory-risk.php',
   'label'=>'Open Inventory Risk Report'
  ],
  [
   'title'=>'Excess & Slow-Moving Inventory',
   'description'=>'Identify excess inventory, months of supply, estimated inventory value, last sale, supplier, and customers that historically purchased the product.',
   'url'=>'slow-inventory.php',
   'label'=>'Open Slow Inventory Report'
  ],
  [
   'title'=>'Open PO / Incoming Inventory',
   'description'=>'Review inbound purchase orders by PO, supplier and product alongside current available inventory and open customer demand.',
   'url'=>'open-po-dashboard.php',
   'label'=>'Open Incoming Inventory Report'
  ],
  [
   'title'=>'Open Sales Orders / Allocation',
   'description'=>'Review customer commitments, ship dates, allocated and available inventory, open PO supply, and products with negative availability.',
   'url'=>'open-orders.php',
   'label'=>'Open Sales Order Allocation'
  ]
 ],

 'Quotes' => [
  [
   'title'=>'Create a Price Quote',
   'description'=>'Prepare a new customer quote or create one directly from a customer sample.',
   'url'=>'pricequote.php',
   'label'=>'Create Price Quote'
  ],
  [
   'title'=>'Saved Quote History',
   'description'=>'Search, open, edit, review, or delete saved customer price quotes.',
   'url'=>'quotes.php',
   'label'=>'View Saved Quotes'
  ]
 ],

 'Samples' => [
  [
   'title'=>'Sample Database',
   'description'=>'Create, manage, track, follow up, evaluate, quote, and convert customer product samples.',
   'url'=>'samples.php',
   'label'=>'Open Sample Database'
  ],
  [
   'title'=>'Sample Tracking',
   'description'=>'Review requested samples, shipping progress, follow-up dates, evaluation status, and sample-to-quote activity.',
   'url'=>'sampletracking.php',
   'label'=>'Open Sample Tracking'
  ],
  [
   'title'=>'Customer Sample Request',
   'description'=>'Open the customer-facing product sample request form for customer sample submissions.',
   'url'=>'samplerequest.php',
   'label'=>'Open Sample Request Form',
   'public'=>true
  ]
 ],

 'Sourcing / RFQs' => [
  [
   'title'=>'Create Supplier RFQ',
   'description'=>'Build a supplier pricing request with product, CAS number, packaging, application, quantity, delivery location, suppliers, and Lowe email recipients.',
   'url'=>'vendor-pricing-request.php',
   'label'=>'Create Supplier RFQ'
  ],
  [
   'title'=>'RFQ Dashboard',
   'description'=>'View all supplier RFQs, check email activity, edit requests, continue to email drafting, resend requests, review responses, compare quotes, or delete RFQs.',
   'url'=>'rfq-list.php',
   'label'=>'Open RFQ Dashboard'
  ],
  [
   'title'=>'AI Supplier Email Draft',
   'description'=>'Draft and edit the supplier email after the RFQ has been saved. Each supplier receives an individual email with a secure pricing-response link.',
   'url'=>'rfq-list.php',
   'label'=>'Choose RFQ to Draft Email'
  ],
  [
   'title'=>'Supplier Pricing Responses',
   'description'=>'Review pricing submitted by suppliers through their secure response links. Supplier submissions are stored in the sourcing database and Lowe receives an email notification.',
   'url'=>'rfq-list.php',
   'label'=>'Choose RFQ to View Responses'
  ],
  [
   'title'=>'Supplier Quote Comparison',
   'description'=>'Compare responding suppliers side by side using price, freight terms, FOB point, minimum order, lead time, availability, payment terms, origin, manufacturer, expiration, and surcharges.',
   'url'=>'rfq-list.php',
   'label'=>'Choose RFQ to Compare'
  ],
  [
   'title'=>'Supplier Response Portal',
   'description'=>'Suppliers receive a unique secure URL in their RFQ email. The link identifies the RFQ and supplier, collects pricing, and stores the response without exposing other suppliers.',
   'url'=>'rfq-list.php',
   'label'=>'Manage Supplier RFQs',
   'info'=>true
  ]
 ]
];

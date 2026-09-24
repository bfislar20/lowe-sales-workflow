<?php
/*
 * Lowe Sales Workflow
 * Place this page inside the website's password-protected area.
 * If a module filename changes, update only the matching URL below.
 */

$workflowSections = [

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

$sectionAnchors = [
 'Predict' => 'predict',
 'Sales Intelligence & Customer Reports' => 'sales-intelligence-customer-reports',
 'Purchasing Reports' => 'purchasing-reports',
 'Inventory Management' => 'inventory-management',
 'Quotes' => 'quotes',
 'Samples' => 'samples',
 'Sourcing / RFQs' => 'sourcing-rfqs'
];

function h($value): string {
 return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Lowe Sales Workflow</title>

<style>
:root{
 --navy:#0B2A5B;
 --blue:#174F8A;
 --red:#D71920;
 --green:#237a45;
 --green-dark:#195d34;
 --bg:#f2f5f7;
 --line:#d3dde5;
 --muted:#60717d;
}

*{box-sizing:border-box}

body{
 margin:0;
 background:var(--bg);
 font-family:Arial,sans-serif;
 color:#1d2935
}

.page{
 max-width:1180px;
 margin:auto;
 padding:22px
}

.top{
 background:linear-gradient(135deg,var(--navy),#16477f);
 border-top:6px solid var(--red);
 border-radius:14px;
 color:#fff;
 padding:24px 26px;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:24px
}

.brand{
 display:flex;
 align-items:center;
 gap:20px
}

.logo{
 display:block;
 width:220px;
 max-height:76px;
 object-fit:contain;
 background:#fff;
 border-radius:8px;
 padding:10px 14px
}

.top h1{
 font-size:30px;
 margin:0
}

.top p{
 color:#dce8f1;
 line-height:1.45;
 margin:7px 0 0
}

.security{
 white-space:nowrap;
 background:#ffffff18;
 border:1px solid #ffffff30;
 border-radius:999px;
 padding:8px 12px;
 font-size:12px;
 font-weight:700
}

.intro{
 margin:18px 0;
 background:#fff;
 border:1px solid var(--line);
 border-radius:12px;
 padding:18px 20px
}

.intro h2{
 margin:0 0 6px;
 color:var(--navy);
 font-size:18px
}

.intro p{
 margin:0;
 color:#465965;
 line-height:1.5
}

.flow{
 display:grid;
 grid-template-columns:repeat(7,1fr);
 gap:10px;
 margin:0 0 18px
}

.step{
 background:#fff;
 border:1px solid var(--line);
 border-radius:10px;
 padding:12px 10px;
 display:flex;
 align-items:center;
 gap:12px
}

.step strong{
 display:flex;
 align-items:center;
 justify-content:center;
 flex:0 0 34px;
 height:34px;
 border-radius:50%;
 background:var(--navy);
 color:#fff
}

.step span{
 font-weight:800;
 color:var(--navy);
 font-size:12px;
 line-height:1.2
}

.step a{
 display:flex;
 align-items:center;
 gap:12px;
 width:100%;
 color:inherit;
 text-decoration:none
}

.step a:hover strong,
.step a:focus strong{
 background:var(--blue)
}

.section{
 margin:0 0 20px;
 scroll-margin-top:18px
}

.section + .section{
 border-top:3px solid #c7d2dc;
 padding-top:24px;
 margin-top:28px
}

.section-title{
 display:flex;
 align-items:center;
 gap:10px;
 margin:0 0 10px;
 color:var(--navy);
 font-size:20px
}

.section-title:after{
 content:"";
 height:2px;
 background:#d7e0e7;
 flex:1
}

.sourcing-flow{
 display:grid;
 grid-template-columns:repeat(6,1fr);
 gap:8px;
 margin:0 0 14px
}

.sourcing-flow .mini{
 background:#f8fbf9;
 border:1px solid #cfe0d5;
 border-radius:9px;
 padding:10px;
 min-height:74px;
 display:flex;
 flex-direction:column;
 gap:6px
}

.sourcing-flow .mini b{
 color:var(--green-dark);
 font-size:12px
}

.sourcing-flow .mini span{
 color:#596a60;
 font-size:11px;
 line-height:1.3
}

.cards{
 display:grid;
 grid-template-columns:repeat(3,minmax(0,1fr));
 gap:12px
}

.card{
 background:#fff;
 border:1px solid var(--line);
 border-radius:12px;
 padding:18px;
 display:flex;
 flex-direction:column;
 min-height:190px;
 box-shadow:0 4px 14px #0b2a5b0b
}

.card h3{
 margin:0 0 8px;
 color:var(--navy);
 font-size:17px
}

.card p{
 margin:0 0 15px;
 color:#536570;
 line-height:1.5;
 font-size:14px;
 flex:1
}

.tag{
 display:inline-block;
 align-self:flex-start;
 margin:0 0 10px;
 background:#fff5db;
 color:#735500;
 border-radius:999px;
 padding:4px 8px;
 font-size:10px;
 font-weight:800
}

.tag.internal{
 background:#e7f4ec;
 color:#1d633d
}

.btn{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:10px;
 background:var(--navy);
 color:#fff;
 text-decoration:none;
 border-radius:8px;
 padding:11px 13px;
 font-weight:800;
 font-size:13px
}

.btn:hover,
.btn:focus{
 background:var(--blue)
}

.btn:after{
 content:"›";
 font-size:20px;
 line-height:12px
}

/* Sourcing section is the last section */
.section:last-of-type .btn{
 background:var(--green)
}

.section:last-of-type .btn:hover,
.section:last-of-type .btn:focus{
 background:var(--green-dark)
}

.footer{
 text-align:center;
 color:var(--muted);
 font-size:12px;
 line-height:1.6;
 padding:18px 10px 5px;
 border-top:1px solid var(--line);
 margin-top:8px
}

@media(max-width:1000px){
 .flow{grid-template-columns:repeat(4,1fr)}
 .sourcing-flow{
  grid-template-columns:repeat(3,1fr)
 }
}

@media(max-width:850px){
 .top{
  align-items:flex-start;
  flex-direction:column
 }
 .brand{
  align-items:flex-start;
  flex-direction:column
 }
 .security{
  white-space:normal
 }
 .flow,.cards{
  grid-template-columns:1fr 1fr
 }
}

@media(max-width:580px){
 .page{padding:9px}
 .top{padding:20px}
 .logo{width:190px}
 .top h1{font-size:25px}
 .flow,.cards,.sourcing-flow{grid-template-columns:1fr}
 .card{min-height:0}
}
</style>
</head>

<body>

<main class="page">

<header class="top">
 <div class="brand">
  <img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company">
  <div>
   <h1>Lowe Sales Workflow</h1>
   <p>One starting point for predictive ordering, sales intelligence, purchasing, inventory management, quotes, opportunities, samples, and supplier sourcing.</p>
  </div>
 </div>

 <div class="security">Internal Sales Team Access</div>
</header>

<section class="intro">
 <h2>Choose where you are in the sales and sourcing process</h2>
 <p>Use this page as the internal starting point for predictive ordering, customer and sales reporting, purchasing analysis, inventory management, quotes, opportunities, samples, and supplier RFQs.</p>
</section>

<div class="flow" aria-label="Lowe reporting workflow">
 <div class="step"><a href="#predict"><strong>1</strong><span>Predict</span></a></div>
 <div class="step"><a href="#sales-intelligence-customer-reports"><strong>2</strong><span>Sales Intelligence &amp; Customer Reports</span></a></div>
 <div class="step"><a href="#purchasing-reports"><strong>3</strong><span>Purchasing Reports</span></a></div>
 <div class="step"><a href="#inventory-management"><strong>4</strong><span>Inventory Management</span></a></div>
 <div class="step"><a href="#quotes"><strong>5</strong><span>Quotes</span></a></div>
 <div class="step"><a href="#samples"><strong>6</strong><span>Samples</span></a></div>
 <div class="step"><a href="#sourcing-rfqs"><strong>7</strong><span>Sourcing / RFQs</span></a></div>
</div>

<?php foreach($workflowSections as $section=>$links):?>

<section id="<?=h($sectionAnchors[$section] ?? strtolower(preg_replace('/[^a-z0-9]+/i','-',$section)))?>" class="section<?= $section === 'Sourcing / RFQs' ? ' sourcing' : '' ?>">

 <h2 class="section-title"><?=h($section)?></h2>

 <?php if($section === 'Sourcing / RFQs'):?>
 <div class="sourcing-flow" aria-label="Supplier sourcing workflow">
  <div class="mini"><b>1. Create RFQ</b><span>Enter product, application, quantity, suppliers, and email options.</span></div>
  <div class="mini"><b>2. Draft Email</b><span>Review or generate the supplier email before sending.</span></div>
  <div class="mini"><b>3. Send</b><span>Each supplier receives an individual email and secure pricing link.</span></div>
  <div class="mini"><b>4. Supplier Responds</b><span>Supplier enters pricing and commercial terms online.</span></div>
  <div class="mini"><b>5. Review</b><span>Lowe receives notification and the response is stored.</span></div>
  <div class="mini"><b>6. Compare</b><span>Compare supplier quotes side by side before selection.</span></div>
 </div>
 <?php endif;?>

 <div class="cards">

  <?php foreach($links as $link):?>

  <article class="card">

   <h3><?=h($link['title'])?></h3>

   <?php if(!empty($link['public'])):?>
   <span class="tag">Customer-Facing Page</span>
   <?php elseif($section === 'Sourcing / RFQs'):?>
   <span class="tag internal"><?= !empty($link['info']) ? 'Supplier Portal Process' : 'Internal Sourcing' ?></span>
   <?php endif;?>

   <p><?=h($link['description'])?></p>

   <a class="btn" href="<?=h($link['url'])?>">
    <?=h($link['label'])?>
   </a>

  </article>

  <?php endforeach;?>

 </div>

</section>

<?php endforeach;?>

<footer class="footer">
 <strong>Lowe Chemical Company</strong><br>
 8300 Baker Ave., Cleveland, OH 44102 · 216-961-4222 · 800-837-5693 · sales@lowechemical.com
 <div>Our Chemistry Enhances Your Chemistry</div>
</footer>

</main>

</body>
</html>

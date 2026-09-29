<?php
/*
 * Lowe Sales Workflow
 * Place this page inside the website's password-protected area.
 * If a module filename changes, update only the matching URL below.
 */

$workflowSections = require __DIR__ . '/report-registry.php';
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

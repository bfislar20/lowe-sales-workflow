<?php
declare(strict_types=1);

/**
 * Lowe Sales Workflow report registry.
 *
 * The navigation page can consume this array so report names, categories,
 * descriptions and URLs are maintained in one place.
 */
return [
    'Predict' => [
        // Add predictive ordering/forecast tools here.
    ],
    'Sales Intelligence & Customer Reports' => [
        'opportunities' => [
            'title' => 'Sales Opportunities',
            'url' => 'opportunities.php',
            'description' => 'Create, manage and review sales opportunities.',
            'public' => false,
        ],
        'management-dashboard' => [
            'title' => 'Management Dashboard',
            'url' => 'management-dashboard.php',
            'description' => 'Executive view of sales, purchasing, inventory and open commitments.',
            'public' => false,
        ],
    ],
    'Purchasing Reports' => [
        'supplier-scorecard' => [
            'title' => 'Purchasing & Supplier Scorecard',
            'url' => 'supplier-scorecard.php',
            'description' => 'Supplier spend, fill rate, price variance and sourcing risk.',
            'public' => false,
        ],
        'vendor-summary' => [
            'title' => 'Vendor Purchase Summary',
            'url' => 'vendor-summary.php',
            'description' => 'Rolling supplier and product purchase activity.',
            'public' => false,
        ],
        'cost-trend' => [
            'title' => 'Purchase Cost Trend',
            'url' => 'cost-trend.php',
            'description' => 'Supplier/product purchase cost trends.',
            'public' => false,
        ],
        'cdn' => [
            'title' => 'CDN Member Activity',
            'url' => 'cdn.php',
            'description' => 'CDN purchase and sales activity with drill-downs.',
            'public' => false,
        ],
    ],
    'Inventory Management' => [
        'inventory-dashboard' => [
            'title' => 'Inventory Action',
            'url' => 'inventory-dashboard.php',
            'description' => 'Inventory action recommendations and purchasing detail.',
            'public' => false,
        ],
        'inventory-detail' => [
            'title' => 'Inventory Detail',
            'url' => 'inventory-detail.php',
            'description' => 'Current inventory, open sales orders and open purchase orders by product.',
            'public' => false,
        ],
        'inventory-risk' => [
            'title' => 'Inventory Risk',
            'url' => 'inventory-risk.php',
            'description' => 'Projected shortages and inventory risk.',
            'public' => false,
        ],
        'slow-inventory' => [
            'title' => 'Slow Inventory',
            'url' => 'slow-inventory.php',
            'description' => 'Aging, slow-moving and excess inventory.',
            'public' => false,
        ],
        'open-orders' => [
            'title' => 'Open Sales Orders',
            'url' => 'open-orders.php',
            'description' => 'Open customer commitments and inventory coverage.',
            'public' => false,
        ],
        'open-po-dashboard' => [
            'title' => 'Open Purchase Orders',
            'url' => 'open-po-dashboard.php',
            'description' => 'Inbound purchase commitments and projected inventory.',
            'public' => false,
        ],
    ],
    'Quotes' => [
        'pricequote' => [
            'title' => 'Price Quote',
            'url' => 'pricequote.php',
            'description' => 'Prepare and send customer price quotes.',
            'public' => false,
        ],
    ],
    'Samples' => [
        'samples' => [
            'title' => 'Sample Database',
            'url' => 'samples.php',
            'description' => 'Create and manage Lowe sample records.',
            'public' => false,
        ],
        'sampletracking' => [
            'title' => 'Sample Tracking',
            'url' => 'sampletracking.php',
            'description' => 'Track sample status, follow-up and conversion.',
            'public' => false,
        ],
        'samplerequest' => [
            'title' => 'Customer Sample Request',
            'url' => 'samplerequest.php',
            'description' => 'Customer-facing sample request form.',
            'public' => true,
        ],
    ],
    'Sourcing / RFQs' => [
        // Add sourcing and RFQ tools here as current production files are imported.
    ],
];

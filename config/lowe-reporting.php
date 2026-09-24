<?php
declare(strict_types=1);

return [
    'workflow_url' => '/salesworkflow.php',
    'workbook_candidates' => [
        __DIR__ . '/../predictive-order-files/Lowe-Master-Latest.xlsx',
        __DIR__ . '/../order-forecast-files/Lowe-Master-Latest.xlsx',
        __DIR__ . '/../Lowe Master.xlsx',
        __DIR__ . '/../Lowe-Master-Latest.xlsx',
    ],
    'report_registry' => __DIR__ . '/../report-registry.php',
    'production_host' => 'SiteGround',
];

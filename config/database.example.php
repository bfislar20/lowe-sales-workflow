<?php
declare(strict_types=1);

/**
 * Example database configuration for the Lowe Sales Workflow.
 *
 * Copy this file to config/database.php on the production server
 * and fill in the real credentials there. Never commit the real
 * config/database.php file to GitHub.
 */
function db(): PDO {
    $host = 'YOUR_DATABASE_HOST';
    $name = 'YOUR_DATABASE_NAME';
    $user = 'YOUR_DATABASE_USER';
    $pass = 'YOUR_DATABASE_PASSWORD';

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

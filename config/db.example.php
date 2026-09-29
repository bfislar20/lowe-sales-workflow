<?php
declare(strict_types=1);

/**
 * Example RFQ database configuration.
 *
 * Copy to config/db.php on the target server and fill in the real
 * connection values there. Do not commit the real file.
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

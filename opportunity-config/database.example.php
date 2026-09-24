<?php
declare(strict_types=1);

/**
 * Example database configuration for the Opportunities module.
 *
 * Copy this file to opportunity-config/database.php on production and
 * replace the placeholders with the real connection details.
 * Never commit the production database.php file.
 */

function oqs_db(): ?PDO {
    try {
        return new PDO(
            'mysql:host=YOUR_DATABASE_HOST;dbname=YOUR_DATABASE_NAME;charset=utf8mb4',
            'YOUR_DATABASE_USER',
            'YOUR_DATABASE_PASSWORD',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}

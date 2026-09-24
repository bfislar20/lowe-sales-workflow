<?php
/** Lowe Chemical Opportunity Pipeline database configuration. */
const OPP_DB_HOST = 'localhost';
const OPP_DB_NAME = 'db7oeugsnh8qcl';
const OPP_DB_USER = 'u53kacddllli8';
const OPP_DB_PASS = 'kFbF1212!';

function opp_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . OPP_DB_HOST . ';dbname=' . OPP_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, OPP_DB_USER, OPP_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

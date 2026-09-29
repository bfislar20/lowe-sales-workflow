<?php
declare(strict_types=1);

/**
 * Credential-free Opportunities helpers.
 *
 * Copy to app.php on the test server. database.php must already define
 * opp_db() and point exclusively to the Opportunities test database.
 * Review the probabilities against production before using these defaults
 * for live pipeline forecasts.
 */
require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function opp_device(): ?string {
    $device = (string)($_SESSION['opp_device'] ?? '');
    return in_array($device, ['desktop', 'mobile'], true) ? $device : null;
}

function opp_require_device(): void {
    if (isset($_GET['change_device'])) {
        unset($_SESSION['opp_device']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['device_choice'])) {
        $choice = (string)$_POST['device_choice'];
        if (in_array($choice, ['desktop', 'mobile'], true)) {
            $_SESSION['opp_device'] = $choice;
        }
    }
}

function opp_csrf_token(): string {
    if (empty($_SESSION['opp_csrf'])) {
        $_SESSION['opp_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['opp_csrf'];
}

function opp_check_csrf($token): bool {
    return is_string($token) && $token !== '' && hash_equals(opp_csrf_token(), $token);
}

function opp_stage_probabilities(): array {
    return [
        'Lead' => 10,
        'Qualified' => 35,
        'Sample / Trial' => 45,
        'Customer Testing' => 55,
        'Quoting' => 65,
        'Negotiation' => 75,
        'Awaiting PO' => 90,
        'On Hold' => 25,
        'Won' => 100,
        'Lost' => 0,
    ];
}

function opp_stages(): array {
    return array_keys(opp_stage_probabilities());
}

function opp_default_probability(string $stage): int {
    return opp_stage_probabilities()[$stage] ?? 10;
}

function opp_status_for_stage(string $stage): string {
    if ($stage === 'Won' || $stage === 'Lost' || $stage === 'On Hold') {
        return $stage;
    }
    return 'Open';
}

function opp_num($value): float {
    $clean = str_replace([',', '$', ' '], '', (string)$value);
    return is_numeric($clean) ? (float)$clean : 0.0;
}

function opp_money($value): string {
    return '$' . number_format(opp_num($value), 2);
}

function opp_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function opp_new_number(): string {
    return 'OPP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

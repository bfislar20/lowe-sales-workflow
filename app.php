<?php
/**
 * Lowe Chemical Opportunity Pipeline
 * Shared application helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function opp_require_device(): void
{
    if (isset($_GET['change_device'])) {
        unset($_SESSION['opp_device']);
        header('Location: opportunities.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['device_choice'])) {
        $choice = strtolower(trim((string) $_POST['device_choice']));
        if (in_array($choice, ['desktop', 'mobile'], true)) {
            $_SESSION['opp_device'] = $choice;
            header('Location: opportunities.php');
            exit;
        }
    }
}

function opp_device(): ?string
{
    $device = $_SESSION['opp_device'] ?? null;
    return in_array($device, ['desktop', 'mobile'], true) ? $device : null;
}

function opp_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function opp_num(mixed $value): float
{
    if ($value === null || $value === '') return 0.0;
    if (is_string($value)) {
        $value = str_replace([',', '$', '%', ' '], '', $value);
    }
    return is_numeric($value) ? (float) $value : 0.0;
}

function opp_money(mixed $value): string
{
    return '$' . number_format(opp_num($value), 2);
}

function opp_stages(): array
{
    return [
        'Lead',
        'Qualified',
        'Sample / Trial',
        'Customer Testing',
        'Quoting',
        'Negotiation',
        'Awaiting PO',
        'Won',
        'On Hold',
        'Lost',
    ];
}

function opp_statuses(): array
{
    return ['Open', 'Won', 'Lost', 'On Hold'];
}

function opp_stage_probabilities(): array
{
    return [
        'Lead' => 10,
        'Qualified' => 35,
        'Sample / Trial' => 45,
        'Customer Testing' => 55,
        'Quoting' => 65,
        'Negotiation' => 75,
        'Awaiting PO' => 90,
        'Won' => 100,
        'On Hold' => 25,
        'Lost' => 0,
    ];
}

function opp_default_probability(string $stage): float
{
    $map = opp_stage_probabilities();
    return (float)($map[$stage] ?? 10);
}

function opp_status_for_stage(string $stage): string
{
    return match ($stage) {
        'Won' => 'Won',
        'Lost' => 'Lost',
        'On Hold' => 'On Hold',
        default => 'Open',
    };
}

function opp_csrf_token(): string
{
    if (empty($_SESSION['opp_csrf_token'])) {
        $_SESSION['opp_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['opp_csrf_token'];
}

function opp_check_csrf(string $token): bool
{
    $saved = $_SESSION['opp_csrf_token'] ?? '';
    return $saved !== '' && $token !== '' && hash_equals($saved, $token);
}

function opp_new_number(): string
{
    $date = date('Ymd');

    // Use a short random suffix and verify uniqueness in the opportunity database.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $suffix = strtoupper(bin2hex(random_bytes(3)));
        $number = 'OPP-' . $date . '-' . $suffix;

        try {
            $stmt = opp_db()->prepare('SELECT COUNT(*) FROM opportunities WHERE opportunity_no = ?');
            $stmt->execute([$number]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $number;
            }
        } catch (Throwable $e) {
            // Allows the new-opportunity page to render before the schema is imported.
            return $number;
        }
    }

    return 'OPP-' . $date . '-' . strtoupper(bin2hex(random_bytes(4)));
}

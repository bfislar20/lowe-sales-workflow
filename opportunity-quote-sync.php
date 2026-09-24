<?php
/**
 * Lowe Chemical Phase 4B - Quote -> Opportunity synchronization
 * Upload to: public_html/opportunity-quote-sync.php
 *
 * This file is intentionally isolated from pricequote.php so the quote builder
 * does not need to be replaced when its UI/features change.
 */

function oqs_number($value): float {
    $value = str_replace([',', '$', ' '], '', (string)$value);
    return is_numeric($value) ? (float)$value : 0.0;
}

function oqs_item_weight(array $item): float {
    foreach (['units_ordered', 'weight_per_unit', 'units_per_package'] as $key) {
        if (array_key_exists($key, $item)) {
            $v = oqs_number($item[$key]);
            if ($v > 0) return $v;
        }
    }
    return 1.0;
}

function oqs_quote_total(array $quote): float {
    $subtotal = 0.0;
    foreach (($quote['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $qty = oqs_number($item['quantity'] ?? 0);
        $weight = oqs_item_weight($item);
        $price = oqs_number($item['unit_price'] ?? 0);
        $subtotal += $qty * $weight * $price;
    }

    $preTax = $subtotal
        + oqs_number($quote['freight'] ?? 0)
        + oqs_number($quote['hazmat'] ?? 0)
        + oqs_number($quote['other'] ?? 0);

    $tax = $preTax * (oqs_number($quote['tax_rate'] ?? 0) / 100);
    return round($preTax + $tax, 2);
}

function oqs_opportunity_id(array $quote): int {
    $id = (int)($quote['opportunity_id'] ?? 0);
    if ($id > 0) return $id;
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    return (int)($_SESSION['lowe_quote_opportunity_id'] ?? 0);
}

function oqs_db(): ?PDO {
    $path = __DIR__ . '/opportunity-config/database.php';
    if (!is_file($path)) return null;
    require_once $path;
    if (!function_exists('opp_db')) return null;
    try {
        return opp_db();
    } catch (Throwable $e) {
        error_log('Opportunity quote sync DB connection failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Synchronize a quote into the linked Opportunity Pipeline record.
 *
 * $event values:
 *   saved  - refresh amount/date, preserve non-Draft status
 *   sent   - refresh amount/date and set status to Sent
 *
 * Safe to call repeatedly. A repeated email will not create duplicate
 * "Quote Sent" activity rows for the same quote number.
 */
function oqs_sync(array $quote, string $event = 'saved'): bool {
    $oppId = oqs_opportunity_id($quote);
    $quoteNo = trim((string)($quote['quote_number'] ?? ''));
    if ($oppId <= 0 || $quoteNo === '') return false;

    $pdo = oqs_db();
    if (!$pdo) return false;

    $amount = oqs_quote_total($quote);
    $quoteDate = trim((string)($quote['quote_date'] ?? '')) ?: date('Y-m-d');
    $validThrough = trim((string)($quote['valid_through'] ?? '')) ?: null;
    $rep = trim((string)($quote['sales_rep'] ?? ''));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, quote_status FROM opportunity_quotes WHERE opportunity_id=? AND quote_number=? LIMIT 1');
        $stmt->execute([$oppId, $quoteNo]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $status = ($event === 'sent') ? 'Sent' : 'Draft';
            $ins = $pdo->prepare('INSERT INTO opportunity_quotes
                (opportunity_id,quote_number,quote_status,quote_date,valid_through,quote_amount,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?)');
            $ins->execute([$oppId, $quoteNo, $status, $quoteDate, $validThrough, $amount, 'Synchronized from Price Quote Builder', $rep]);
        } else {
            $status = (string)$existing['quote_status'];
            if ($event === 'sent') {
                $status = 'Sent';
            }
            // A normal save must not downgrade Sent/Revised/Accepted/etc. back to Draft.
            $upd = $pdo->prepare('UPDATE opportunity_quotes
                SET quote_status=?, quote_date=?, valid_through=?, quote_amount=?, updated_at=CURRENT_TIMESTAMP
                WHERE id=?');
            $upd->execute([$status, $quoteDate, $validThrough, $amount, (int)$existing['id']]);
        }

        if ($event === 'sent') {
            $subject = 'Quote Sent - ' . $quoteNo . ' - $' . number_format($amount, 2);
            $check = $pdo->prepare("SELECT id FROM opportunity_activities
                WHERE opportunity_id=? AND activity_type='Quote' AND subject=? LIMIT 1");
            $check->execute([$oppId, $subject]);
            if (!$check->fetchColumn()) {
                $details = 'Quote emailed to customer.';
                $email = trim((string)($quote['customer_email'] ?? ''));
                if ($email !== '') $details .= ' Customer email: ' . $email . '.';
                $act = $pdo->prepare('INSERT INTO opportunity_activities
                    (opportunity_id,activity_type,subject,details,created_by)
                    VALUES (?,?,?,?,?)');
                $act->execute([$oppId, 'Quote', $subject, $details, $rep]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Opportunity quote sync failed for ' . $quoteNo . ': ' . $e->getMessage());
        return false;
    }
}

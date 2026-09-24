<?php
session_start();require_once __DIR__ . '/opportunity-quote-sync.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/workflow-nav.php';

/*
 * Lowe Chemical Price Quote Builder
 * File: pricequote.php
 *
 * Features:
 * - Employee quote-entry form
 * - Multiple line items
 * - Automatic subtotal / freight / hazmat / other / tax / total
 * - Customer-facing quote preview
 * - Browser Print / Save as PDF
 * - HTML email using PHP mail()
 * - Quote number generation
 *
 * Optional future upgrades:
 * - Store quotes in MySQL
 * - Add user login / permissions
 * - Generate server-side PDFs with Dompdf
 * - Send attachments with PHPMailer / SMTP
 */

date_default_timezone_set('America/Chicago');

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return '$' . number_format((float)$value, 2);
}

function quote_number($value, int $maxDecimals = 2): string {
    $n = (float)$value;
    $formatted = number_format($n, $maxDecimals, '.', ',');
    if ($maxDecimals > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }
    return $formatted;
}

function number_clean($value): float {
    $value = str_replace([',', '$', ' '], '', (string)$value);
    return is_numeric($value) ? (float)$value : 0.0;
}

function new_quote_number(): string {
    return 'LC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}


function quote_totals(array $quote): array {
    $subtotal = 0.0;
    foreach (($quote['items'] ?? []) as $item) {
        $subtotal += number_clean($item['quantity'] ?? 0) * number_clean($item['units_ordered'] ?? 1) * number_clean($item['unit_price'] ?? 0);
    }
    $freight = number_clean($quote['freight'] ?? 0);
    $hazmat = number_clean($quote['hazmat'] ?? 0);
    $other = number_clean($quote['other'] ?? 0);
    $preTax = $subtotal + $freight + $hazmat + $other;
    $taxRate = number_clean($quote['tax_rate'] ?? 0);
    $tax = $preTax * ($taxRate / 100);
    return [
        'subtotal' => $subtotal,
        'freight' => $freight,
        'hazmat' => $hazmat,
        'other' => $other,
        'tax_rate' => $taxRate,
        'tax' => $tax,
        'total' => $preTax + $tax,
    ];
}

function save_quote_archive(array $quote, ?string $forceStatus = null): void {
    $pdo = db();
    $totals = quote_totals($quote);
    $quoteNumber = trim((string)($quote['quote_number'] ?? ''));
    if ($quoteNumber === '') throw new RuntimeException('Quote number is required before saving.');

    $existing = $pdo->prepare('SELECT id, status FROM quote_records WHERE quote_number = ? LIMIT 1');
    $existing->execute([$quoteNumber]);
    $row = $existing->fetch();
    $status = $forceStatus ?: ($row['status'] ?? 'Draft');
    $allowed = ['Draft','Sent','Won','Lost','Expired'];
    if (!in_array($status, $allowed, true)) $status = 'Draft';

    $json = json_encode($quote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to encode quote for storage.');

    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO quote_records
            (quote_number, quote_date, valid_through, customer_no, customer_company, customer_name,
             customer_email, customer_phone, sales_rep, sales_email, sales_phone, subtotal, freight,
             hazmat, other_charges, tax_rate, tax_amount, total, status, quote_json, sent_at)
            VALUES
            (:quote_number, :quote_date, :valid_through, :customer_no, :customer_company, :customer_name,
             :customer_email, :customer_phone, :sales_rep, :sales_email, :sales_phone, :subtotal, :freight,
             :hazmat, :other_charges, :tax_rate, :tax_amount, :total, :status, :quote_json,
             CASE WHEN :status2 = 'Sent' THEN NOW() ELSE NULL END)
            ON DUPLICATE KEY UPDATE
             quote_date=VALUES(quote_date), valid_through=VALUES(valid_through), customer_no=VALUES(customer_no),
             customer_company=VALUES(customer_company), customer_name=VALUES(customer_name),
             customer_email=VALUES(customer_email), customer_phone=VALUES(customer_phone), sales_rep=VALUES(sales_rep),
             sales_email=VALUES(sales_email), sales_phone=VALUES(sales_phone), subtotal=VALUES(subtotal),
             freight=VALUES(freight), hazmat=VALUES(hazmat), other_charges=VALUES(other_charges),
             tax_rate=VALUES(tax_rate), tax_amount=VALUES(tax_amount), total=VALUES(total), status=VALUES(status),
             quote_json=VALUES(quote_json),
             sent_at=CASE WHEN VALUES(status)='Sent' THEN COALESCE(sent_at, NOW()) ELSE sent_at END";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':quote_number' => $quoteNumber,
            ':quote_date' => $quote['quote_date'] ?? date('Y-m-d'),
            ':valid_through' => ($quote['valid_through'] ?? '') ?: null,
            ':customer_no' => ($quote['customer_no'] ?? '') ?: null,
            ':customer_company' => $quote['customer_company'] ?? '',
            ':customer_name' => ($quote['customer_name'] ?? '') ?: null,
            ':customer_email' => ($quote['customer_email'] ?? '') ?: null,
            ':customer_phone' => ($quote['customer_phone'] ?? '') ?: null,
            ':sales_rep' => ($quote['sales_rep'] ?? '') ?: null,
            ':sales_email' => ($quote['sales_email'] ?? '') ?: null,
            ':sales_phone' => ($quote['sales_phone'] ?? '') ?: null,
            ':subtotal' => $totals['subtotal'],
            ':freight' => $totals['freight'],
            ':hazmat' => $totals['hazmat'],
            ':other_charges' => $totals['other'],
            ':tax_rate' => $totals['tax_rate'],
            ':tax_amount' => $totals['tax'],
            ':total' => $totals['total'],
            ':status' => $status,
            ':status2' => $status,
            ':quote_json' => $json,
        ]);

        $idStmt = $pdo->prepare('SELECT id FROM quote_records WHERE quote_number = ? LIMIT 1');
        $idStmt->execute([$quoteNumber]);
        $quoteId = (int)$idStmt->fetchColumn();
        if (!$quoteId) throw new RuntimeException('Unable to locate saved quote record.');

        $pdo->prepare('DELETE FROM quote_record_items WHERE quote_id = ?')->execute([$quoteId]);
        $itemStmt = $pdo->prepare("INSERT INTO quote_record_items
            (quote_id, line_no, product_number, product, description, packaging, quantity, units_ordered, unit, unit_price, extended)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $lineNo = 1;
        foreach (($quote['items'] ?? []) as $item) {
            $qty = number_clean($item['quantity'] ?? 0);
            $unitsOrdered = number_clean($item['units_ordered'] ?? 1);
            if ($unitsOrdered <= 0) $unitsOrdered = 1;
            $unitPrice = number_clean($item['unit_price'] ?? 0);
            $itemStmt->execute([
                $quoteId, $lineNo++, ($item['product_number'] ?? '') ?: null, $item['product'] ?? '',
                ($item['description'] ?? '') ?: null, ($item['packaging'] ?? '') ?: null,
                $qty, $unitsOrdered, ($item['unit'] ?? '') ?: null, $unitPrice, $qty * $unitsOrdered * $unitPrice
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function load_quote_archive(string $quoteNumber): ?array {
    $stmt = db()->prepare('SELECT quote_json FROM quote_records WHERE quote_number = ? LIMIT 1');
    $stmt->execute([$quoteNumber]);
    $json = $stmt->fetchColumn();
    if (!$json) return null;
    $quote = json_decode((string)$json, true);
    if (!is_array($quote)) return null;
    foreach (($quote['items'] ?? []) as &$item) { if (!isset($item['units_ordered']) || number_clean($item['units_ordered']) <= 0) $item['units_ordered'] = 1; }
    unset($item);
    if (!array_key_exists('include_totals', $quote)) $quote['include_totals'] = false;
    return $quote;
}


function pdf_text_clean($value): string {
    $text = (string)$value;
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($converted !== false) $text = $converted;
    $text = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $text);
    return preg_replace('/\s+/', ' ', trim($text)) ?? '';
}

function pdf_escape_text($value): string {
    $text = pdf_text_clean($value);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function build_quote_pdf(array $quote): string {
    $subtotal = 0.0;
    foreach ($quote['items'] as $item) {
        $subtotal += number_clean($item['quantity']) * number_clean($item['units_ordered'] ?? 1) * number_clean($item['unit_price']);
    }
    $preTax = $subtotal + number_clean($quote['freight']) + number_clean($quote['hazmat']) + number_clean($quote['other']);
    $tax = $preTax * (number_clean($quote['tax_rate']) / 100);
    $total = $preTax + $tax;

    $navy = [0.043, 0.165, 0.357];
    $red = [0.843, 0.098, 0.125];
    $gray = [0.965, 0.973, 0.980];
    $midGray = [0.420, 0.470, 0.520];
    $lineGray = [0.820, 0.850, 0.880];

    $esc = static function ($value): string {
        return pdf_escape_text($value);
    };
    $rgb = static function (array $c): string {
        return sprintf('%.3F %.3F %.3F', $c[0], $c[1], $c[2]);
    };
    $text = static function ($x, $y, $size, $value, $font = 'F1', $color = [0,0,0]) use ($esc, $rgb): string {
        return "BT\n/{$font} {$size} Tf\n" . $rgb($color) . " rg\n1 0 0 1 {$x} {$y} Tm\n(" . $esc($value) . ") Tj\nET\n";
    };
    $fillRect = static function ($x, $y, $w, $h, $color) use ($rgb): string {
        return "q\n" . $rgb($color) . " rg\n{$x} {$y} {$w} {$h} re f\nQ\n";
    };
    $strokeRect = static function ($x, $y, $w, $h, $color, $width = 1) use ($rgb): string {
        return "q\n{$width} w\n" . $rgb($color) . " RG\n{$x} {$y} {$w} {$h} re S\nQ\n";
    };
    $line = static function ($x1, $y1, $x2, $y2, $color, $width = 1) use ($rgb): string {
        return "q\n{$width} w\n" . $rgb($color) . " RG\n{$x1} {$y1} m {$x2} {$y2} l S\nQ\n";
    };
    $wrap = static function ($value, $chars): array {
        $clean = pdf_text_clean($value);
        if ($clean === '') return [];
        return explode("\n", wordwrap($clean, $chars, "\n", true));
    };
    $fmtDate = static function ($value): string {
        $ts = strtotime((string)$value);
        return $ts ? date('M j, Y', $ts) : (string)$value;
    };

    // Optional Lowe logo for the server-generated PDF attachment.
    // Upload the JPEG supplied with this file to /images/lowe-logo-pdf.jpg.
    $logoPath = __DIR__ . '/images/lowe-logo-pdf.jpg';
    $logoJpeg = null;
    $logoW = 0;
    $logoH = 0;
    if (is_file($logoPath)) {
        $info = @getimagesize($logoPath);
        if ($info && ($info['mime'] ?? '') === 'image/jpeg') {
            $logoJpeg = @file_get_contents($logoPath);
            $logoW = (int)($info[0] ?? 0);
            $logoH = (int)($info[1] ?? 0);
            if ($logoJpeg === false || $logoW < 1 || $logoH < 1) $logoJpeg = null;
        }
    }

    $items = $quote['items'] ?? [];
    if (!$items) $items = [['product'=>'Product','description'=>'','packaging'=>'','quantity'=>0,'units_ordered'=>1,'unit'=>'','unit_price'=>0]];

    $pages = [];
    $pageIndex = 0;
    $itemIndex = 0;
    $firstPage = true;

    do {
        $s = '';
        // Top accent and company header.
        $s .= $fillRect(36, 754, 540, 4, $red);
        if ($logoJpeg !== null) {
            // The image keeps the same Lowe branding used on the browser quote.
            $s .= "q\n164 0 0 72 40 676 cm\n/Im1 Do\nQ\n";
        } else {
            $s .= $text(40, 720, 21, 'LOWE CHEMICAL COMPANY', 'F2', $navy);
        }
        $s .= $text(40, 663, 10, 'Our Chemistry Enhances Your Chemistry', 'F2', $navy);
        $s .= $text(40, 649, 8.1, '8300 Baker Ave. - Cleveland, OH 44102 - 216-961-4222 - 800-837-5693 - sales@lowechemical.com', 'F1', $midGray);
        $s .= $text(430, 720, 18, 'PRICE QUOTE', 'F2', $navy);
        $s .= $text(430, 701, 9, $quote['quote_number'] ?? '', 'F2', [0,0,0]);
        $s .= $text(430, 686, 8.5, 'Date: ' . $fmtDate($quote['quote_date'] ?? ''), 'F1', [0,0,0]);
        $s .= $text(430, 673, 8.5, 'Valid Through: ' . $fmtDate($quote['valid_through'] ?? ''), 'F1', [0,0,0]);
        $s .= $line(40, 640, 572, 640, $navy, 1.8);

        if ($firstPage) {
            // Customer Information / Prepared By boxes.
            // Keep structured address fields when available, with a fallback for older saved quotes.
            $custAddress = trim((string)($quote['customer_address'] ?? ''));
            $custCity = trim((string)($quote['customer_city'] ?? ''));
            $custState = trim((string)($quote['customer_state'] ?? ''));
            $custZip = trim((string)($quote['customer_zipcode'] ?? ''));
            if (($custAddress === '' || $custCity === '' || $custState === '' || $custZip === '') && !empty($quote['bill_to'])) {
                $billLines = preg_split('/\r?\n/', trim((string)$quote['bill_to']));
                if ($custAddress === '' && !empty($billLines[0])) $custAddress = trim($billLines[0]);
                if (!empty($billLines[1]) && preg_match('/^\s*(.*?)\s*,\s*([A-Za-z]{2})\s*,?\s*(\d{5}(?:-\d{4})?)?\s*$/', trim($billLines[1]), $m)) {
                    if ($custCity === '') $custCity = trim($m[1] ?? '');
                    if ($custState === '') $custState = strtoupper(trim($m[2] ?? ''));
                    if ($custZip === '') $custZip = trim($m[3] ?? '');
                }
            }

            $s .= $strokeRect(40, 506, 255, 125, $lineGray, 0.8);
            $s .= $strokeRect(317, 506, 255, 125, $lineGray, 0.8);

            // USPS-style customer block: recipient, delivery address, city/state/ZIP.
            // Phone and email follow beneath the mailing address without field labels.
            $companyLine = strtoupper(trim((string)($quote['customer_company'] ?: 'Customer Company')));
            $contactLine = strtoupper(trim((string)($quote['customer_name'] ?? '')));
            $addressLine = strtoupper(trim((string)$custAddress));
            $cityStateZip = trim(strtoupper(trim((string)$custCity) . ' ' . trim((string)$custState)) . ' ' . trim((string)$custZip));

            $py = 607;
            $s .= $text(52, $py, 10.3, $companyLine, 'F2', [0,0,0]);
            $py -= 14;
            if ($contactLine !== '') { $s .= $text(52, $py, 8.7, $contactLine, 'F1', [0,0,0]); $py -= 13; }
            if ($addressLine !== '') { $s .= $text(52, $py, 8.7, $addressLine, 'F1', [0,0,0]); $py -= 13; }
            if ($cityStateZip !== '') { $s .= $text(52, $py, 8.7, $cityStateZip, 'F1', [0,0,0]); $py -= 16; }
            if (!empty($quote['customer_phone'])) { $s .= $text(52, $py, 8.2, $quote['customer_phone'], 'F1', $midGray); $py -= 12; }
            if (!empty($quote['customer_email'])) { $s .= $text(52, $py, 8.2, $quote['customer_email'], 'F1', $midGray); }

            $s .= $text(329, 607, 10.5, $quote['sales_rep'] ?: 'Lowe Chemical Sales', 'F2', [0,0,0]);
            $ry = 591;
            if (!empty($quote['sales_email'])) { $s .= $text(329, $ry, 8.2, $quote['sales_email'], 'F1', $midGray); $ry -= 12; }
            if (!empty($quote['sales_phone'])) { $s .= $text(329, $ry, 8.2, $quote['sales_phone'], 'F1', $midGray); $ry -= 12; }
            $s .= $text(329, $ry, 8.2, 'Payment: ' . ($quote['payment_terms'] ?? ''), 'F1', [0,0,0]); $ry -= 12;
            $s .= $text(329, $ry, 8.2, 'Freight: ' . ($quote['freight_terms'] ?? ''), 'F1', [0,0,0]);
            $tableTop = 486;
        } else {
            $s .= $text(40, 625, 9, 'Quote ' . ($quote['quote_number'] ?? '') . ' - continued', 'F2', $midGray);
            $tableTop = 606;
        }

        // Product table header.
        $headerH = 22;
        $s .= $fillRect(40, $tableTop - $headerH, 532, $headerH, $navy);
        $s .= $text(47, $tableTop - 15, 7.5, 'PRODUCT / SPECIFICATION', 'F2', [1,1,1]);
        $s .= $text(300, $tableTop - 15, 7.2, 'QTY', 'F2', [1,1,1]);
        $s .= $text(350, $tableTop - 15, 6.8, 'WT/UNIT', 'F2', [1,1,1]);
        $s .= $text(410, $tableTop - 15, 6.8, 'TOTAL UNITS', 'F2', [1,1,1]);
        $s .= $text(475, $tableTop - 15, 6.8, 'UNIT PRICE', 'F2', [1,1,1]);
        if (!empty($quote['include_totals'])) $s .= $text(535, $tableTop - 15, 6.2, 'EXTENDED', 'F2', [1,1,1]);

        $y = $tableTop - $headerH - 13;
        $bottomForItems = 220;
        $itemsOnPage = 0;
        while ($itemIndex < count($items)) {
            $item = $items[$itemIndex];
            $qty = number_clean($item['quantity'] ?? 0);
            $unitsOrdered = number_clean($item['units_ordered'] ?? 1);
            if ($unitsOrdered <= 0) $unitsOrdered = 1;
            $unitPrice = number_clean($item['unit_price'] ?? 0);
            $extended = $qty * $unitsOrdered * $unitPrice;
            $product = trim((string)($item['product'] ?? '')) ?: 'Product';
            $desc = trim((string)($item['description'] ?? ''));
            $productLines = $wrap($product . ($desc ? ' - ' . $desc : ''), 48);
            if (!$productLines) $productLines = ['Product'];
            $rowH = max(30, 15 + (count($productLines) * 10));
            if (($y - $rowH) < $bottomForItems && $itemsOnPage > 0) break;

            if ($itemsOnPage % 2 === 1) $s .= $fillRect(40, $y - $rowH + 8, 532, $rowH, $gray);
            $ty = $y;
            foreach ($productLines as $idx => $pl) {
                $s .= $text(47, $ty, $idx === 0 ? 8.4 : 7.6, $pl, $idx === 0 ? 'F2' : 'F1', [0,0,0]);
                $ty -= 10;
            }
            $unitLabel = trim((string)($item['unit'] ?? 'LB')) ?: 'LB';
            $s .= $text(300, $y, 7.7, quote_number($qty), 'F1', [0,0,0]);
            $s .= $text(350, $y, 7.2, quote_number($unitsOrdered) . ' / ' . $unitLabel, 'F1', [0,0,0]);
            $s .= $text(410, $y, 7.2, quote_number($qty * $unitsOrdered), 'F1', [0,0,0]);
            $s .= $text(475, $y, 7.2, money($unitPrice) . '/' . strtolower($unitLabel), 'F1', [0,0,0]);
            if (!empty($quote['include_totals'])) $s .= $text(535, $y, 7.0, money($extended), 'F2', [0,0,0]);
            $s .= $line(40, $y - $rowH + 6, 572, $y - $rowH + 6, $lineGray, 0.5);
            $y -= $rowH;
            $itemIndex++;
            $itemsOnPage++;
        }

        $isLastPage = ($itemIndex >= count($items));
        if ($isLastPage) {
            // Commercial terms on left and totals on right.
            $termsY = min($y - 12, 188);
            $s .= $text(40, $termsY, 8.3, 'Commercial Terms', 'F2', $navy);
            $terms = 'Pricing is based on the quantity, packaging, delivery location, and freight assumptions shown on this quote. Product availability, freight rates, fuel surcharges, tariffs, taxes, and accessorial charges may change before order acceptance unless specifically stated as firm.';
            $termLines = $wrap($terms, 70);
            $ty = $termsY - 13;
            foreach (array_slice($termLines, 0, 5) as $tl) { $s .= $text(40, $ty, 7.1, $tl, 'F1', [0,0,0]); $ty -= 10; }
            if (!empty($quote['special_instructions'])) {
                $inst = $wrap('Delivery Instructions: ' . str_replace(["\r", "\n"], ' ', $quote['special_instructions']), 70);
                foreach (array_slice($inst, 0, 2) as $tl) { $s .= $text(40, $ty - 2, 7.1, $tl, 'F1', [0,0,0]); $ty -= 10; }
            }
            if (!empty($quote['notes'])) {
                $notes = $wrap('Notes: ' . str_replace(["\r", "\n"], ' ', $quote['notes']), 70);
                foreach (array_slice($notes, 0, 2) as $tl) { $s .= $text(40, $ty - 2, 7.1, $tl, 'F1', [0,0,0]); $ty -= 10; }
            }

            if (!empty($quote['include_totals'])) {
                $boxY = 88;
                $boxH = 116;
                $s .= $fillRect(385, $boxY, 187, $boxH, $gray);
                $s .= $strokeRect(385, $boxY, 187, $boxH, $lineGray, 0.8);
                $yy = $boxY + $boxH - 19;
                $s .= $text(397, $yy, 8.5, 'Product Subtotal', 'F1', [0,0,0]);
                $s .= $text(520, $yy, 8.5, money($subtotal), 'F2', [0,0,0]); $yy -= 16;
                if (number_clean($quote['freight'])) { $s .= $text(397, $yy, 8.2, 'Freight', 'F1', [0,0,0]); $s .= $text(520, $yy, 8.2, money($quote['freight']), 'F2', [0,0,0]); $yy -= 14; }
                if (number_clean($quote['hazmat'])) { $s .= $text(397, $yy, 8.2, 'Hazmat / Accessorial', 'F1', [0,0,0]); $s .= $text(520, $yy, 8.2, money($quote['hazmat']), 'F2', [0,0,0]); $yy -= 14; }
                if (number_clean($quote['other'])) { $s .= $text(397, $yy, 8.2, 'Other Charges', 'F1', [0,0,0]); $s .= $text(520, $yy, 8.2, money($quote['other']), 'F2', [0,0,0]); $yy -= 14; }
                if (number_clean($quote['tax_rate'])) { $s .= $text(397, $yy, 8.2, 'Tax', 'F1', [0,0,0]); $s .= $text(520, $yy, 8.2, money($tax), 'F2', [0,0,0]); $yy -= 14; }
                $s .= $line(397, $yy - 2, 560, $yy - 2, $navy, 1.2); $yy -= 20;
                $s .= $text(397, $yy, 12, 'TOTAL', 'F2', $navy);
                $s .= $text(508, $yy, 12, money($total), 'F2', $navy);
            }
        }

        // Purchase-order instructions and footer.
        $s .= $fillRect(40, 64, 532, 18, [0.925, 0.953, 0.976]);
        $s .= $strokeRect(40, 64, 532, 18, $lineGray, 0.6);
        $s .= $text(132, 70, 8.4, 'To place your order, send your purchase order to orders@lowechemical.com.', 'F2', $navy);
        $s .= $line(40, 58, 572, 58, $navy, 1.2);
        $s .= $text(195, 43, 7.3, 'Lowe Chemical Company - Founded 1968', 'F2', $navy);
        $s .= $text(116, 31, 6.8, '8300 Baker Ave., Cleveland, OH 44102 - 216-961-4222 - 800-837-5693 - sales@lowechemical.com', 'F1', $midGray);
        $s .= $text(205, 18, 7.5, 'Our Chemistry Enhances Your Chemistry', 'F2', $navy);

        $pages[] = $s;
        $firstPage = false;
        $pageIndex++;
    } while ($itemIndex < count($items));

    // PDF objects: catalog, pages, two fonts, optional logo image, then page/content pairs.
    $objects = [];
    $pageKids = [];
    $logoObj = null;
    $nextObj = 5;
    if ($logoJpeg !== null) {
        $logoObj = $nextObj++;
        $objects[$logoObj] = "<< /Type /XObject /Subtype /Image /Width {$logoW} /Height {$logoH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logoJpeg) . " >>\nstream\n" . $logoJpeg . "\nendstream";
    }
    foreach ($pages as $stream) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageKids[] = $pageObj . ' 0 R';
        $xobj = $logoObj !== null ? " /XObject << /Im1 {$logoObj} 0 R >>" : '';
        $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$xobj} >> /Contents {$contentObj} 0 R >>";
        $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    }
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageKids) . '] /Count ' . count($pageKids) . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    $maxObj = max(array_keys($objects));
    for ($i = 1; $i <= $maxObj; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}

$defaultQuote = [
    'quote_number' => new_quote_number(),
    'quote_date' => date('Y-m-d'),
    'valid_through' => date('Y-m-d', strtotime('+30 days')),
    'sales_rep' => '',
    'sales_email' => '',
    'sales_phone' => '',
    'customer_no' => '',
    'customer_company' => '',
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'customer_address' => '',
    'customer_city' => '',
    'customer_state' => '',
    'customer_zipcode' => '',
    'bill_to' => '',
    'ship_to' => '',
    'payment_terms' => 'Net 30, subject to credit approval',
    'freight_terms' => 'Delivered',
    'lead_time' => 'Subject to availability at time of order',
    'shipping_method' => '',
    'special_instructions' => '',
    'notes' => '',
    'freight' => 0,
    'hazmat' => 0,
    'other' => 0,
    'tax_rate' => 0,
    'include_totals' => false,
    'items' => [
        [
            'product_number' => '',
            'product' => '',
            'description' => '',
            'cas' => '',
            'grade' => '',
            'packaging' => '',
            'quantity' => 1,
            'units_ordered' => 1,
            'unit' => 'LB',
            'unit_price' => 0,
            'manufacturer' => '',
            'un_number' => '',
            'hazard_class' => '',
            'packing_group' => ''
        ]
    ]
];

$quote = $_SESSION['lowe_quote'] ?? $defaultQuote;
$message = '';
$messageType = 'success';
$showPreview = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['load'])) {
    try {
        $loaded = load_quote_archive(trim((string)$_GET['load']));
        if ($loaded) {
            $quote = array_replace_recursive($defaultQuote, $loaded);
            $_SESSION['lowe_quote'] = $quote;
            $showPreview = true;
            $message = 'Saved quote loaded: ' . $quote['quote_number'];
        } else {
            $message = 'That saved quote could not be found.';
            $messageType = 'error';
        }
    } catch (Throwable $e) {
        $message = 'Unable to load quote history: ' . $e->getMessage();
        $messageType = 'error';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'preview';

    if ($action === 'new_quote') {
        unset($_SESSION['lowe_quote']);
        header('Location: pricequote.php');
        exit;
    }

    $quote = [
        'quote_number' => trim($_POST['quote_number'] ?? new_quote_number()),
        'quote_date' => $_POST['quote_date'] ?? date('Y-m-d'),
        'valid_through' => $_POST['valid_through'] ?? date('Y-m-d', strtotime('+30 days')),
        'sales_rep' => trim($_POST['sales_rep'] ?? ''),
        'sales_email' => trim($_POST['sales_email'] ?? ''),
        'sales_phone' => trim($_POST['sales_phone'] ?? ''),
        'customer_no' => trim($_POST['customer_no'] ?? ''),
        'customer_company' => trim($_POST['customer_company'] ?? ''),
        'customer_name' => trim($_POST['customer_name'] ?? ''),
        'customer_email' => trim($_POST['customer_email'] ?? ''),
        'customer_phone' => trim($_POST['customer_phone'] ?? ''),
        'customer_address' => trim($_POST['customer_address'] ?? ''),
        'customer_city' => trim($_POST['customer_city'] ?? ''),
        'customer_state' => trim($_POST['customer_state'] ?? ''),
        'customer_zipcode' => trim($_POST['customer_zipcode'] ?? ''),
        'bill_to' => trim($_POST['bill_to'] ?? ''),
        'ship_to' => trim($_POST['ship_to'] ?? ''),
        'payment_terms' => trim($_POST['payment_terms'] ?? ''),
        'freight_terms' => trim($_POST['freight_terms'] ?? ''),
        'lead_time' => trim($_POST['lead_time'] ?? ''),
        'shipping_method' => trim($_POST['shipping_method'] ?? ''),
        'special_instructions' => trim($_POST['special_instructions'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'freight' => number_clean($_POST['freight'] ?? 0),
        'hazmat' => number_clean($_POST['hazmat'] ?? 0),
        'other' => number_clean($_POST['other'] ?? 0),
        'tax_rate' => number_clean($_POST['tax_rate'] ?? 0),
        'include_totals' => isset($_POST['include_totals']) && $_POST['include_totals'] === '1',
        'items' => []
    ];

    $products = $_POST['item_product'] ?? [];
    foreach ($products as $i => $product) {
        $product = trim($product);
        $description = trim($_POST['item_description'][$i] ?? '');
        if ($product === '' && $description === '') {
            continue;
        }
        $quote['items'][] = [
            'product_number' => trim($_POST['item_product_number'][$i] ?? ''),
            'product' => $product,
            'description' => $description,
            'cas' => trim($_POST['item_cas'][$i] ?? ''),
            'grade' => trim($_POST['item_grade'][$i] ?? ''),
            'packaging' => trim($_POST['item_packaging'][$i] ?? ''),
            'quantity' => number_clean($_POST['item_quantity'][$i] ?? 0),
            'units_ordered' => max(1, number_clean($_POST['item_units_ordered'][$i] ?? 1)),
            'unit' => trim($_POST['item_unit'][$i] ?? 'LB'),
            'unit_price' => number_clean($_POST['item_unit_price'][$i] ?? 0),
            'manufacturer' => trim($_POST['item_manufacturer'][$i] ?? ''),
            'un_number' => trim($_POST['item_un_number'][$i] ?? ''),
            'hazard_class' => trim($_POST['item_hazard_class'][$i] ?? ''),
            'packing_group' => trim($_POST['item_packing_group'][$i] ?? '')
        ];
    }

    if (!$quote['items']) {
        $quote['items'] = $defaultQuote['items'];
    }

    $_SESSION['lowe_quote'] = $quote;
    $showPreview = true;

    if ($action !== 'send_email') {
        oqs_sync($quote, 'saved');
    }

    try {
        save_quote_archive($quote);
        if ($action === 'preview') {
            $message = 'Quote saved to Quote History.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        $message = 'The quote was created, but it could not be saved to Quote History: ' . $e->getMessage();
        $messageType = 'error';
    }

    if ($action === 'send_email') {
        if (!filter_var($quote['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid customer email address before sending.';
            $messageType = 'error';
        } else {
            $subtotal = 0;
            foreach ($quote['items'] as $item) {
                $subtotal += $item['quantity'] * ($item['units_ordered'] ?? 1) * $item['unit_price'];
            }
            $preTax = $subtotal + $quote['freight'] + $quote['hazmat'] + $quote['other'];
            $tax = $preTax * ($quote['tax_rate'] / 100);
            $total = $preTax + $tax;

            $subject = 'Lowe Chemical Quote ' . $quote['quote_number'];
            $body = '<html><body style="font-family:Arial,sans-serif;color:#20262d;">';
            $body .= '<h2 style="color:#0B2A5B">Lowe Chemical Company Price Quote</h2>';
            $body .= '<p><strong>Our Chemistry Enhances Your Chemistry</strong></p>';
            $body .= '<p>Dear ' . h($quote['customer_name'] ?: 'Customer') . ',</p>';
            $body .= '<p>Thank you for the opportunity to quote your chemical requirements. Please find the quote details below.</p>';
            $body .= '<p><strong>Quote:</strong> ' . h($quote['quote_number']) . '<br>';
            $body .= '<strong>Date:</strong> ' . h($quote['quote_date']) . '<br>';
            $body .= '<strong>Valid Through:</strong> ' . h($quote['valid_through']) . '</p>';
            $body .= '<table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;border-color:#cfd6dc;">';
            $body .= '<tr style="background:#f1f4f6;"><th align="left">Product / Specification</th><th align="right">Qty</th><th align="right">Wt / Unit</th><th align="right">Total Units</th><th align="right">Unit Price</th>' . (!empty($quote['include_totals']) ? '<th align="right">Extended Price</th>' : '') . '</tr>';
            foreach ($quote['items'] as $item) {
                $qty = number_clean($item['quantity'] ?? 0);
                $weightPerUnit = max(0, number_clean($item['units_ordered'] ?? 1));
                $unit = trim((string)($item['unit'] ?? 'LB')) ?: 'LB';
                $extended = $qty * $weightPerUnit * number_clean($item['unit_price'] ?? 0);
                $body .= '<tr><td><strong>' . h($item['product']) . '</strong><br>' . nl2br(h($item['description'])) . '</td><td align="right">' . h(quote_number($qty)) . '</td><td align="right">' . h(quote_number($weightPerUnit)) . ' / ' . h($unit) . '</td><td align="right">' . h(quote_number($qty * $weightPerUnit)) . '</td><td align="right">' . money($item['unit_price']) . '/' . h(strtolower($unit)) . '</td>' . (!empty($quote['include_totals']) ? '<td align="right">' . money($extended) . '</td>' : '') . '</tr>';
            }
            $body .= '</table>';
            if (!empty($quote['include_totals'])) {
                $body .= '<p style="text-align:right;">';
                if ($quote['freight'] > 0) $body .= '<strong>Freight:</strong> ' . money($quote['freight']) . '<br>';
                if ($quote['hazmat'] > 0) $body .= '<strong>Hazmat / Accessorial:</strong> ' . money($quote['hazmat']) . '<br>';
                if ($quote['other'] > 0) $body .= '<strong>Other Charges:</strong> ' . money($quote['other']) . '<br>';
                if ($quote['tax_rate'] > 0) $body .= '<strong>Tax:</strong> ' . money($tax) . '<br>';
                $body .= '<strong>Total: ' . money($total) . '</strong></p>';
            }
            $body .= '<p><strong>Freight Terms:</strong> ' . h($quote['freight_terms']) . '<br>';
            $body .= '<strong>Lead Time:</strong> ' . h($quote['lead_time']) . '<br>';
            $body .= '<strong>Payment Terms:</strong> ' . h($quote['payment_terms']) . '</p>';
            if ($quote['notes'] !== '') {
                $body .= '<p><strong>Notes:</strong><br>' . nl2br(h($quote['notes'])) . '</p>';
            }
            $body .= '<p style="margin:20px 0;padding:12px 14px;background:#eef4f8;border:1px solid #cbd8e2;color:#0B2A5B;font-size:16px;font-weight:bold;text-align:center;">To place your order, send your purchase order to <a href="mailto:orders@lowechemical.com" style="color:#0B2A5B;">orders@lowechemical.com</a>.</p>';
            $body .= '<p>Regards,<br>' . h($quote['sales_rep']) . '<br>Lowe Chemical Company<br>8300 Baker Ave., Cleveland, OH 44102<br>216-961-4222 | 800-837-5693<br>sales@lowechemical.com</p>';
            $body .= '</body></html>';

            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $ccRecipients = [];
            if (filter_var($quote['sales_email'], FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'From: ' . ($quote['sales_rep'] ? $quote['sales_rep'] . ' ' : '') . '<' . $quote['sales_email'] . '>';
                $headers[] = 'Reply-To: ' . $quote['sales_email'];
                // Always copy the sales representative on customer quote emails.
                if (strcasecmp($quote['sales_email'], $quote['customer_email']) !== 0) {
                    $ccRecipients[] = $quote['sales_email'];
                }
            }
            if (($_POST['cc_orders'] ?? '0') === '1') {
                $ordersEmail = 'orders@lowechemical.com';
                if (strcasecmp($ordersEmail, $quote['customer_email']) !== 0 && !in_array(strtolower($ordersEmail), array_map('strtolower', $ccRecipients), true)) {
                    $ccRecipients[] = $ordersEmail;
                }
            }
            if ($ccRecipients) {
                $headers[] = 'Cc: ' . implode(', ', $ccRecipients);
            }

            $attachPdf = ($_POST['attach_pdf'] ?? '0') === '1';
            $mailBody = $body;

            if ($attachPdf) {
                $boundary = '=_LoweQuote_' . bin2hex(random_bytes(12));
                $pdf = build_quote_pdf($quote);
                $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $quote['quote_number']) . '.pdf';

                $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
                $mailBody = '--' . $boundary . "\r\n";
                $mailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
                $mailBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $mailBody .= $body . "\r\n\r\n";
                $mailBody .= '--' . $boundary . "\r\n";
                $mailBody .= 'Content-Type: application/pdf; name="' . $filename . '"' . "\r\n";
                $mailBody .= "Content-Transfer-Encoding: base64\r\n";
                $mailBody .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n";
                $mailBody .= chunk_split(base64_encode($pdf)) . "\r\n";
                $mailBody .= '--' . $boundary . "--\r\n";
            } else {
                $headers[] = 'Content-type: text/html; charset=UTF-8';
            }

            $sent = @mail($quote['customer_email'], $subject, $mailBody, implode("\r\n", $headers));
            if ($sent) {
                oqs_sync($quote, 'sent');
                $message = 'Quote email sent to ' . $quote['customer_email'] . '.';
                $messageType = 'success';
            } else {
                $message = 'The quote was created, but the web server did not send the email. Your hosting account may require SMTP or mail configuration.';
                $messageType = 'error';
            }
        }
    }
}

$subtotal = 0;
foreach ($quote['items'] as $item) {
    $subtotal += number_clean($item['quantity']) * number_clean($item['units_ordered'] ?? 1) * number_clean($item['unit_price']);
}
$preTax = $subtotal + number_clean($quote['freight']) + number_clean($quote['hazmat']) + number_clean($quote['other']);
$tax = $preTax * (number_clean($quote['tax_rate']) / 100);
$grandTotal = $preTax + $tax;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lowe Chemical Company Price Quote Builder</title>
<style>
:root{
    --navy:#0B2A5B;
    --blue:#174F8A;
    --light:#f3f6fb;
    --line:#d6dee4;
    --text:#1f2933;
    --muted:#65737e;
    --success:#e8f5ec;
    --successText:#23683a;
    --error:#fbe9e9;
    --errorText:#9b2c2c;
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:var(--text)}
.page{max-width:1450px;margin:0 auto;padding:24px}
.topbar{background:linear-gradient(135deg,var(--navy),#123d78);border-top:5px solid #D71920;color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:22px;display:flex;justify-content:space-between;gap:20px;align-items:center}
.topbar h1{margin:0;font-size:26px}.topbar p{margin:6px 0 0;color:#dbe6ee}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{border:0;border-radius:8px;padding:11px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.btn-primary{background:#fff;color:var(--navy)}
.btn-secondary{background:var(--blue);color:#fff}
.btn-green{background:#2d7a4f;color:#fff}
.btn-outline{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.45)}
.btn-danger{background:#a73737;color:#fff}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 3px 12px rgba(22,42,60,.04)}
.card h2{font-size:18px;margin:0 0 16px;color:var(--navy)}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
label{font-size:13px;font-weight:700;color:#394955}
input,select,textarea{width:100%;border:1px solid #c9d2d9;border-radius:7px;padding:10px 11px;font:inherit;background:#fff}
textarea{min-height:82px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid #aac7da;border-color:#6d9dbc}

/* Lowe required-entry fields */
.mandatory-field{
    background:#fff8d9 !important;
    border-color:#d6a11d !important;
}
.mandatory-field:focus{
    background:#fffdf2 !important;
    border-color:#b67d00 !important;
    outline:2px solid rgba(214,161,29,.28) !important;
}
.mandatory-label::after,
th.mandatory-heading::after{
    content:" *";
    color:#c62828;
    font-weight:800;
}
.required-note{
    margin:0 0 18px;
    padding:9px 12px;
    border-left:4px solid #d6a11d;
    background:#fff8d9;
    color:#5c4a16;
    border-radius:7px;
    font-size:12px;
    font-weight:700;
}
.required-note .star{color:#c62828;font-size:14px}

/* Mobile and narrow-screen hardening */
html,body{max-width:100%;overflow-x:hidden}
input,select,textarea,button{max-width:100%}
.voice-field{min-width:0}.voice-field input,.voice-field textarea{min-width:0}
.search-result,.btn,.voice-btn,.remove-row,.customer-add-btn,.device-choice{touch-action:manipulation}
.mandatory-field:invalid:not(:focus){border-color:#d6a11d}
.voice-field{display:flex;gap:7px;align-items:stretch}.voice-field input,.voice-field textarea{flex:1}.voice-btn{flex:0 0 42px;border:1px solid #b7c6d1;background:#eef4f7;color:var(--navy);border-radius:7px;cursor:pointer;font-size:19px;line-height:1;display:flex;align-items:center;justify-content:center}.voice-btn:hover{background:#e1ebf1}.voice-btn.listening{background:#fbe9e9;color:#a73737;border-color:#e3abab;animation:pulse 1s infinite}.voice-btn:disabled{opacity:.45;cursor:not-allowed}.voice-status{font-size:12px;color:var(--muted);margin-top:5px}.voice-status.active{color:#a73737;font-weight:700}@keyframes pulse{0%,100%{opacity:1}50%{opacity:.55}}
.message{padding:12px 15px;border-radius:8px;margin-bottom:18px;font-weight:700}.message.success{background:var(--success);color:var(--successText)}.message.error{background:var(--error);color:var(--errorText)}
.table-wrap{overflow-x:auto}
table.items{width:100%;border-collapse:collapse;min-width:820px}
table.items th{background:var(--light);text-align:left;font-size:12px;padding:9px;border-bottom:1px solid var(--line);color:#354753}
table.items td{padding:7px;border-bottom:1px solid #e7ecef;vertical-align:top}
table.items input, table.items select{padding:8px;font-size:13px}
.remove-row{background:#fff0f0;border:1px solid #efc5c5;color:#9b2c2c;border-radius:6px;padding:8px 10px;cursor:pointer}
.add-row{margin-top:12px;background:#e9f0f5;color:var(--navy);border:1px solid #c9d7e1}
.totals-grid{display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start}
.summary{background:#f7f9fa;border:1px solid var(--line);border-radius:10px;padding:16px}.summary-row{display:flex;justify-content:space-between;gap:20px;padding:7px 0}.summary-row.total{border-top:2px solid var(--navy);margin-top:8px;padding-top:12px;font-size:20px;font-weight:700;color:var(--navy)}
.help{font-size:12px;color:var(--muted);margin-top:5px}
.preview-wrap{margin-top:26px}.quote-sheet{background:#fff;max-width:980px;margin:0 auto;border:1px solid #cfd8df;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:42px}
.quote-header{display:flex;justify-content:space-between;gap:30px;border-bottom:4px solid var(--navy);padding-bottom:20px;margin-bottom:22px}
.lowe-brand{max-width:560px}.lowe-logo{display:block;max-width:520px;width:100%;height:auto}.lowe-brand .tagline{font-style:italic;font-weight:700;color:var(--navy);margin-top:8px}.quote-sheet{border-top:5px solid #D71920}.quote-title h1{letter-spacing:.04em}.brand h2{font-size:30px;color:var(--navy);margin:0}.brand p{margin:6px 0;color:var(--muted)}.quote-title{text-align:right}.quote-title h1{margin:0;color:var(--navy);font-size:30px}.quote-title div{margin-top:6px}
.quote-meta{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px}.box{border:1px solid var(--line);border-radius:8px;padding:14px}.box h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}.box p{margin:4px 0;white-space:pre-line}
.usps-customer{font-size:14px;line-height:1.28}.usps-customer p{margin:2px 0;text-transform:none}.usps-customer .usps-company{font-weight:800;font-size:15px;letter-spacing:.01em}.usps-contact-gap{height:8px}.prepared-by .prepared-name{font-size:15px;margin-bottom:7px}
.quote-table{width:100%;border-collapse:collapse}.quote-table th{background:var(--navy);color:#fff;padding:10px;text-align:left;font-size:12px}.quote-table td{padding:11px 10px;border-bottom:1px solid #dce3e8;font-size:13px;vertical-align:top}.quote-table .right{text-align:right}.product-title{font-weight:700;color:var(--navy)}.detail{font-size:12px;color:#566672;margin-top:4px}
.quote-table th:first-child,.quote-table td:first-child{width:42%}
.quote-table th:nth-child(2),.quote-table td:nth-child(2){width:9%}
.quote-table th:nth-child(3),.quote-table td:nth-child(3){width:12%}
.quote-table th:nth-child(4),.quote-table td:nth-child(4){width:13%}
.quote-table th:nth-child(5),.quote-table td:nth-child(5){width:12%}
.quote-table th:nth-child(6),.quote-table td:nth-child(6){width:12%}
.quote-bottom{display:grid;grid-template-columns:1fr 310px;gap:24px;margin-top:22px}.terms{font-size:12px;line-height:1.55}.terms strong{color:var(--navy)}
.preview-actions{max-width:980px;margin:0 auto 12px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.po-instructions{margin:24px 0 0;padding:13px 16px;border:1px solid #cbd8e2;border-radius:8px;background:#eef4f8;color:var(--navy);font-size:15px;font-weight:800;text-align:center}.po-instructions a{color:var(--navy);white-space:nowrap}.lowe-footer{margin-top:14px;padding-top:14px;border-top:2px solid var(--navy);font-size:11px;line-height:1.6;color:#405168;text-align:center}.footer-tagline{font-weight:700;font-style:italic;color:var(--navy);font-size:13px;margin-top:3px}
@media(max-width:900px){
.grid,.quote-meta,.quote-bottom,.totals-grid,.form-grid,.form-grid.three{grid-template-columns:1fr}
.topbar{align-items:flex-start;flex-direction:column;padding:16px}
.topbar .actions{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.topbar .actions .btn{width:100%;min-height:46px}
.quote-header{flex-direction:column}.quote-title{text-align:left}
.page{padding:10px}.card{padding:15px}.quote-sheet{padding:18px;overflow:hidden}
.history-tools{grid-template-columns:1fr}
.customer-add-row{align-items:stretch;flex-direction:column}.customer-add-btn{width:100%;min-height:44px}
.option-box,.customer-box,.manual-product-box{max-height:calc(100dvh - 24px);overflow-y:auto;padding:18px}
.option-actions,.customer-actions,.manual-product-actions{display:grid;grid-template-columns:1fr;gap:8px}
.option-actions .btn,.customer-actions .btn,.manual-product-actions .btn{width:100%;min-height:48px}
.email-modal,.customer-modal,.manual-product-modal{padding:12px;align-items:flex-start;overflow-y:auto}
.device-box{max-height:calc(100dvh - 24px);overflow-y:auto;padding:20px}
.device-gate{padding:12px}
/* Product entry becomes stacked cards before horizontal scrolling is needed. */
.items thead{display:none}
.items,.items tbody,.items tr,.items td{display:block;width:100%;min-width:0}
.items{min-width:0!important}
.items tr{border:1px solid #d9e2e8;border-radius:12px;padding:11px;margin-bottom:14px;background:#fff}
.items td{border:0!important;padding:6px 0!important}
.items td::before{content:attr(data-label);display:block;font-size:11px;font-weight:800;color:var(--navy);margin:0 0 4px;text-transform:uppercase;letter-spacing:.03em}
.items input,.items select{font-size:16px;min-height:46px}
.items .remove-row{width:100%;min-height:44px;margin-top:4px}
/* Customer quote preview also stacks cleanly on a phone. */
.quote-table thead{display:none}
.quote-table,.quote-table tbody,.quote-table tr,.quote-table td{display:block;width:100%}
.quote-table tr{border:1px solid #d9e2e8;border-radius:10px;padding:10px;margin-bottom:12px}
.quote-table td{border:0!important;padding:5px 0!important;display:flex;justify-content:space-between;gap:12px;align-items:flex-start;text-align:right!important}
.quote-table td:first-child{display:block;text-align:left!important}
.quote-table td:nth-child(2)::before{content:'Qty';font-weight:700;color:var(--navy)}
.quote-table td:nth-child(3)::before{content:'Wt / Unit';font-weight:700;color:var(--navy)}
.quote-table td:nth-child(4)::before{content:'Total Units';font-weight:700;color:var(--navy)}
.quote-table td:nth-child(5)::before{content:'Unit Price';font-weight:700;color:var(--navy)}
.quote-table td:nth-child(6)::before{content:'Extended Price';font-weight:700;color:var(--navy)}
.preview-actions{justify-content:stretch}.preview-actions .btn{width:100%;min-height:48px}
.include-totals-option{align-items:flex-start!important}
}
@media(max-width:520px){
.page{padding:6px}.card{padding:12px;border-radius:9px}.topbar{border-radius:10px}
.topbar h1{font-size:20px;line-height:1.2}.topbar p{font-size:13px;line-height:1.35}
.topbar .actions{grid-template-columns:1fr}
.device-choice{padding:16px 12px}.device-box h2{font-size:22px}
.required-note{font-size:11px}
.quote-sheet{padding:12px}.quote-title h1{font-size:24px}.lowe-logo{max-width:100%}
.po-instructions{font-size:13px;padding:11px}.po-instructions a{white-space:normal;word-break:break-word}
}


/* Database search + device-aware quote entry */
.device-gate{position:fixed;inset:0;background:rgba(7,27,56,.94);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px}.device-gate.hidden{display:none}.device-box{width:min(720px,100%);background:#fff;border-radius:18px;padding:28px;box-shadow:0 24px 70px rgba(0,0,0,.35);text-align:center}.device-box h2{margin:0 0 8px;color:var(--navy);font-size:28px}.device-box p{margin:0 0 22px;color:#526272}.device-choices{display:grid;grid-template-columns:1fr 1fr;gap:16px}.device-choice{border:2px solid #d9e1e8;background:#fff;color:var(--navy);border-radius:14px;padding:22px 16px;font-size:18px;font-weight:800;cursor:pointer}.device-choice:hover,.device-choice:focus{border-color:var(--blue);background:#f3f7fb}.device-choice small{display:block;font-weight:500;color:#647586;margin-top:6px}.device-change{font-size:12px;margin-top:10px;background:none;border:0;color:var(--blue);text-decoration:underline;cursor:pointer}
.customer-search-wrap,.product-search-wrap,.rep-search-wrap{position:relative}.search-results{position:absolute;z-index:50;left:0;right:0;top:100%;background:#fff;border:1px solid #c9d4df;border-radius:8px;box-shadow:0 10px 26px rgba(15,36,61,.15);max-height:300px;overflow:auto;display:none}.search-results.open{display:block}.search-result{display:block;width:100%;border:0;border-bottom:1px solid #edf1f4;background:#fff;text-align:left;padding:11px 12px;cursor:pointer;color:#172536}.search-result:hover,.search-result:focus{background:#f3f7fb}.search-result strong{display:block;color:var(--navy)}.search-result small{display:block;color:#687786;margin-top:3px}.selected-customer{margin-top:8px;padding:9px 11px;background:#eef6ff;border-left:4px solid var(--blue);font-size:12px;color:#334b63;display:none}.selected-customer.show{display:block}.history-tools{display:grid;grid-template-columns:minmax(220px,1fr) auto minmax(240px,1fr) auto;gap:10px;align-items:end;margin-bottom:16px;padding:14px;background:#f7f9fb;border:1px solid #e0e7ed;border-radius:10px}.history-note{grid-column:1/-1;font-size:12px;color:#657585;margin-top:-2px}.product-number{font-size:11px;color:#6d7985;margin-top:3px}.db-status{font-size:12px;color:#556675;margin-top:8px}.db-status.error{color:#a32929}.db-status.ok{color:#17633a}
body.mobile-mode .page{max-width:none;padding:8px}body.mobile-mode .topbar{padding:14px}body.mobile-mode .topbar h1{font-size:20px}body.mobile-mode .grid,body.mobile-mode .form-grid,body.mobile-mode .form-grid.three,body.mobile-mode .totals-grid{grid-template-columns:1fr}body.mobile-mode .card{padding:14px}body.mobile-mode input,body.mobile-mode select,body.mobile-mode textarea{font-size:16px;min-height:46px}body.mobile-mode .btn{min-height:48px;font-size:15px}body.mobile-mode .history-tools{grid-template-columns:1fr}body.mobile-mode .items thead{display:none}body.mobile-mode .items,body.mobile-mode .items tbody,body.mobile-mode .items tr,body.mobile-mode .items td{display:block;width:100%;min-width:0}body.mobile-mode .items{min-width:0!important}body.mobile-mode .items tr{border:1px solid #d9e2e8;border-radius:12px;padding:10px;margin-bottom:14px;background:#fff}body.mobile-mode .items td{border:0!important;padding:5px 0!important}body.mobile-mode .items td::before{content:attr(data-label);display:block;font-size:11px;font-weight:800;color:var(--navy);margin:0 0 3px;text-transform:uppercase;letter-spacing:.03em}body.mobile-mode .remove-row{width:100%;min-height:44px}
@media(max-width:760px){.device-choices{grid-template-columns:1fr}}

@media print{
    body{background:#fff}
    .no-print,.topbar,.editor,.preview-actions,.message{display:none!important}
    .page{padding:0;max-width:none}
    .preview-wrap{margin:0}
    .quote-sheet{box-shadow:none;border:none;max-width:none;padding:0}
    @page{size:Letter;margin:.45in}
}

.email-modal,.customer-modal,.manual-product-modal{position:fixed;inset:0;background:rgba(7,27,56,.72);z-index:10000;display:none;align-items:center;justify-content:center;padding:20px}.email-modal.open,.customer-modal.open,.manual-product-modal.open{display:flex}.option-box,.customer-box,.manual-product-box{width:min(640px,100%);background:#fff;border-radius:16px;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.3)}.option-box h3,.customer-box h3,.manual-product-box h3{margin:0 0 8px;color:var(--navy)}.option-box p,.customer-box p,.manual-product-box p{color:#526272}.option-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.customer-actions,.manual-product-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}.customer-add-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px}.customer-add-btn{background:#eef4f7;color:var(--navy);border:1px solid #b7c6d1;border-radius:7px;padding:8px 11px;font-weight:700;cursor:pointer}.customer-add-btn:hover{background:#e1ebf1}.manual-product-trigger{background:#eef4f7!important;color:var(--navy)!important;border:1px solid #b7c6d1!important}.manual-product-trigger:hover{background:#e1ebf1!important}.manual-product-note{margin-top:8px;padding:9px 11px;background:#f7f9fb;border:1px solid #e0e7ed;border-radius:8px;font-size:12px;color:#5b6878}.modal-error{display:none;margin-top:12px;padding:10px;border-radius:7px;background:var(--error);color:var(--errorText);font-size:13px}.modal-error.show{display:block}

</style>
</head>
<body>

<div id="deviceGate" class="device-gate no-print" role="dialog" aria-modal="true" aria-labelledby="deviceQuestion">
  <div class="device-box">
    <h2 id="deviceQuestion">How are you preparing this quote?</h2>
    <p>Choose the device you are using. The quote form will adapt for easier entry.</p>
    <div class="device-choices">
      <button type="button" class="device-choice" onclick="chooseDevice('mobile')">Mobile Phone<small>Larger controls, stacked product cards, voice-friendly entry</small></button>
      <button type="button" class="device-choice" onclick="chooseDevice('desktop')">Desktop / Laptop / Notebook<small>Full-width quote entry and product table</small></button>
    </div>
  </div>
</div>
<div class="page">
    <div class="topbar no-print">
        <div>
            <h1>Lowe Chemical Company Price Quote Builder</h1>
            <p>Create Lowe Chemical customer quotations, preview them, print/save as PDF, or email them directly.</p>
        </div>
        <div class="actions">
            <?=workflow_back_link('btn btn-outline')?>
            <a href="quotes.php" class="btn" style="text-decoration:none;display:inline-flex;align-items:center">Quote History</a>
            <button type="button" class="btn" onclick="showDeviceGate()">Change Device</button>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('quoteForm').requestSubmit(document.getElementById('previewBtn'))">Preview Quote</button>
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <div class="required-note no-print"><span class="star">*</span> Yellow fields are required before the quote can be generated or emailed.</div>

    <?php if ($message): ?>
        <div class="message <?= h($messageType) ?> no-print"><?= h($message) ?></div>
    <?php endif; ?>

    <form method="post" id="quoteForm" class="editor no-print">
        <div class="grid">
            <div class="card">
                <h2>Quote Information</h2>
                <div class="form-grid three">
                    <div class="form-group">
                        <label>Quote Number</label>
                        <input name="quote_number" value="<?= h($quote['quote_number']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Quote Date</label>
                        <input type="date" name="quote_date" value="<?= h($quote['quote_date']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Valid Through</label>
                        <input type="date" name="valid_through" value="<?= h($quote['valid_through']) ?>" required>
                    </div>
                    <div class="form-group rep-search-wrap">
                        <label class="mandatory-label">Lowe Chemical Sales Rep</label>
                        <div class="voice-field"><input type="search" id="repSearch" class="mandatory-field" name="sales_rep" value="<?= h($quote['sales_rep']) ?>" placeholder="Start typing rep name..." autocomplete="off" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate sales rep name" aria-label="Dictate sales rep name">🎤</button></div>
                        <div id="repResults" class="search-results" role="listbox"></div>
                        <div id="repStatus" class="db-status">Search Lowe sales representatives.</div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Sales Rep Email</label>
                        <input type="email" id="salesRepEmail" class="mandatory-field" name="sales_email" value="<?= h($quote['sales_email']) ?>" placeholder="name@lowechemical.com" required>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Sales Rep Phone</label>
                        <div class="voice-field"><input id="salesRepPhone" class="mandatory-field" name="sales_phone" value="<?= h($quote['sales_phone']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate phone number" aria-label="Dictate phone number">🎤</button></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Customer</h2>
                <div class="form-grid">
                    <div class="form-group full customer-search-wrap">
                        <label>Select Customer from Lowe Database</label>
                        <input type="search" id="customerSearch" autocomplete="off" placeholder="Start typing customer name, customer number, city or ZIP..." value="<?= h($quote['customer_company']) ?>">
                        <div id="customerResults" class="search-results" role="listbox"></div>
                        <input type="hidden" id="customerNo" name="customer_no" value="<?= h($quote['customer_no'] ?? '') ?>">
                        <input type="hidden" id="customerAddress" name="customer_address" value="<?= h($quote['customer_address'] ?? '') ?>">
                        <input type="hidden" id="customerCity" name="customer_city" value="<?= h($quote['customer_city'] ?? '') ?>">
                        <input type="hidden" id="customerState" name="customer_state" value="<?= h($quote['customer_state'] ?? '') ?>">
                        <input type="hidden" id="customerZipcode" name="customer_zipcode" value="<?= h($quote['customer_zipcode'] ?? '') ?>">
                        <div id="selectedCustomer" class="selected-customer <?= !empty($quote['customer_no']) ? 'show' : '' ?>">
                            Selected customer: <strong id="selectedCustomerText"><?= h(($quote['customer_no'] ?? '') . (($quote['customer_no'] ?? '') ? ' · ' : '') . $quote['customer_company']) ?></strong>
                        </div>
                        <div id="dbStatus" class="db-status">Search connects to the Lowe customer database.</div>
                        <div class="customer-add-row"><span class="help">If the customer is not found, add them to the Lowe quote database.</span><button type="button" class="customer-add-btn" onclick="openCustomerModal()">+ Add New Customer</button></div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Company</label>
                        <div class="voice-field"><input id="customerCompany" class="mandatory-field" name="customer_company" value="<?= h($quote['customer_company']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate company name" aria-label="Dictate company name">🎤</button></div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Contact Name</label>
                        <div class="voice-field"><input class="mandatory-field" name="customer_name" value="<?= h($quote['customer_name']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate contact name" aria-label="Dictate contact name">🎤</button></div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Email</label>
                        <input type="email" class="mandatory-field" name="customer_email" value="<?= h($quote['customer_email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Phone</label>
                        <div class="voice-field"><input id="customerPhone" class="mandatory-field" name="customer_phone" value="<?= h($quote['customer_phone']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate phone number" aria-label="Dictate phone number">🎤</button></div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Bill To</label>
                        <div class="voice-field"><textarea id="billTo" class="mandatory-field" name="bill_to" required><?= h($quote['bill_to']) ?></textarea><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate billing address" aria-label="Dictate billing address">🎤</button></div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Ship To</label>
                        <div class="voice-field"><textarea id="shipTo" class="mandatory-field" name="ship_to" required><?= h($quote['ship_to']) ?></textarea><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate shipping address" aria-label="Dictate shipping address">🎤</button></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Products & Pricing</h2>
            <div id="voiceStatus" class="voice-status">Tap a microphone to dictate into that field.</div>
            <div class="history-tools">
                <div class="form-group">
                    <label>Products Previously Purchased by This Customer</label>
                    <select id="historyProducts" disabled><option value="">Select a customer first</option></select>
                </div>
                <button type="button" id="addHistoryProduct" class="btn btn-primary" disabled onclick="addSelectedHistoryProduct()">+ Add Past Product</button>
                <div class="form-group product-search-wrap">
                    <label>Not Listed? Search All Lowe Products</label>
                    <input type="search" id="allProductSearch" autocomplete="off" placeholder="Product name or product number...">
                    <div id="productResults" class="search-results" role="listbox"></div>
                </div>
                <button type="button" class="btn manual-product-trigger" onclick="openManualProductModal()">+ Add Manual Product</button>
                <div class="history-note">Past products come from the customer history file. The all-products search comes from Lowe's product master. If the product is not in Lowe's product master, use <strong>+ Add Manual Product</strong>. Pricing remains blank so the sales rep enters today's price.</div>
            </div>
            <div class="table-wrap">
                <table class="items" id="itemsTable">
                    <thead>
                    <tr>
                        <th class="mandatory-heading">Product</th>
                        <th class="mandatory-heading">Description / Specification</th>
                        <th class="mandatory-heading">Packaging</th>
                        <th class="mandatory-heading">Qty</th>
                        <th class="mandatory-heading">Weight per Unit</th>
                        <th>Unit</th>
                        <th>Total Units</th>
                        <th class="mandatory-heading">$/Unit</th>
                        <th>Extended Price</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($quote['items'] as $item): ?>
                        <tr>
                            <td><input type="hidden" name="item_product_number[]" value="<?= h($item['product_number'] ?? '') ?>"><div class="voice-field"><input class="mandatory-field" name="item_product[]" value="<?= h($item['product']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate product" aria-label="Dictate product">🎤</button></div></td>
                            <td><div class="voice-field"><input class="mandatory-field" name="item_description[]" value="<?= h($item['description']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate description or specification" aria-label="Dictate description or specification">🎤</button></div></td>
                            <td><input class="mandatory-field" name="item_packaging[]" value="<?= h($item['packaging']) ?>" required></td>
                            <td><input type="number" step="0.01" min="0.01" class="qty mandatory-field" name="item_quantity[]" value="<?= h($item['quantity']) ?>" required></td>
                            <td><input type="number" step="0.01" min="0.01" class="units-ordered mandatory-field" name="item_units_ordered[]" value="<?= h($item['units_ordered'] ?? 1) ?>" required></td>
                            <td>
                                <select name="item_unit[]">
                                    <?php foreach (['LB','KG','GAL','DRUM','TOTE','BAG','PAIL','EA','LOAD'] as $unit): ?>
                                        <option value="<?= $unit ?>" <?= $item['unit'] === $unit ? 'selected' : '' ?>><?= $unit ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="total-units"><strong><?= h(number_format(number_clean($item['quantity']) * number_clean($item['units_ordered'] ?? 1), 0)) ?></strong></td>
                            <td><input type="number" step="0.0001" min="0.0001" class="unit-price mandatory-field" name="item_unit_price[]" value="<?= h($item['unit_price']) ?>" required></td>
                            <td class="row-extended"><strong><?= money(number_clean($item['quantity']) * number_clean($item['units_ordered'] ?? 1) * number_clean($item['unit_price'])) ?></strong></td>
                            <td><button type="button" class="remove-row" onclick="removeRow(this)">×</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn add-row" onclick="addRow()">+ Add Product</button>
        </div>

        <div class="card">
            <h2>Delivery, Charges & Terms</h2>
            <div class="totals-grid">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="mandatory-label">Freight Terms</label>
                        <select class="mandatory-field" name="freight_terms" required>
                            <?php foreach (['Delivered','Prepaid & Add','Collect','FOB Shipping Point','Customer Pickup','Other'] as $term): ?>
                                <option <?= $quote['freight_terms'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Shipping Method</label>
                        <select class="mandatory-field" name="shipping_method" required>
                            <option value="">Select...</option>
                            <?php foreach (['Bulk Tanker','Full Truckload (FTL)','Less Than Truckload (LTL)','Parcel','Customer Pickup','Rail','Other'] as $method): ?>
                                <option <?= $quote['shipping_method'] === $method ? 'selected' : '' ?>><?= h($method) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Lead Time</label>
                        <div class="voice-field"><input name="lead_time" value="<?= h($quote['lead_time']) ?>"><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate lead time" aria-label="Dictate lead time">🎤</button></div>
                    </div>
                    <div class="form-group">
                        <label class="mandatory-label">Payment Terms</label>
                        <div class="voice-field"><input class="mandatory-field" name="payment_terms" value="<?= h($quote['payment_terms']) ?>" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate payment terms" aria-label="Dictate payment terms">🎤</button></div>
                    </div>
                    <div class="form-group full">
                        <label class="mandatory-label">Customer PO / Delivery Instructions</label>
                        <div class="voice-field"><textarea class="mandatory-field" name="special_instructions" placeholder="Example: Appointment required, COA with shipment, no Friday delivery, driver PPE requirements..." required><?= h($quote['special_instructions']) ?></textarea><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate delivery instructions" aria-label="Dictate delivery instructions">🎤</button></div>
                    </div>
                    <div class="form-group full">
                        <label>Quote Notes</label>
                        <div class="voice-field"><textarea name="notes" placeholder="Example: Price based on full truckload quantity. Subject to availability and final freight confirmation."><?= h($quote['notes']) ?></textarea><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate quote notes" aria-label="Dictate quote notes">🎤</button></div>
                    </div>
                </div>

                <div class="summary" id="chargesPanel" style="<?= !empty($quote['include_totals']) ? '' : 'display:none;' ?>">
                    <div class="form-group">
                        <label>Freight Charge</label>
                        <input type="number" step="0.01" min="0" id="freight" name="freight" value="<?= h($quote['freight']) ?>">
                    </div>
                    <div class="form-group" style="margin-top:10px">
                        <label>Hazmat / Accessorial Charges</label>
                        <input type="number" step="0.01" min="0" id="hazmat" name="hazmat" value="<?= h($quote['hazmat']) ?>">
                    </div>
                    <div class="form-group" style="margin-top:10px">
                        <label>Other Charges</label>
                        <input type="number" step="0.01" min="0" id="other" name="other" value="<?= h($quote['other']) ?>">
                    </div>
                    <div class="form-group" style="margin-top:10px">
                        <label>Sales Tax %</label>
                        <input type="number" step="0.001" min="0" id="taxRate" name="tax_rate" value="<?= h($quote['tax_rate']) ?>">
                    </div>
                    <hr style="border:0;border-top:1px solid #d6dee4;margin:16px 0">
                    <div class="summary-row"><span>Product Subtotal</span><strong id="jsSubtotal"><?= money($subtotal) ?></strong></div>
                    <div class="summary-row"><span>Tax</span><strong id="jsTax"><?= money($tax) ?></strong></div>
                    <div class="summary-row total"><span>Total</span><span id="jsTotal"><?= money($grandTotal) ?></span></div>
                </div>
            </div>
        </div>

        <div class="include-totals-option" style="margin:0 0 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:9px;font-weight:700;color:#0B2A5B;cursor:pointer">
                <input type="checkbox" id="includeTotals" name="include_totals" value="1" <?= !empty($quote['include_totals']) ? 'checked' : '' ?> style="width:18px;height:18px">
                Include Freight / Additional Charges &amp; Total on Quote
            </label>
            <span class="help">Leave unchecked for a standard unit-price quote without extended prices or a grand total.</span>
        </div>

        <div class="actions" style="margin-bottom:24px">
            <button id="previewBtn" type="submit" name="action" value="preview" class="btn btn-secondary">Generate / Update Quote</button>
            <input type="hidden" name="attach_pdf" id="attachPdf" value="0">
            <input type="hidden" name="cc_orders" id="ccOrders" value="0">
            <button type="button" class="btn btn-green" onclick="openEmailOptions()">Email Quote</button>
            <button type="submit" id="emailSubmit" name="action" value="send_email" style="display:none">Send Email</button>
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print / Save PDF</button>
            <button type="submit" name="action" value="new_quote" class="btn btn-danger" formnovalidate>Start New Quote</button>
        </div>
    </form>


    <div id="emailOptionsModal" class="email-modal no-print" role="dialog" aria-modal="true" aria-labelledby="emailOptionsTitle">
        <div class="option-box">
            <h3 id="emailOptionsTitle">How would you like to email this quote?</h3>
            <p>The customer will receive the quote details in the email either way. The selected Lowe sales representative will automatically receive a CC.</p>
            <label style="display:flex;align-items:center;gap:9px;margin:14px 0 18px;font-weight:700;color:#0B2A5B;cursor:pointer">
                <input type="checkbox" id="ccOrdersOption" style="width:18px;height:18px">
                Also CC orders@lowechemical.com
            </label>
            <div class="option-actions">
                <button type="button" class="btn btn-secondary" onclick="sendQuoteEmail(false)">Email Only</button>
                <button type="button" class="btn btn-green" onclick="sendQuoteEmail(true)">Email + PDF Attachment</button>
                <button type="button" class="btn add-row" onclick="closeEmailOptions()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="customerModal" class="customer-modal no-print" role="dialog" aria-modal="true" aria-labelledby="customerModalTitle">
        <div class="customer-box">
            <h3 id="customerModalTitle">Add New Customer</h3>
            <p>This adds the customer to the Lowe quote database so the company can be searched on future quotes.</p>
            <div class="form-grid">
                <div class="form-group full"><label>Company *</label><input id="newCustomerCompany" autocomplete="organization"></div>
                <div class="form-group"><label>Customer Number <span class="help">(optional)</span></label><input id="newCustomerNo" placeholder="Leave blank to auto-generate"></div>
                <div class="form-group"><label class="mandatory-label">Phone</label><input id="newCustomerPhone"></div>
                <div class="form-group full"><label>Street Address</label><input id="newCustomerAddress"></div>
                <div class="form-group"><label>City</label><input id="newCustomerCity"></div>
                <div class="form-group"><label>State</label><input id="newCustomerState" maxlength="2"></div>
                <div class="form-group"><label>ZIP</label><input id="newCustomerZip"></div>
                <div class="form-group"><label class="mandatory-label">Contact Name</label><input id="newCustomerContact"></div>
                <div class="form-group"><label class="mandatory-label">Email</label><input type="email" id="newCustomerEmail"></div>
            </div>
            <div id="newCustomerError" class="modal-error"></div>
            <div class="customer-actions">
                <button type="button" class="btn add-row" onclick="closeCustomerModal()">Cancel</button>
                <button type="button" class="btn btn-secondary" id="saveNewCustomer" onclick="saveNewCustomer()">Add Customer</button>
            </div>
        </div>
    </div>

    <div id="manualProductModal" class="manual-product-modal no-print" role="dialog" aria-modal="true" aria-labelledby="manualProductTitle">
        <div class="manual-product-box">
            <h3 id="manualProductTitle">Add Product Not in Lowe Product Master</h3>
            <p>Use this when the product is not available in the Lowe product search. Enter the customer-facing product information manually.</p>
            <div class="form-grid">
                <div class="form-group full">
                    <label class="mandatory-label">Product Name</label>
                    <input id="manualProductName" class="mandatory-field" placeholder="Example: Specialty Blend XYZ">
                </div>
                <div class="form-group full">
                    <label>Description / Specification</label>
                    <input id="manualProductDescription" placeholder="Optional grade, concentration, specification, or notes">
                </div>
                <div class="form-group">
                    <label class="mandatory-label">Package Type</label>
                    <input id="manualProductPackaging" class="mandatory-field" placeholder="Example: Tote, Drum, Bag, Bulk">
                </div>
                <div class="form-group">
                    <label class="mandatory-label">Qty</label>
                    <input type="number" id="manualProductQty" class="mandatory-field" min="0.01" step="0.01" value="1">
                </div>
                <div class="form-group">
                    <label class="mandatory-label">Total Units</label>
                    <input type="number" id="manualProductTotalUnits" class="mandatory-field" min="0.01" step="0.01" placeholder="Example: 1950">
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select id="manualProductUnit">
                        <option>LB</option><option>KG</option><option>GAL</option><option>DRUM</option><option>TOTE</option><option>BAG</option><option>PAIL</option><option>EA</option><option>LOAD</option>
                    </select>
                </div>
            </div>
            <div class="manual-product-note">The quote builder will calculate Weight per Unit as <strong>Total Units ÷ Qty</strong> so the existing quote calculations remain accurate. You can still edit the resulting row before generating the quote.</div>
            <div id="manualProductError" class="modal-error"></div>
            <div class="manual-product-actions">
                <button type="button" class="btn add-row" onclick="closeManualProductModal()">Cancel</button>
                <button type="button" class="btn btn-secondary" onclick="addManualProduct()">Add Product to Quote</button>
            </div>
        </div>
    </div>

    <div class="preview-wrap" id="quotePreview">
        <div class="preview-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print / Save as PDF</button>
        </div>

        <div class="quote-sheet">
            <div class="quote-header">
                <div class="brand lowe-brand">
                    <img src="/images/lowe-logo.png" alt="Lowe Chemical Company logo" class="lowe-logo">
                    <p class="tagline">Our Chemistry Enhances Your Chemistry</p>
                    <p>8300 Baker Ave. • Cleveland, OH 44102</p>
                    <p>216-961-4222 • 800-837-5693 • sales@lowechemical.com</p>
                </div>
                <div class="quote-title">
                    <h1>PRICE QUOTE</h1>
                    <div><strong><?= h($quote['quote_number']) ?></strong></div>
                    <div>Date: <?= h(date('M j, Y', strtotime($quote['quote_date']))) ?></div>
                    <div>Valid Through: <?= h(date('M j, Y', strtotime($quote['valid_through']))) ?></div>
                </div>
            </div>

            <div class="quote-meta">
                <div class="box usps-customer">
                    <p class="usps-company" id="previewCustomerCompany"><?= h(strtoupper($quote['customer_company'] ?: 'Customer Company')) ?></p>
                    <p id="previewCustomerName"<?= $quote['customer_name'] ? '' : ' style="display:none"' ?>><span><?= h(strtoupper($quote['customer_name'])) ?></span></p>
                    <p id="previewCustomerAddress"<?= !empty($quote['customer_address']) ? '' : ' style="display:none"' ?>><span><?= h(strtoupper($quote['customer_address'] ?? '')) ?></span></p>
                    <?php $previewCsz = trim(strtoupper(trim((string)($quote['customer_city'] ?? '')) . ' ' . trim((string)($quote['customer_state'] ?? ''))) . ' ' . trim((string)($quote['customer_zipcode'] ?? ''))); ?>
                    <p id="previewCustomerCityStateZip"<?= $previewCsz !== '' ? '' : ' style="display:none"' ?>><span><?= h($previewCsz) ?></span></p>
                    <div class="usps-contact-gap"></div>
                    <p id="previewCustomerPhone"<?= $quote['customer_phone'] ? '' : ' style="display:none"' ?>><span><?= h($quote['customer_phone']) ?></span></p>
                    <p id="previewCustomerEmail"<?= $quote['customer_email'] ? '' : ' style="display:none"' ?>><span><?= h($quote['customer_email']) ?></span></p>
                </div>
                <div class="box prepared-by">
                    <p class="prepared-name" id="previewSalesRep"><strong><?= h($quote['sales_rep'] ?: 'Lowe Chemical Sales') ?></strong></p>
                    <p id="previewSalesEmail"<?= $quote['sales_email'] ? '' : ' style="display:none"' ?>><span><?= h($quote['sales_email']) ?></span></p>
                    <p id="previewSalesPhone"<?= $quote['sales_phone'] ? '' : ' style="display:none"' ?>><span><?= h($quote['sales_phone']) ?></span></p>
                    <p id="previewPayment"><strong>Payment:</strong> <span><?= h($quote['payment_terms']) ?></span></p>
                    <p id="previewFreight"><strong>Freight:</strong> <span><?= h($quote['freight_terms']) ?></span></p>
                    <p id="previewLeadTime"><strong>Lead Time:</strong> <span><?= h($quote['lead_time']) ?></span></p>
                </div>
            </div>

            <table class="quote-table">
                <thead>
                    <tr>
                        <th>Product / Specification</th>
                        <th class="right">Qty</th>
                        <th class="right">Wt / Unit</th>
                        <th class="right">Total Units</th>
                        <th class="right">Unit Price</th>
                        <?php if (!empty($quote['include_totals'])): ?><th class="right preview-extended-col">Extended Price</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quote['items'] as $item):
                        $qty = number_clean($item['quantity']);
                        $weightPerUnit = number_clean($item['units_ordered'] ?? 1);
                        $unit = trim((string)($item['unit'] ?? 'LB')) ?: 'LB';
                        $extended = $qty * $weightPerUnit * number_clean($item['unit_price']);
                    ?>
                    <tr>
                        <td>
                            <div class="product-title"><?= h($item['product'] ?: 'Product') ?></div>
                            <?php if ($item['description']): ?><div><?= h($item['description']) ?></div><?php endif; ?>
                        </td>
                        <td class="right"><?= h(quote_number($qty)) ?></td>
                        <td class="right"><?= h(quote_number($weightPerUnit)) ?> / <?= h($unit) ?></td>
                        <td class="right"><?= h(quote_number($qty * $weightPerUnit)) ?></td>
                        <td class="right"><?= money($item['unit_price']) ?>/<?= h(strtolower($unit)) ?></td>
                        <?php if (!empty($quote['include_totals'])): ?><td class="right preview-extended-col"><strong><?= money($extended) ?></strong></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="quote-bottom" id="previewQuoteBottom">
                <div class="terms">
                    <?php if ($quote['shipping_method']): ?><p><strong>Shipping Method:</strong> <?= h($quote['shipping_method']) ?></p><?php endif; ?>
                    <?php if ($quote['special_instructions']): ?><p><strong>Special Instructions:</strong><br><?= nl2br(h($quote['special_instructions'])) ?></p><?php endif; ?>
                    <?php if ($quote['notes']): ?><p><strong>Notes:</strong><br><?= nl2br(h($quote['notes'])) ?></p><?php endif; ?>
                    <p><strong>Commercial Terms:</strong> Pricing is based on the quantity, packaging, delivery location, and freight assumptions shown on this quote. Product availability, freight rates, fuel surcharges, tariffs, taxes, and accessorial charges may change prior to order acceptance unless specifically stated as firm. Delivery dates are estimates unless Lowe Chemical Company expressly confirms a guaranteed delivery date in writing.</p>
                    <p>All sales are subject to Lowe Chemical Company's applicable terms and conditions of sale. Customer purchase-order terms that conflict with Lowe Chemical Company's terms are not accepted unless expressly agreed to in writing by Lowe Chemical Company.</p>
                </div>
                <div class="summary" id="previewSummary" style="<?= !empty($quote['include_totals']) ? '' : 'display:none;' ?>">
                    <div class="summary-row"><span>Product Subtotal</span><strong><?= money($subtotal) ?></strong></div>
                    <?php if ($quote['freight'] > 0): ?><div class="summary-row"><span>Freight</span><strong><?= money($quote['freight']) ?></strong></div><?php endif; ?>
                    <?php if ($quote['hazmat'] > 0): ?><div class="summary-row"><span>Hazmat / Accessorial</span><strong><?= money($quote['hazmat']) ?></strong></div><?php endif; ?>
                    <?php if ($quote['other'] > 0): ?><div class="summary-row"><span>Other</span><strong><?= money($quote['other']) ?></strong></div><?php endif; ?>
                    <?php if ($quote['tax_rate'] > 0): ?><div class="summary-row"><span>Tax (<?= h(number_format($quote['tax_rate'], 3)) ?>%)</span><strong><?= money($tax) ?></strong></div><?php endif; ?>
                    <div class="summary-row total"><span>TOTAL</span><span><?= money($grandTotal) ?></span></div>
                </div>
            </div>
            <div class="po-instructions">To place your order, send your purchase order to <a href="mailto:orders@lowechemical.com">orders@lowechemical.com</a>.</div>
            <div class="lowe-footer">
                <div><strong>Lowe Chemical Company</strong> • Founded 1968</div>
                <div>8300 Baker Ave., Cleveland, OH 44102 • 216-961-4222 • 800-837-5693 • Fax 216-961-4904 • sales@lowechemical.com</div>
                <div class="footer-tagline">Our Chemistry Enhances Your Chemistry</div>
            </div>
        </div>
    </div>
</div>

<script>
function rowTemplate(){
    return `<tr>
        <td><input type="hidden" name="item_product_number[]"><div class="voice-field"><input class="mandatory-field" name="item_product[]" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate product" aria-label="Dictate product">🎤</button></div></td>
        <td><div class="voice-field"><input class="mandatory-field" name="item_description[]" required><button type="button" class="voice-btn" data-voice-target="prev" title="Dictate description or specification" aria-label="Dictate description or specification">🎤</button></div></td>
        <td><input class="mandatory-field" name="item_packaging[]" required></td>
        <td><input type="number" step="0.01" min="0.01" class="qty mandatory-field" name="item_quantity[]" value="1" required></td>
        <td><input type="number" step="0.01" min="0.01" class="units-ordered mandatory-field" name="item_units_ordered[]" value="1" required></td>
        <td><select name="item_unit[]"><option>LB</option><option>KG</option><option>GAL</option><option>DRUM</option><option>TOTE</option><option>BAG</option><option>PAIL</option><option>EA</option><option>LOAD</option></select></td>
        <td class="total-units"><strong>1</strong></td>
        <td><input type="number" step="0.0001" min="0.0001" class="unit-price mandatory-field" name="item_unit_price[]" value="" required></td>
        <td class="row-extended"><strong>$0.00</strong></td>
        <td><button type="button" class="remove-row" onclick="removeRow(this)">×</button></td>
    </tr>`;
}

function addRow(){
    document.querySelector('#itemsTable tbody').insertAdjacentHTML('beforeend', rowTemplate());
    calculateTotals();
    labelMobileCells();
}

function removeRow(button){
    const tbody = document.querySelector('#itemsTable tbody');
    if (tbody.rows.length > 1) {
        button.closest('tr').remove();
        calculateTotals();
    }
}

function n(v){ return parseFloat(v) || 0; }
function dollars(v){ return new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v); }

function syncQuotePreview(subtotal, freight, hazmat, other, rate, tax, total){
    // Keep the Customer Information section synchronized with the customer fields.
    const company = document.getElementById('customerCompany')?.value?.trim() || '';
    const contact = document.querySelector('[name="customer_name"]')?.value?.trim() || '';
    const email = document.querySelector('[name="customer_email"]')?.value?.trim() || '';
    const phone = document.getElementById('customerPhone')?.value?.trim() || '';
    let address = document.getElementById('customerAddress')?.value?.trim() || '';
    let city = document.getElementById('customerCity')?.value?.trim() || '';
    let state = document.getElementById('customerState')?.value?.trim() || '';
    let zipcode = document.getElementById('customerZipcode')?.value?.trim() || '';
    const billTo = document.getElementById('billTo')?.value?.trim() || '';
    if (billTo && (!address || !city || !state || !zipcode)) {
        const lines = billTo.split(/\r?\n/).map(v=>v.trim()).filter(Boolean);
        if (!address && lines[0]) address = lines[0];
        if (lines[1]) {
            const m = lines[1].match(/^\s*(.*?)\s*,\s*([A-Za-z]{2})\s*,?\s*(\d{5}(?:-\d{4})?)?\s*$/);
            if (m) { if (!city) city=m[1]||''; if (!state) state=(m[2]||'').toUpperCase(); if (!zipcode) zipcode=m[3]||''; }
        }
    }

    const companyEl = document.getElementById('previewCustomerCompany');
    if (companyEl) companyEl.textContent = (company || 'Customer Company').toUpperCase();

    const setSimplePreview = (id, value, uppercase = false) => {
        const el = document.getElementById(id);
        if (!el) return;
        const span = el.querySelector('span');
        if (span) span.textContent = uppercase ? String(value || '').toUpperCase() : value;
        el.style.display = value ? '' : 'none';
    };
    setSimplePreview('previewCustomerName', contact, true);
    setSimplePreview('previewCustomerAddress', address, true);
    const cityStateZip = [city, state, zipcode].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim().toUpperCase();
    setSimplePreview('previewCustomerCityStateZip', cityStateZip);
    setSimplePreview('previewCustomerPhone', phone);
    setSimplePreview('previewCustomerEmail', email);

    // Keep the Lowe sales representative block synchronized too.
    const repName = document.querySelector('[name="sales_rep"]')?.value?.trim() || '';
    const repEmail = document.querySelector('[name="sales_email"]')?.value?.trim() || '';
    const repPhone = document.querySelector('[name="sales_phone"]')?.value?.trim() || '';
    const paymentTerms = document.querySelector('[name="payment_terms"]')?.value?.trim() || '';
    const freightTerms = document.querySelector('[name="freight_terms"]')?.value?.trim() || '';
    const leadTime = document.querySelector('[name="lead_time"]')?.value?.trim() || '';
    const repEl = document.getElementById('previewSalesRep');
    if (repEl) repEl.innerHTML = '<strong>' + escapeHtml(repName || 'Lowe Chemical Sales') + '</strong>';
    setSimplePreview('previewSalesEmail', repEmail);
    setSimplePreview('previewSalesPhone', repPhone);
    [['previewPayment',paymentTerms],['previewFreight',freightTerms],['previewLeadTime',leadTime]].forEach(([id,val])=>{
        const el=document.getElementById(id); if(!el)return; const span=el.querySelector('span'); if(span)span.textContent=val; el.style.display=val?'':'none';
    });
    const includeTotals = document.getElementById('includeTotals')?.checked || false;
    const previewHeadRow = document.querySelector('#quotePreview .quote-table thead tr');
    if (previewHeadRow) {
        const existingExt = previewHeadRow.querySelector('.preview-extended-col');
        if (includeTotals && !existingExt) {
            const th = document.createElement('th');
            th.className = 'right preview-extended-col';
            th.textContent = 'Extended Price';
            previewHeadRow.appendChild(th);
        } else if (!includeTotals && existingExt) {
            existingExt.remove();
        }
    }
    const previewBody = document.querySelector('#quotePreview .quote-table tbody');
    if (previewBody) {
        previewBody.innerHTML = '';
        document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
            const product = row.querySelector('[name="item_product[]"]')?.value?.trim() || 'Product';
            const description = row.querySelector('[name="item_description[]"]')?.value?.trim() || '';
            const qty = n(row.querySelector('.qty')?.value);
            const unitsOrdered = Math.max(0, n(row.querySelector('.units-ordered')?.value));
            const unit = row.querySelector('[name="item_unit[]"]')?.value || 'LB';
            const price = n(row.querySelector('.unit-price')?.value);
            const totalUnits = qty * unitsOrdered;
            const extended = totalUnits * price;
            const displayNumber = (value) => Number(value || 0).toLocaleString(undefined,{maximumFractionDigits:2});

            const tr = document.createElement('tr');
            const productTd = document.createElement('td');
            const title = document.createElement('div');
            title.className = 'product-title';
            title.textContent = product;
            productTd.appendChild(title);
            if (description) {
                const desc = document.createElement('div');
                desc.textContent = description;
                productTd.appendChild(desc);
            }

            const qtyTd = document.createElement('td');
            qtyTd.className = 'right';
            qtyTd.textContent = displayNumber(qty);

            const weightTd = document.createElement('td');
            weightTd.className = 'right';
            weightTd.textContent = displayNumber(unitsOrdered) + ' / ' + unit;

            const totalUnitsTd = document.createElement('td');
            totalUnitsTd.className = 'right';
            totalUnitsTd.textContent = displayNumber(totalUnits);

            const priceTd = document.createElement('td');
            priceTd.className = 'right';
            priceTd.textContent = dollars(price) + '/' + unit.toLowerCase();

            const extendedTd = document.createElement('td');
            extendedTd.className = 'right preview-extended-col';
            const strong = document.createElement('strong');
            strong.textContent = dollars(extended);
            extendedTd.appendChild(strong);

            const includeTotals = document.getElementById('includeTotals')?.checked || false;
            if (includeTotals) tr.append(productTd, qtyTd, weightTd, totalUnitsTd, priceTd, extendedTd);
            else tr.append(productTd, qtyTd, weightTd, totalUnitsTd, priceTd);
            previewBody.appendChild(tr);
        });
    }

    const previewSummary = document.querySelector('#quotePreview .quote-bottom .summary');
    const previewBottom = document.getElementById('previewQuoteBottom');
    if (previewBottom) previewBottom.style.gridTemplateColumns = includeTotals ? '1fr 310px' : '1fr';
    if (previewSummary) {
        previewSummary.style.display = includeTotals ? '' : 'none';
        previewSummary.innerHTML = '';
        const addSummaryRow = (label, value, totalRow = false) => {
            const row = document.createElement('div');
            row.className = 'summary-row' + (totalRow ? ' total' : '');
            const labelEl = document.createElement('span');
            labelEl.textContent = label;
            const valueEl = totalRow ? document.createElement('span') : document.createElement('strong');
            valueEl.textContent = dollars(value);
            row.append(labelEl, valueEl);
            previewSummary.appendChild(row);
        };
        if (includeTotals) {
        addSummaryRow('Product Subtotal', subtotal);
        if (freight > 0) addSummaryRow('Freight', freight);
        if (hazmat > 0) addSummaryRow('Hazmat / Accessorial', hazmat);
        if (other > 0) addSummaryRow('Other', other);
        if (rate > 0) addSummaryRow('Tax (' + rate.toFixed(3) + '%)', tax);
        addSummaryRow('TOTAL', total, true);
        }
    }
}

function toggleTotalsOption(){
    const checked = document.getElementById('includeTotals')?.checked || false;
    const panel = document.getElementById('chargesPanel');
    if (panel) panel.style.display = checked ? '' : 'none';
    calculateTotals();
}

function calculateTotals(){
    let subtotal = 0;
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const qty = n(row.querySelector('.qty')?.value);
        const unitsOrdered = Math.max(1, n(row.querySelector('.units-ordered')?.value) || 1);
        const price = n(row.querySelector('.unit-price')?.value);
        const totalUnits = qty * unitsOrdered;
        const extended = totalUnits * price;
        subtotal += extended;
        const totalUnitsCell = row.querySelector('.total-units strong');
        if (totalUnitsCell) totalUnitsCell.textContent = totalUnits.toLocaleString(undefined,{maximumFractionDigits:2});
        const extendedCell = row.querySelector('.row-extended strong');
        if (extendedCell) extendedCell.textContent = dollars(extended);
    });
    const freight = n(document.getElementById('freight')?.value);
    const hazmat = n(document.getElementById('hazmat')?.value);
    const other = n(document.getElementById('other')?.value);
    const rate = n(document.getElementById('taxRate')?.value);
    const preTax = subtotal + freight + hazmat + other;
    const tax = preTax * (rate / 100);
    const total = preTax + tax;
    document.getElementById('jsSubtotal').textContent = dollars(subtotal);
    document.getElementById('jsTax').textContent = dollars(tax);
    document.getElementById('jsTotal').textContent = dollars(total);
    syncQuotePreview(subtotal, freight, hazmat, other, rate, tax, total);
}



// ---- Device selection -----------------------------------------------------
function applyDeviceMode(mode){
    document.body.classList.toggle('mobile-mode', mode === 'mobile');
    document.body.classList.toggle('desktop-mode', mode === 'desktop');
    const gate = document.getElementById('deviceGate');
    if (gate) gate.classList.add('hidden');
    try { sessionStorage.setItem('loweQuoteDevice', mode); } catch(e) {}
    labelMobileCells();
}
function chooseDevice(mode){ applyDeviceMode(mode); }
function showDeviceGate(){
    const gate = document.getElementById('deviceGate');
    if (gate) gate.classList.remove('hidden');
}
function labelMobileCells(){
    const table=document.getElementById('itemsTable');
    if(!table) return;
    const headers=[...table.querySelectorAll('thead th')].map(th=>{
        const base=th.textContent.trim();
        return th.classList.contains('mandatory-heading') && base ? base+' *' : base;
    });
    table.querySelectorAll('tbody tr').forEach(tr=>[...tr.children].forEach((td,i)=>td.setAttribute('data-label',headers[i]||'')));
}
try {
    const savedDevice=sessionStorage.getItem('loweQuoteDevice');
    if(savedDevice==='mobile'||savedDevice==='desktop') applyDeviceMode(savedDevice);
} catch(e) {}

// ---- Customer + product database ----------------------------------------
const repSearch=document.getElementById('repSearch');
const repResults=document.getElementById('repResults');
const salesRepEmail=document.getElementById('salesRepEmail');
const salesRepPhone=document.getElementById('salesRepPhone');

function openEmailOptions(){
 const email=document.querySelector('[name="customer_email"]');
 if(!email || !email.value.trim()) { alert('Enter the customer email address before emailing the quote.'); if(email) email.focus(); return; }
 document.getElementById('emailOptionsModal').classList.add('open');
}
function closeEmailOptions(){document.getElementById('emailOptionsModal').classList.remove('open');}
function sendQuoteEmail(withPdf){
 document.getElementById('attachPdf').value=withPdf?'1':'0';
 document.getElementById('ccOrders').value=document.getElementById('ccOrdersOption')?.checked?'1':'0';
 closeEmailOptions();
 document.getElementById('quoteForm').requestSubmit(document.getElementById('emailSubmit'));
}

function openManualProductModal(){
  const modal=document.getElementById('manualProductModal');
  const err=document.getElementById('manualProductError');
  if(err){err.textContent='';err.classList.remove('show');}
  ['manualProductName','manualProductDescription','manualProductPackaging','manualProductTotalUnits'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.value='';
  });
  const qty=document.getElementById('manualProductQty'); if(qty) qty.value='1';
  const unit=document.getElementById('manualProductUnit'); if(unit) unit.value='LB';
  if(modal) modal.classList.add('open');
  setTimeout(()=>document.getElementById('manualProductName')?.focus(),50);
}

function closeManualProductModal(){
  document.getElementById('manualProductModal')?.classList.remove('open');
}

function addManualProduct(){
  const err=document.getElementById('manualProductError');
  const productName=document.getElementById('manualProductName')?.value.trim()||'';
  const description=document.getElementById('manualProductDescription')?.value.trim()||'';
  const packaging=document.getElementById('manualProductPackaging')?.value.trim()||'';
  const qty=Math.max(0,n(document.getElementById('manualProductQty')?.value));
  const totalUnits=Math.max(0,n(document.getElementById('manualProductTotalUnits')?.value));
  const unit=document.getElementById('manualProductUnit')?.value||'LB';

  if(!productName || !packaging || qty<=0 || totalUnits<=0){
    if(err){
      err.textContent='Product Name, Package Type, Qty, and Total Units are required.';
      err.classList.add('show');
    }
    return;
  }

  let rows=[...document.querySelectorAll('#itemsTable tbody tr')];
  let row=rows.find(r=>{
    const productField=r.querySelector('[name="item_product[]"]');
    const descriptionField=r.querySelector('[name="item_description[]"]');
    return productField && descriptionField && !productField.value.trim() && !descriptionField.value.trim();
  });
  if(!row){
    addRow();
    rows=[...document.querySelectorAll('#itemsTable tbody tr')];
    row=rows[rows.length-1];
  }
  if(!row) return;

  const weightPerUnit=totalUnits/qty;
  const productNumber=row.querySelector('[name="item_product_number[]"]');
  const productField=row.querySelector('[name="item_product[]"]');
  const descriptionField=row.querySelector('[name="item_description[]"]');
  const packagingField=row.querySelector('[name="item_packaging[]"]');
  const qtyField=row.querySelector('[name="item_quantity[]"]');
  const weightField=row.querySelector('[name="item_units_ordered[]"]');
  const unitField=row.querySelector('[name="item_unit[]"]');
  const priceField=row.querySelector('[name="item_unit_price[]"]');

  if(productNumber) productNumber.value='';
  if(productField) productField.value=productName;
  if(descriptionField) descriptionField.value=description || 'Manual product entry';
  if(packagingField) packagingField.value=packaging;
  if(qtyField) qtyField.value=String(qty);
  if(weightField) weightField.value=String(Number(weightPerUnit.toFixed(6)));
  if(unitField && [...unitField.options].some(o=>o.value===unit)) unitField.value=unit;
  if(priceField) priceField.value='';

  [productField,descriptionField,packagingField,qtyField,weightField,unitField].filter(Boolean).forEach(el=>{
    el.dispatchEvent(new Event('input',{bubbles:true}));
    el.dispatchEvent(new Event('change',{bubbles:true}));
  });

  closeManualProductModal();
  labelMobileCells();
  calculateTotals();
  row.scrollIntoView({behavior:'smooth',block:'center'});
  setTimeout(()=>priceField?.focus(),250);
}

function openCustomerModal(){
 document.getElementById('newCustomerCompany').value=(document.getElementById('customerSearch')?.value||'').trim();
 document.getElementById('newCustomerError').classList.remove('show');
 document.getElementById('newCustomerError').textContent='';
 document.getElementById('customerModal').classList.add('open');
 setTimeout(()=>document.getElementById('newCustomerCompany').focus(),50);
}
function closeCustomerModal(){document.getElementById('customerModal').classList.remove('open');}
async function saveNewCustomer(){
 const btn=document.getElementById('saveNewCustomer');
 const err=document.getElementById('newCustomerError');
 const company=document.getElementById('newCustomerCompany').value.trim();
 if(!company){err.textContent='Company name is required.';err.classList.add('show');return;}
 const payload={
   customer_name:company,
   customer_no:document.getElementById('newCustomerNo').value.trim(),
   phone:document.getElementById('newCustomerPhone').value.trim(),
   address:document.getElementById('newCustomerAddress').value.trim(),
   city:document.getElementById('newCustomerCity').value.trim(),
   state:document.getElementById('newCustomerState').value.trim().toUpperCase(),
   zipcode:document.getElementById('newCustomerZip').value.trim(),
   lowe_rep:(document.querySelector('[name="sales_rep"]')?.value||'').trim()
 };
 btn.disabled=true;btn.textContent='Adding...';err.classList.remove('show');
 try{
   const r=await fetch('api/customer_add.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
   const data=await r.json();
   if(!r.ok||data.ok===false) throw new Error(data.error||'Unable to add customer.');
   await selectCustomer(data.customer);
   const contact=document.querySelector('[name="customer_name"]');
   const email=document.querySelector('[name="customer_email"]');
   if(contact) contact.value=document.getElementById('newCustomerContact').value.trim();
   if(email) email.value=document.getElementById('newCustomerEmail').value.trim();
   closeCustomerModal();
   setDbStatus('New customer added to the Lowe quote database and selected.','ok');
 }catch(e){err.textContent=e.message;err.classList.add('show');}
 finally{btn.disabled=false;btn.textContent='Add Customer';}
}

const customerSearch=document.getElementById('customerSearch');
const customerResults=document.getElementById('customerResults');
const productSearch=document.getElementById('allProductSearch');
const productResults=document.getElementById('productResults');
const historySelect=document.getElementById('historyProducts');
const addHistoryButton=document.getElementById('addHistoryProduct');
let historyProducts=[];
let repTimer=null, customerTimer=null, productTimer=null;

function escapeHtml(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
function setDbStatus(text,type=''){const el=document.getElementById('dbStatus');if(!el)return;el.textContent=text;el.className='db-status'+(type?' '+type:'');}
async function getJson(url){const r=await fetch(url,{headers:{'Accept':'application/json'}});const data=await r.json();if(!r.ok||data.ok===false)throw new Error(data.error||'Database request failed');return data;}

function setRepStatus(text,type=''){const el=document.getElementById('repStatus');if(!el)return;el.textContent=text;el.className='db-status'+(type?' '+type:'');}

if(repSearch){
 repSearch.addEventListener('input',()=>{
   clearTimeout(repTimer); const q=repSearch.value.trim();
   if(q.length<1){repResults.classList.remove('open');repResults.innerHTML='';return;}
   repTimer=setTimeout(async()=>{
     try{
       const data=await getJson('api/rep_search.php?q='+encodeURIComponent(q));
       repResults.innerHTML=data.results.map((r,i)=>`<button type="button" class="search-result" data-rep-i="${i}"><strong>${escapeHtml(r.name)}</strong><small>${escapeHtml(r.email||'')}${r.phone?' · '+escapeHtml(r.phone):''}</small></button>`).join('') || '<div class="search-result"><small>No matching sales representative found.</small></div>';
       repResults.classList.add('open');
       repResults.querySelectorAll('button[data-rep-i]').forEach(btn=>btn.addEventListener('click',()=>selectRep(data.results[Number(btn.dataset.repI)])));
     }catch(e){setRepStatus(e.message,'error');}
   },150);
 });
}

function selectRep(r){
 const repName=String(r.name||'').trim();
 const repEmail=String(r.email||'').trim();
 const repPhone=String(r.phone||'').trim();

 // Populate all three rep fields explicitly.
 if(repSearch){
   repSearch.value=repName;
   repSearch.setAttribute('value',repName);
   repSearch.dispatchEvent(new Event('change',{bubbles:true}));
 }
 if(salesRepEmail){
   salesRepEmail.value=repEmail;
   salesRepEmail.setAttribute('value',repEmail);
 }
 if(salesRepPhone){
   salesRepPhone.value=repPhone;
   salesRepPhone.setAttribute('value',repPhone);
 }

 // Re-apply the rep name after the result click finishes so browser
 // autocomplete/search UI cannot clear the visible field.
 requestAnimationFrame(()=>{ if(repSearch) repSearch.value=repName; });

 repResults.classList.remove('open');
 repResults.innerHTML='';
 setRepStatus(repName ? 'Sales representative selected: '+repName : 'Sales representative selected.','ok');
}

if(customerSearch){
 customerSearch.addEventListener('input',()=>{
   clearTimeout(customerTimer); const q=customerSearch.value.trim();
   if(q.length<1){customerResults.classList.remove('open');customerResults.innerHTML='';return;}
   customerTimer=setTimeout(async()=>{
     try{
       const data=await getJson('api/customer_search.php?q='+encodeURIComponent(q));
       customerResults.innerHTML=data.results.map((c,i)=>`<button type="button" class="search-result" data-i="${i}"><strong>${escapeHtml(c.customer_name)}</strong><small>${escapeHtml(c.customer_no)} · ${escapeHtml([c.city,c.state,c.zipcode].filter(Boolean).join(', '))} · ${escapeHtml(c.phone||'')}</small></button>`).join('') || '<div class="search-result"><small>No matching customer found.</small></div>';
       customerResults.classList.add('open');
       customerResults.querySelectorAll('button[data-i]').forEach(btn=>btn.addEventListener('click',()=>selectCustomer(data.results[Number(btn.dataset.i)])));
     }catch(e){setDbStatus(e.message,'error');}
   },180);
 });
}

async function selectCustomer(c){
 document.getElementById('customerNo').value=c.customer_no||'';
 document.getElementById('customerCompany').value=c.customer_name||'';
 document.getElementById('customerPhone').value=c.phone||'';
 document.getElementById('customerAddress').value=c.address||'';
 document.getElementById('customerCity').value=c.city||'';
 document.getElementById('customerState').value=c.state||'';
 document.getElementById('customerZipcode').value=c.zipcode||'';
 customerSearch.value=c.customer_name||'';
 const address=[c.address,[c.city,c.state,c.zipcode].filter(Boolean).join(', ')].filter(Boolean).join('\n');
 document.getElementById('billTo').value=address; document.getElementById('shipTo').value=address;
 const salesRep=document.querySelector('[name="sales_rep"]'); if(salesRep && !salesRep.value.trim()) salesRep.value=c.lowe_rep||'';
 document.getElementById('selectedCustomerText').textContent=(c.customer_no||'')+' · '+(c.customer_name||'');
 document.getElementById('selectedCustomer').classList.add('show'); customerResults.classList.remove('open');
 calculateTotals();
 setDbStatus('Customer selected. Loading past product history…');
 await loadCustomerProducts(c.customer_no);
 calculateTotals();
}

async function loadCustomerProducts(customerNo){
 historyProducts=[]; historySelect.innerHTML='<option value="">Loading…</option>'; historySelect.disabled=true; addHistoryButton.disabled=true;
 try{
   const data=await getJson('api/customer_products.php?customer_no='+encodeURIComponent(customerNo)); historyProducts=data.results||[];
   if(!historyProducts.length){historySelect.innerHTML='<option value="">No past products found for this customer</option>';setDbStatus('Customer loaded. No past products were found; use the all-products search.','ok');return;}
   historySelect.innerHTML='<option value="">Choose from '+historyProducts.length+' past product'+(historyProducts.length===1?'':'s')+'…</option>'+historyProducts.map((p,i)=>`<option value="${i}">${escapeHtml(p.product_number)} · ${escapeHtml(p.product_description||p.history_product_name)}${p.uom?' · '+escapeHtml(p.uom):''}</option>`).join('');
   historySelect.disabled=false; addHistoryButton.disabled=false; setDbStatus('Customer loaded with '+historyProducts.length+' past product option'+(historyProducts.length===1?'':'s')+'.','ok');
 }catch(e){historySelect.innerHTML='<option value="">Unable to load history</option>';setDbStatus(e.message,'error');}
}

function uomToQuoteUnit(uom){const m={DM:'DRUM',DR:'DRUM',TO:'TOTE',TT:'TOTE',BG:'BAG',BA:'BAG',PL:'PAIL',PA:'PAIL',LB:'LB',KG:'KG',GL:'GAL',GA:'GAL',EA:'EA'};return m[String(uom||'').toUpperCase()]||'LB';}
function addSelectedHistoryProduct(){
  if(!historySelect) return;
  const optionIndex = historySelect.selectedIndex;
  const fallbackIndex = Number(historySelect.value);
  let product = null;
  if(optionIndex > 0 && historyProducts[optionIndex - 1]) product = historyProducts[optionIndex - 1];
  else if(Number.isInteger(fallbackIndex) && historyProducts[fallbackIndex]) product = historyProducts[fallbackIndex];
  if(!product) return;
  addProductToQuote(product,true);
}
function addProductToQuote(p,fromHistory=false){
  if(!p || typeof p !== 'object') return;
  let rows=[...document.querySelectorAll('#itemsTable tbody tr')];
  let row=rows.find(r=>{
    const productField=r.querySelector('[name="item_product[]"]');
    const descriptionField=r.querySelector('[name="item_description[]"]');
    return productField && descriptionField && !productField.value.trim() && !descriptionField.value.trim();
  });
  if(!row){addRow();rows=[...document.querySelectorAll('#itemsTable tbody tr')];row=rows[rows.length-1];}
  if(!row) return;

  const productNumber = p.product_number || p.prod_number || p.product_no || '';
  const productName = p.product_description || p.history_product_name || p.product_name || p.name || '';
  const packaging = p.container || p.packaging || p.package_type || '';
  const rawWeight = p.lbs_per_uom || p.container_weight || p.weight_per_unit || p.unit_weight || 0;
  const weight = parseFloat(rawWeight) || 0;

  const numberField=row.querySelector('[name="item_product_number[]"]');
  const productField=row.querySelector('[name="item_product[]"]');
  const descriptionField=row.querySelector('[name="item_description[]"]');
  const packagingField=row.querySelector('[name="item_packaging[]"]');
  const qtyInput=row.querySelector('[name="item_quantity[]"]');
  const unitsInput=row.querySelector('[name="item_units_ordered[]"]');
  const unit=row.querySelector('[name="item_unit[]"]');
  const priceField=row.querySelector('[name="item_unit_price[]"]');

  if(numberField) numberField.value=productNumber;
  if(productField) productField.value=productName;
  const description=[];
  if(productNumber) description.push('Product #'+productNumber);
  if(fromHistory && weight>0) description.push(weight+' LB/UOM');
  if(descriptionField) descriptionField.value=description.join(' · ');
  if(packagingField) packagingField.value=packaging;
  if(qtyInput) qtyInput.value='1';

  if(weight>0){
    if(unitsInput) unitsInput.value=String(weight);
    if(unit && [...unit.options].some(o=>o.value==='LB')) unit.value='LB';
  } else {
    if(unitsInput) unitsInput.value='1';
    if(unit){
      const wanted=uomToQuoteUnit(p.uom || p.unit || '');
      if([...unit.options].some(o=>o.value===wanted)) unit.value=wanted;
    }
  }
  if(priceField) priceField.value='';

  [productField,descriptionField,packagingField,qtyInput,unitsInput,unit].filter(Boolean).forEach(el=>{
    el.dispatchEvent(new Event('input',{bubbles:true}));
    el.dispatchEvent(new Event('change',{bubbles:true}));
  });
  labelMobileCells();
  calculateTotals();
  row.scrollIntoView({behavior:'smooth',block:'center'});
}

if(productSearch){
 productSearch.addEventListener('input',()=>{
   clearTimeout(productTimer);const q=productSearch.value.trim();if(q.length<1){productResults.classList.remove('open');productResults.innerHTML='';return;}
   productTimer=setTimeout(async()=>{
     try{const data=await getJson('api/product_search.php?q='+encodeURIComponent(q));
       productResults.innerHTML=data.results.map((p,i)=>`<button type="button" class="search-result" data-i="${i}"><strong>${escapeHtml(p.product_description)}</strong><small>${escapeHtml(p.product_number)}${p.container?' · '+escapeHtml(p.container):''}${p.container_weight?' · '+escapeHtml(p.container_weight)+' lb':''}</small></button>`).join('')||'<div class="search-result"><small>No Lowe product found.</small></div>';
       productResults.classList.add('open');productResults.querySelectorAll('button[data-i]').forEach(btn=>btn.addEventListener('click',()=>{addProductToQuote(data.results[Number(btn.dataset.i)],false);productResults.classList.remove('open');productSearch.value='';}));
     }catch(e){setDbStatus(e.message,'error');}
   },180);
 });
}
document.addEventListener('click',e=>{if(customerResults&&!e.target.closest('.customer-search-wrap'))customerResults.classList.remove('open');if(productResults&&!e.target.closest('.product-search-wrap'))productResults.classList.remove('open');});
const existingCustomerNo=document.getElementById('customerNo')?.value;if(existingCustomerNo)loadCustomerProducts(existingCustomerNo);
labelMobileCells();

// On small screens, bring the first invalid required field fully into view.
const quoteFormForValidation=document.getElementById('quoteForm');
if(quoteFormForValidation){
  quoteFormForValidation.addEventListener('invalid',function(e){
    const field=e.target;
    if(field && typeof field.scrollIntoView==='function'){
      setTimeout(()=>field.scrollIntoView({behavior:'smooth',block:'center'}),0);
    }
  },true);
}

const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
let activeRecognition = null;
let activeVoiceButton = null;

function setVoiceStatus(text, active = false){
    const status = document.getElementById('voiceStatus');
    if (!status) return;
    status.textContent = text;
    status.classList.toggle('active', active);
}

function getVoiceTarget(button){
    const wrapper = button.closest('.voice-field');
    return wrapper ? wrapper.querySelector('input, textarea') : null;
}

function stopVoiceRecognition(){
    if (activeRecognition) {
        try { activeRecognition.stop(); } catch (e) {}
    }
}

function startVoiceRecognition(button){
    if (!SpeechRecognition) {
        setVoiceStatus('Voice recognition is not available in this browser. You can still use your phone or tablet keyboard dictation.', false);
        return;
    }

    if (activeVoiceButton === button && activeRecognition) {
        stopVoiceRecognition();
        return;
    }

    if (activeRecognition) stopVoiceRecognition();

    const target = getVoiceTarget(button);
    if (!target) return;

    const recognition = new SpeechRecognition();
    recognition.lang = 'en-US';
    recognition.interimResults = true;
    recognition.continuous = false;

    const startingText = target.value.trim();
    let finalTranscript = '';

    activeRecognition = recognition;
    activeVoiceButton = button;
    button.classList.add('listening');
    button.setAttribute('aria-label', 'Stop dictation');
    setVoiceStatus('Listening… speak naturally. Tap the microphone again to stop.', true);

    recognition.onresult = function(event){
        let interimTranscript = '';
        finalTranscript = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
            const text = event.results[i][0].transcript;
            if (event.results[i].isFinal) finalTranscript += text;
            else interimTranscript += text;
        }
        const spoken = (finalTranscript || interimTranscript).trim();
        if (spoken) target.value = startingText ? startingText + ' ' + spoken : spoken;
        target.dispatchEvent(new Event('input', {bubbles:true}));
    };

    recognition.onerror = function(event){
        const msg = event.error === 'not-allowed'
            ? 'Microphone permission was denied. Allow microphone access in your browser settings, or use keyboard dictation.'
            : 'Dictation stopped: ' + event.error + '. You can try again or use keyboard dictation.';
        setVoiceStatus(msg, false);
    };

    recognition.onend = function(){
        button.classList.remove('listening');
        button.setAttribute('aria-label', button.getAttribute('title') || 'Start dictation');
        if (activeRecognition === recognition) {
            activeRecognition = null;
            activeVoiceButton = null;
        }
        if (target.value.trim()) setVoiceStatus('Dictation added. Review the text before generating the quote.', false);
    };

    try { recognition.start(); } catch (e) {
        setVoiceStatus('Unable to start microphone dictation. You can still use keyboard dictation.', false);
    }
}

document.addEventListener('click', e => {
    const button = e.target.closest('.voice-btn');
    if (button) startVoiceRecognition(button);
});

if (!SpeechRecognition) {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.voice-btn').forEach(btn => {
            btn.title = 'Browser voice recognition unavailable. Use keyboard dictation.';
        });
        setVoiceStatus('Browser microphone dictation is unavailable here. Phone/tablet keyboard dictation will still work in these fields.', false);
    });
}

document.addEventListener('input', e => {
    if (e.target.matches('.qty,.units-ordered,.unit-price,#freight,#hazmat,#other,#taxRate')) calculateTotals();
    if (e.target.matches('#includeTotals')) toggleTotalsOption();
    if (e.target.matches('[name="customer_company"],[name="customer_name"],[name="customer_email"],[name="customer_phone"],[name="bill_to"],[name="ship_to"],[name="sales_rep"],[name="sales_email"],[name="sales_phone"],[name="payment_terms"],[name="freight_terms"],[name="lead_time"],[name="shipping_method"],[name="special_instructions"],[name="notes"],[name="item_product[]"],[name="item_description[]"],[name="item_packaging[]"],[name="item_unit[]"]')) calculateTotals();
});
document.addEventListener('change', e => {
    if (e.target.matches('[name="freight_terms"],[name="shipping_method"],[name="payment_terms"],[name="item_unit[]"]')) calculateTotals();
});
calculateTotals();
</script>

<script>
document.getElementById('quoteForm')?.addEventListener('invalid', function(e){
    e.target.classList.add('mandatory-field');
}, true);

document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){
    closeManualProductModal();
  }
});

</script>
</body>
</html>

<?php
session_start();
require __DIR__ . '/config/db.php';

const RFQ_EXPORT_TO = 'Sourcing@lowechemical.com';
const RFQ_EXPORT_FROM = 'Sourcing@lowechemical.com';

function fail_export(string $message): never {
    header('Location: rfq-list.php?export=error&message=' . rawurlencode($message));
    exit;
}

function xh(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function excel_col(int $n): string {
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

function eastern_display(?string $value): string {
    if (!$value) return '';
    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format('M j, Y g:i A T');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function write_zip_archive(array $entries, string $path): void {
    $localData = '';
    $centralData = '';
    $offset = 0;
    $count = 0;

    // XLSX is a standard ZIP container. Build it directly so the export does not
    // depend on the optional PHP ZipArchive extension being enabled.
    $dosTime = ((int)date('H') << 11) | ((int)date('i') << 5) | (int)(date('s') / 2);
    $dosDate = (((int)date('Y') - 1980) << 9) | ((int)date('n') << 5) | (int)date('j');

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $crc = crc32($data);
        if ($crc < 0) $crc += 4294967296;
        $compressed = gzdeflate($data, 6);
        if ($compressed === false) throw new RuntimeException('Unable to compress the Excel workbook.');
        $method = 8;
        $compressedSize = strlen($compressed);
        $size = strlen($data);
        $nameLen = strlen($name);

        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate, $crc, $compressedSize, $size, $nameLen, 0);
        $localData .= $localHeader . $name . $compressed;

        $centralHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate, $crc, $compressedSize, $size, $nameLen, 0, 0, 0, 0, 0, $offset);
        $centralData .= $centralHeader . $name;

        $offset += strlen($localHeader) + $nameLen + $compressedSize;
        $count++;
    }

    $centralOffset = strlen($localData);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($centralData), $centralOffset, 0);
    if (file_put_contents($path, $localData . $centralData . $end) === false) {
        throw new RuntimeException('Unable to write the Excel workbook.');
    }
}

function create_xlsx(array $headers, array $rows, string $path): void {

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="RFQ History" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font>'
        . '<font><color rgb="FF008000"/><sz val="10"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0C2340"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $columnWidths = [20,14,16,22,28,18,16,24,16,16,14,15,16,18,18,18,18,18,12,12,22];
    $cols = '<cols>';
    foreach ($columnWidths as $i => $width) {
        $n = $i + 1;
        $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
    }
    $cols .= '</cols>';

    $sheetRows = '';
    $rowNum = 1;
    $sheetRows .= '<row r="1" ht="28" customHeight="1">';
    foreach ($headers as $i => $header) {
        $ref = excel_col($i + 1) . $rowNum;
        $sheetRows .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . xh($header) . '</t></is></c>';
    }
    $sheetRows .= '</row>';

    foreach ($rows as $row) {
        $rowNum++;
        $sheetRows .= '<row r="' . $rowNum . '">';
        foreach (array_values($row) as $i => $value) {
            $ref = excel_col($i + 1) . $rowNum;
            $style = ($i === 19 && (int)$value > 0) ? 2 : 0; // response count in green
            $sheetRows .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . xh((string)$value) . '</t></is></c>';
        }
        $sheetRows .= '</row>';
    }

    $lastCol = excel_col(count($headers));
    $lastRow = max(1, $rowNum);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $cols
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>'
        . '</worksheet>';

    write_zip_archive([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ], $path);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail_export('Invalid request method.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['rfq_export_csrf']) || !hash_equals($_SESSION['rfq_export_csrf'], $token)) {
    fail_export('Your session expired. Refresh the RFQ page and try again.');
}

try {
    $pdo = db();
    $sql = "
        SELECT
            r.*,
            (SELECT COUNT(*) FROM rfq_suppliers s WHERE s.rfq_id=r.id) AS supplier_count,
            (SELECT GROUP_CONCAT(s.supplier_name ORDER BY s.sort_order SEPARATOR ', ') FROM rfq_suppliers s WHERE s.rfq_id=r.id) AS supplier_names,
            (SELECT MAX(s.sent_at) FROM rfq_suppliers s WHERE s.rfq_id=r.id) AS last_sent_at,
            (SELECT COUNT(*) FROM rfq_supplier_responses p WHERE p.rfq_id=r.id) AS response_count
        FROM rfqs r
        ORDER BY r.id DESC
    ";
    $records = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $headers = [
        'RFQ Number','Request Date','Pricing Needed By','Requester','Requester Email','Requester Phone',
        'Product','CAS Number','Product Grade','Packaging','Quantity','UOM','Price Basis','Estimated Annual Usage',
        'Requirement Type','Delivery Address','City','State','ZIP','Responses','Suppliers','Supplier Names','Status','Last Sent'
    ];

    $rows = [];
    foreach ($records as $r) {
        $rows[] = [
            $r['rfq_number'] ?? '',
            $r['request_date'] ?? '',
            $r['pricing_needed_by'] ?? '',
            $r['requester_name'] ?? '',
            $r['requester_email'] ?? '',
            $r['requester_phone'] ?? '',
            $r['product_name'] ?? '',
            $r['cas_number'] ?? '',
            $r['product_grade'] ?? '',
            $r['packaging'] ?? '',
            $r['quantity'] ?? '',
            $r['quantity_uom'] ?? '',
            $r['price_basis'] ?? '',
            $r['estimated_annual_usage'] ?? '',
            $r['requirement_type'] ?? '',
            $r['ship_address'] ?? '',
            $r['ship_city'] ?? '',
            $r['ship_state'] ?? '',
            $r['ship_zip'] ?? '',
            (int)($r['response_count'] ?? 0),
            (int)($r['supplier_count'] ?? 0),
            $r['supplier_names'] ?? '',
            $r['status'] ?? '',
            eastern_display($r['last_sent_at'] ?? null),
        ];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'lowe-rfq-');
    if ($tmp === false) throw new RuntimeException('Unable to create a temporary export file.');
    $xlsxPath = $tmp . '.xlsx';
    @unlink($tmp);

    create_xlsx($headers, $rows, $xlsxPath);

    $filename = 'Lowe_RFQ_History_' . date('Y-m-d') . '.xlsx';
    $subject = 'Lowe Chemical RFQ History - ' . date('M j, Y');
    $body = "Attached is the current Lowe Chemical RFQ history workbook.\r\n\r\n"
          . 'RFQs included: ' . count($rows) . "\r\n"
          . 'Generated: ' . date('M j, Y g:i A') . "\r\n";

    $boundary = '=_LoweRFQ_' . bin2hex(random_bytes(12));
    $headersMail = [
        'MIME-Version: 1.0',
        'From: Lowe Chemical Sourcing <' . RFQ_EXPORT_FROM . '>',
        'Reply-To: ' . RFQ_EXPORT_FROM,
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];

    $message = '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $body . "\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; name="' . $filename . "\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n";
    $message .= chunk_split(base64_encode((string)file_get_contents($xlsxPath))) . "\r\n";
    $message .= '--' . $boundary . "--\r\n";

    $sent = mail(RFQ_EXPORT_TO, $subject, $message, implode("\r\n", $headersMail));
    @unlink($xlsxPath);

    if (!$sent) {
        fail_export('The workbook was created, but SiteGround did not accept the email for sending.');
    }

    header('Location: rfq-list.php?export=sent');
    exit;
} catch (Throwable $e) {
    fail_export($e->getMessage());
}

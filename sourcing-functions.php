<?php

declare(strict_types=1);

const LOWE_SOURCING_EMAIL = 'sourcing@lowechemical.com';
const LOWE_FROM_EMAIL = 'sourcing@lowechemical.com';
const LOWE_FROM_NAME = 'Lowe Chemical Sourcing';

function sourcing_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sourcing_clean_header(string $value): string {
    return trim(str_replace(["\r", "\n"], '', $value));
}

function sourcing_split_requirements(?string $stored): array {
    $stored = trim((string)$stored);
    $application = '';
    $notes = $stored;

    if (preg_match('/^Application:\s*(.*?)(?:\n\nSpecial Requirements \/ Notes:\s*(.*))?$/s', $stored, $m)) {
        $application = trim((string)($m[1] ?? ''));
        $notes = trim((string)($m[2] ?? ''));
    }

    return ['application' => $application, 'notes' => $notes];
}

function sourcing_template_subject(array $rfq): string {
    return 'Lowe Chemical Request for Pricing | ' . ($rfq['rfq_number'] ?? '') . ' | ' . ($rfq['product_name'] ?? '');
}

function sourcing_template_body(array $rfq): string {
    $req = sourcing_split_requirements($rfq['special_requirements'] ?? '');
    $deliveryLine2 = trim(($rfq['ship_city'] ?? '') . ', ' . ($rfq['ship_state'] ?? '') . ' ' . ($rfq['ship_zip'] ?? ''));
    $delivery = trim(($rfq['ship_address'] ?? '') . ($deliveryLine2 !== '' ? ', ' . $deliveryLine2 : ''));
    $quantity = trim((string)($rfq['quantity'] ?? '') . ' ' . (string)($rfq['quantity_uom'] ?? ''));

    $lines = [
        'Hello {{CONTACT_NAME}},',
        '',
        'Lowe Chemical Company is requesting your best pricing and availability for the requirement below.',
        '',
        'Product: ' . ($rfq['product_name'] ?? ''),
        'CAS No.: ' . (($rfq['cas_number'] ?? '') ?: 'Not specified'),
        'Grade: ' . (($rfq['product_grade'] ?? '') ?: 'Not specified'),
        'Packaging: ' . (($rfq['packaging'] ?? '') ?: 'Not specified'),
        'Quantity: ' . $quantity,
        'Price Basis Requested: ' . (($rfq['price_basis'] ?? '') ?: 'Not specified'),
        'Application / End Use: ' . ($req['application'] ?: 'Not specified'),
        'Delivery Location: ' . $delivery,
        'Pricing Needed By: ' . (($rfq['pricing_needed_by'] ?? '') ?: 'Not specified'),
        '',
        'Please include the following in your quotation:',
        '- Price and price unit',
        '- Freight method and FOB location',
        '- Minimum order',
        '- Lead time and availability',
        '- Container / net weight per package',
        '- Manufacturer and country of origin',
        '- Payment terms',
        '- Quote expiration',
        '- Any applicable freight or surcharges',
        '',
        'Please use the secure pricing link in this email to submit your quotation. The response will be recorded directly with this RFQ.',
        '',
        'Please reference ' . ($rfq['rfq_number'] ?? '') . ' in your response.',
        '',
        'Thank you,',
        ($rfq['requester_name'] ?? 'Lowe Chemical Company'),
        'Lowe Chemical Company',
    ];

    return implode("\n", $lines);
}

function sourcing_get_or_create_supplier_token(PDO $pdo, int $rfqSupplierId, ?string $expiresAt = null): string {
    $stmt = $pdo->prepare('SELECT access_token, expires_at, is_active FROM rfq_supplier_access WHERE rfq_supplier_id=? LIMIT 1');
    $stmt->execute([$rfqSupplierId]);
    $existing = $stmt->fetch();

    if ($existing && (int)$existing['is_active'] === 1) {
        if (empty($existing['expires_at']) || strtotime((string)$existing['expires_at']) > time()) {
            return (string)$existing['access_token'];
        }
    }

    $token = bin2hex(random_bytes(32));
    $sql = 'INSERT INTO rfq_supplier_access (rfq_supplier_id, access_token, expires_at, is_active)
            VALUES (?,?,?,1)
            ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), expires_at=VALUES(expires_at), is_active=1, updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([$rfqSupplierId, $token, $expiresAt]);
    return $token;
}

function sourcing_supplier_quote_url(string $token): string {
    return 'https://lowechemical.com/supplier-quote.php?token=' . rawurlencode($token);
}

function sourcing_html_email(string $subject, string $bodyText, string $buttonUrl, string $rfqNumber): string {
    $bodyHtml = nl2br(sourcing_h($bodyText));
    $button = '<a href="' . sourcing_h($buttonUrl) . '" style="display:inline-block;background:#0c2340;color:#fff;text-decoration:none;padding:12px 18px;border-radius:7px;font-weight:bold;margin:18px 0">Submit Pricing</a>';

    return '<!doctype html><html><body style="margin:0;background:#f4f7fa;font-family:Arial,Helvetica,sans-serif;color:#17212b">'
        . '<div style="max-width:760px;margin:24px auto;background:#fff;border:1px solid #d9e0e6;border-radius:10px;overflow:hidden">'
        . '<div style="background:#0c2340;padding:18px 22px;color:#fff">'
        . '<img src="https://lowechemical.com/images/lowe-logo.png" alt="Lowe Chemical Company" style="max-height:56px;max-width:240px;background:#fff;padding:4px;border-radius:4px">'
        . '<div style="font-size:18px;font-weight:bold;margin-top:8px">Supplier Pricing Request</div>'
        . '<div style="font-size:13px;color:#d9e5ef;margin-top:3px">' . sourcing_h($rfqNumber) . '</div></div>'
        . '<div style="padding:22px">' . $bodyHtml
        . '<div style="text-align:center">' . $button . '</div>'
        . '<p style="font-size:12px;color:#66717d;margin-top:18px">If the button does not open, copy this address into your browser:<br>' . sourcing_h($buttonUrl) . '</p>'
        . '</div></div></body></html>';
}

function sourcing_pdf_text(string $s): string { $s=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s)?:$s; return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s); }
function sourcing_pdf_cmd_text(string $text, float $x, float $y, float $size=9, string $font='F1', array $rgb=[0.09,0.13,0.18]): string {
    $safe=sourcing_pdf_text($text);
    return sprintf("BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",$font,$size,$rgb[0],$rgb[1],$rgb[2],$x,$y,$safe);
}
function sourcing_pdf_line(float $x1,float $y1,float $x2,float $y2,array $rgb=[0.84,0.87,0.90],float $w=0.7): string {
    return sprintf("q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q\n",$rgb[0],$rgb[1],$rgb[2],$w,$x1,$y1,$x2,$y2);
}
function sourcing_pdf_fill_rect(float $x,float $y,float $w,float $h,array $rgb): string {
    return sprintf("q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",$rgb[0],$rgb[1],$rgb[2],$x,$y,$w,$h);
}
function sourcing_pdf_stroke_rect(float $x,float $y,float $w,float $h,array $rgb=[0.82,0.85,0.88],float $lw=0.8): string {
    return sprintf("q %.3f %.3f %.3f RG %.2f w %.2f %.2f %.2f %.2f re S Q\n",$rgb[0],$rgb[1],$rgb[2],$lw,$x,$y,$w,$h);
}
function sourcing_build_pdf(array $d): string {
    $navy=[0.047,0.137,0.251];
    $red=[0.78,0.02,0.06];
    $ink=[0.09,0.13,0.18];
    $muted=[0.37,0.42,0.47];
    $light=[0.965,0.973,0.980];
    $border=[0.82,0.85,0.88];

    // PDF logo: use a JPEG copy directly so the logo works even when the PHP GD extension is unavailable.
    // Upload images/lowe-logo.jpg alongside the existing images/lowe-logo.png.
    $logoJpeg=null;$logoW=1531;$logoH=675;
    $logoJpegPath=__DIR__.'/images/lowe-logo.jpg';
    $logoPngPath=__DIR__.'/images/lowe-logo.png';

    if(is_file($logoJpegPath)){
        $logoJpeg=@file_get_contents($logoJpegPath);
        $info=@getimagesize($logoJpegPath);
        if(is_array($info)){$logoW=(int)$info[0];$logoH=(int)$info[1];}
    } elseif(is_file($logoPngPath) && function_exists('imagecreatefrompng') && function_exists('imagejpeg')){
        // Fallback to the PNG when GD is available.
        $src=@imagecreatefrompng($logoPngPath);
        if($src){
            $w=imagesx($src);$h=imagesy($src);
            $canvas=imagecreatetruecolor($w,$h);
            $white=imagecolorallocate($canvas,255,255,255);
            imagefill($canvas,0,0,$white);
            imagealphablending($canvas,true);
            imagecopy($canvas,$src,0,0,0,0,$w,$h);
            ob_start(); imagejpeg($canvas,null,92); $logoJpeg=ob_get_clean();
            $logoW=$w;$logoH=$h;
            imagedestroy($canvas);imagedestroy($src);
        }
    }

    $v=fn($x)=>trim((string)$x)!==''?trim((string)$x):'Not specified';
    $content='';

    // Header band
    $content.=sourcing_pdf_fill_rect(0,692,612,100,$navy);
    if($logoJpeg!==null){
        $drawW=190;
        $drawH=min(66,$drawW*($logoH/max(1,$logoW)));
        $content.=sprintf("q %.2f 0 0 %.2f 40 %.2f cm /Im1 Do Q\n",$drawW,$drawH,708+(66-$drawH)/2);
    }
    $content.=sourcing_pdf_cmd_text('REQUEST FOR PRICING',370,747,17,'F2',[1,1,1]);
    $content.=sourcing_pdf_cmd_text($d['rfq'],370,724,10,'F2',[1,1,1]);
    $content.=sourcing_pdf_cmd_text('Lowe Chemical Company',370,707,8.5,'F1',[0.86,0.90,0.95]);

    // RFQ summary row
    $content.=sourcing_pdf_fill_rect(40,645,532,34,[0.985,0.988,0.992]);
    $content.=sourcing_pdf_stroke_rect(40,645,532,34,$border,0.7);
    $content.=sourcing_pdf_cmd_text('REQUEST DATE',54,663,7.3,'F2',$muted);
    $content.=sourcing_pdf_cmd_text($v($d['request_date']),54,650,9.2,'F1',$ink);
    $content.=sourcing_pdf_cmd_text('PRICING NEEDED BY',215,663,7.3,'F2',$muted);
    $content.=sourcing_pdf_cmd_text($v($d['pricing_needed_by']),215,650,9.2,'F1',$ink);
    $content.=sourcing_pdf_cmd_text('REQUESTED BY',398,663,7.3,'F2',$muted);
    $content.=sourcing_pdf_cmd_text($v($d['requester_name']),398,650,9.2,'F1',$ink);

    // Section title
    $content.=sourcing_pdf_cmd_text('PRODUCT REQUIREMENT',40,620,11,'F2',$navy);
    $content.=sourcing_pdf_fill_rect(40,611,532,2,$red);

    // Product details table
    $details=[
        ['Product Name',$v($d['product_name']),'CAS Number',$v($d['cas_number'])],
        ['Product Grade',$v($d['product_grade']),'Packaging',$v($d['packaging'])],
        ['Quantity',$v($d['quantity']),'Price Basis Requested',$v($d['price_basis'])],
        ['Delivery',$v($d['delivery']),'Estimated Annual Usage',$v($d['annual_usage'])],
        ['Requirement Type',$v($d['requirement_type']),'Container Weight / Net Weight','Supplier to provide'],
    ];
    $top=598;$rowH=38;$leftX=40;$colW=266;
    foreach($details as $i=>$r){
        $y=$top-($i+1)*$rowH;
        if($i%2===0)$content.=sourcing_pdf_fill_rect($leftX,$y,$colW*2,$rowH,$light);
        $content.=sourcing_pdf_line($leftX,$y,$leftX+$colW*2,$y,$border,0.5);
        $content.=sourcing_pdf_cmd_text($r[0],52,$y+23,7.2,'F2',$muted);
        $content.=sourcing_pdf_cmd_text($r[1],52,$y+9,9.4,'F1',$ink);
        $content.=sourcing_pdf_cmd_text($r[2],52+$colW,$y+23,7.2,'F2',$muted);
        $content.=sourcing_pdf_cmd_text($r[3],52+$colW,$y+9,9.4,'F1',$ink);
    }
    $content.=sourcing_pdf_stroke_rect($leftX,$top-$rowH*count($details),$colW*2,$rowH*count($details),$border,0.7);
    $content.=sourcing_pdf_line($leftX+$colW,$top-$rowH*count($details),$leftX+$colW,$top,$border,0.5);

    // Application
    $applicationY=402;
    $content.=sourcing_pdf_cmd_text('APPLICATION / HOW PRODUCT WILL BE USED',40,$applicationY,7.4,'F2',$muted);
    $applicationText=$v($d['application']);
    $applicationWrapped=explode("\n",wordwrap($applicationText,92,"\n",true));
    $content.=sourcing_pdf_fill_rect(40,$applicationY-42,532,34,[0.985,0.988,0.992]);
    $content.=sourcing_pdf_stroke_rect(40,$applicationY-42,532,34,$border,0.6);
    $ayy=$applicationY-20;
    foreach(array_slice($applicationWrapped,0,2) as $line){$content.=sourcing_pdf_cmd_text($line,52,$ayy,9,'F1',$ink);$ayy-=11;}

    // Notes
    $notesY=350;
    $content.=sourcing_pdf_cmd_text('SPECIAL REQUIREMENTS / NOTES',40,$notesY,7.4,'F2',$muted);
    $noteText=$v($d['notes']);
    $wrapped=explode("\n",wordwrap($noteText,92,"\n",true));
    $content.=sourcing_pdf_fill_rect(40,$notesY-42,532,34,[0.985,0.988,0.992]);
    $content.=sourcing_pdf_stroke_rect(40,$notesY-42,532,34,$border,0.6);
    $yy=$notesY-20;
    foreach(array_slice($wrapped,0,2) as $line){$content.=sourcing_pdf_cmd_text($line,52,$yy,9,'F1',$ink);$yy-=11;}

    // Requested quote details
    $content.=sourcing_pdf_cmd_text('PLEASE INCLUDE WITH YOUR QUOTE',40,290,11,'F2',$navy);
    $content.=sourcing_pdf_fill_rect(40,281,532,2,$red);
    $items=['Price and price unit','Freight method (FOB / Delivered / Prepaid & Add)','FOB location','Minimum order','Lead time','Availability','Payment terms','Country of origin','Quote expiration','Freight / surcharges','Manufacturer','Container weight / net weight per package'];
    $x1=50;$x2=316;$startY=258;$lineGap=19;
    foreach($items as $i=>$item){
        $x=$i<6?$x1:$x2;
        $row=$i<6?$i:$i-6;
        $y=$startY-$row*$lineGap;
        $content.=sourcing_pdf_stroke_rect($x,$y-3,9,9,[0.55,0.61,0.67],0.7);
        $content.=sourcing_pdf_cmd_text($item,$x+17,$y-1,8.9,'F1',$ink);
    }

    // Return instructions callout
    $content.=sourcing_pdf_fill_rect(40,80,532,58,[0.94,0.965,0.985]);
    $content.=sourcing_pdf_stroke_rect(40,80,532,58,[0.60,0.71,0.82],0.8);
    $content.=sourcing_pdf_cmd_text('RETURN PRICING TO',54,119,7.5,'F2',$muted);
    $content.=sourcing_pdf_cmd_text(LOWE_SOURCING_EMAIL,54,101,11.2,'F2',$navy);
    $content.=sourcing_pdf_cmd_text('Please reference '.$d['rfq'].' in your response.',300,101,9.1,'F1',$ink);

    // Footer
    $content.=sourcing_pdf_line(40,70,572,70,[0.75,0.79,0.83],0.5);
    $content.=sourcing_pdf_cmd_text('Lowe Chemical Company',40,52,8.2,'F2',$navy);
    $content.=sourcing_pdf_cmd_text('Our Chemistry Enhances Your Chemistry.',420,52,7.8,'F1',$muted);

    $o=[
        1=>'<< /Type /Catalog /Pages 2 0 R >>',
        2=>'<< /Type /Pages /Kids [3 0 R] /Count 1 >>'
    ];
    $resources='<< /Font << /F1 4 0 R /F2 7 0 R >>'.($logoJpeg!==null?' /XObject << /Im1 6 0 R >>':'').' >>';
    $o[3]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources '.$resources.' /Contents 5 0 R >>';
    $o[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $o[5]='<< /Length '.strlen($content)." >>\nstream\n".$content."endstream";
    if($logoJpeg!==null)$o[6]='<< /Type /XObject /Subtype /Image /Width '.$logoW.' /Height '.$logoH.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($logoJpeg)." >>\nstream\n".$logoJpeg."\nendstream";
    $o[7]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $pdf="%PDF-1.4\n";$offsets=[0];ksort($o);
    foreach($o as$n=>$obj){$offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$obj."\nendobj\n";}
    $xref=strlen($pdf);$max=max(array_keys($o));
    $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$max;$i++)$pdf.=isset($offsets[$i])?sprintf('%010d 00000 n ',$offsets[$i])."\n":"0000000000 00000 f \n";
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}
function buildEmailHtml(array $d): string {
    $row=fn($a,$b)=>'<tr><td style="padding:7px 10px;border-bottom:1px solid #e5e9ed;color:#5b6670;width:34%;font-weight:bold">'.h($a).'</td><td style="padding:7px 10px;border-bottom:1px solid #e5e9ed">'.h($b?:'Not specified').'</td></tr>';$table='';foreach([['RFQ',$d['rfq']],['Pricing Needed By',$d['pricing_needed_by']],['Product Name',$d['product_name']],['CAS Number',$d['cas_number']],['Product Grade',$d['product_grade']],['Packaging',$d['packaging']],['Quantity',$d['quantity']],['Price Basis Requested',$d['price_basis']],['Delivery',$d['delivery']],['Estimated Annual Usage',$d['annual_usage']],['Requirement Type',$d['requirement_type']],['Application / How Product Will Be Used',$d['application']],['Special Requirements / Notes',$d['notes']]]as[$a,$b])$table.=$row($a,$b);
    return '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#17212b;background:#f5f7f9;margin:0;padding:24px"><div style="max-width:760px;margin:auto;background:#fff;border:1px solid #d9e0e6;border-radius:10px;overflow:hidden"><div style="background:#0c2340;color:#fff;padding:20px"><img src="https://lowechemical.com/images/lowe-logo.png" alt="Lowe Chemical Company" style="max-height:58px;max-width:250px;background:#fff;padding:4px;border-radius:4px"><div style="font-size:20px;font-weight:bold;margin-top:10px">Request for Pricing</div></div><div style="padding:22px"><p>Hello,</p><p>Lowe Chemical Company is requesting pricing for the requirement below. Please reference <strong>'.h($d['rfq']).'</strong> in your response.</p><table style="width:100%;border-collapse:collapse;border:1px solid #e5e9ed">'.$table.'</table><h3 style="color:#0c2340;margin-top:22px">Please provide</h3><ul><li>Price and price unit</li><li>Freight method (FOB / Delivered / Prepaid &amp; Add)</li><li>FOB location</li><li>Minimum order</li><li>Lead time</li><li>Availability</li><li>Payment terms</li><li>Country of origin</li><li>Quote expiration</li><li>Freight / surcharges</li><li>Manufacturer</li><li>Container weight / net weight per package</li></ul><p><strong>Please return pricing and availability to <a href="mailto:'.h(LOWE_SOURCING_EMAIL).'">'.h(LOWE_SOURCING_EMAIL).'</a>.</strong></p><p>Thank you,<br>'.h($d['requester_name']).'<br>Lowe Chemical Company</p></div></div></body></html>';
}
function sendMail(array $emails,array $d,array $rfq): bool {
    $to=!empty($rfq['cc_sourcing'])?LOWE_SOURCING_EMAIL:$rfq['requester_email'];$cc=[];if(!empty($rfq['cc_requester'])&&strcasecmp($to,$rfq['requester_email'])!==0)$cc[]=$rfq['requester_email'];foreach(parseEmailList((string)$rfq['additional_cc'])as$e)if(strcasecmp($e,$to)!==0)$cc[]=$e;$cc=array_values(array_unique($cc));
    $subject=cleanHeader($rfq['email_subject']?:'Lowe Chemical Request for Pricing').' | '.$d['rfq'].' | '.$d['product_name'];$boundary='=_LoweRFQ_'.bin2hex(random_bytes(12));$headers=['From: '.LOWE_FROM_NAME.' <'.LOWE_FROM_EMAIL.'>','Reply-To: '.cleanHeader((string)($rfq['reply_to'] ?: LOWE_SOURCING_EMAIL)),'MIME-Version: 1.0','Bcc: '.implode(', ',$emails),'Content-Type: multipart/mixed; boundary="'.$boundary.'"'];if($cc)$headers[]='Cc: '.implode(', ',$cc);$html=buildEmailHtml($d);$body='--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$html."\r\n";
    if($rfq['email_format']==='email_pdf'){$pdf=buildPdf($d);$filename=preg_replace('/[^A-Za-z0-9._-]/','_',$d['rfq'].'_'.$d['product_name']).'.pdf';$body.='--'.$boundary."\r\nContent-Type: application/pdf; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdf))."\r\n";}$body.='--'.$boundary."--\r\n";return mail($to,$subject,$body,implode("\r\n",$headers));
}

function sourcing_rfq_pdf_data(array $rfq): array {
    $req = sourcing_split_requirements($rfq['special_requirements'] ?? '');
    return [
        'rfq'=>(string)($rfq['rfq_number']??''),
        'request_date'=>(string)($rfq['request_date']??''),
        'pricing_needed_by'=>(string)($rfq['pricing_needed_by']??''),
        'requester_name'=>(string)($rfq['requester_name']??''),
        'product_name'=>(string)($rfq['product_name']??''),
        'cas_number'=>(string)($rfq['cas_number']??''),
        'product_grade'=>(string)($rfq['product_grade']??''),
        'packaging'=>(string)($rfq['packaging']??''),
        'quantity'=>trim((string)($rfq['quantity']??'').' '.(string)($rfq['quantity_uom']??'')),
        'price_basis'=>(string)($rfq['price_basis']??''),
        'delivery'=>trim((string)($rfq['ship_address']??'').', '.(string)($rfq['ship_city']??'').', '.(string)($rfq['ship_state']??'').' '.(string)($rfq['ship_zip']??'')),
        'annual_usage'=>(string)($rfq['estimated_annual_usage']??''),
        'requirement_type'=>(string)($rfq['requirement_type']??''),
        'application'=>$req['application'],
        'notes'=>$req['notes'],
    ];
}

function sourcing_send_html_mail(string $to, array $cc, string $replyTo, string $subject, string $html, ?string $pdfBytes=null, ?string $pdfFilename=null): bool {
    $baseHeaders = [
        'From: ' . LOWE_FROM_NAME . ' <' . LOWE_FROM_EMAIL . '>',
        'Reply-To: ' . sourcing_clean_header($replyTo ?: LOWE_SOURCING_EMAIL),
        'MIME-Version: 1.0',
    ];
    if ($cc) $baseHeaders[] = 'Cc: ' . implode(', ', array_unique($cc));

    if ($pdfBytes === null) {
        $headers = array_merge($baseHeaders, ['Content-Type: text/html; charset=UTF-8']);
        return mail($to, sourcing_clean_header($subject), $html, implode("\r\n", $headers));
    }

    $boundary='=_LoweRFQ_'.bin2hex(random_bytes(12));
    $headers=array_merge($baseHeaders,['Content-Type: multipart/mixed; boundary="'.$boundary.'"']);
    $body='--'.$boundary."\r\n";
    $body.="Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$html."\r\n";
    $safeName=preg_replace('/[^A-Za-z0-9._-]/','_', $pdfFilename ?: 'Lowe-RFQ.pdf');
    $body.='--'.$boundary."\r\n";
    $body.='Content-Type: application/pdf; name="'.$safeName.'"'."\r\n";
    $body.='Content-Disposition: attachment; filename="'.$safeName.'"'."\r\n";
    $body.="Content-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdfBytes))."\r\n";
    $body.='--'.$boundary."--\r\n";
    return mail($to, sourcing_clean_header($subject), $body, implode("\r\n", $headers));
}

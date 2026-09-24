<?php
declare(strict_types=1);

/**
 * Read-only schema guard for the Samples module.
 *
 * This file never creates or alters database objects. Schema changes belong
 * in database/sample_records.sql and should be applied intentionally during
 * deployment/maintenance.
 */

function sample_schema_required_columns(): array {
    return [
        'id','sample_number','request_date','needed_by','status','request_source',
        'sales_rep','sales_rep_email','customer_no','customer_company',
        'contact_name','contact_email','contact_phone','ship_to',
        'product_number','product_name','cas_number','manufacturer','lot_number',
        'sample_quantity','sample_unit','packaging','application',
        'currently_buying','current_supplier','reason_for_sample',
        'shipping_method','carrier','shipping_account_number','tracking_number',
        'shipped_date','delivered_date','follow_up_date','evaluation_result',
        'customer_feedback','internal_notes','quote_number','order_number',
        'created_at','updated_at'
    ];
}

function sample_schema_status(PDO $pdo): array {
    $exists = (bool)$pdo->query("SHOW TABLES LIKE 'sample_records'")->fetchColumn();
    if (!$exists) {
        return ['ok'=>false,'table_exists'=>false,'missing'=>sample_schema_required_columns()];
    }

    $cols = $pdo->query("SHOW COLUMNS FROM sample_records")->fetchAll(PDO::FETCH_COLUMN);
    $lookup = array_fill_keys(array_map('strtolower',$cols),true);
    $missing = [];
    foreach (sample_schema_required_columns() as $col) {
        if (!isset($lookup[strtolower($col)])) $missing[]=$col;
    }
    return ['ok'=>!$missing,'table_exists'=>true,'missing'=>$missing];
}

function sample_schema_assert(PDO $pdo): void {
    $status = sample_schema_status($pdo);
    if ($status['ok']) return;

    $detail = !$status['table_exists']
        ? 'The sample_records table does not exist.'
        : 'Missing columns: '.implode(', ',$status['missing']).'.';

    throw new RuntimeException(
        'Samples database schema is not current. '.$detail.
        ' Apply database/sample_records.sql during maintenance before using the Samples module.'
    );
}

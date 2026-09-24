-- Lowe Sales Workflow - Samples schema
-- Apply intentionally during deployment/maintenance. Do not run from normal page requests.

CREATE TABLE IF NOT EXISTS sample_records (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sample_number VARCHAR(40) NOT NULL UNIQUE,
 request_date DATE NOT NULL,
 needed_by DATE NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'Requested',
 request_source VARCHAR(30) NOT NULL DEFAULT 'Internal',
 sales_rep VARCHAR(120) NULL,
 sales_rep_email VARCHAR(190) NULL,
 customer_no VARCHAR(60) NULL,
 customer_company VARCHAR(180) NOT NULL,
 contact_name VARCHAR(150) NULL,
 contact_email VARCHAR(190) NULL,
 contact_phone VARCHAR(60) NULL,
 ship_to TEXT NULL,
 product_number VARCHAR(80) NULL,
 product_name VARCHAR(220) NOT NULL,
 cas_number VARCHAR(80) NULL,
 manufacturer VARCHAR(160) NULL,
 lot_number VARCHAR(100) NULL,
 sample_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
 sample_unit VARCHAR(20) NOT NULL DEFAULT 'LB',
 packaging VARCHAR(160) NULL,
 application TEXT NULL,
 currently_buying VARCHAR(10) NULL,
 current_supplier VARCHAR(180) NULL,
 reason_for_sample TEXT NULL,
 shipping_method VARCHAR(80) NULL,
 carrier VARCHAR(100) NULL,
 shipping_account_number VARCHAR(40) NULL,
 tracking_number VARCHAR(150) NULL,
 shipped_date DATE NULL,
 delivered_date DATE NULL,
 follow_up_date DATE NULL,
 evaluation_result VARCHAR(30) NULL,
 customer_feedback TEXT NULL,
 internal_notes TEXT NULL,
 quote_number VARCHAR(50) NULL,
 order_number VARCHAR(50) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_sample_customer (customer_company),
 INDEX idx_sample_product (product_name),
 INDEX idx_sample_status (status),
 INDEX idx_sample_followup (follow_up_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade statements for older installations.
-- MySQL 8+ supports ADD COLUMN IF NOT EXISTS. If your server is older,
-- inspect the table first and run only the needed ALTER statements.

ALTER TABLE sample_records ADD COLUMN IF NOT EXISTS request_source VARCHAR(30) NOT NULL DEFAULT 'Internal' AFTER status;
ALTER TABLE sample_records ADD COLUMN IF NOT EXISTS sales_rep_email VARCHAR(190) NULL AFTER sales_rep;
ALTER TABLE sample_records ADD COLUMN IF NOT EXISTS cas_number VARCHAR(80) NULL AFTER product_name;
ALTER TABLE sample_records ADD COLUMN IF NOT EXISTS currently_buying VARCHAR(10) NULL AFTER application;
ALTER TABLE sample_records ADD COLUMN IF NOT EXISTS current_supplier VARCHAR(180) NULL AFTER currently_buying;
ALTER TABLE sample_records ADD COLUMN IF NOT EXISTS shipping_account_number VARCHAR(40) NULL AFTER carrier;

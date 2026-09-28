-- ==============================================================
-- LSMS - Update Garansi Sparepart Penjualan
-- Jalankan script ini pada setiap database CABANG:
-- lsms_jakarta, lsms_bandung, lsms_surabaya, dst.
-- ==============================================================

CREATE TABLE IF NOT EXISTS sales (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_code VARCHAR(50) NOT NULL,
    customer_id INT(10) UNSIGNED NULL,
    customer_name VARCHAR(150) NULL,
    created_by_user_id INT(10) UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_invoice_code (invoice_code),
    KEY idx_sales_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_items (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_id INT(10) UNSIGNED NOT NULL,
    item_type VARCHAR(50) NOT NULL DEFAULT 'sparepart',
    item_name VARCHAR(150) NOT NULL,
    quantity INT(11) NOT NULL DEFAULT 1,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    serial_number VARCHAR(120) NULL,
    warranty_duration INT NOT NULL DEFAULT 0,
    warranty_start DATE NULL,
    warranty_end DATE NULL,
    warranty_status ENUM('active', 'expired') NOT NULL DEFAULT 'expired',
    is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_items_sales_id (sales_id),
    KEY idx_sales_items_serial_number (serial_number),
    KEY idx_sales_items_warranty_status (warranty_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jika tabel sales/sales_items sudah ada dari versi lama, pastikan kolom wajib tersedia.
ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS invoice_code VARCHAR(50) NOT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS customer_id INT(10) UNSIGNED NULL AFTER invoice_code,
    ADD COLUMN IF NOT EXISTS customer_name VARCHAR(150) NULL AFTER customer_id,
    ADD COLUMN IF NOT EXISTS created_by_user_id INT(10) UNSIGNED NULL AFTER customer_name,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id;

ALTER TABLE sales_items
    ADD COLUMN IF NOT EXISTS sales_id INT(10) UNSIGNED NOT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS item_type VARCHAR(50) NOT NULL DEFAULT 'sparepart' AFTER sales_id,
    ADD COLUMN IF NOT EXISTS item_name VARCHAR(150) NOT NULL AFTER item_type,
    ADD COLUMN IF NOT EXISTS quantity INT(11) NOT NULL DEFAULT 1 AFTER item_name,
    ADD COLUMN IF NOT EXISTS price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity,
    ADD COLUMN IF NOT EXISTS serial_number VARCHAR(120) NULL AFTER price,
    ADD COLUMN IF NOT EXISTS warranty_duration INT NOT NULL DEFAULT 0 AFTER serial_number,
    ADD COLUMN IF NOT EXISTS warranty_start DATE NULL AFTER warranty_duration,
    ADD COLUMN IF NOT EXISTS warranty_end DATE NULL AFTER warranty_start,
    ADD COLUMN IF NOT EXISTS warranty_status ENUM('active', 'expired') NOT NULL DEFAULT 'expired' AFTER warranty_end,
    ADD COLUMN IF NOT EXISTS is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0 AFTER warranty_status;


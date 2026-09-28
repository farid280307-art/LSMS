<?php

/**
 * Utility helper untuk fitur garansi sparepart dari penjualan langsung.
 * Membuat/menjaga skema tabel sales & sales_items tetap sinkron di database cabang.
 */

function normalizeSalesWarrantyDuration($duration): int
{
    $duration = (int)$duration;
    return in_array($duration, [0, 3, 6], true) ? $duration : 0;
}

function salesWarrantyStatusFromEnd(?string $warrantyEnd): string
{
    if (empty($warrantyEnd)) {
        return 'expired';
    }

    $today = date('Y-m-d');
    return $today <= $warrantyEnd ? 'active' : 'expired';
}

/**
 * @return array{duration:int,start:?string,end:?string,status:string}
 */
function calculateSalesWarrantyByDate(?string $transactionDate, int $warrantyDuration): array
{
    $duration = normalizeSalesWarrantyDuration($warrantyDuration);

    if (empty($transactionDate)) {
        return [
            'duration' => $duration,
            'start' => null,
            'end' => null,
            'status' => 'expired',
        ];
    }

    $startTs = strtotime($transactionDate);
    if ($startTs === false) {
        return [
            'duration' => $duration,
            'start' => null,
            'end' => null,
            'status' => 'expired',
        ];
    }

    $start = date('Y-m-d', $startTs);
    $end = date('Y-m-d', strtotime($start . " +{$duration} months"));

    return [
        'duration' => $duration,
        'start' => $start,
        'end' => $end,
        'status' => salesWarrantyStatusFromEnd($end),
    ];
}

function ensureSalesWarrantySchema(PDO $pdo): void
{
    static $checkedDb = [];

    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') {
            return;
        }

        if (isset($checkedDb[$dbName])) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sales (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                invoice_code VARCHAR(50) NOT NULL,
                customer_id INT(10) UNSIGNED NULL,
                customer_name VARCHAR(150) NULL,
                created_by_user_id INT(10) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sales_invoice_code (invoice_code),
                KEY idx_sales_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sales_items (
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
                warranty_status ENUM('active','expired') NOT NULL DEFAULT 'expired',
                is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sales_items_sales_id (sales_id),
                KEY idx_sales_items_serial_number (serial_number),
                KEY idx_sales_items_warranty_status (warranty_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmtSalesColumns = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'sales'"
        );
        $stmtSalesColumns->execute(['db' => $dbName]);
        $salesColumns = array_flip($stmtSalesColumns->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $salesColumnDdl = [
            'invoice_code' => "ADD COLUMN invoice_code VARCHAR(50) NOT NULL AFTER id",
            'customer_id' => "ADD COLUMN customer_id INT(10) UNSIGNED NULL AFTER invoice_code",
            'customer_name' => "ADD COLUMN customer_name VARCHAR(150) NULL AFTER customer_id",
            'created_by_user_id' => "ADD COLUMN created_by_user_id INT(10) UNSIGNED NULL AFTER customer_name",
            'created_at' => "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id",
        ];

        foreach ($salesColumnDdl as $column => $ddl) {
            if (!isset($salesColumns[$column])) {
                $pdo->exec("ALTER TABLE sales {$ddl}");
            }
        }

        $stmtSalesItemColumns = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'sales_items'"
        );
        $stmtSalesItemColumns->execute(['db' => $dbName]);
        $salesItemColumns = array_flip($stmtSalesItemColumns->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $salesItemColumnDdl = [
            'sales_id' => "ADD COLUMN sales_id INT(10) UNSIGNED NOT NULL AFTER id",
            'item_type' => "ADD COLUMN item_type VARCHAR(50) NOT NULL DEFAULT 'sparepart' AFTER sales_id",
            'item_name' => "ADD COLUMN item_name VARCHAR(150) NOT NULL AFTER item_type",
            'quantity' => "ADD COLUMN quantity INT(11) NOT NULL DEFAULT 1 AFTER item_name",
            'price' => "ADD COLUMN price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity",
            'serial_number' => "ADD COLUMN serial_number VARCHAR(120) NULL AFTER price",
            'warranty_duration' => "ADD COLUMN warranty_duration INT NOT NULL DEFAULT 0 AFTER serial_number",
            'warranty_start' => "ADD COLUMN warranty_start DATE NULL AFTER warranty_duration",
            'warranty_end' => "ADD COLUMN warranty_end DATE NULL AFTER warranty_start",
            'warranty_status' => "ADD COLUMN warranty_status ENUM('active','expired') NOT NULL DEFAULT 'expired' AFTER warranty_end",
            'is_warranty_claim' => "ADD COLUMN is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0 AFTER warranty_status",
            'created_at' => "ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_warranty_claim",
        ];

        foreach ($salesItemColumnDdl as $column => $ddl) {
            if (!isset($salesItemColumns[$column])) {
                $pdo->exec("ALTER TABLE sales_items {$ddl}");
            }
        }

        $checkedDb[$dbName] = true;
    } catch (Throwable $e) {
        // Tetap diam agar alur utama transaksi tidak langsung gagal karena sync schema.
    }
}

function syncSalesItemWarrantyStatus(PDO $pdo, string $invoiceCode = '', string $serialNumber = ''): void
{
    ensureSalesWarrantySchema($pdo);

    $where = ["si.item_type = 'sparepart'"];
    $params = [];

    if ($serialNumber !== '') {
        $where[] = 'TRIM(si.serial_number) = :serial_number';
        $params['serial_number'] = $serialNumber;
    } elseif ($invoiceCode !== '') {
        $where[] = 'TRIM(s.invoice_code) = :invoice_code';
        $params['invoice_code'] = $invoiceCode;
    }

    $sql = "
        SELECT si.id, si.warranty_end
        FROM sales_items si
        JOIN sales s ON s.id = si.sales_id
        WHERE " . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        return;
    }

    $update = $pdo->prepare('UPDATE sales_items SET warranty_status = ? WHERE id = ?');
    foreach ($rows as $row) {
        $status = salesWarrantyStatusFromEnd($row['warranty_end'] ?? null);
        $update->execute([$status, (int)$row['id']]);
    }
}

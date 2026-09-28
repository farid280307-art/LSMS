<?php

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../purchases/sales_warranty_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function warrantyRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if (!is_string($json)) {
        http_response_code(500);
        $json = json_encode([
            'success' => false,
            'message' => 'Gagal membentuk respons API yang valid.',
        ], JSON_UNESCAPED_UNICODE);
    }

    echo $json ?: '{"success":false,"message":"Gagal membentuk respons API yang valid."}';
    exit();
}

function connectWarrantyDbSafely(array $config): ?PDO
{
    try {
        $dbName = (string)($config['dbname'] ?? '');
        if ($dbName === '') {
            return null;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string)($config['host'] ?? '127.0.0.1'),
            (string)($config['port'] ?? '3306'),
            $dbName
        );

        return new PDO(
            $dsn,
            (string)($config['user'] ?? ''),
            (string)($config['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}

function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeLookupInput(string $input): string
{
    $normalized = trim($input);
    $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalized);

    return trim((string)$normalized);
}

function ensureWarrantyCheckServiceSchema(PDO $pdo): void
{
    static $checked = [];

    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') {
            return;
        }

        if (isset($checked[$dbName])) {
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'service_items'"
        );
        $stmt->execute(['db' => $dbName]);
        $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $alterMap = [
            'warranty_duration' => "ADD COLUMN warranty_duration INT NOT NULL DEFAULT 0 AFTER price",
            'warranty_start' => "ADD COLUMN warranty_start DATE NULL AFTER warranty_duration",
            'warranty_end' => "ADD COLUMN warranty_end DATE NULL AFTER warranty_start",
            'warranty_status' => "ADD COLUMN warranty_status ENUM('active','expired') NOT NULL DEFAULT 'expired' AFTER warranty_end",
            'part_serial_number' => "ADD COLUMN part_serial_number VARCHAR(120) NULL AFTER warranty_status",
            'is_warranty_claim' => "ADD COLUMN is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0 AFTER part_serial_number",
        ];

        foreach ($alterMap as $column => $ddl) {
            if (!isset($existing[$column])) {
                $pdo->exec("ALTER TABLE service_items {$ddl}");
            }
        }

        $checked[$dbName] = true;
    } catch (Throwable $e) {
        // Tidak menghentikan alur utama jika sinkronisasi schema service gagal.
    }
}

function syncWarrantyCheckServiceStatus(PDO $pdo, int $serviceId): void
{
    ensureWarrantyCheckServiceSchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, warranty_end FROM service_items WHERE service_id = ? AND item_type = 'sparepart'"
    );
    $stmt->execute([$serviceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return;
    }

    $update = $pdo->prepare('UPDATE service_items SET warranty_status = ? WHERE id = ?');
    foreach ($rows as $row) {
        $status = salesWarrantyStatusFromEnd($row['warranty_end'] ?? null);
        $update->execute([$status, (int)$row['id']]);
    }
}

function getAllowedBranchKeysByRole(array $dbConfigs, string $userRole, string $userBranch): array
{
    $isCentralRole = in_array($userRole, ['superadmin', 'kepala_pusat', 'kepala_admin'], true);
    $branchKeys = [];

    if ($isCentralRole) {
        $branchKeys = array_keys(array_filter($dbConfigs, static function (string $key): bool {
            return strpos($key, 'cabang_') === 0;
        }, ARRAY_FILTER_USE_KEY));
    } else {
        if ($userBranch !== '' && isset($dbConfigs[$userBranch])) {
            $branchKeys = [$userBranch];
        }
    }

    if (empty($branchKeys)) {
        if ($userBranch !== '' && isset($dbConfigs[$userBranch])) {
            $branchKeys = [$userBranch];
        } elseif (isset($dbConfigs['pusat'])) {
            $branchKeys = ['pusat'];
        }
    }

    return $branchKeys;
}

function getBranchNameMap(array $dbConfigs): array
{
    $map = [];
    if (!isset($dbConfigs['pusat'])) {
        return $map;
    }

    $pdoCenter = connectWarrantyDbSafely($dbConfigs['pusat']);
    if (!$pdoCenter instanceof PDO) {
        return $map;
    }

    try {
        $stmt = $pdoCenter->query('SELECT branch_code, branch_name FROM branches');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string)($row['branch_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $map[$code] = (string)($row['branch_name'] ?? $code);
        }
    } catch (Throwable $e) {
        // Abaikan jika tabel branches belum tersedia.
    }

    return $map;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'teknisi'];
$userId = (int)($_SESSION['user']['id'] ?? 0);
$userRole = (string)($_SESSION['user']['role'] ?? '');
$userBranch = (string)($_SESSION['user']['cabang'] ?? '');

if ($userId <= 0) {
    warrantyRespond([
        'success' => false,
        'message' => 'Sesi login berakhir. Silakan login kembali.',
    ], 401);
}

if (!in_array($userRole, $allowedRoles, true)) {
    warrantyRespond([
        'success' => false,
        'message' => 'Anda tidak memiliki akses ke fitur ini.',
    ], 403);
}

global $db_configs;
if (!is_array($db_configs) || empty($db_configs)) {
    warrantyRespond([
        'success' => false,
        'message' => 'Konfigurasi database tidak ditemukan.',
    ], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $keyword = normalizeLookupInput((string)($_GET['keyword'] ?? ''));
    $serialNumber = normalizeLookupInput((string)($_GET['serial_number'] ?? $_GET['serial'] ?? $_GET['sn'] ?? ''));
    $invoiceCode = normalizeLookupInput((string)($_GET['invoice_code'] ?? $_GET['invoice'] ?? ''));
    $serviceCode = normalizeLookupInput((string)($_GET['service_code'] ?? $_GET['service'] ?? ''));
    $statusFilter = trim((string)($_GET['status_filter'] ?? 'all'));
    $statusFilter = in_array($statusFilter, ['all', 'active', 'expired', 'expiring_soon'], true)
        ? $statusFilter
        : 'all';

    if ($keyword === '' && $serialNumber === '' && $invoiceCode === '' && $serviceCode === '') {
        warrantyRespond([
            'success' => false,
            'message' => 'Keyword SN, invoice code, atau service code wajib diisi.',
        ], 422);
    }

    if (
        strlen($keyword) > 120
        || strlen($serialNumber) > 120
        || strlen($invoiceCode) > 120
        || strlen($serviceCode) > 120
    ) {
        warrantyRespond([
            'success' => false,
            'message' => 'Input terlalu panjang.',
        ], 422);
    }

    $lookupMode = 'keyword';
    if ($keyword !== '') {
        $lookupMode = 'keyword';
    } elseif ($serialNumber !== '' && $invoiceCode !== '') {
        $lookupMode = 'serial_or_invoice';
    } elseif ($serialNumber !== '') {
        $lookupMode = 'serial';
    } elseif ($invoiceCode !== '') {
        $lookupMode = 'invoice';
    } elseif ($serviceCode !== '') {
        $lookupMode = 'service';
    }

    $serviceLookupByCode = '';
    $serviceLookupBySerial = '';
    $shouldLookupService = false;
    if ($lookupMode === 'service') {
        $serviceLookupByCode = $serviceCode;
        $shouldLookupService = true;
    } elseif ($lookupMode === 'serial') {
        $serviceLookupBySerial = $serialNumber;
        $shouldLookupService = true;
    } elseif ($lookupMode === 'serial_or_invoice') {
        if ($serialNumber !== '') {
            $serviceLookupBySerial = $serialNumber;
            $shouldLookupService = true;
        }
    } elseif ($lookupMode === 'keyword') {
        $serviceLookupByCode = $keyword;
        $serviceLookupBySerial = $keyword;
        $shouldLookupService = true;
    }
    $branchKeys = getAllowedBranchKeysByRole($db_configs, $userRole, $userBranch);
    $branchNameMap = getBranchNameMap($db_configs);

    $rows = [];
    $activeCount = 0;
    $expiredCount = 0;
    $expiringSoonCount = 0;

    foreach ($branchKeys as $branchKey) {
        if (!isset($db_configs[$branchKey])) {
            continue;
        }

        $pdoBranch = connectWarrantyDbSafely($db_configs[$branchKey]);
        if (!$pdoBranch instanceof PDO) {
            continue;
        }

        if ($lookupMode !== 'service') {
            try {
                ensureSalesWarrantySchema($pdoBranch);
                if ($lookupMode === 'keyword') {
                    syncSalesItemWarrantyStatus($pdoBranch, $keyword, '');
                    syncSalesItemWarrantyStatus($pdoBranch, '', $keyword);
                } elseif ($lookupMode === 'serial_or_invoice') {
                    syncSalesItemWarrantyStatus($pdoBranch, $invoiceCode, '');
                    syncSalesItemWarrantyStatus($pdoBranch, '', $serialNumber);
                } else {
                    syncSalesItemWarrantyStatus(
                        $pdoBranch,
                        $lookupMode === 'invoice' ? $invoiceCode : '',
                        $lookupMode === 'serial' ? $serialNumber : ''
                    );
                }

                $where = ["si.item_type = 'sparepart'"];
                $params = [];

                if ($lookupMode === 'keyword') {
                    $where[] = '(TRIM(si.serial_number) = :keyword_serial OR TRIM(s.invoice_code) = :keyword_invoice)';
                    $params['keyword_serial'] = $keyword;
                    $params['keyword_invoice'] = $keyword;
                } elseif ($lookupMode === 'serial_or_invoice') {
                    $where[] = '(TRIM(si.serial_number) = :serial_number OR TRIM(s.invoice_code) = :invoice_code)';
                    $params['serial_number'] = $serialNumber;
                    $params['invoice_code'] = $invoiceCode;
                } elseif ($lookupMode === 'serial') {
                    $where[] = 'TRIM(si.serial_number) = :serial_number';
                    $params['serial_number'] = $serialNumber;
                } else {
                    $where[] = 'TRIM(s.invoice_code) = :invoice_code';
                    $params['invoice_code'] = $invoiceCode;
                }

                $sql = "
                    SELECT
                        s.id AS sales_id,
                        s.invoice_code,
                        s.customer_id,
                        s.customer_name,
                        s.created_at AS sales_date,
                        si.id AS sales_item_id,
                        si.item_type,
                        si.item_name,
                        si.quantity,
                        si.price,
                        si.serial_number,
                        COALESCE(si.warranty_duration, 0) AS warranty_duration,
                        si.warranty_start,
                        si.warranty_end,
                        COALESCE(si.warranty_status, 'expired') AS warranty_status,
                        COALESCE(si.is_warranty_claim, 0) AS is_warranty_claim
                    FROM sales_items si
                    JOIN sales s ON s.id = si.sales_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY s.created_at DESC, si.id DESC
                ";

                $stmt = $pdoBranch->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($results as $row) {
                    $duration = normalizeSalesWarrantyDuration($row['warranty_duration'] ?? 0);
                    $status = salesWarrantyStatusFromEnd($row['warranty_end'] ?? null);

                    $daysToExpire = null;
                    if (!empty($row['warranty_end'])) {
                        $todayTs = strtotime(date('Y-m-d'));
                        $endTs = strtotime((string)$row['warranty_end']);
                        if ($todayTs !== false && $endTs !== false) {
                            $daysToExpire = (int)floor(($endTs - $todayTs) / 86400);
                        }
                    }

                    if ($statusFilter === 'active' && $status !== 'active') {
                        continue;
                    }

                    if ($statusFilter === 'expired' && $status !== 'expired') {
                        continue;
                    }

                    if ($statusFilter === 'expiring_soon') {
                        if (!($status === 'active' && $daysToExpire !== null && $daysToExpire >= 0 && $daysToExpire <= 7)) {
                            continue;
                        }
                    }

                    if ($status === 'active') {
                        $activeCount++;
                        if ($daysToExpire !== null && $daysToExpire >= 0 && $daysToExpire <= 7) {
                            $expiringSoonCount++;
                        }
                    } else {
                        $expiredCount++;
                    }

                    $isClaim = (int)($row['is_warranty_claim'] ?? 0) === 1;
                    $message = $status === 'active'
                        ? 'Garansi masih berlaku'
                        : 'Garansi telah habis';

                    if ($isClaim) {
                        $message = 'Warranty claim sudah digunakan (biaya Rp 0)';
                    }

                    $rows[] = [
                        'source_type' => 'sales',
                        'branch_code' => $branchKey,
                        'branch_name' => $branchNameMap[$branchKey] ?? strtoupper(str_replace('_', ' ', $branchKey)),
                        'sales_id' => (int)$row['sales_id'],
                        'sales_item_id' => (int)$row['sales_item_id'],
                        'service_id' => 0,
                        'service_item_id' => 0,
                        'service_code' => '',
                        'invoice_code' => (string)($row['invoice_code'] ?? ''),
                        'customer_name' => (string)($row['customer_name'] ?? ''),
                        'sales_date' => (string)($row['sales_date'] ?? ''),
                        'item_name' => (string)($row['item_name'] ?? ''),
                        'quantity' => (int)($row['quantity'] ?? 0),
                        'price' => (float)($row['price'] ?? 0),
                        'serial_number' => (string)($row['serial_number'] ?? ''),
                        'part_serial_number' => (string)($row['serial_number'] ?? ''),
                        'unit_serial_number' => '',
                        'warranty_duration' => $duration,
                        'warranty_start' => $row['warranty_start'],
                        'warranty_end' => $row['warranty_end'],
                        'warranty_status' => $status,
                        'is_warranty_claim' => $isClaim ? 1 : 0,
                        'claim_available' => ($status === 'active' && !$isClaim) ? 1 : 0,
                        'days_to_expire' => $daysToExpire,
                        'message' => $message,
                    ];
                }
            } catch (Throwable $e) {
                // Abaikan kegagalan query penjualan per-cabang.
            }
        }

        if ($shouldLookupService) {
            try {
                ensureWarrantyCheckServiceSchema($pdoBranch);

                $serviceWhere = ["si.item_type = 'sparepart'"];
                $serviceParams = [];
                if ($serviceLookupByCode !== '' && $serviceLookupBySerial !== '') {
                    $serviceWhere[] = '(TRIM(sv.service_code) = :service_code_lookup OR TRIM(d.serial_number) = :service_serial_lookup OR TRIM(si.part_serial_number) = :service_part_serial_lookup)';
                    $serviceParams['service_code_lookup'] = $serviceLookupByCode;
                    $serviceParams['service_serial_lookup'] = $serviceLookupBySerial;
                    $serviceParams['service_part_serial_lookup'] = $serviceLookupBySerial;
                } elseif ($serviceLookupByCode !== '') {
                    $serviceWhere[] = 'TRIM(sv.service_code) = :service_code_lookup';
                    $serviceParams['service_code_lookup'] = $serviceLookupByCode;
                } elseif ($serviceLookupBySerial !== '') {
                    $serviceWhere[] = '(TRIM(d.serial_number) = :service_serial_lookup OR TRIM(si.part_serial_number) = :service_part_serial_lookup)';
                    $serviceParams['service_serial_lookup'] = $serviceLookupBySerial;
                    $serviceParams['service_part_serial_lookup'] = $serviceLookupBySerial;
                }

                if (empty($serviceParams)) {
                    continue;
                }

                $svcStmt = $pdoBranch->prepare(
                    "SELECT DISTINCT sv.id
                     FROM service_items si
                     JOIN services sv ON sv.id = si.service_id
                     LEFT JOIN devices d ON d.id = sv.device_id
                     WHERE " . implode(' AND ', $serviceWhere)
                );
                $svcStmt->execute($serviceParams);
                $serviceIds = $svcStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

                foreach ($serviceIds as $serviceId) {
                    syncWarrantyCheckServiceStatus($pdoBranch, (int)$serviceId);
                }

                $serviceSql = "
                    SELECT
                        sv.id AS service_id,
                        sv.service_code,
                        sv.updated_at AS service_date,
                        si.id AS service_item_id,
                        si.description AS item_name,
                        si.quantity,
                        si.price,
                        d.serial_number AS unit_serial_number,
                        si.part_serial_number,
                        COALESCE(si.warranty_duration, 0) AS warranty_duration,
                        si.warranty_start,
                        si.warranty_end,
                        COALESCE(si.warranty_status, 'expired') AS warranty_status,
                        COALESCE(si.is_warranty_claim, 0) AS is_warranty_claim
                    FROM service_items si
                    JOIN services sv ON sv.id = si.service_id
                    LEFT JOIN devices d ON d.id = sv.device_id
                    WHERE " . implode(' AND ', $serviceWhere) . "
                    ORDER BY sv.updated_at DESC, si.id DESC
                ";

                $serviceStmt = $pdoBranch->prepare($serviceSql);
                $serviceStmt->execute($serviceParams);
                $serviceRows = $serviceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($serviceRows as $row) {
                    $duration = normalizeSalesWarrantyDuration($row['warranty_duration'] ?? 0);
                    $status = salesWarrantyStatusFromEnd($row['warranty_end'] ?? null);

                    $daysToExpire = null;
                    if (!empty($row['warranty_end'])) {
                        $todayTs = strtotime(date('Y-m-d'));
                        $endTs = strtotime((string)$row['warranty_end']);
                        if ($todayTs !== false && $endTs !== false) {
                            $daysToExpire = (int)floor(($endTs - $todayTs) / 86400);
                        }
                    }

                    if ($statusFilter === 'active' && $status !== 'active') {
                        continue;
                    }

                    if ($statusFilter === 'expired' && $status !== 'expired') {
                        continue;
                    }

                    if ($statusFilter === 'expiring_soon') {
                        if (!($status === 'active' && $daysToExpire !== null && $daysToExpire >= 0 && $daysToExpire <= 7)) {
                            continue;
                        }
                    }

                    if ($status === 'active') {
                        $activeCount++;
                        if ($daysToExpire !== null && $daysToExpire >= 0 && $daysToExpire <= 7) {
                            $expiringSoonCount++;
                        }
                    } else {
                        $expiredCount++;
                    }

                    $isClaim = (int)($row['is_warranty_claim'] ?? 0) === 1;
                    $message = $status === 'active'
                        ? 'Garansi sparepart service masih berlaku'
                        : 'Garansi sparepart service telah habis';

                    if ($isClaim) {
                        $message = 'Warranty claim sparepart service sudah digunakan';
                    }

                    $rows[] = [
                        'source_type' => 'service',
                        'branch_code' => $branchKey,
                        'branch_name' => $branchNameMap[$branchKey] ?? strtoupper(str_replace('_', ' ', $branchKey)),
                        'sales_id' => 0,
                        'sales_item_id' => 0,
                        'service_id' => (int)($row['service_id'] ?? 0),
                        'service_item_id' => (int)($row['service_item_id'] ?? 0),
                        'service_code' => (string)($row['service_code'] ?? ''),
                        'invoice_code' => '',
                        'customer_name' => '',
                        'sales_date' => (string)($row['service_date'] ?? ''),
                        'item_name' => (string)($row['item_name'] ?? ''),
                        'quantity' => (int)($row['quantity'] ?? 0),
                        'price' => (float)($row['price'] ?? 0),
                        'serial_number' => (string)($row['part_serial_number'] ?? $row['unit_serial_number'] ?? ''),
                        'part_serial_number' => (string)($row['part_serial_number'] ?? ''),
                        'unit_serial_number' => (string)($row['unit_serial_number'] ?? ''),
                        'warranty_duration' => $duration,
                        'warranty_start' => $row['warranty_start'],
                        'warranty_end' => $row['warranty_end'],
                        'warranty_status' => $status,
                        'is_warranty_claim' => $isClaim ? 1 : 0,
                        'claim_available' => 0,
                        'days_to_expire' => $daysToExpire,
                        'message' => $message,
                    ];
                }
            } catch (Throwable $e) {
                // Abaikan bila skema service cabang belum lengkap.
            }
        }
    }

    if (empty($rows)) {
        warrantyRespond([
            'success' => true,
            'message' => 'Data garansi sparepart tidak ditemukan.',
            'data' => [],
            'summary' => [
                'lookup_mode' => $lookupMode,
                'keyword' => $keyword,
                'serial_number' => $serialNumber,
                'invoice_code' => $invoiceCode,
                'service_code' => $serviceLookupByCode,
                'service_serial' => $serviceLookupBySerial,
                'status_filter' => $statusFilter,
                'total_items' => 0,
                'active_count' => 0,
                'expired_count' => 0,
                'expiring_soon_count' => 0,
            ],
        ]);
    }

    warrantyRespond([
        'success' => true,
        'data' => $rows,
        'summary' => [
            'lookup_mode' => $lookupMode,
            'keyword' => $keyword,
            'serial_number' => $serialNumber,
            'invoice_code' => $invoiceCode,
            'service_code' => $serviceLookupByCode,
            'service_serial' => $serviceLookupBySerial,
            'status_filter' => $statusFilter,
            'total_items' => count($rows),
            'active_count' => $activeCount,
            'expired_count' => $expiredCount,
            'expiring_soon_count' => $expiringSoonCount,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = $_POST;
    if (empty($payload)) {
        $payload = readJsonBody();
    }

    $action = trim((string)($payload['action'] ?? ''));
    if ($action !== 'claim') {
        warrantyRespond([
            'success' => false,
            'message' => 'Aksi tidak valid.',
        ], 422);
    }

    $salesItemId = (int)($payload['sales_item_id'] ?? 0);
    $branchCode = trim((string)($payload['branch_code'] ?? ''));

    if ($salesItemId <= 0 || $branchCode === '') {
        warrantyRespond([
            'success' => false,
            'message' => 'Data claim tidak lengkap.',
        ], 422);
    }

    if (!isset($db_configs[$branchCode])) {
        warrantyRespond([
            'success' => false,
            'message' => 'Cabang tidak valid.',
        ], 422);
    }

    $isCentralRole = in_array($userRole, ['superadmin', 'kepala_pusat', 'kepala_admin'], true);
    if (!$isCentralRole && $userBranch !== $branchCode) {
        warrantyRespond([
            'success' => false,
            'message' => 'Anda tidak memiliki akses ke data cabang tersebut.',
        ], 403);
    }

    $pdoBranch = connectWarrantyDbSafely($db_configs[$branchCode]);
    if (!$pdoBranch instanceof PDO) {
        warrantyRespond([
            'success' => false,
            'message' => 'Koneksi database cabang gagal.',
        ], 500);
    }

    try {
        ensureSalesWarrantySchema($pdoBranch);

        $stmt = $pdoBranch->prepare(
            "SELECT
                si.id,
                si.item_type,
                si.item_name,
                si.warranty_end,
                COALESCE(si.is_warranty_claim, 0) AS is_warranty_claim,
                s.invoice_code
             FROM sales_items si
             JOIN sales s ON s.id = si.sales_id
             WHERE si.id = ?
             LIMIT 1"
        );
        $stmt->execute([$salesItemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            warrantyRespond([
                'success' => false,
                'message' => 'Data item tidak ditemukan.',
            ], 404);
        }

        if ((string)($item['item_type'] ?? '') !== 'sparepart') {
            warrantyRespond([
                'success' => false,
                'message' => 'Claim hanya berlaku untuk item sparepart.',
            ], 422);
        }

        if ((int)($item['is_warranty_claim'] ?? 0) === 1) {
            warrantyRespond([
                'success' => true,
                'message' => 'Item ini sudah ditandai sebagai warranty claim sebelumnya.',
                'data' => [
                    'sales_item_id' => (int)$item['id'],
                    'invoice_code' => (string)$item['invoice_code'],
                    'item_name' => (string)$item['item_name'],
                    'claim_cost' => 0,
                    'warranty_status' => salesWarrantyStatusFromEnd($item['warranty_end'] ?? null),
                ],
            ]);
        }

        $status = salesWarrantyStatusFromEnd($item['warranty_end'] ?? null);
        if ($status !== 'active') {
            warrantyRespond([
                'success' => false,
                'message' => 'Garansi telah habis. Claim tidak bisa diproses.',
            ], 422);
        }

        $update = $pdoBranch->prepare('UPDATE sales_items SET is_warranty_claim = 1 WHERE id = ?');
        $update->execute([$salesItemId]);

        warrantyRespond([
            'success' => true,
            'message' => 'Warranty claim berhasil ditandai. Biaya claim = Rp 0.',
            'data' => [
                'sales_item_id' => (int)$item['id'],
                'invoice_code' => (string)$item['invoice_code'],
                'item_name' => (string)$item['item_name'],
                'claim_cost' => 0,
                'warranty_status' => $status,
            ],
        ]);
    } catch (Throwable $e) {
        warrantyRespond([
            'success' => false,
            'message' => 'Gagal memproses claim garansi.',
        ], 500);
    }
}

warrantyRespond([
    'success' => false,
    'message' => 'Method tidak diizinkan.',
], 405);

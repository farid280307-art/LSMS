<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * Kirim response JSON lalu hentikan eksekusi.
 */
function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond([
        'success' => false,
        'message' => 'Method tidak diizinkan',
    ], 405);
}

$serviceCode = trim((string)($_GET['service_code'] ?? ''));
$rawPhone = trim((string)($_GET['phone'] ?? ''));
$normalizedPhone = preg_replace('/\D+/', '', $rawPhone);

if ($serviceCode === '') {
    respond([
        'success' => false,
        'message' => 'Service code wajib diisi',
    ], 422);
}

if (strlen($serviceCode) > 100) {
    respond([
        'success' => false,
        'message' => 'Data tidak ditemukan',
    ], 404);
}

global $db_configs;

// Ambil daftar database cabang. Fallback ke pusat jika tidak ada cabang terkonfigurasi.
$branchKeys = array_keys(array_filter($db_configs, static function (string $key): bool {
    return strpos($key, 'cabang_') === 0;
}, ARRAY_FILTER_USE_KEY));

if (empty($branchKeys)) {
    $branchKeys = ['pusat'];
}

// Peta nama cabang agar bisa ditampilkan di hasil tracking.
$branchNameMap = [];
try {
    $pdoCenter = getDbConnection('pusat');
    if ($pdoCenter instanceof PDO) {
        $branchRows = $pdoCenter->query('SELECT branch_code, branch_name FROM branches')->fetchAll();
        foreach ($branchRows as $row) {
            $branchNameMap[$row['branch_code']] = $row['branch_name'];
        }
    }
} catch (Throwable $e) {
    // Jika query nama cabang gagal, tracking tetap dapat berjalan.
}

$serviceData = null;
$timelineData = [];
$branchCodeFound = null;

foreach ($branchKeys as $branchKey) {
    try {
        $pdoBranch = getDbConnection($branchKey);
        if (!$pdoBranch instanceof PDO) {
            continue;
        }

        $serviceSql = "
            SELECT
                s.id,
                s.service_code,
                s.status,
                s.reported_problem,
                s.created_at,
                c.name AS customer_name,
                c.phone AS customer_phone,
                d.brand,
                d.model,
                d.serial_number
            FROM services s
            LEFT JOIN customers c ON c.id = s.customer_id
            JOIN devices d ON d.id = s.device_id
            WHERE s.service_code = :service_code
        ";

        $params = ['service_code' => $serviceCode];

        if (!empty($normalizedPhone)) {
            $serviceSql .= "
                AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = :phone
            ";
            $params['phone'] = $normalizedPhone;
        }

        $serviceSql .= ' LIMIT 1';

        $serviceStmt = $pdoBranch->prepare($serviceSql);
        $serviceStmt->execute($params);
        $service = $serviceStmt->fetch();

        if (!$service) {
            continue;
        }

        $serviceData = $service;
        $branchCodeFound = $branchKey;

        $logStmt = $pdoBranch->prepare("
            SELECT log_time, description
            FROM service_logs
            WHERE service_id = :service_id
            ORDER BY log_time ASC
        ");
        $logStmt->execute(['service_id' => $service['id']]);
        $timelineData = $logStmt->fetchAll();

        break;
    } catch (Throwable $e) {
        // Jika ada cabang dengan skema belum sinkron, lanjut cabang lain.
        continue;
    }
}

if (!$serviceData) {
    respond([
        'success' => false,
        'message' => 'Data tidak ditemukan',
    ], 404);
}

if (empty($timelineData)) {
    $timelineData[] = [
        'log_time' => $serviceData['created_at'],
        'description' => 'Unit diterima',
    ];
}

$branchName = $branchNameMap[$branchCodeFound] ?? strtoupper(str_replace('_', ' ', (string)$branchCodeFound));

respond([
    'success' => true,
    'data' => [
        'customer' => [
            'name' => $serviceData['customer_name'] ?? '-',
            'phone' => $serviceData['customer_phone'] ?? '-',
        ],
        'device' => [
            'brand' => $serviceData['brand'] ?? '-',
            'model' => $serviceData['model'] ?? '-',
            'serial_number' => $serviceData['serial_number'] ?? '-',
        ],
        'service' => [
            'service_code' => $serviceData['service_code'],
            'status' => $serviceData['status'] ?? 'pending',
            'reported_problem' => $serviceData['reported_problem'] ?? '-',
            'created_at' => $serviceData['created_at'],
            'branch_code' => $branchCodeFound,
            'branch_name' => $branchName,
        ],
        'timeline' => $timelineData,
    ],
]);

<?php
// modules/service/check_sn.php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['sn'])) {
    echo json_encode(['status' => 'error', 'message' => 'SN required']);
    exit;
}

$sn = $_GET['sn'];
$ignore_id = $_GET['ignore_id'] ?? null;

try {
    // 1. Cek Active Service di SEMUA Cabang (Cross-Branch Check)
    global $db_configs;
    $branch_dbs = array_filter($db_configs, function($key) {
        return strpos($key, 'cabang_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($branch_dbs as $branch_key => $config) {
        $pdo_branch = getDbConnection($branch_key);
        if (!$pdo_branch) continue;

        $sql = "SELECT s.service_code, s.status 
                FROM services s 
                JOIN devices d ON s.device_id = d.id 
                WHERE d.serial_number = ? 
                AND s.status NOT IN ('taken', 'cancelled')";
        
        $params = [$sn];
        if ($ignore_id) {
            $sql .= " AND s.id != ?";
            $params[] = $ignore_id;
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo_branch->prepare($sql);
        $stmt->execute($params);
        $active_service = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($active_service) {
            // Ambil nama cabang untuk pesan error yang informatif
            $pdo_pusat = getDbConnection('pusat');
            $stmt_b = $pdo_pusat->prepare("SELECT branch_name FROM branches WHERE branch_code = ?");
            $stmt_b->execute([$branch_key]);
            $b_name = $stmt_b->fetchColumn() ?: $branch_key;

            echo json_encode([
                'status' => 'error',
                'message' => "Unit ini sedang diservice di $b_name (Status: " . strtoupper(str_replace('_', ' ', $active_service['status'])) . ", Tiket: " . $active_service['service_code'] . ")."
            ]);
            exit;
        }
    }

    // Selalu cari di database pusat untuk master data unit
    $pdo = getDbConnection('pusat');
    
    $stmt = $pdo->prepare("SELECT * FROM master_units WHERE serial_number = ?");
    $stmt->execute([$sn]);
    $unit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($unit) {
        // Hitung status garansi
        $today = date('Y-m-d');
        $is_warranty = (!empty($unit['warranty_start']) && !empty($unit['warranty_end']) && $today >= $unit['warranty_start'] && $today <= $unit['warranty_end']) ? true : false;
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'unit_name' => $unit['unit_name'],
                'model_code' => $unit['model_code'],
                'warranty_start' => $unit['warranty_start'],
                'warranty_end' => $unit['warranty_end'],
                'is_warranty' => $is_warranty
            ]
        ]);
    } else {
        echo json_encode(['status' => 'not_found']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

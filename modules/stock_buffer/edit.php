<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$pdo = getDbConnection('pusat');

$id = $_POST['id'] ?? '';
$code = $_POST['item_code'] ?? '';
$name = $_POST['item_name'] ?? '';
$type = $_POST['item_type'] ?? '';
$qty = $_POST['quantity'] ?? 0;
$sell_price = $_POST['sell_price'] ?? 0;

if (empty($id)) {
    header("Location: index.php?msg=save_failed&err_msg=" . urlencode("ID item tidak valid."));
    exit();
}

try {
    $stmt_old = $pdo->prepare("SELECT item_code FROM stock_buffer_pusat WHERE id = ?");
    $stmt_old->execute([$id]);
    $old_item = $stmt_old->fetch();
    $old_code = $old_item['item_code'] ?? $code;

    $stmt = $pdo->prepare("UPDATE stock_buffer_pusat SET item_code=?, item_name=?, item_type=?, quantity=?, sell_price=? WHERE id=?");
    $stmt->execute([$code, $name, $type, $qty, $sell_price, $id]);

    global $db_configs;
    $branch_dbs = array_filter($db_configs, function($key) {
        return strpos($key, 'cabang_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($branch_dbs as $branch_key => $config) {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
            $pdo_branch = new PDO($dsn, $config['user'], $config['pass'], [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo_branch->prepare("UPDATE stock_buffer_cabang SET part_code=?, part_name=?, type=?, sell_price=? WHERE part_code=?")
                ->execute([$code, $name, $type, $sell_price, $old_code]);
        } catch (Exception $e) {
        }
    }

    header("Location: index.php?msg=saved");
    exit();
} catch (Exception $e) {
    header("Location: index.php?msg=save_failed&err_msg=" . urlencode("Gagal menyimpan data: " . $e->getMessage()));
    exit();
}


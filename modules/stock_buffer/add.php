<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$pdo = getDbConnection('pusat');

$code = $_POST['item_code'] ?? '';
$name = $_POST['item_name'] ?? '';
$type = $_POST['item_type'] ?? '';
$qty = $_POST['quantity'] ?? 0;
$sell_price = $_POST['sell_price'] ?? 0;

try {
    $stmt_check = $pdo->prepare("SELECT id, quantity FROM stock_buffer_pusat WHERE item_code = ?");
    $stmt_check->execute([$code]);
    $existing_item = $stmt_check->fetch();

    if ($existing_item) {
        $new_qty = $existing_item['quantity'] + $qty;
        $stmt = $pdo->prepare("UPDATE stock_buffer_pusat SET item_name=?, item_type=?, quantity=?, sell_price=? WHERE id=?");
        $stmt->execute([$name, $type, $new_qty, $sell_price, $existing_item['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO stock_buffer_pusat (item_code, item_name, item_type, quantity, sell_price) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$code, $name, $type, $qty, $sell_price]);
    }

    if ($type === 'device' && !empty($_POST['serial_number'])) {
        $sn = $_POST['serial_number'];
        $warranty_end = $_POST['warranty_end'] ?? null;

        $stmt_check_sn = $pdo->prepare("SELECT serial_number FROM master_units WHERE serial_number = ?");
        $stmt_check_sn->execute([$sn]);
        if (!$stmt_check_sn->fetch()) {
            $stmt_mu = $pdo->prepare("INSERT INTO master_units (serial_number, unit_name, model_code, warranty_start, warranty_end) VALUES (?, ?, ?, CURDATE(), ?)");
            $stmt_mu->execute([$sn, $name, $code, $warranty_end]);
        }
    }

    header("Location: index.php?msg=saved");
    exit();
} catch (Exception $e) {
    header("Location: index.php?msg=save_failed&err_msg=" . urlencode("Gagal menyimpan data: " . $e->getMessage()));
    exit();
}


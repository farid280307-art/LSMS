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
$qty_add = (int)($_POST['quantity_add'] ?? 0);

try {
    if (empty($id)) {
        throw new Exception("Item tidak valid.");
    }

    $stmt_info = $pdo->prepare("SELECT item_code, item_name, item_type FROM stock_buffer_pusat WHERE id = ?");
    $stmt_info->execute([$id]);
    $item_info = $stmt_info->fetch();

    if (!$item_info) {
        throw new Exception("Data item tidak ditemukan.");
    }

    if ($item_info['item_type'] === 'device') {
        $qty_add = 1;
        $sn = $_POST['serial_number'] ?? '';

        if (!empty($sn)) {
            $stmt_check = $pdo->prepare("SELECT id FROM master_units WHERE serial_number = ?");
            $stmt_check->execute([$sn]);
            if (!$stmt_check->fetch()) {
                $stmt_mu = $pdo->prepare("INSERT INTO master_units (serial_number, unit_name, model_code) VALUES (?, ?, ?)");
                $stmt_mu->execute([$sn, $item_info['item_name'], $item_info['item_code']]);
            }
        }
    }

    if ($qty_add <= 0) {
        throw new Exception("Jumlah penambahan stok harus lebih dari 0.");
    }

    $stmt = $pdo->prepare("UPDATE stock_buffer_pusat SET quantity = quantity + ? WHERE id = ?");
    $stmt->execute([$qty_add, $id]);

    header("Location: index.php?msg=stock_added");
    exit();
} catch (Exception $e) {
    header("Location: index.php?msg=restock_failed&err_msg=" . urlencode("Gagal restock: " . $e->getMessage()));
    exit();
}


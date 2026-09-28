<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$pdo = getDbConnection('pusat');
$to_branch = $_POST['to_branch_id'] ?? '';
$notes = $_POST['notes'] ?? '';
$items = $_POST['items'] ?? [];
$transfer_ids = [];

$batch_ref = date('ymdHis');
$final_notes = $notes . ($notes ? " " : "") . "[Ref:$batch_ref]";

try {
    if (empty($to_branch) || empty($items)) {
        throw new Exception("Data transfer tidak lengkap.");
    }

    $pdo->beginTransaction();

    $stmt_branch = $pdo->prepare("SELECT branch_code FROM branches WHERE id = ?");
    $stmt_branch->execute([$to_branch]);
    $target_branch_code = $stmt_branch->fetchColumn();

    if (!$target_branch_code) {
        throw new Exception("Cabang tujuan tidak valid.");
    }

    $pdo_branch = getDbConnection($target_branch_code);
    if (!$pdo_branch) {
        throw new Exception("Gagal terhubung ke database cabang.");
    }

    foreach ($items as $item) {
        $item_id = $item['item_id'] ?? null;
        $qty_transfer = (int)($item['quantity'] ?? 0);
        $sn = $item['serial_number'] ?? null;

        if (empty($item_id) || $qty_transfer <= 0) {
            throw new Exception("Data item transfer tidak valid.");
        }

        $stmt = $pdo->prepare("SELECT * FROM stock_buffer_pusat WHERE id = ? FOR UPDATE");
        $stmt->execute([$item_id]);
        $item_data = $stmt->fetch();

        if (!$item_data || $item_data['quantity'] < $qty_transfer) {
            throw new Exception("Stok tidak cukup untuk item: " . ($item_data['item_name'] ?? 'Unknown'));
        }

        $item_code = $item_data['item_code'];

        if ($item_data['item_type'] === 'device') {
            if (empty($sn)) {
                throw new Exception("Serial Number wajib diisi untuk device: " . $item_data['item_name']);
            }

            $stmt_check_loc = $pdo->prepare("SELECT current_branch FROM master_units WHERE serial_number = ?");
            $stmt_check_loc->execute([$sn]);
            $current_loc = $stmt_check_loc->fetchColumn();

            if ($current_loc === false) {
                throw new Exception("SN $sn tidak ditemukan di Master Data.");
            }
            if ($current_loc !== 'Pusat' && !empty($current_loc)) {
                throw new Exception("Gagal Transfer: Unit dengan SN $sn tercatat berada di '$current_loc'.");
            }

            $check_active_sql = "SELECT s.service_code, s.status FROM services s JOIN devices d ON s.device_id = d.id WHERE d.serial_number = ? AND s.status NOT IN ('taken', 'cancelled') LIMIT 1";
            $stmt_check_active = $pdo_branch->prepare($check_active_sql);
            $stmt_check_active->execute([$sn]);
            $existing_active = $stmt_check_active->fetch(PDO::FETCH_ASSOC);
            if ($existing_active) {
                throw new Exception("SN $sn sedang dalam status " . strtoupper(str_replace('_', ' ', $existing_active['status'])) . " di cabang tujuan.");
            }

            $pdo->prepare("UPDATE master_units SET current_branch = ? WHERE serial_number = ?")->execute([$target_branch_code, $sn]);
        }

        $pdo->prepare("UPDATE stock_buffer_pusat SET quantity = quantity - ? WHERE id = ?")->execute([$qty_transfer, $item_id]);

        $stmt_ins_trf = $pdo->prepare("INSERT INTO transfer_stock (item_code, quantity, to_branch_id, transfer_date, status, notes, created_by_user_id) VALUES (?, ?, ?, CURDATE(), 'completed', ?, ?)");
        $stmt_ins_trf->execute([$item_code, $qty_transfer, $to_branch, $final_notes, $_SESSION['user']['id']]);
        $transfer_ids[] = $pdo->lastInsertId();

        $stmt_check = $pdo_branch->prepare("SELECT id, stock FROM stock_buffer_cabang WHERE part_code = ?");
        $stmt_check->execute([$item_code]);
        $existing = $stmt_check->fetch();

        if ($existing) {
            $new_stock = $existing['stock'] + $qty_transfer;
            $pdo_branch->prepare("UPDATE stock_buffer_cabang SET stock = ?, sell_price = ?, type = ? WHERE id = ?")->execute([$new_stock, $item_data['sell_price'], $item_data['item_type'], $existing['id']]);
            $target_id = $existing['id'];
        } else {
            $pdo_branch->prepare("INSERT INTO stock_buffer_cabang (part_code, part_name, type, stock, sell_price, location) VALUES (?, ?, ?, ?, ?, 'Transfer Pusat')")->execute([$item_code, $item_data['item_name'], $item_data['item_type'], $qty_transfer, $item_data['sell_price']]);
            $target_id = $pdo_branch->lastInsertId();
            $new_stock = $qty_transfer;
        }

        $pdo_branch->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, 'in', ?, ?, ?, NOW())")
            ->execute([$target_id, $_SESSION['user']['id'], $qty_transfer, $new_stock, "Transfer dari Pusat"]);
    }

    $pdo->commit();
    header("Location: index.php?msg=transferred&print_ids=" . implode(',', $transfer_ids));
    exit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: index.php?msg=transfer_failed&err_msg=" . urlencode("Gagal transfer: " . $e->getMessage()));
    exit();
}


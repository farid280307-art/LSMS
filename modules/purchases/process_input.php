<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sales_warranty_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'save') {
    header("Location: index.php");
    exit();
}

$pdo = getDbConnection();
$role = $_SESSION['user']['role'];

if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($_POST['branch_code'])) {
    $pdo = getDbConnection($_POST['branch_code']);
}

$code = '';
$processedStockAdjustments = [];

try {
    $buyer_type = $_POST['buyer_type'] ?? 'personal';
    $supplier = '';

    if ($buyer_type === 'dealer') {
        $dealer_id = $_POST['dealer_id'] ?? '';
        $pdo_pusat_post = getDbConnection('pusat');
        $stmt_dealer = $pdo_pusat_post->prepare("SELECT name FROM dealers WHERE id = ?");
        $stmt_dealer->execute([$dealer_id]);
        $dealer_data = $stmt_dealer->fetch();

        if (!$dealer_data) {
            throw new Exception("Dealer yang dipilih tidak valid.");
        }
        $supplier = $dealer_data['name'];
    } else {
        $supplier = trim($_POST['supplier_name'] ?? '');
        if ($supplier === '') {
            throw new Exception("Nama pelanggan wajib diisi.");
        }
    }

    $date = $_POST['purchase_date'] ?? '';
    if ($date === '') {
        throw new Exception("Tanggal penjualan wajib diisi.");
    }

    $items = $_POST['items'] ?? [];
    if (empty($items)) {
        throw new Exception("Minimal 1 item wajib diinput.");
    }

    $code = 'TRX-' . date('ymd') . '-' . rand(100, 999);
    $validatedItems = [];

    $transaction_branch = $_SESSION['user']['cabang'] ?? '';
    if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($_POST['branch_code'])) {
        $transaction_branch = $_POST['branch_code'];
    }

    $pdo_pusat_mu = getDbConnection('pusat');
    if (!$pdo_pusat_mu) {
        throw new Exception("Koneksi ke database pusat gagal.");
    }

    $itemMasterCache = [];
    $stockProjection = [];

    foreach ($items as $idx => $itemData) {
        $item_id = (int)($itemData['item_id'] ?? 0);
        $type = $itemData['item_type'] ?? '';
        $qty = (int)($itemData['quantity'] ?? 0);
        $sn = trim($itemData['serial_number'] ?? '');
        $warrantyDuration = normalizeSalesWarrantyDuration($itemData['warranty_duration'] ?? 0);

        if ($item_id <= 0 || $qty <= 0 || $type === '') {
            throw new Exception("Data item pada baris ke-" . ($idx + 1) . " tidak lengkap.");
        }

        if ($type === 'device' && $sn === '') {
            throw new Exception("Serial number wajib diisi untuk item device pada baris ke-" . ($idx + 1) . '.');
        }

        if (!isset($itemMasterCache[$item_id])) {
            $stmt_item = $pdo->prepare("SELECT part_name, sell_price, stock FROM stock_buffer_cabang WHERE id = ?");
            $stmt_item->execute([$item_id]);
            $item_details = $stmt_item->fetch();
            if (!$item_details) {
                throw new Exception("Item pada baris ke-" . ($idx + 1) . " tidak valid.");
            }
            $itemMasterCache[$item_id] = $item_details;
            $stockProjection[$item_id] = (int)$item_details['stock'];
        } else {
            $item_details = $itemMasterCache[$item_id];
        }

        $stockAvailable = (int)($stockProjection[$item_id] ?? 0);
        if ($stockAvailable < $qty) {
            throw new Exception("Stok tidak cukup untuk '" . $item_details['part_name'] . "'. Sisa: " . $stockAvailable);
        }
        $stockAfter = $stockAvailable - $qty;
        $stockProjection[$item_id] = $stockAfter;

        $item_name = $item_details['part_name'];
        $price = (float)$item_details['sell_price'];
        $total = $qty * $price;

        $warranty = [
            'duration' => 0,
            'start' => null,
            'end' => null,
            'status' => 'expired',
        ];

        if ($type === 'sparepart') {
            $warranty = calculateSalesWarrantyByDate($date, $warrantyDuration);
        }

        $deviceWarrantyStart = null;
        $deviceWarrantyEnd = null;

        if ($type === 'device') {
            $stmt_loc = $pdo_pusat_mu->prepare("SELECT current_branch FROM master_units WHERE serial_number = ?");
            $stmt_loc->execute([$sn]);
            $current_loc = $stmt_loc->fetchColumn();

            if ($current_loc && $current_loc !== $transaction_branch) {
                throw new Exception("Gagal Jual: SN $sn tercatat berada di '$current_loc', bukan di cabang ini.");
            }

            $deviceWarrantyStart = $date;
            $deviceWarrantyEnd = date('Y-m-d', strtotime($date . ' + 1 year'));
        }

        $validatedItems[] = [
            'item_id' => $item_id,
            'type' => $type,
            'qty' => $qty,
            'sn' => $sn,
            'item_name' => $item_name,
            'price' => $price,
            'total' => $total,
            'stock_after' => $stockAfter,
            'warranty' => $warranty,
            'device_warranty_start' => $deviceWarrantyStart,
            'device_warranty_end' => $deviceWarrantyEnd,
        ];
    }

    ensureSalesWarrantySchema($pdo);
    $pdo->beginTransaction();

    $salesCreatedAt = $date . ' ' . date('H:i:s');
    $customerId = null;
    $stmtSales = $pdo->prepare(
        "INSERT INTO sales (invoice_code, customer_id, customer_name, created_by_user_id, created_at)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmtSales->execute([$code, $customerId, $supplier, $_SESSION['user']['id'], $salesCreatedAt]);
    $salesId = (int)$pdo->lastInsertId();

    $totalQty = 0;
    $grandTotal = 0.0;
    $itemNames = [];
    $itemTypes = [];
    foreach ($validatedItems as $entrySummary) {
        $totalQty += (int)$entrySummary['qty'];
        $grandTotal += (float)$entrySummary['total'];
        $itemNames[] = (string)$entrySummary['item_name'];
        $itemTypes[(string)$entrySummary['type']] = true;
    }

    $primaryType = count($itemTypes) === 1
        ? array_key_first($itemTypes)
        : (string)($validatedItems[0]['type'] ?? 'sparepart');
    if (!in_array($primaryType, ['device', 'sparepart'], true)) {
        $primaryType = 'sparepart';
    }

    $itemSummary = '-';
    if (!empty($itemNames)) {
        $itemSummary = $itemNames[0];
        if (count($itemNames) > 1) {
            $itemSummary .= ' +' . (count($itemNames) - 1) . ' item lainnya';
        }
    }

    $quantitySummary = max(1, $totalQty);
    $unitPriceSummary = $quantitySummary > 0 ? ($grandTotal / $quantitySummary) : $grandTotal;
    $stmtPurchase = $pdo->prepare(
        "INSERT INTO purchases (purchase_code, supplier_name, item_name, item_type, quantity, unit_price, total_price, purchase_date, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmtPurchase->execute([
        $code,
        $supplier,
        $itemSummary,
        $primaryType,
        $quantitySummary,
        $unitPriceSummary,
        $grandTotal,
        $date,
        $_SESSION['user']['id'],
    ]);

    $stmtSalesItem = $pdo->prepare(
        "INSERT INTO sales_items
            (sales_id, item_type, item_name, quantity, price, serial_number, warranty_duration, warranty_start, warranty_end, warranty_status, is_warranty_claim)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
    );
    $stmt_update = $pdo->prepare("UPDATE stock_buffer_cabang SET stock = ? WHERE id = ?");
    $stmt_hist = $pdo->prepare(
        "INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time)
         VALUES (?, ?, 'out', ?, ?, ?, NOW())"
    );
    $stmt_mu = $pdo_pusat_mu->prepare("UPDATE master_units SET warranty_start = ?, warranty_end = ?, current_branch = 'Terjual' WHERE serial_number = ?");

    foreach ($validatedItems as $entry) {
        $stmtSalesItem->execute([
            $salesId,
            $entry['type'],
            $entry['item_name'],
            $entry['qty'],
            $entry['price'],
            $entry['sn'] !== '' ? $entry['sn'] : null,
            $entry['warranty']['duration'],
            $entry['warranty']['start'],
            $entry['warranty']['end'],
            $entry['warranty']['status'],
        ]);

        $new_stock = (int)$entry['stock_after'];
        $stmt_update->execute([$new_stock, (int)$entry['item_id']]);
        $stmt_hist->execute([
            (int)$entry['item_id'],
            $_SESSION['user']['id'],
            (int)$entry['qty'],
            $new_stock,
            "Penjualan: $code"
        ]);

        $processedStockAdjustments[] = [
            'item_id' => (int)$entry['item_id'],
            'qty' => (int)$entry['qty'],
        ];

        if ($entry['type'] === 'device' && $entry['sn'] !== '') {
            $stmt_mu->execute([
                $entry['device_warranty_start'],
                $entry['device_warranty_end'],
                $entry['sn'],
            ]);
        }
    }

    $pdo->commit();

    $redirect_url = "invoice.php?code=" . urlencode($code);
    if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($_POST['branch_code'])) {
        $redirect_url .= "&branch=" . urlencode($_POST['branch_code']);
    }
    header("Location: $redirect_url");
    exit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $messageLower = strtolower($e->getMessage());
    $needCompensation = (strpos($messageLower, 'no active transaction') !== false);

    if ($needCompensation && $code !== '' && !empty($processedStockAdjustments)) {
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            $restoreStmt = $pdo->prepare("UPDATE stock_buffer_cabang SET stock = stock + ? WHERE id = ?");
            $restoreLogStmt = $pdo->prepare(
                "INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time)
                 VALUES (?, ?, 'in', ?, (SELECT stock FROM stock_buffer_cabang WHERE id = ?), ?, NOW())"
            );

            foreach ($processedStockAdjustments as $adj) {
                $restoreStmt->execute([(int)$adj['qty'], (int)$adj['item_id']]);
                $restoreLogStmt->execute([
                    (int)$adj['item_id'],
                    $_SESSION['user']['id'],
                    (int)$adj['qty'],
                    (int)$adj['item_id'],
                    "Rollback otomatis transaksi gagal: $code",
                ]);
            }

            $deletePurchaseStmt = $pdo->prepare("DELETE FROM purchases WHERE purchase_code = ?");
            $deletePurchaseStmt->execute([$code]);

            $deleteSalesItemStmt = $pdo->prepare(
                "DELETE si FROM sales_items si
                 INNER JOIN sales s ON s.id = si.sales_id
                 WHERE s.invoice_code = ?"
            );
            $deleteSalesItemStmt->execute([$code]);

            $deleteSalesStmt = $pdo->prepare("DELETE FROM sales WHERE invoice_code = ?");
            $deleteSalesStmt->execute([$code]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $compensationError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    $error = "Gagal menyimpan penjualan: " . $e->getMessage();
    header("Location: index.php?msg=error&err_msg=" . urlencode($error));
    exit();
}

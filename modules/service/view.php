<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'teknisi'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/warranty_helpers.php';

// Untuk superadmin, koneksi bisa dipaksa ke database cabang tertentu
// Untuk role lain, koneksi akan otomatis berdasarkan session
$branch_override = (in_array($_SESSION['user']['role'], ['superadmin', 'kepala_pusat', 'kepala_admin'])) ? ($_GET['branch'] ?? null) : null;
$pdo = getDbConnection($branch_override);
ensureServiceItemsWarrantySchema($pdo);

$id = $_GET['id'] ?? 0;
$role = $_SESSION['user']['role'];

// --- DATA FETCHING (Moved Up) ---
$sql = "SELECT s.*, COALESCE(c.name, dl.name) as customer_name, COALESCE(c.phone, dl.phone) as phone, 
               d.brand, d.model, d.serial_number, d.physical_condition,
               u.full_name as technician_name
        FROM services s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN lsms_pusat.dealers dl ON s.dealer_id = dl.id
        JOIN devices d ON s.device_id = d.id
        LEFT JOIN lsms_pusat.users u ON s.technician_id = u.id
        WHERE s.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) die("Service tidak ditemukan.");

// --- LOGIC HANDLER (POST) ---
function buildViewRedirectUrl(int $serviceId, ?string $branchOverride, string $msg = 'saved', ?string $errMsg = null): string
{
    $url = "view.php?id={$serviceId}&msg=" . urlencode($msg);
    if ($branchOverride) {
        $url .= "&branch=" . urlencode($branchOverride);
    }
    if ($errMsg) {
        $url .= "&err_msg=" . urlencode($errMsg);
    }
    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Admin Validasi & Assign Teknisi
    if ($action === 'assign_tech' && in_array($role, ['admin', 'superadmin', 'kepala_admin', 'kepala_cabang'], true)) {
        $tech_id = $_POST['technician_id'] ?? null;

        if (empty($tech_id)) {
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'no_tech_selected'));
            exit();
        }

        $stmt = $pdo->prepare("UPDATE services SET technician_id = ?, status = 'diagnosis' WHERE id = ?");
        $stmt->execute([$tech_id, $id]);

        $tech_name = $pdo->query("SELECT full_name FROM lsms_pusat.users WHERE id = $tech_id")->fetchColumn();
        $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'assign_tech', ?, ?, NOW())")
            ->execute([$id, "Menugaskan teknisi: $tech_name", $_SESSION['user']['id']]);

        header('Location: index.php?msg=assigned');
        exit();
    }

    // 2. Teknisi Update Status / Diagnosis
    if ($action === 'update_diagnosis' && in_array($role, ['teknisi', 'admin', 'kepala_admin'], true)) {
        $status = $_POST['status'] ?? 'diagnosis';
        $stmt = $pdo->prepare('UPDATE services SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);

        $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'status_update', ?, ?, NOW())")
            ->execute([$id, 'Mengubah status menjadi: ' . strtoupper(str_replace('_', ' ', $status)), $_SESSION['user']['id']]);

        applyWarrantyToServiceItems($pdo, (int)$id);
    }

    // 3. Tambah Item (Jasa/Sparepart)
    if ($action === 'add_item') {
        try {
            $stmt_check = $pdo->prepare('SELECT d.serial_number FROM services s JOIN devices d ON s.device_id = d.id WHERE s.id = ?');
            $stmt_check->execute([$id]);
            $service_to_check = $stmt_check->fetch();

            $is_under_warranty_post = false;
            if ($service_to_check && !empty($service_to_check['serial_number'])) {
                $pdo_pusat_warranty = getDbConnection('pusat');
                $stmt_warranty = $pdo_pusat_warranty->prepare('SELECT warranty_start, warranty_end FROM lsms_pusat.master_units WHERE serial_number = ?');
                $stmt_warranty->execute([$service_to_check['serial_number']]);
                $unit_master = $stmt_warranty->fetch();
                $today_post = date('Y-m-d');
                if ($unit_master && !empty($unit_master['warranty_start']) && !empty($unit_master['warranty_end']) && $today_post >= $unit_master['warranty_start'] && $today_post <= $unit_master['warranty_end']) {
                    $is_under_warranty_post = true;
                }
            }

            $type = trim((string)($_POST['item_type'] ?? 'service_fee'));
            $desc = trim((string)($_POST['description'] ?? ''));
            $price = (int)str_replace('.', '', (string)($_POST['price'] ?? '0'));
            $warrantyDuration = $type === 'sparepart' ? normalizeWarrantyDuration($_POST['warranty_duration'] ?? 0) : 0;
            $isWarrantyClaim = ($type === 'sparepart' && isset($_POST['is_warranty_claim'])) ? 1 : 0;
            $partSerialNumber = $type === 'sparepart'
                ? trim((string)($_POST['part_serial_number'] ?? ''))
                : '';

            if ($partSerialNumber !== '' && strlen($partSerialNumber) > 120) {
                throw new Exception('SN sparepart terlalu panjang (maksimal 120 karakter).');
            }

            if ($desc === '') {
                throw new Exception('Deskripsi item wajib diisi.');
            }

            if ($is_under_warranty_post) {
                $price = 0;
            }

            if ($isWarrantyClaim === 1) {
                $serialToCheck = (string)($service_to_check['serial_number'] ?? '');
                $reference = findActiveWarrantyReferenceForPart($pdo, $serialToCheck, $desc, (int)$id);
                if (!$reference) {
                    throw new Exception('Klaim garansi tidak valid. Sparepart aktif tidak ditemukan.');
                }
                $price = 0;
                $warrantyDuration = 0;
            }

            if ($role === 'gudang' && $type === 'sparepart') {
                $pdo->query("UPDATE services SET status = 'on_progress' WHERE id = $id");
            }

            $warrantyInfo = calculateWarranty(getServiceCompletionDate($pdo, (int)$id), $warrantyDuration);
            if ($type !== 'sparepart') {
                $warrantyInfo = ['duration' => 0, 'start' => null, 'end' => null, 'status' => 'expired'];
            }

            $stmt = $pdo->prepare('INSERT INTO service_items (service_id, item_type, description, price, warranty_duration, warranty_start, warranty_end, warranty_status, part_serial_number, is_warranty_claim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $id,
                $type,
                $desc,
                $price,
                $warrantyInfo['duration'],
                $warrantyInfo['start'],
                $warrantyInfo['end'],
                $warrantyInfo['status'],
                $partSerialNumber !== '' ? $partSerialNumber : null,
                $isWarrantyClaim,
            ]);

            $extraNote = '';
            if ($type === 'sparepart') {
                $extraNote = ' | Garansi: ' . warrantyDurationLabel($warrantyInfo['duration']);
                if ($partSerialNumber !== '') {
                    $extraNote .= ' | SN Part: ' . $partSerialNumber;
                }
                if ($isWarrantyClaim === 1) {
                    $extraNote .= ' | Warranty Claim';
                }
            }

            $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'item_added', ?, ?, NOW())")
                ->execute([$id, 'Menambahkan ' . ($type === 'sparepart' ? 'Sparepart' : 'Jasa') . ': ' . $desc . ' (Rp ' . number_format($price) . ')' . $extraNote, $_SESSION['user']['id']]);

            applyWarrantyToServiceItems($pdo, (int)$id);
        } catch (Exception $e) {
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'item_error', $e->getMessage()));
            exit();
        }
    }

    // 4. Pembayaran & Ambil Barang
    if ($action === 'process_payment' && in_array($role, ['admin', 'superadmin', 'kepala_admin', 'kepala_cabang', 'cs'], true)) {
        $amount = $_POST['total_amount'];
        $method = $_POST['payment_method'];
        $pay_code = 'PAY-' . date('ymd') . rand(100, 999);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO payments (service_id, payment_code, amount, payment_method, received_by_user_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $pay_code, $amount, $method, $_SESSION['user']['id']]);

        $stmt = $pdo->prepare("UPDATE services SET status = 'taken', final_cost = ? WHERE id = ?");
        $stmt->execute([$amount, $id]);

        applyWarrantyToServiceItems($pdo, (int)$id);

        $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'payment', ?, ?, NOW())")
            ->execute([$id, 'Pembayaran diterima (' . $method . ') sebesar Rp ' . number_format((float)$amount) . '. Unit diambil.', $_SESSION['user']['id']]);

        $pdo->commit();

        header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'paid'));
        exit();
    }

    // 5. Selesaikan Tiket Garansi (Tanpa Biaya)
    if ($action === 'close_warranty_ticket' && in_array($role, ['admin', 'superadmin', 'kepala_admin', 'kepala_cabang', 'cs'], true)) {
        $stmt = $pdo->prepare("UPDATE services SET status = 'taken', final_cost = 0 WHERE id = ?");
        $stmt->execute([$id]);

        applyWarrantyToServiceItems($pdo, (int)$id);

        $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'taken', ?, ?, NOW())")
            ->execute([$id, 'Service garansi selesai. Unit diambil oleh pelanggan.', $_SESSION['user']['id']]);

        header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'saved'));
        exit();
    }

    // 6. Teknisi Menggunakan Sparepart dari Stok
    if ($action === 'use_sparepart' && in_array($role, ['teknisi', 'admin', 'kepala_admin', 'kepala_cabang'], true)) {
        $sparepart_id = (int)($_POST['sparepart_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);
        $warrantyDuration = normalizeWarrantyDuration($_POST['warranty_duration'] ?? 0);
        $isWarrantyClaim = isset($_POST['is_warranty_claim']) ? 1 : 0;
        $partSerialNumber = trim((string)($_POST['part_serial_number'] ?? ''));

        if ($partSerialNumber !== '' && strlen($partSerialNumber) > 120) {
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'stock_error', 'SN sparepart terlalu panjang (maksimal 120 karakter).'));
            exit();
        }

        if ($sparepart_id <= 0 || $quantity <= 0) {
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'invalid_part'));
            exit();
        }

        $pdo->beginTransaction();
        try {
            $stmt_item = $pdo->prepare('SELECT * FROM stock_buffer_cabang WHERE id = ? FOR UPDATE');
            $stmt_item->execute([$sparepart_id]);
            $item = $stmt_item->fetch();

            if (!$item || $item['stock'] < $quantity) {
                throw new Exception("Stok untuk '" . ($item['part_name'] ?? 'item') . "' tidak mencukupi. Sisa: " . ($item['stock'] ?? 0));
            }

            $new_stock = $item['stock'] - $quantity;
            $pdo->prepare('UPDATE stock_buffer_cabang SET stock = ? WHERE id = ?')->execute([$new_stock, $sparepart_id]);

            $stmt_sn = $pdo->prepare('SELECT d.serial_number FROM services s JOIN devices d ON s.device_id = d.id WHERE s.id = ?');
            $stmt_sn->execute([$id]);
            $sn_data = $stmt_sn->fetch();

            $is_warranty_active = false;
            if ($sn_data && !empty($sn_data['serial_number'])) {
                $pdo_pusat_w = getDbConnection('pusat');
                $stmt_w = $pdo_pusat_w->prepare('SELECT warranty_start, warranty_end FROM master_units WHERE serial_number = ?');
                $stmt_w->execute([$sn_data['serial_number']]);
                $w_data = $stmt_w->fetch();
                $today_w = date('Y-m-d');
                if ($w_data && !empty($w_data['warranty_start']) && !empty($w_data['warranty_end']) && $today_w >= $w_data['warranty_start'] && $today_w <= $w_data['warranty_end']) {
                    $is_warranty_active = true;
                }
            }

            $item_description = $item['part_name'] . ' (x' . $quantity . ')';

            if ($isWarrantyClaim === 1) {
                $serialToCheck = (string)($sn_data['serial_number'] ?? '');
                $reference = findActiveWarrantyReferenceForPart($pdo, $serialToCheck, (string)$item['part_name'], (int)$id);
                if (!$reference) {
                    throw new Exception('Klaim garansi tidak valid. Sparepart aktif tidak ditemukan untuk unit ini.');
                }
                $warrantyDuration = 0;
            }

            $price_to_add = ($is_warranty_active || $isWarrantyClaim === 1) ? 0 : ($item['sell_price'] * $quantity);
            $warrantyInfo = calculateWarranty(getServiceCompletionDate($pdo, (int)$id), $warrantyDuration);

            $pdo->prepare('INSERT INTO service_items (service_id, item_type, description, price, warranty_duration, warranty_start, warranty_end, warranty_status, part_serial_number, is_warranty_claim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $id,
                    'sparepart',
                    $item_description,
                    $price_to_add,
                    $warrantyInfo['duration'],
                    $warrantyInfo['start'],
                    $warrantyInfo['end'],
                    $warrantyInfo['status'],
                    $partSerialNumber !== '' ? $partSerialNumber : null,
                    $isWarrantyClaim,
                ]);

            $log_note = 'Digunakan untuk service: ' . $service['service_code'];
            $pdo->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, 'out', ?, ?, ?, NOW())")
                ->execute([$sparepart_id, $_SESSION['user']['id'], $quantity, $new_stock, $log_note]);

            $log_desc_svc = 'Menggunakan sparepart: ' . $item_description . ' | Garansi: ' . warrantyDurationLabel($warrantyInfo['duration']);
            if ($partSerialNumber !== '') {
                $log_desc_svc .= ' | SN Part: ' . $partSerialNumber;
            }
            if ($isWarrantyClaim === 1) {
                $log_desc_svc .= ' | Warranty Claim';
            }

            $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'item_added', ?, ?, NOW())")
                ->execute([$id, $log_desc_svc, $_SESSION['user']['id']]);

            applyWarrantyToServiceItems($pdo, (int)$id);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'stock_error', $e->getMessage()));
            exit();
        }
    }

    // 7. Hapus Item (Jasa/Sparepart)
    if ($action === 'delete_item' && in_array($role, ['teknisi', 'admin', 'kepala_admin', 'kepala_cabang'], true)) {
        $item_id = (int)($_POST['item_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt_get = $pdo->prepare('SELECT * FROM service_items WHERE id = ?');
            $stmt_get->execute([$item_id]);
            $item_to_delete = $stmt_get->fetch();

            if (!$item_to_delete) {
                throw new Exception('Item tidak ditemukan.');
            }

            if ($item_to_delete['item_type'] === 'sparepart') {
                if (preg_match('/^(.*) \(x(\d+)\)$/', $item_to_delete['description'], $matches)) {
                    $part_name = trim($matches[1]);
                    $qty_return = (int)$matches[2];

                    $stmt_find_part = $pdo->prepare('SELECT id, stock FROM stock_buffer_cabang WHERE part_name = ? LIMIT 1');
                    $stmt_find_part->execute([$part_name]);
                    $part_data = $stmt_find_part->fetch();

                    if ($part_data) {
                        $new_stock = $part_data['stock'] + $qty_return;
                        $pdo->prepare('UPDATE stock_buffer_cabang SET stock = ? WHERE id = ?')->execute([$new_stock, $part_data['id']]);

                        $pdo->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, 'in', ?, ?, ?, NOW())")
                            ->execute([$part_data['id'], $_SESSION['user']['id'], $qty_return, $new_stock, 'Batal Service: ' . $service['service_code']]);
                    }
                }
            }

            $pdo->prepare('DELETE FROM service_items WHERE id = ?')->execute([$item_id]);

            $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'item_removed', ?, ?, NOW())")
                ->execute([$id, 'Menghapus item: ' . $item_to_delete['description'], $_SESSION['user']['id']]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'error', $e->getMessage()));
            exit();
        }
    }

    // 8. Tandai item sparepart sebagai klaim garansi (biaya 0)
    if ($action === 'mark_warranty_claim' && in_array($role, ['teknisi', 'admin', 'superadmin', 'kepala_admin', 'kepala_cabang', 'cs'], true)) {
        $item_id = (int)($_POST['item_id'] ?? 0);

        try {
            $stmt_item = $pdo->prepare('SELECT id, description, item_type, warranty_end, is_warranty_claim FROM service_items WHERE id = ? AND service_id = ? LIMIT 1');
            $stmt_item->execute([$item_id, $id]);
            $item = $stmt_item->fetch();

            if (!$item || $item['item_type'] !== 'sparepart') {
                throw new Exception('Item sparepart tidak ditemukan.');
            }

            $statusNow = checkWarrantyStatus($item['warranty_end'] ?? null);
            if ($statusNow !== 'active') {
                header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'claim_expired'));
                exit();
            }

            $pdo->prepare('UPDATE service_items SET is_warranty_claim = 1, price = 0, warranty_status = ? WHERE id = ?')
                ->execute([$statusNow, $item_id]);

            $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'warranty_claim', ?, ?, NOW())")
                ->execute([$id, 'Warranty Claim untuk sparepart: ' . $item['description'], $_SESSION['user']['id']]);

            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'claim_success'));
            exit();
        } catch (Exception $e) {
            header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'claim_error', $e->getMessage()));
            exit();
        }
    }

    applyWarrantyToServiceItems($pdo, (int)$id);

    header('Location: ' . buildViewRedirectUrl((int)$id, $branch_override, 'saved'));
    exit();
}
// Cek Status Garansi dari DB Pusat
$is_under_warranty = false;
if (!empty($service['serial_number'])) {
    $pdo_pusat_warranty = getDbConnection('pusat');
    $stmt_warranty = $pdo_pusat_warranty->prepare("SELECT warranty_start, warranty_end FROM lsms_pusat.master_units WHERE serial_number = ?");
    $stmt_warranty->execute([$service['serial_number']]);
    $unit_master = $stmt_warranty->fetch();
    $today_view = date('Y-m-d');
    if ($unit_master && !empty($unit_master['warranty_start']) && !empty($unit_master['warranty_end']) && $today_view >= $unit_master['warranty_start'] && $today_view <= $unit_master['warranty_end']) {
        $is_under_warranty = true;
    }
}

// Ambil Items (Biaya)
syncWarrantyStatusForService($pdo, (int)$id);
$stmt = $pdo->prepare("SELECT * FROM service_items WHERE service_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// Hitung Total
$total_cost = 0;
foreach ($items as $item) $total_cost += $item['price'];

// Ambil List Teknisi untuk Dropdown (dari DB Pusat)
$pdo_pusat = getDbConnection('pusat'); // Pastikan koneksi ke DB pusat ada
$target_branch = $branch_override ?? $_SESSION['user']['cabang'] ?? null;
$technicians = [];

if ($target_branch) {
    // Cari ID Cabang berdasarkan kode cabang
    $stmt_branch = $pdo_pusat->prepare("SELECT id FROM lsms_pusat.branches WHERE branch_code = ?");
    $stmt_branch->execute([$target_branch]);
    $branch_id = $stmt_branch->fetchColumn();

    if ($branch_id) {
        $sql_teknisi = "SELECT id, full_name FROM lsms_pusat.users WHERE role_id = (SELECT id FROM lsms_pusat.roles WHERE role_name = 'teknisi') AND branch_id = ? ORDER BY full_name ASC";
        $stmt_teknisi = $pdo_pusat->prepare($sql_teknisi);
        $stmt_teknisi->execute([$branch_id]);
        $technicians = $stmt_teknisi->fetchAll();
    }
} else if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) && !$target_branch) {
    // Jika Superadmin dan tidak ada cabang spesifik, ambil semua teknisi
    $sql_teknisi = "SELECT id, full_name FROM lsms_pusat.users WHERE role_id = (SELECT id FROM lsms_pusat.roles WHERE role_name = 'teknisi') ORDER BY full_name ASC";
    $technicians = $pdo_pusat->query($sql_teknisi)->fetchAll();
} else {
    // Jika user Pusat (atau tidak ada cabang), ambil teknisi Pusat (branch_id IS NULL)
    $sql_teknisi = "SELECT id, full_name FROM lsms_pusat.users WHERE role_id = (SELECT id FROM lsms_pusat.roles WHERE role_name = 'teknisi') AND branch_id IS NULL ORDER BY full_name ASC";
    $technicians = $pdo_pusat->query($sql_teknisi)->fetchAll();
}

// Ambil Sparepart yang tersedia di cabang ini
$available_spareparts = [];
if (in_array($role, ['teknisi', 'admin', 'kepala_admin', 'kepala_cabang'])) {
    $stmt_parts = $pdo->prepare("SELECT id, part_name, part_code, stock, sell_price FROM stock_buffer_cabang WHERE stock > 0 AND type = 'sparepart' ORDER BY part_name ASC");
    $stmt_parts->execute();
    $available_spareparts = $stmt_parts->fetchAll();
}

// Khusus teknisi: jika unit masih garansi, opsi garansi sparepart disembunyikan
$is_tech_under_unit_warranty = ($role === 'teknisi' && $is_under_warranty);

require_once __DIR__ . '/../../views/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/../../views/layouts/sidebar.php'; ?>
    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-light bg-white px-4 py-3 shadow-sm sticky-top">
            <div class="container-fluid">
                <div class="d-flex align-items-center">
                    <button class="btn btn-light text-primary border-0 d-md-none" id="sidebarToggleMobile"><i class="fas fa-bars fa-lg"></i></button>
                    <button class="btn btn-light text-primary border-0" id="navbarToggleDesktop"><i class="fas fa-bars fa-lg"></i></button>
                </div>
                <ul class="navbar-nav ms-auto mt-2 mt-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <a class="dropdown-item" href="<?= BASE_URL ?>auth/logout.php">Logout</a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php" class="btn btn-link text-decoration-none ps-0 text-muted"><i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar</a>
                <?php
                    $receipt_in_url = "receipt.php?id=" . urlencode($service['id']) . "&type=masuk";
                    $receipt_out_url = "receipt.php?id=" . urlencode($service['id']) . "&type=keluar";
                    if ($branch_override) {
                        $receipt_in_url .= "&branch=" . urlencode($branch_override);
                        $receipt_out_url .= "&branch=" . urlencode($branch_override);
                    }
                ?>
                <?php if ($role !== 'teknisi'): ?>
                <div class="d-flex gap-2">
                    <a href="<?= $receipt_in_url ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-2"></i> Cetak Service Masuk</a>
                    <?php if (in_array($service['status'], ['finished', 'taken'])): ?>
                    <a href="<?= $receipt_out_url ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="fas fa-print me-2"></i> Cetak Service Keluar</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'paid'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> Pembayaran berhasil diproses. Tiket ditutup.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'no_tech_selected'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    Gagal assign: Harap pilih teknisi terlebih dahulu.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'warranty_error'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-shield-alt me-2"></i> Gagal menambah biaya: Unit ini masih dalam masa garansi.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'stock_error'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-times-circle me-2"></i> Gagal menggunakan sparepart: <?= htmlspecialchars($_GET['err_msg'] ?? 'Error tidak diketahui.') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'item_error'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> Gagal menambah item: <?= htmlspecialchars($_GET['err_msg'] ?? 'Error tidak diketahui.') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'claim_success'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-shield-alt me-2"></i> Warranty claim berhasil diterapkan. Biaya item menjadi Rp 0.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'claim_expired'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="fas fa-clock me-2"></i> Garansi sparepart sudah habis, item tidak dapat diklaim.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'claim_error'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-ban me-2"></i> Klaim garansi gagal: <?= htmlspecialchars($_GET['err_msg'] ?? 'Error tidak diketahui.') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Kolom Kiri: Informasi Utama -->
                <div class="col-md-8">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold text-primary">Tiket: <?= $service['service_code'] ?></h5>
                            <?php
                            $status_labels = [
                                'pending' => 'Menunggu',
                                'diagnosis' => 'Diagnosis',
                                'on_progress' => 'Sedang Dikerjakan',
                                'waiting_sparepart' => 'Menunggu Sparepart',
                                'finished' => 'Selesai',
                                'taken' => 'Sudah Diambil',
                                'cancelled' => 'Dibatalkan'
                            ];
                            $status_colors = [
                                'pending' => 'secondary',
                                'diagnosis' => 'info',
                                'on_progress' => 'primary',
                                'waiting_sparepart' => 'warning',
                                'finished' => 'success',
                                'taken' => 'success',
                                'cancelled' => 'danger'
                            ];
                            $badge_color = $status_colors[$service['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $badge_color ?>"><?= $status_labels[$service['status']] ?? strtoupper(str_replace('_', ' ', $service['status'])) ?></span>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Pelanggan:</strong><br>
                                    <?= $service['customer_name'] ?> (<a href="https://wa.me/<?= $service['phone'] ?>" target="_blank"><?= $service['phone'] ?></a>)
                                </div>
                                <div class="col-md-6">
                                    <strong>Perangkat:</strong><br>
                                    <?= $service['brand'] ?> <?= $service['model'] ?> 
                                    <?php if ($is_under_warranty): ?>
                                        <span class="badge bg-primary ms-2"><i class="fas fa-shield-alt"></i> Garansi Aktif</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <strong>Keluhan Awal:</strong>
                                <p class="bg-light p-3 rounded mt-1 text-secondary"><?= nl2br($service['reported_problem']) ?></p>
                            </div>
                            <div class="mb-3">
                                <strong>Kondisi Fisik:</strong>
                                <p class="text-muted small"><?= $service['physical_condition'] ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Tabel Rincian Biaya & Sparepart -->
                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3">Rincian Biaya & Perbaikan</div>
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Tipe</th>
                                        <th>Nama Sparepart / Jasa</th>
                                        <th>Garansi</th>
                                        <th>Mulai</th>
                                        <th>Berakhir</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4">Harga</th>
                                        <th class="text-end pe-4" style="width: 120px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <?php
                                        $isSparepart = ($item['item_type'] === 'sparepart');
                                        $warrantyDuration = normalizeWarrantyDuration($item['warranty_duration'] ?? 0);
                                        $warrantyStart = !empty($item['warranty_start']) ? date('d/m/Y', strtotime($item['warranty_start'])) : '-';
                                        $warrantyEnd = !empty($item['warranty_end']) ? date('d/m/Y', strtotime($item['warranty_end'])) : '-';
                                        $hasWarrantyPeriod = $isSparepart && !empty($item['warranty_start']) && !empty($item['warranty_end']);
                                        $warrantyStatus = $hasWarrantyPeriod ? checkWarrantyStatus($item['warranty_end'] ?? null) : null;
                                        $warrantyStatusLabel = $warrantyStatus === 'active' ? 'Aktif' : 'Expired';
                                        $warrantyBadgeClass = $warrantyStatus === 'active' ? 'success' : 'danger';
                                        $isClaimed = (int)($item['is_warranty_claim'] ?? 0) === 1;
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <?php if ($isSparepart): ?>
                                                <span class="badge bg-warning text-dark">Sparepart</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Jasa</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-medium"><?= htmlspecialchars($item['description']) ?></div>
                                            <?php if ($isClaimed): ?>
                                                <span class="badge bg-primary-subtle text-primary border mt-1"><i class="fas fa-shield-alt me-1"></i>Warranty Claim</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $isSparepart ? htmlspecialchars(warrantyDurationLabel($warrantyDuration)) : '-' ?></td>
                                        <td><?= $isSparepart ? htmlspecialchars($warrantyStart) : '-' ?></td>
                                        <td><?= $isSparepart ? htmlspecialchars($warrantyEnd) : '-' ?></td>
                                        <td>
                                            <?php if ($isSparepart && $hasWarrantyPeriod): ?>
                                                <span class="badge bg-<?= $warrantyBadgeClass ?>"><i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i><?= $warrantyStatusLabel ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">Rp <?= number_format((float)$item['price'], 0, ',', '.') ?></td>
                                        <td class="text-end pe-4">
                                            <div class="d-inline-flex gap-2">
                                                <?php if ($isSparepart && !$isClaimed && $warrantyStatus === 'active' && !$is_tech_under_unit_warranty && in_array($role, ['teknisi', 'admin', 'superadmin', 'kepala_admin', 'kepala_cabang', 'cs'], true)): ?>
                                                <form method="POST" onsubmit="return confirm('Terapkan warranty claim untuk item ini? Biaya item akan menjadi Rp 0.');">
                                                    <input type="hidden" name="action" value="mark_warranty_claim">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Tandai Warranty Claim"><i class="fas fa-shield-alt"></i></button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if (in_array($role, ['teknisi', 'admin', 'kepala_admin', 'kepala_cabang'], true) && !in_array($service['status'], ['finished', 'taken', 'cancelled'], true)): ?>
                                                <form method="POST" onsubmit="return confirm('Hapus item ini? Stok akan dikembalikan jika ini adalah sparepart.');">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Hapus item"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="fw-bold bg-light">
                                        <td colspan="6" class="text-end">Total Estimasi</td>
                                        <td class="text-end pe-4 text-success fs-5">Rp <?= number_format((float)$total_cost, 0, ',', '.') ?></td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Panel Aksi (Flow Control) -->
                <div class="col-md-4">
                    
                    <?php if (in_array($role, ['admin', 'superadmin', 'kepala_admin', 'kepala_cabang']) && !in_array($service['status'], ['finished', 'taken', 'cancelled'])): ?>
                    <div class="card mb-3 border-0 shadow-sm">
                        <div class="card-header bg-light fw-bold">Panel Admin</div>
                        <div class="card-body">
                            <?php include 'assign.php'; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($is_under_warranty): ?>
                    <div class="card mb-3 border-0 shadow-sm">
                        <div class="card-header bg-primary text-white fw-bold"><i class="fas fa-shield-alt me-2"></i>Unit Dalam Garansi</div>
                        <div class="card-body">
                            <p class="text-primary mb-0">Unit ini masih dalam masa garansi. Tidak ada biaya jasa atau sparepart yang akan ditagihkan.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Panel Teknisi: Selalu tampil jika role & status sesuai -->
                    <?php if (in_array($service['status'], ['diagnosis', 'on_progress', 'waiting_sparepart']) && in_array($role, ['teknisi', 'admin', 'kepala_admin', 'kepala_cabang'])): ?>
                    <div class="card mb-3 border-0 shadow-sm">
                        <div class="card-header bg-light fw-bold d-flex align-items-center justify-content-between">
                            <span><i class="fas fa-user-cog me-2"></i>Panel Teknisi</span>
                            <span class="small text-muted"><?= htmlspecialchars($service['technician_name']) ?></span>
                        </div>
                        <div class="card-body">
                            <!-- Section 1: Update Status -->
                            <form method="POST">
                                <input type="hidden" name="action" value="update_diagnosis">
                                <label class="form-label small fw-bold text-muted">Update Status Pengerjaan</label>
                                <div class="input-group">
                                    <select name="status" class="form-select">
                                        <option value="on_progress" <?= $service['status']=='on_progress'?'selected':'' ?>>Sedang Dikerjakan</option>
                                        <option value="waiting_sparepart" <?= $service['status']=='waiting_sparepart'?'selected':'' ?>>Menunggu Sparepart</option>
                                        <option value="finished" <?= $service['status']=='finished'?'selected':'' ?>>Selesai</option>
                                    </select>
                                    <button type="submit" class="btn btn-info text-white">Update</button>
                                </div>
                            </form>

                            <hr class="my-4">

                            <!-- Section 2: Gunakan Sparepart (Selalu tampil jika ada stok) -->
                            <?php if (!empty($available_spareparts)): ?>
                            <form method="POST" class="mb-4">
                                <input type="hidden" name="action" value="use_sparepart">
                                <label class="form-label small fw-bold text-muted">Gunakan Sparepart dari Stok</label>
                                <select name="sparepart_id" class="form-select form-select-sm mb-2" required>
                                    <option value="">-- Pilih Sparepart --</option>
                                    <?php foreach ($available_spareparts as $part): ?>
                                        <option value="<?= $part['id'] ?>"><?= htmlspecialchars($part['part_name']) ?> (Stok: <?= $part['stock'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="row g-2 mb-2">
                                    <div class="<?= $is_tech_under_unit_warranty ? 'col-12' : 'col-5' ?>">
                                        <input type="number" name="quantity" class="form-control form-control-sm" placeholder="Jumlah" value="1" min="1" required>
                                    </div>
                                    <?php if (!$is_tech_under_unit_warranty): ?>
                                    <div class="col-7">
                                        <select name="warranty_duration" class="form-select form-select-sm">
                                            <option value="0">Tanpa Garansi</option>
                                            <option value="3">Garansi 3 Bulan</option>
                                            <option value="6">Garansi 6 Bulan</option>
                                        </select>
                                    </div>
                                    <?php else: ?>
                                        <input type="hidden" name="warranty_duration" value="0">
                                    <?php endif; ?>
                                </div>
                                <input type="text" name="part_serial_number" class="form-control form-control-sm mb-2" placeholder="SN Sparepart (Opsional)">
                                <?php if (!$is_tech_under_unit_warranty): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="1" name="is_warranty_claim" id="claimFromStock">
                                    <label class="form-check-label small text-muted" for="claimFromStock">
                                        Tandai sebagai Warranty Claim (biaya otomatis Rp 0)
                                    </label>
                                </div>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-outline-success btn-sm w-100">Gunakan</button>
                            </form>
                            <?php endif; ?>

                            <!-- Section 3: Tambah Jasa (Hanya jika tidak garansi) -->
                            <?php if (!$is_under_warranty): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_item">
                                <input type="hidden" name="item_type" value="service_fee">
                                <label class="form-label small fw-bold text-muted">Tambah Biaya Jasa Lainnya</label>
                                <input type="text" name="description" class="form-control form-control-sm mb-2" placeholder="Contoh: Install Ulang Windows" required>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" name="price" class="form-control" placeholder="Harga" required>
                                    <button type="submit" class="btn btn-outline-primary">Tambah</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$is_under_warranty): // Sembunyikan panel gudang jika garansi ?>
                        <!-- 3. Panel Gudang: Tambah Sparepart -->
                        <?php if (in_array($role, ['gudang', 'admin', 'kepala_admin']) && $service['status'] != 'finished' && $service['status'] != 'taken'): ?>
                        <div class="card mb-3 border-0 shadow-sm">
                            <div class="card-header bg-warning fw-bold">Panel Gudang / Sparepart</div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_item">
                                    <input type="hidden" name="item_type" value="sparepart">
                                    <label class="form-label">Tambah Sparepart:</label>
                                    <input type="text" name="description" class="form-control mb-2" placeholder="Nama Sparepart" required>
                                    <input type="number" name="price" class="form-control mb-2" placeholder="Harga Jual (Rp)" required>
                                    <input type="text" name="part_serial_number" class="form-control mb-2" placeholder="SN Sparepart (Opsional)">
                                    <select name="warranty_duration" class="form-select mb-2">
                                        <option value="0">Tanpa Garansi</option>
                                        <option value="3">Garansi 3 Bulan</option>
                                        <option value="6">Garansi 6 Bulan</option>
                                    </select>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="1" name="is_warranty_claim" id="claimManualSparepart">
                                        <label class="form-check-label small" for="claimManualSparepart">
                                            Tandai sebagai Warranty Claim (biaya otomatis Rp 0)
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-warning w-100">Input Sparepart</button>
                                    <small class="text-muted d-block mt-1">*Jika status 'Waiting Sparepart', otomatis berubah jadi 'On Progress'</small>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- 4. Panel Penyelesaian -->
                    <?php if ($service['status'] == 'finished' && in_array($role, ['admin', 'superadmin', 'kepala_admin', 'kepala_cabang', 'cs'])): ?>
                        <?php if ($is_under_warranty): ?>
                            <!-- Panel Penyelesaian Garansi -->
                            <div class="card mb-3 border-0 shadow-sm">
                                <div class="card-header bg-primary text-white fw-bold">Penyelesaian Garansi</div>
                                <div class="card-body text-center">
                                    <p>Unit dalam masa garansi telah selesai diperbaiki.</p>
                                    <form method="POST" onsubmit="return confirm('Selesaikan tiket dan serahkan unit ke pelanggan?');">
                                        <input type="hidden" name="action" value="close_warranty_ticket">
                                        <button type="submit" class="btn btn-primary w-100 btn-lg">Selesaikan & Serahkan Unit</button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Panel Pembayaran (Kasir) -->
                            <div class="card mb-3 border-0 shadow-sm">
                                <div class="card-header bg-success text-white fw-bold">Pembayaran & Pengambilan</div>
                                <div class="card-body">
                                    <h3 class="text-center text-success">Rp <?= number_format($total_cost, 0, ',', '.') ?></h3>
                                    <form method="POST" onsubmit="return confirm('Proses pembayaran dan selesaikan tiket?');">
                                        <input type="hidden" name="action" value="process_payment">
                                        <input type="hidden" name="total_amount" value="<?= $total_cost ?>">
                                        
                                        <label class="form-label">Metode Pembayaran:</label>
                                        <select name="payment_method" class="form-select mb-3">
                                            <option value="cash">Tunai (Cash)</option>
                                            <option value="transfer">Transfer Bank</option>
                                            <option value="debit">Debit Card</option>
                                        </select>
                                        <button type="submit" class="btn btn-success w-100 btn-lg">Bayar & Ambil</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($service['status'] == 'taken'): ?>
                        <div class="card mb-3 border-0 shadow-sm">
                            <div class="card-header bg-dark text-white fw-bold">Tiket Ditutup</div>
                            <div class="card-body text-center">
                                <p>Unit sudah diambil oleh pelanggan.</p>
                                <?php
                                    $invoice_url = "invoice.php?id=" . urlencode($service['id']);
                                    if ($branch_override) {
                                        $invoice_url .= "&branch=" . urlencode($branch_override);
                                    }
                                ?>
                                <a href="<?= $invoice_url ?>" target="_blank" class="btn btn-outline-primary">
                                    <i class="fas fa-print me-2"></i> Cetak Ulang Invoice
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>











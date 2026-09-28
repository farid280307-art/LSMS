<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

// Koneksi ke Database Pusat (Selalu)
$pdo = getDbConnection('pusat');
$role = $_SESSION['user']['role'];
$search = $_GET['search'] ?? '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Daur Ulang Stok Rusak
        if ($_POST['action'] === 'recycle') {
            $broken_id = $_POST['id'];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM broken_buffers_pusat WHERE id = ? AND status = 'at_center'");
                $stmt->execute([$broken_id]);
                $item = $stmt->fetch();
                if ($item) {
                    // Cek buffer
                    $stmt_check = $pdo->prepare("SELECT id, quantity FROM stock_buffer_pusat WHERE item_code = ?");
                    $stmt_check->execute([$item['part_code']]);
                    $buffer_item = $stmt_check->fetch();
                    if ($buffer_item) {
                        $stmt_upd = $pdo->prepare("UPDATE stock_buffer_pusat SET quantity = quantity + ? WHERE id = ?");
                        $stmt_upd->execute([$item['quantity'], $buffer_item['id']]);
                    } else {
                        $stmt_ins = $pdo->prepare("INSERT INTO stock_buffer_pusat (item_code, item_name, item_type, quantity, sell_price) VALUES (?, ?, 'sparepart', ?, 0)");
                        $stmt_ins->execute([$item['part_code'], $item['part_name'], $item['quantity']]);
                    }
                    $pdo->prepare("UPDATE broken_buffers_pusat SET status = 'recycled' WHERE id = ?")->execute([$broken_id]);
                }
                $pdo->commit();
                header("Location: index.php?msg=recycled&tab=broken");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Gagal daur ulang: " . $e->getMessage();
            }
        }
        // Approve Request
        elseif ($_POST['action'] === 'approve_request') {
            $req_id = $_POST['id'];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND status = 'pending'");
                $stmt->execute([$req_id]);
                $req = $stmt->fetch();

                if (!$req) throw new Exception("Request tidak valid.");

                // Cek Stok Pusat
                $stmt_stock = $pdo->prepare("SELECT quantity, sell_price, item_type FROM stock_buffer_pusat WHERE item_code = ?");
                $stmt_stock->execute([$req['item_code']]);
                $buffer = $stmt_stock->fetch();

                if (!$buffer || $buffer['quantity'] < $req['quantity']) {
                    throw new Exception("Stok pusat tidak mencukupi.");
                }

                // --- LOGIKA SN DEVICE (APPROVE REQUEST) ---
                if ($buffer['item_type'] === 'device') {
                    $sns = $_POST['serial_numbers'] ?? [];
                    $sns = array_filter($sns);
                    
                    if (count($sns) != $req['quantity']) {
                        throw new Exception("Jumlah Serial Number (" . count($sns) . ") tidak sesuai dengan jumlah request (" . $req['quantity'] . ").");
                    }

                    $pdo_pusat = getDbConnection('pusat');
                    $target_branch_code = $req['branch_code'];
                    $pdo_branch = getDbConnection($target_branch_code);

                    $stmt_check_loc = $pdo_pusat->prepare("SELECT current_branch FROM master_units WHERE serial_number = ?");
                    $stmt_mu = $pdo_pusat->prepare("UPDATE master_units SET current_branch = ? WHERE serial_number = ?");
                    
                    $check_active_sql = "SELECT s.service_code, s.status FROM services s JOIN devices d ON s.device_id = d.id WHERE d.serial_number = ? AND s.status NOT IN ('taken', 'cancelled') LIMIT 1";
                    $stmt_check_active = $pdo_branch->prepare($check_active_sql);

                    foreach ($sns as $sn) {
                        // 1. Validasi Lokasi Saat Ini (Harus di Pusat)
                        $stmt_check_loc->execute([$sn]);
                        $current_loc = $stmt_check_loc->fetchColumn();

                        if ($current_loc === false) throw new Exception("SN $sn tidak ditemukan di Master Data.");
                        if ($current_loc !== 'Pusat' && !empty($current_loc)) {
                            throw new Exception("Gagal Transfer: Unit dengan SN $sn tercatat berada di '$current_loc'. Harap retur ke Pusat terlebih dahulu.");
                        }

                        // 2. Validasi Service Aktif di Cabang Tujuan
                        $stmt_check_active->execute([$sn]);
                        $existing_active = $stmt_check_active->fetch(PDO::FETCH_ASSOC);
                        if ($existing_active) {
                            throw new Exception("SN $sn sedang dalam status " . strtoupper(str_replace('_', ' ', $existing_active['status'])) . " di cabang tujuan (Tiket: " . $existing_active['service_code'] . ").");
                        }

                        // 3. Update Lokasi
                        $stmt_mu->execute([$target_branch_code, $sn]);
                    }
                }
                // --- END LOGIKA SN ---

                // Kurangi Stok Pusat
                $pdo->prepare("UPDATE stock_buffer_pusat SET quantity = quantity - ? WHERE item_code = ?")->execute([$req['quantity'], $req['item_code']]);

                // Tambah Stok Cabang
                $pdo_branch = getDbConnection($req['branch_code']);
                if ($pdo_branch) {
                    $stmt_check = $pdo_branch->prepare("SELECT id, stock FROM stock_buffer_cabang WHERE part_code = ?");
                    $stmt_check->execute([$req['item_code']]);
                    $existing = $stmt_check->fetch();

                    if ($existing) {
                        $new_stock = $existing['stock'] + $req['quantity'];
                        $pdo_branch->prepare("UPDATE stock_buffer_cabang SET stock = ?, sell_price = ?, type = ? WHERE id = ?")->execute([$new_stock, $buffer['sell_price'], $buffer['item_type'], $existing['id']]);
                        $target_id = $existing['id'];
                    } else {
                        $pdo_branch->prepare("INSERT INTO stock_buffer_cabang (part_code, part_name, type, stock, sell_price, location) VALUES (?, ?, ?, ?, ?, 'Transfer Request')")->execute([$req['item_code'], $req['item_name'], $buffer['item_type'], $req['quantity'], $buffer['sell_price']]);
                        $target_id = $pdo_branch->lastInsertId();
                        $new_stock = $req['quantity'];
                    }

                    // LOG HISTORY: Stok Masuk (Request Approved)
                    $pdo_branch->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, 'in', ?, ?, ?, NOW())")
                        ->execute([$target_id, $_SESSION['user']['id'], $req['quantity'], $new_stock, "Request Disetujui (Ref: " . $req['id'] . ")"]);
                }

                // Update Status Request & Log Transfer
                $pdo->prepare("UPDATE stock_requests SET status = 'approved', processed_date = NOW() WHERE id = ?")->execute([$req_id]);
                
                // Ambil ID Cabang untuk log transfer
                $branch_id = $pdo->query("SELECT id FROM branches WHERE branch_code = '{$req['branch_code']}'")->fetchColumn();
                $pdo->prepare("INSERT INTO transfer_stock (item_code, quantity, to_branch_id, transfer_date, status, notes, created_by_user_id) VALUES (?, ?, ?, CURDATE(), 'completed', ?, ?)")
                    ->execute([$req['item_code'], $req['quantity'], $branch_id, 'Approved Request #' . $req_id, $_SESSION['user']['id']]);

                $pdo->commit();
                header("Location: index.php?msg=approved&tab=requests");
                exit();
            } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
        }
        // Bulk Approve Requests
        elseif ($_POST['action'] === 'bulk_approve_requests') {
            $req_items = $_POST['req_items'] ?? []; // Array of [id, quantity, sn[]]
            $transfer_ids = [];
            $batch_note = "Approved Request Batch " . date('d/m/Y H:i:s'); // Tambahkan detik agar unik per klik
            
            $pdo->beginTransaction();
            try {
                foreach ($req_items as $req_data) {
                    $req_id = $req_data['id'];
                    // Fetch request details
                    $stmt = $pdo->prepare("SELECT r.*, s.item_type, s.sell_price, s.item_name, s.quantity as central_stock 
                                           FROM stock_requests r 
                                           JOIN stock_buffer_pusat s ON r.item_code = s.item_code 
                                           WHERE r.id = ? AND r.status = 'pending'");
                    $stmt->execute([$req_id]);
                    $req = $stmt->fetch();

                    if (!$req) continue;

                    if ($req['central_stock'] < $req['quantity']) {
                        throw new Exception("Stok pusat tidak cukup untuk " . $req['item_name']);
                    }

                    // Device SN Logic
                    if ($req['item_type'] === 'device') {
                        $sns = $req_data['sn'] ?? [];
                        $sns = array_filter($sns);
                        if (count($sns) != $req['quantity']) {
                            throw new Exception("Jumlah SN tidak sesuai untuk " . $req['item_name']);
                        }
                        
                        $target_branch_code = $req['branch_code'];
                        $pdo_branch = getDbConnection($target_branch_code);
                        
                        foreach ($sns as $sn) {
                             // Validasi & Update Lokasi SN (Simplified for bulk)
                             $pdo->prepare("UPDATE master_units SET current_branch = ? WHERE serial_number = ?")->execute([$target_branch_code, $sn]);
                        }
                    }

                    // Update Central Stock
                    $pdo->prepare("UPDATE stock_buffer_pusat SET quantity = quantity - ? WHERE item_code = ?")->execute([$req['quantity'], $req['item_code']]);

                    // Update Branch Stock
                    $pdo_branch = getDbConnection($req['branch_code']);
                    if ($pdo_branch) {
                        $stmt_check = $pdo_branch->prepare("SELECT id, stock FROM stock_buffer_cabang WHERE part_code = ?");
                        $stmt_check->execute([$req['item_code']]);
                        $existing = $stmt_check->fetch();
                        if ($existing) {
                            $new_stock = $existing['stock'] + $req['quantity'];
                            $pdo_branch->prepare("UPDATE stock_buffer_cabang SET stock = ? WHERE id = ?")->execute([$new_stock, $existing['id']]);
                            $target_id = $existing['id'];
                        } else {
                            $pdo_branch->prepare("INSERT INTO stock_buffer_cabang (part_code, part_name, type, stock, sell_price, location) VALUES (?, ?, ?, ?, ?, 'Transfer Request')")->execute([$req['item_code'], $req['item_name'], $req['item_type'], $req['quantity'], $req['sell_price']]);
                            $target_id = $pdo_branch->lastInsertId();
                            $new_stock = $req['quantity'];
                        }
                        $pdo_branch->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, 'in', ?, ?, ?, NOW())")->execute([$target_id, $_SESSION['user']['id'], $req['quantity'], $new_stock, "Request Disetujui (Ref: " . $req['id'] . ")"]);
                    }

                    // Update Request & Log Transfer
                    $pdo->prepare("UPDATE stock_requests SET status = 'approved', processed_date = NOW() WHERE id = ?")->execute([$req_id]);
                    $branch_id = $pdo->query("SELECT id FROM branches WHERE branch_code = '{$req['branch_code']}'")->fetchColumn();
                    $pdo->prepare("INSERT INTO transfer_stock (item_code, quantity, to_branch_id, transfer_date, status, notes, created_by_user_id) VALUES (?, ?, ?, CURDATE(), 'completed', ?, ?)")->execute([$req['item_code'], $req['quantity'], $branch_id, $batch_note, $_SESSION['user']['id']]);
                    $transfer_ids[] = $pdo->lastInsertId();
                }
                $pdo->commit();
                header("Location: index.php?msg=approved&print_ids=" . implode(',', $transfer_ids));
                exit();
            } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
        }
        // Reject Request
        elseif ($_POST['action'] === 'reject_request') {
            $pdo->prepare("UPDATE stock_requests SET status = 'rejected', processed_date = NOW() WHERE id = ?")->execute([$_POST['id']]);
            header("Location: index.php?msg=rejected&tab=requests");
            exit();
        }
        // Bulk Reject Request (New)
        elseif ($_POST['action'] === 'bulk_reject_request') {
            $ids = explode(',', $_POST['ids']);
            foreach ($ids as $id) {
                $pdo->prepare("UPDATE stock_requests SET status = 'rejected', processed_date = NOW() WHERE id = ?")->execute([$id]);
            }
            header("Location: index.php?msg=rejected&tab=requests");
            exit();
        }
    }
}

// Query Data Stok
$sql = "SELECT * FROM stock_buffer_pusat";
$params = [];
if (!empty($search)) {
    $sql .= " WHERE item_name LIKE ? OR item_code LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY item_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

// Ambil Data Cabang untuk Dropdown Transfer
$stmt = $pdo->query("SELECT * FROM branches");
$branches = $stmt->fetchAll();

// Query Data Stok Rusak (Pusat)
$sql_broken = "SELECT bb.*, b.branch_name 
               FROM broken_buffers_pusat bb
               LEFT JOIN branches b ON bb.received_from_branch_id = b.id
               ORDER BY bb.received_at DESC";
$stmt_broken = $pdo->query($sql_broken);
$broken_stocks = $stmt_broken->fetchAll();

// Query Data Permintaan Stok (Pending di atas)
$sql_req = "SELECT r.*, b.branch_name, s.item_type 
            FROM stock_requests r 
            LEFT JOIN branches b ON r.branch_code = b.branch_code 
            LEFT JOIN stock_buffer_pusat s ON r.item_code = s.item_code
            ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.request_date DESC";
$stmt_req = $pdo->query($sql_req);
$requests = $stmt_req->fetchAll();

// Group Requests by Batch (Branch + Date)
$grouped_requests = [];
$seen_req_ids = []; // Array untuk mencegah duplikasi request akibat JOIN

foreach ($requests as $r) {
    if (isset($seen_req_ids[$r['id']])) continue; // Skip jika ID sudah ada
    $seen_req_ids[$r['id']] = true;

    $key = $r['branch_code'] . '|' . $r['request_date'] . '|' . $r['status'];
    if (!isset($grouped_requests[$key])) {
        $grouped_requests[$key] = [
            'branch_name' => $r['branch_name'] ?? $r['branch_code'],
            'branch_code' => $r['branch_code'],
            'request_date' => $r['request_date'],
            'status' => $r['status'],
            'processed_date' => $r['processed_date'],
            'notes' => $r['notes'],
            'items' => []
        ];
    }
    $grouped_requests[$key]['items'][] = $r;
}
$grouped_requests = array_values($grouped_requests);

// Hitung jumlah request pending untuk badge notifikasi
$pending_count = 0;
foreach ($requests as $r) {
    if ($r['status'] == 'pending') $pending_count++;
}

// Query Riwayat Transfer
$sql_transfers = "SELECT t.*, b.branch_name, s.item_name, u.full_name as sender_name 
                  FROM transfer_stock t 
                  LEFT JOIN branches b ON t.to_branch_id = b.id 
                  LEFT JOIN stock_buffer_pusat s ON t.item_code = s.item_code
                  LEFT JOIN users u ON t.created_by_user_id = u.id
                  ORDER BY t.transfer_date DESC, t.id DESC";
$transfer_history = $pdo->query($sql_transfers)->fetchAll();

// Group Transfers by Date + Branch (Surat Jalan Logic)
$grouped_transfers = [];
$seen_transfer_ids = []; // Array untuk mencegah duplikasi

foreach ($transfer_history as $t) {
    if (isset($seen_transfer_ids[$t['id']])) continue; // Skip jika ID sudah ada
    $seen_transfer_ids[$t['id']] = true;

    $key = $t['transfer_date'] . '|' . $t['to_branch_id'] . '|' . ($t['notes'] ?? '') . '|' . ($t['created_by_user_id'] ?? '');
    if (!isset($grouped_transfers[$key])) {
        $grouped_transfers[$key] = [
            'transfer_date' => $t['transfer_date'],
            'branch_name' => $t['branch_name'],
            'sender_name' => $t['sender_name'],
            'notes' => $t['notes'],
            'ids' => [],
            'items' => []
        ];
    }
    $grouped_transfers[$key]['ids'][] = $t['id'];
    $grouped_transfers[$key]['items'][] = $t;
}
$grouped_transfers = array_values($grouped_transfers);

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
            <h2 class="fw-bold text-dark mb-4">Stok Pusat (Buffer)</h2>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['msg'])): ?>
                <?php
                    $msg = $_GET['msg'];
                    $is_error_msg = in_array($msg, ['save_failed', 'delete_failed', 'restock_failed', 'transfer_failed'], true);
                ?>
                <div class="alert alert-<?= $is_error_msg ? 'danger' : 'success' ?>">
                    <?php
                    if ($msg == 'transferred') {
                        echo 'Stok berhasil ditransfer.';
                    } elseif ($msg == 'saved') {
                        echo 'Data berhasil disimpan.';
                    } elseif ($msg == 'deleted') {
                        echo 'Data berhasil dihapus.';
                    } elseif ($msg == 'recycled') {
                        echo 'Item berhasil didaur ulang.';
                    } elseif ($msg == 'approved') {
                        echo 'Permintaan disetujui & stok ditransfer.';
                    } elseif ($msg == 'rejected') {
                        echo 'Permintaan ditolak.';
                    } elseif ($msg == 'stock_added') {
                        echo 'Stok berhasil ditambahkan.';
                    } elseif (in_array($msg, ['save_failed', 'delete_failed', 'restock_failed', 'transfer_failed'], true)) {
                        echo htmlspecialchars($_GET['err_msg'] ?? 'Terjadi kesalahan saat memproses data.');
                    }
                    ?>
                </div>
            <?php endif; ?>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="main-tab" data-bs-toggle="tab" data-bs-target="#main" type="button" role="tab">Stok Buffer</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="broken-tab" data-bs-toggle="tab" data-bs-target="#broken" type="button" role="tab">Stok Rusak (Retur)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="requests-tab" data-bs-toggle="tab" data-bs-target="#requests" type="button" role="tab">
                        Permintaan Stok
                        <?php if ($pending_count > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-1"><?= $pending_count ?></span>
                        <?php endif; ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="transfers-tab" data-bs-toggle="tab" data-bs-target="#transfers" type="button" role="tab">Riwayat Transfer</button>
                </li>
            </ul>

            <div class="tab-content" id="stockTabsContent">
                <!-- Tab Stok Utama -->
                <div class="tab-pane fade show active" id="main" role="tabpanel">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <?php if (!in_array($role, ['kepala_admin', 'kepala_cabang'])): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#stockModal" onclick="resetForm()">
                                        <i class="fas fa-plus me-2"></i> Tambah Item Baru
                                    </button>
                                    <button type="button" class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#restockModal">
                                        <i class="fas fa-boxes me-2"></i> Tambah Stok
                                    </button>
                                    <button type="button" class="btn btn-warning ms-2 text-white" data-bs-toggle="modal" data-bs-target="#transferModal">
                                        <i class="fas fa-truck me-2"></i> Transfer Stok ke Cabang
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <form method="GET" class="d-flex">
                                        <input type="text" name="search" class="form-control me-2" placeholder="Cari nama atau kode item..." value="<?= htmlspecialchars($search) ?>">
                                        <button type="submit" class="btn btn-outline-primary">Cari</button>
                                    </form>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3">Kode Item</th>
                                            <th>Nama Item</th>
                                            <th>Tipe</th>
                                            <th>Stok</th>
                                            <th class="text-end pe-3">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($stocks)): ?>
                                            <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada data stok buffer.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($stocks as $s): ?>
                                            <tr>
                                                <td class="ps-3 fw-bold"><?= htmlspecialchars($s['item_code']) ?></td>
                                                <td><?= htmlspecialchars($s['item_name']) ?></td>
                                                <td><span class="badge bg-secondary"><?= ucfirst($s['item_type']) ?></span></td>
                                                <td class="fw-bold <?= $s['quantity'] < 5 ? 'text-danger' : 'text-success' ?>"><?= number_format($s['quantity']) ?></td>
                                                <td class="text-end pe-3">
                                                    <?php if (!in_array($role, ['kepala_admin', 'kepala_cabang'])): ?>
                                                    <button class="btn btn-sm btn-info text-white me-1" onclick='editStock(<?= json_encode($s) ?>)'>
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus item ini?');">
                                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Stok Rusak -->
                <div class="tab-pane fade" id="broken" role="tabpanel">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3">Tanggal Terima</th>
                                            <th>Asal Cabang</th>
                                            <th>Kode Part</th>
                                            <th>Nama Part</th>
                                            <th>Jumlah</th>
                                            <th>Catatan</th>
                                            <th>Status</th>
                                            <th class="text-end pe-3">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($broken_stocks)): ?>
                                            <tr><td colspan="8" class="text-center py-4 text-muted">Belum ada data stok rusak.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($broken_stocks as $s): ?>
                                            <tr>
                                                <td class="ps-3 small"><?= date('d/M/Y', strtotime($s['received_at'])) ?></td>
                                                <td><?= htmlspecialchars($s['branch_name']) ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($s['part_code']) ?></td>
                                                <td><?= htmlspecialchars($s['part_name']) ?></td>
                                                <td class="fw-bold text-danger"><?= number_format($s['quantity']) ?></td>
                                                <td class="small"><?= htmlspecialchars($s['notes']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $s['status'] == 'recycled' ? 'success' : 'warning' ?>"><?= $s['status'] == 'recycled' ? 'Didaur Ulang' : 'Di Pusat' ?></span>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <?php if ($s['status'] == 'at_center' && !in_array($role, ['kepala_admin', 'kepala_cabang'])): ?>
                                                    <form method="POST" onsubmit="return confirm('Daur ulang item ini?');">
                                                        <input type="hidden" name="action" value="recycle">
                                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-recycle"></i> Daur Ulang</button>
                                                    </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Permintaan Stok -->
                <div class="tab-pane fade" id="requests" role="tabpanel">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3">Tanggal</th>
                                            <th>Cabang</th>
                                            <th>Item Request</th>
                                            <th>Catatan</th>
                                            <th>Status</th>
                                            <th class="text-end pe-3">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($grouped_requests)): ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">Belum ada permintaan stok.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($grouped_requests as $grp): ?>
                                            <tr>
                                                <td class="ps-3 small"><?= date('d/M/Y H:i', strtotime($grp['request_date'])) ?></td>
                                                <td><?= htmlspecialchars($grp['branch_name']) ?></td>
                                                <td>
                                                    <ul class="list-unstyled mb-0 small">
                                                    <?php 
                                                    // Agregasi Item untuk Tampilan (Gabungkan item sama dalam satu batch)
                                                    $display_items = [];
                                                    foreach ($grp['items'] as $item) {
                                                        $key = $item['item_name'] ?? $item['item_code'];
                                                        if (!isset($display_items[$key])) $display_items[$key] = 0;
                                                        $display_items[$key] += $item['quantity'];
                                                    }
                                                    foreach ($display_items as $name => $qty): ?>
                                                        <li>
                                                            <strong><?= htmlspecialchars($name) ?></strong> <span class="text-muted">(<?= $qty ?> unit)</span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                    </ul>
                                                </td>
                                                <td class="small"><?= htmlspecialchars($grp['notes']) ?></td>
                                                <td>
                                                    <?php if ($grp['status'] == 'pending'): ?>
                                                        <span class="badge bg-warning text-dark">Menunggu</span>
                                                    <?php elseif ($grp['status'] == 'approved'): ?>
                                                        <span class="badge bg-success">Disetujui</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Ditolak</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <?php if ($grp['status'] == 'pending' && !in_array($role, ['kepala_admin', 'kepala_cabang'])): ?>
                                                    <?php 
                                                        // Prepare data for bulk approve
                                                        $approveData = [];
                                                        foreach($grp['items'] as $itm) {
                                                            $approveData[] = [
                                                                'id' => $itm['id'],
                                                                'item_name' => $itm['item_name'],
                                                                'quantity' => $itm['quantity'],
                                                                'item_type' => $itm['item_type'],
                                                                'branch_code' => $itm['branch_code']
                                                            ];
                                                        }
                                                    ?>
                                                    <button type="button" class="btn btn-sm btn-success" title="Setujui Semua" onclick='openBulkApprove(<?= json_encode($approveData) ?>)'>
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" title="Tolak Semua" onclick="rejectBatch(<?= htmlspecialchars(json_encode(array_column($grp['items'], 'id'))) ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Riwayat Transfer -->
                <div class="tab-pane fade" id="transfers" role="tabpanel">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3">Tanggal</th>
                                            <th>Tujuan</th>
                                            <th>Item Transfer</th>
                                            <th>Pengirim</th>
                                            <th class="text-end pe-3">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($grouped_transfers)): ?>
                                            <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat transfer.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($grouped_transfers as $grp): ?>
                                            <tr>
                                                <td class="ps-3 small"><?= date('d/M/Y', strtotime($grp['transfer_date'])) ?></td>
                                                <td><?= htmlspecialchars($grp['branch_name']) ?></td>
                                                <td>
                                                    <ul class="list-unstyled mb-0 small">
                                                    <?php 
                                                    // Agregasi Item Transfer
                                                    $display_transfers = [];
                                                    foreach ($grp['items'] as $item) {
                                                        $key = $item['item_name'] ?? $item['item_code'];
                                                        if (!isset($display_transfers[$key])) $display_transfers[$key] = 0;
                                                        $display_transfers[$key] += $item['quantity'];
                                                    }
                                                    foreach ($display_transfers as $name => $qty): ?>
                                                        <li><?= htmlspecialchars($name) ?> <span class="text-danger fw-bold">(<?= $qty ?>)</span></li>
                                                    <?php endforeach; ?>
                                                    </ul>
                                                </td>
                                                <td class="small"><?= htmlspecialchars($grp['sender_name']) ?></td>
                                                <td class="text-end pe-3">
                                                    <a href="print_transfer.php?ids=<?= implode(',', $grp['ids']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak Surat Jalan">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah/Edit -->
<div class="modal fade" id="stockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Tambah Item Buffer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="add.php" id="stockForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="stockId">
                    <div class="mb-3">
                        <label class="form-label">Kode Item</label>
                        <input type="text" name="item_code" id="itemCode" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Item</label>
                        <input type="text" name="item_name" id="itemName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipe</label>
                        <select name="item_type" id="itemType" class="form-select" onchange="toggleDeviceFields()">
                            <option value="sparepart">Sparepart</option>
                            <option value="device">Device / Unit</option>
                        </select>
                    </div>
                    
                    <!-- Field Khusus Device -->
                    <div id="deviceFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Serial Number (SN)</label>
                            <input type="text" name="serial_number" id="itemSN" class="form-control" placeholder="Masukkan SN untuk data garansi">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Garansi Berakhir</label>
                            <input type="date" name="warranty_end" id="itemWarranty" class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Jumlah Stok</label>
                        <input type="number" name="quantity" id="itemQty" class="form-control" required min="0">
                    </div>
                    <div class="mb-3">
                            <label class="form-label">Harga Jual</label>
                            <input type="number" name="sell_price" id="sellPrice" class="form-control" required min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Restock -->
<div class="modal fade" id="restockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="restock.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Pilih Item</label>
                        <select name="id" id="restockItemId" class="form-select" required onchange="updateRestockInfo()">
                            <option value="">-- Pilih Item --</option>
                            <?php foreach ($stocks as $s): ?>
                                <option value="<?= $s['id'] ?>" data-stock="<?= $s['quantity'] ?>" data-type="<?= $s['item_type'] ?>">
                                    <?= htmlspecialchars($s['item_name']) ?> (<?= htmlspecialchars($s['item_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Field SN untuk Device -->
                    <div id="restockDeviceFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Serial Number (SN)</label>
                            <input type="text" name="serial_number" id="restockSN" class="form-control" placeholder="Masukkan SN Unit">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Jumlah Penambahan</label>
                        <input type="number" name="quantity_add" id="restockQty" class="form-control" required min="1">
                        <small class="text-muted">Stok saat ini: <span id="restockCurrentStock">-</span></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Tambah Stok</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Transfer (Bulk) -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Transfer Stok ke Cabang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="transfer.php">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tujuan Cabang</label>
                            <select name="to_branch_id" class="form-select" required>
                                <option value="">-- Pilih Cabang --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= $b['branch_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end justify-content-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addTransferRow()">
                                <i class="fas fa-plus me-1"></i> Tambah Barang
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle">
                            <thead class="bg-light small">
                                <tr>
                                    <th style="width: 45%">Nama Barang</th>
                                    <th style="width: 15%">Tipe</th>
                                    <th style="width: 15%">Qty</th>
                                    <th style="width: 20%">SN (Jika Device)</th>
                                    <th style="width: 5%"></th>
                                </tr>
                            </thead>
                            <tbody id="transferItemsContainer">
                                <!-- Rows added via JS -->
                            </tbody>
                        </table>
                        <div id="emptyTransferMsg" class="text-center text-muted py-3 small">
                            Belum ada barang yang ditambahkan. Klik "Tambah Barang".
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Catatan Transfer</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Opsional..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Kirim Stok</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Bulk Approve -->
<div class="modal fade" id="bulkApproveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Proses Permintaan Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_approve_requests">
                    <div id="bulkApproveContainer"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Setujui & Kirim</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const allStocks = <?= json_encode($stocks) ?>;

function toggleDeviceFields() {
    const type = document.getElementById('itemType').value;
    const fields = document.getElementById('deviceFields');
    const qtyInput = document.getElementById('itemQty');
    const isNew = document.getElementById('stockId').value === '';

    if (type === 'device') {
        fields.style.display = 'block';
        if (isNew) {
            qtyInput.value = 1;
            qtyInput.readOnly = true;
        } else {
            qtyInput.readOnly = false;
        }
    } else {
        fields.style.display = 'none';
        qtyInput.readOnly = false;
    }
}

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Tambah Item Buffer';
    document.getElementById('stockForm').action = 'add.php';
    document.getElementById('stockId').value = '';
    document.getElementById('itemCode').value = '';
    document.getElementById('itemName').value = '';
    document.getElementById('itemQty').value = '';
    document.getElementById('sellPrice').value = '';
    document.getElementById('itemType').value = 'sparepart';
    document.getElementById('itemSN').value = '';
    document.getElementById('itemWarranty').value = '';
    toggleDeviceFields();
}

function editStock(data) {
    document.getElementById('modalTitle').innerText = 'Edit Item Buffer';
    document.getElementById('stockForm').action = 'edit.php';
    document.getElementById('stockId').value = data.id;
    document.getElementById('itemCode').value = data.item_code;
    document.getElementById('itemName').value = data.item_name;
    document.getElementById('itemType').value = data.item_type;
    document.getElementById('itemQty').value = data.quantity;
    document.getElementById('sellPrice').value = data.sell_price;
    
    toggleDeviceFields();
    new bootstrap.Modal(document.getElementById('stockModal')).show();
}

function updateRestockInfo() {
    const select = document.getElementById('restockItemId');
    const selectedOption = select.options[select.selectedIndex];
    const currentStock = selectedOption.getAttribute('data-stock');
    const itemType = selectedOption.getAttribute('data-type');
    
    document.getElementById('restockCurrentStock').innerText = currentStock ? currentStock : '-';
    
    const qtyInput = document.getElementById('restockQty');
    const deviceFields = document.getElementById('restockDeviceFields');
    const snInput = document.getElementById('restockSN');

    if (itemType === 'device') {
        qtyInput.value = 1;
        qtyInput.readOnly = true;
        deviceFields.style.display = 'block';
        snInput.required = true;
    } else {
        qtyInput.readOnly = false;
        deviceFields.style.display = 'none';
        snInput.required = false;
        snInput.value = '';
    }
}

function addTransferRow() {
    document.getElementById('emptyTransferMsg').style.display = 'none';
    const container = document.getElementById('transferItemsContainer');
    const rowId = Date.now();
    
    let optionsHtml = '<option value="">-- Pilih Barang --</option>';
    allStocks.forEach(item => {
        if(item.quantity > 0) {
            optionsHtml += `<option value="${item.id}" data-type="${item.item_type}" data-stock="${item.quantity}">${item.item_name} (${item.item_code})</option>`;
        }
    });

    const html = `
        <tr id="trf-row-${rowId}">
            <td>
                <select name="items[${rowId}][item_id]" class="form-select form-select-sm" required onchange="updateTransferRow(${rowId}, this)">
                    ${optionsHtml}
                </select>
                <div class="text-muted" style="font-size: 0.75rem;">Stok Tersedia: <span id="trf-stock-${rowId}">-</span></div>
            </td>
            <td><span id="trf-type-${rowId}" class="badge bg-secondary">-</span></td>
            <td>
                <input type="number" name="items[${rowId}][quantity]" id="trf-qty-${rowId}" class="form-control form-control-sm text-center" value="1" min="1" required>
            </td>
            <td>
                <input type="text" name="items[${rowId}][serial_number]" id="trf-sn-${rowId}" class="form-control form-control-sm" placeholder="SN Unit" style="display:none;">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-link text-danger p-0" onclick="document.getElementById('trf-row-${rowId}').remove()"><i class="fas fa-times"></i></button>
            </td>
        </tr>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function updateTransferRow(rowId, selectEl) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const type = selectedOption.getAttribute('data-type');
    const stock = selectedOption.getAttribute('data-stock');
    
    document.getElementById(`trf-type-${rowId}`).innerText = type ? type.toUpperCase() : '-';
    document.getElementById(`trf-stock-${rowId}`).innerText = stock || '-';
    
    const qtyInput = document.getElementById(`trf-qty-${rowId}`);
    const snInput = document.getElementById(`trf-sn-${rowId}`);

    if (type === 'device') {
        qtyInput.value = 1;
        qtyInput.readOnly = true; // Device harus 1 per baris untuk input SN
        snInput.style.display = 'block';
        snInput.required = true;
    } else {
        qtyInput.readOnly = false;
        snInput.style.display = 'none';
        snInput.required = false;
    }
}

function rejectBatch(ids) {
    if (confirm('Tolak semua permintaan dalam batch ini?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="bulk_reject_request"><input type="hidden" name="ids" value="${ids.join(',')}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function bulkApprove() {
    const checkboxes = document.querySelectorAll('.req-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Pilih minimal satu permintaan.');
        return;
    }
    const items = Array.from(checkboxes).map(cb => ({
        id: cb.value,
        item_name: cb.getAttribute('data-item'),
        quantity: cb.getAttribute('data-qty'),
        item_type: cb.getAttribute('data-type'),
        branch_code: cb.getAttribute('data-branch')
    }));
    openBulkApprove(items);
}

function openBulkApprove(items) {
    // Validasi cabang sama
    const firstBranch = items[0].branch_code;
    if (items.some(i => i.branch_code !== firstBranch)) {
        alert("Harap pilih request dari cabang yang sama untuk diproses bersamaan.");
        return;
    }

    const container = document.getElementById('bulkApproveContainer');
    container.innerHTML = '';
    
    items.forEach((item, index) => {
        let html = `
            <div class="mb-3 border-bottom pb-2">
                <input type="hidden" name="req_items[${index}][id]" value="${item.id}">
                <div class="fw-bold">${item.item_name} <span class="badge bg-secondary ms-2">${item.quantity} Unit</span></div>
        `;
        
        if (item.item_type === 'device') {
            html += `<div class="mt-2 ps-3 border-start border-3 border-primary">
                     <label class="small text-muted">Masukkan SN untuk ${item.quantity} unit:</label>`;
            for(let i=0; i<item.quantity; i++) {
                html += `<input type="text" name="req_items[${index}][sn][]" class="form-control form-control-sm mb-1" placeholder="SN Unit #${i+1}" required>`;
            }
            html += `</div>`;
        }
        html += `</div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
    
    new bootstrap.Modal(document.getElementById('bulkApproveModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    // Deteksi parameter 'tab' di URL untuk mengaktifkan tab yang sesuai
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab');
    if (activeTab) {
        const tabTrigger = document.querySelector('#' + activeTab + '-tab');
        if (tabTrigger) {
            const tab = new bootstrap.Tab(tabTrigger);
            tab.show();
        }
    }

    // Auto Open Surat Jalan di Tab Baru
    const printIds = urlParams.get('print_ids');
    if (printIds) {
        window.open('print_transfer.php?ids=' + printIds, '_blank');
    }
});
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'gudang', 'teknisi'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();
$role = $_SESSION['user']['role'];
$search = $_GET['search'] ?? '';

// Ambil Data Cabang untuk Dropdown (Khusus Superadmin)
$branches = [];
if ($role === 'superadmin') {
    $stmt = $pdo->query("SELECT branch_code, branch_name FROM branches");
    $branches = $stmt->fetchAll();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Jika Superadmin/Kepala Admin, ganti koneksi ke database cabang yang sesuai
        if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($_POST['branch_code'])) {
            $pdo = getDbConnection($_POST['branch_code']);
        }

        if ($_POST['action'] === 'save') {
            $code = $_POST['part_code'];
            $name = $_POST['part_name'];
            $stock = $_POST['stock'];
            $sell = $_POST['sell_price'];
            $loc = $_POST['location'];
            $id = $_POST['id'] ?? '';

            if (!empty($id)) {
                // Ambil stok lama untuk perbandingan
                $old_stock = $pdo->query("SELECT stock FROM stock_buffer_cabang WHERE id = $id")->fetchColumn();
                
                if (in_array($role, ['superadmin', 'kepala_admin'])) {
                    // Superadmin can edit everything
                    $stmt = $pdo->prepare("UPDATE stock_buffer_cabang SET part_code=?, part_name=?, stock=?, sell_price=?, location=? WHERE id=?");
                    $stmt->execute([$code, $name, $stock, $sell, $loc, $id]);
                } else {
                    // Branch users cannot edit sell_price
                    $stmt = $pdo->prepare("UPDATE stock_buffer_cabang SET part_code=?, part_name=?, stock=?, location=? WHERE id=?");
                    $stmt->execute([$code, $name, $stock, $loc, $id]);
                }

                // LOG HISTORY: Adjustment (Edit Manual)
                if ($stock != $old_stock) {
                    $diff = $stock - $old_stock;
                    $change_type = 'adjustment';
                    $pdo->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$id, $_SESSION['user']['id'], $change_type, abs($diff), $stock, "Edit Manual Stok"]);
                }
            }
            header("Location: index.php?msg=saved");
            exit();
        }
        // Laporkan Rusak
        elseif ($_POST['action'] === 'report_broken') {
            $id = $_POST['id'];
            $qty_broken = $_POST['quantity_broken'];
            $notes = $_POST['notes'];
            $sn = $_POST['serial_number'] ?? null;

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM stock_buffer_cabang WHERE id = ?");
                $stmt->execute([$id]);
                $item = $stmt->fetch();
                
                if (!$item) throw new Exception("Item tidak ditemukan.");

                // Validasi Device & SN
                if (isset($item['type']) && $item['type'] === 'device') {
                    $qty_broken = 1; // Device selalu 1 per 1
                    if (empty($sn)) throw new Exception("Serial Number wajib diisi untuk device.");

                    // Cek apakah SN benar ada di cabang ini (via Master Units)
                    $pdo_pusat = getDbConnection('pusat');
                    $stmt_mu = $pdo_pusat->prepare("SELECT current_branch FROM master_units WHERE serial_number = ?");
                    $stmt_mu->execute([$sn]);
                    $current_loc = $stmt_mu->fetchColumn();
                    
                    $my_branch = $_SESSION['user']['cabang'] ?? (in_array($role, ['superadmin', 'kepala_admin']) ? $_POST['branch_code'] : '');
                    if ($current_loc !== $my_branch) {
                        throw new Exception("SN $sn tidak tercatat berada di cabang ini (Posisi: $current_loc).");
                    }
                }

                if ($item['stock'] < $qty_broken) throw new Exception("Stok tidak cukup.");
                
                $new_stock = $item['stock'] - $qty_broken;
                $pdo->prepare("UPDATE stock_buffer_cabang SET stock = ? WHERE id = ?")->execute([$new_stock, $id]);
                
                $pdo->prepare("INSERT INTO broken_buffers (part_code, part_name, serial_number, quantity, notes, reported_by_user_id) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$item['part_code'], $item['part_name'], $sn, $qty_broken, $notes, $_SESSION['user']['id']]);
                
                // LOG HISTORY: Stok Keluar (Rusak)
                $log_note = "Laporan Rusak: $notes" . ($sn ? " (SN: $sn)" : "");
                $pdo->prepare("INSERT INTO stock_history (sparepart_id, user_id, change_type, quantity_change, quantity_after, notes, log_time) VALUES (?, ?, 'out', ?, ?, ?, NOW())")
                    ->execute([$id, $_SESSION['user']['id'], $qty_broken, $new_stock, $log_note]);

                $pdo->commit();
                header("Location: index.php?msg=broken_reported");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
        // Kirim ke Pusat
        elseif ($_POST['action'] === 'send_to_center') {
            $broken_id = $_POST['id'];
            $current_branch = $_POST['branch_code'] ?? $_SESSION['user']['cabang'] ?? '';
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM broken_buffers WHERE id = ? AND status = 'at_branch'");
                $stmt->execute([$broken_id]);
                $item = $stmt->fetch();
                if ($item) {
                    $pdo_pusat = getDbConnection('pusat');
                    $branch_code = $_POST['branch_code'] ?? $_SESSION['user']['cabang'];
                    $branch_id = $pdo_pusat->query("SELECT id FROM branches WHERE branch_code = '$branch_code'")->fetchColumn();
                    
                    $sn = $item['serial_number'] ?? null;
                    $pdo_pusat->prepare("INSERT INTO broken_buffers_pusat (part_code, part_name, serial_number, quantity, notes, received_from_branch_id) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$item['part_code'], $item['part_name'], $sn, $item['quantity'], $item['notes'], $branch_id]);
                    
                    $pdo->prepare("UPDATE broken_buffers SET status = 'sent_to_center' WHERE id = ?")->execute([$broken_id]);
                    
                    // Update Master Unit Location if SN exists
                    if ($sn) {
                        $pdo_pusat->prepare("UPDATE master_units SET current_branch = 'Pusat' WHERE serial_number = ?")->execute([$sn]);
                    }
                }
                $pdo->commit();
                
                $redirect_url = "index.php?msg=sent&print_id=$broken_id";
                if ($current_branch) $redirect_url .= "&branch=$current_branch";
                
                header("Location: $redirect_url");
                exit();
            } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
        }
        // Request Stok ke Pusat
        elseif ($_POST['action'] === 'request_stock') {
            $items = $_POST['items'] ?? [];
            $notes = $_POST['notes'] ?? '';
            
            $branch_code = $_SESSION['user']['cabang'] ?? '';
            if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($_POST['branch_code'])) {
                $branch_code = $_POST['branch_code'];
            }

            $pdo_pusat = getDbConnection('pusat');
            $pdo_pusat->beginTransaction();
            try {
                $request_date = date('Y-m-d H:i:s'); // Waktu yang sama untuk satu batch
                $stmt = $pdo_pusat->prepare("INSERT INTO stock_requests (branch_code, item_code, item_name, quantity, notes, request_date, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                foreach ($items as $item) {
                    $stmt->execute([$branch_code, $item['part_code'], $item['part_name'], $item['quantity'], $notes, $request_date]);
                }
                $pdo_pusat->commit();
            } catch (Exception $e) { $pdo_pusat->rollBack(); $error = "Gagal request: " . $e->getMessage(); }
            
            header("Location: index.php?msg=requested");
            exit();
        }
    }
}

// Query Data Stok
$params = [];
if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    global $db_configs;
    $union_parts = [];
    $branch_dbs = array_filter($db_configs, function($key) {
        return strpos($key, 'cabang_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($branch_dbs as $branch_code => $config) {
        $db_name = $config['dbname'];
        $union_parts[] = "SELECT *, '$branch_code' as branch_code FROM `$db_name`.stock_buffer_cabang";
        $union_broken[] = "SELECT *, '$branch_code' as branch_code FROM `$db_name`.broken_buffers";
    }

    if (!empty($union_parts)) {
        $union_sql = implode(' UNION ALL ', $union_parts);
        $union_broken_sql = implode(' UNION ALL ', $union_broken);
        $sql = "SELECT t.*, b.branch_name 
                FROM ({$union_sql}) as t
                LEFT JOIN `lsms_pusat`.branches b ON t.branch_code = b.branch_code";
        
        if (!empty($search)) {
            $sql .= " WHERE t.part_name LIKE ? OR t.part_code LIKE ?";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $sql .= " ORDER BY t.part_name ASC";

        $sql_broken = "SELECT t.*, b.branch_name FROM ({$union_broken_sql}) as t LEFT JOIN `lsms_pusat`.branches b ON t.branch_code = b.branch_code ORDER BY t.reported_at DESC";
    } else {
        $sql = "";
        $sql_broken = "";
    }
} else {
    $sql = "SELECT * FROM stock_buffer_cabang";
    if (!empty($search)) {
        $sql .= " WHERE part_name LIKE ? OR part_code LIKE ?";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY part_name ASC";
    $sql_broken = "SELECT * FROM broken_buffers ORDER BY reported_at DESC";
}

$stocks = [];
if (!empty($sql)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stocks = $stmt->fetchAll();
}

$broken_stocks = [];
if (!empty($sql_broken)) {
    $stmt = $pdo->query($sql_broken);
    $broken_stocks = $stmt->fetchAll();
}

// Query Data Riwayat Request (Dari DB Pusat)
$pdo_pusat = getDbConnection('pusat');
if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    $req_sql = "SELECT r.*, b.branch_name FROM stock_requests r LEFT JOIN branches b ON r.branch_code = b.branch_code ORDER BY r.request_date DESC";
} else {
    $my_branch = $_SESSION['user']['cabang'];
    $req_sql = "SELECT * FROM stock_requests WHERE branch_code = '$my_branch' ORDER BY request_date DESC";
}
$requests = $pdo_pusat->query($req_sql)->fetchAll();

// Ambil Katalog dari Pusat untuk Dropdown Request (Sparepart & Device)
$central_spareparts = $pdo_pusat->query("SELECT item_code, item_name FROM stock_buffer_pusat WHERE item_type = 'sparepart' ORDER BY item_name ASC")->fetchAll();
$central_devices = $pdo_pusat->query("SELECT DISTINCT model_code, unit_name FROM master_units ORDER BY unit_name ASC")->fetchAll();

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
            <h2 class="fw-bold text-dark mb-4">Stok Cabang</h2>
            
            <?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'broken_reported'): ?><div class="alert alert-success">Item rusak berhasil dilaporkan.</div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'sent'): ?>
                <div class="alert alert-success">
                    Item rusak dikirim ke pusat.
                    <?php if (isset($_GET['print_id'])): ?><a href="print_broken_transfer.php?id=<?= htmlspecialchars($_GET['print_id']) ?>&branch=<?= htmlspecialchars($_GET['branch'] ?? '') ?>" target="_blank" class="fw-bold text-decoration-underline ms-2"><i class="fas fa-print"></i> Cetak Surat Jalan</a><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'requested'): ?><div class="alert alert-success">Permintaan stok berhasil dikirim ke pusat.</div><?php endif; ?>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="main-tab" data-bs-toggle="tab" data-bs-target="#main" type="button" role="tab">Stok Cabang</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="broken-tab" data-bs-toggle="tab" data-bs-target="#broken" type="button" role="tab">Stok Rusak</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="requests-tab" data-bs-toggle="tab" data-bs-target="#requests" type="button" role="tab">Riwayat Request</button>
                </li>
            </ul>

            <div class="tab-content" id="stockTabsContent">
                <!-- Tab Stok Utama -->
                <div class="tab-pane fade show active" id="main" role="tabpanel">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                </div>
                                <div class="col-md-6">
                                    <form method="GET" class="d-flex">
                                        <input type="text" name="search" class="form-control me-2" placeholder="Cari nama atau kode part..." value="<?= htmlspecialchars($search) ?>">
                                        <button type="submit" class="btn btn-outline-primary">Cari</button>
                                    </form>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3">Kode</th>
                                            <th>Nama Sparepart</th>
                                    <th>Tipe</th>
                                            <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                            <th>Cabang</th>
                                            <?php endif; ?>
                                            <th>Lokasi</th>
                                            <th>Stok</th>
                                            <th>Harga Jual</th>
                                            <th class="text-end pe-3">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($stocks)): ?>
                                    <tr><td colspan="<?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) ? 8 : 7 ?>" class="text-center py-4 text-muted">Belum ada data stok.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($stocks as $s): ?>
                                            <tr>
                                                <td class="ps-3 fw-bold"><?= htmlspecialchars($s['part_code']) ?></td>
                                                <td><?= htmlspecialchars($s['part_name']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($s['type'] ?? 'sparepart')) ?></span></td>
                                                <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                                <td><?= htmlspecialchars($s['branch_name'] ?? $s['branch_code'] ?? '-') ?></td>
                                                <?php endif; ?>
                                                <td><?= htmlspecialchars($s['location'] ?? '-') ?></td>
                                                <td class="fw-bold <?= $s['stock'] < 5 ? 'text-danger' : 'text-success' ?>"><?= number_format($s['stock']) ?></td>
                                                <td>Rp <?= number_format($s['sell_price'], 0, ',', '.') ?></td>
                                                <td class="text-end pe-3">
                                                    <?php if (!in_array($role, ['kepala_pusat', 'teknisi'])): // Monitor Only ?>
                                                    <button class="btn btn-sm btn-info text-white" onclick='editStock(<?= json_encode($s) ?>)'>
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" title="Laporkan Rusak" onclick='reportBroken(<?= json_encode($s) ?>)'>
                                                        <i class="fas fa-heart-broken"></i>
                                                    </button>
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
                                            <th class="ps-3">Tanggal</th>
                                            <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?><th>Cabang</th><?php endif; ?>
                                            <th>Kode</th>
                                            <th>Nama Part</th>
                                            <th>SN</th>
                                            <th>Jumlah</th>
                                            <th>Catatan</th>
                                            <th>Status</th>
                                            <th class="text-end pe-3">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($broken_stocks)): ?>
                                            <tr><td colspan="<?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) ? 9 : 8 ?>" class="text-center py-4 text-muted">Belum ada data stok rusak.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($broken_stocks as $s): ?>
                                            <tr>
                                                <td class="ps-3 small"><?= date('d/M/y', strtotime($s['reported_at'])) ?></td>
                                                <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?><td><?= htmlspecialchars($s['branch_name'] ?? $s['branch_code']) ?></td><?php endif; ?>
                                                <td class="fw-bold"><?= htmlspecialchars($s['part_code']) ?></td>
                                                <td><?= htmlspecialchars($s['part_name']) ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars($s['serial_number'] ?? '-') ?></td>
                                                <td class="fw-bold text-danger"><?= number_format($s['quantity']) ?></td>
                                                <td class="small"><?= htmlspecialchars($s['notes']) ?></td>
                                                <td><span class="badge bg-<?= $s['status'] == 'sent_to_center' ? 'success' : 'warning' ?>"><?= $s['status'] == 'sent_to_center' ? 'Terkirim' : 'Di Cabang' ?></span></td>
                                                <td class="text-end pe-3">
                                                    <?php if ($s['status'] == 'at_branch' && !in_array($role, ['kepala_pusat', 'teknisi'])): ?>
                                                    <form method="POST" onsubmit="return confirm('Kirim ke pusat?');">
                                                        <input type="hidden" name="action" value="send_to_center">
                                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                                        <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                                                            <input type="hidden" name="branch_code" value="<?= $s['branch_code'] ?>">
                                                        <?php endif; ?>
                                                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-truck"></i> Kirim</button>
                                                    </form>
                                                    <?php endif; ?>

                                                    <?php if ($s['status'] == 'sent_to_center' || $s['status'] == 'recycled'): ?>
                                                        <?php 
                                                            $print_url = "print_broken_transfer.php?id=" . $s['id'];
                                                            if (isset($s['branch_code'])) $print_url .= "&branch=" . $s['branch_code'];
                                                            elseif (isset($_SESSION['user']['cabang'])) $print_url .= "&branch=" . $_SESSION['user']['cabang'];
                                                        ?>
                                                        <a href="<?= $print_url ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak Surat Jalan"><i class="fas fa-print"></i></a>
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

            <!-- Tab Riwayat Request -->
            <div class="tab-pane fade" id="requests" role="tabpanel">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title m-0">Riwayat Permintaan</h5>
                            <button class="btn btn-primary" onclick="openRequestModal()">
                                <i class="fas fa-plus-circle me-2"></i>Buat Request Stok
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Tanggal Request</th>
                                        <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?><th>Cabang</th><?php endif; ?>
                                        <th>Kode Part</th>
                                        <th>Nama Part</th>
                                        <th>Jumlah</th>
                                        <th>Catatan</th>
                                        <th>Status</th>
                                        <th>Tgl Proses</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requests)): ?>
                                        <tr><td colspan="<?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) ? 8 : 7 ?>" class="text-center py-4 text-muted">Belum ada riwayat request.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($requests as $r): ?>
                                        <tr>
                                            <td class="ps-3 small"><?= date('d/M/Y H:i', strtotime($r['request_date'])) ?></td>
                                            <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?><td><?= htmlspecialchars($r['branch_name'] ?? $r['branch_code']) ?></td><?php endif; ?>
                                            <td class="fw-bold"><?= htmlspecialchars($r['item_code']) ?></td>
                                            <td><?= htmlspecialchars($r['item_name']) ?></td>
                                            <td class="fw-bold"><?= number_format($r['quantity']) ?></td>
                                            <td class="small"><?= htmlspecialchars($r['notes']) ?></td>
                                            <td>
                                                <?php if ($r['status'] == 'pending'): ?>
                                                    <span class="badge bg-warning text-dark">Menunggu</span>
                                                <?php elseif ($r['status'] == 'approved'): ?>
                                                    <span class="badge bg-success">Disetujui</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Ditolak</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted"><?= $r['processed_date'] ? date('d/M/Y', strtotime($r['processed_date'])) : '-' ?></td>
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

<!-- Modal Tambah/Edit (Hanya untuk Cabang) -->
<div class="modal fade" id="stockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Edit Stok Sparepart</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="stockId">
                    
                    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                    <div class="mb-3">
                        <label class="form-label">Cabang</label>
                        <select name="branch_code" id="branchCode" class="form-select" required>
                            <option value="">-- Pilih Cabang --</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_code'] ?>"><?= $b['branch_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Kode Part</label>
                        <input type="text" name="part_code" id="partCode" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Sparepart</label>
                        <input type="text" name="part_name" id="partName" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Stok Awal</label>
                            <input type="number" name="stock" id="partStock" class="form-control" required min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lokasi Rak</label>
                            <input type="text" name="location" id="partLoc" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Harga Jual</label>
                        <input type="number" name="sell_price" id="partSell" class="form-control" required <?= !in_array($role, ['superadmin', 'kepala_admin']) ? 'readonly' : '' ?>>
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

<!-- Modal Report Broken -->
<div class="modal fade" id="brokenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Laporkan Stok Rusak</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="report_broken">
                    <input type="hidden" name="id" id="brokenStockId">
                    
                    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                    <input type="hidden" name="branch_code" id="brokenBranchCode">
                    <?php endif; ?>

                    <div class="mb-3"><label class="form-label">Nama Item</label><input type="text" id="brokenPartName" class="form-control" readonly></div>
                    
                    <!-- Field SN untuk Device -->
                    <div id="brokenSnField" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Serial Number (SN)</label>
                            <input type="text" name="serial_number" class="form-control" placeholder="Scan SN">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Jumlah Rusak</label>
                        <input type="number" name="quantity_broken" id="brokenQty" class="form-control" required min="1">
                        <small class="text-muted">Stok saat ini: <span id="brokenCurrentStock"></span></small>
                    </div>
                    <div class="mb-3"><label class="form-label">Catatan</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Laporkan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Request Stock -->
<div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Stok ke Pusat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="request_stock">
                    
                    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Untuk Cabang</label>
                            <select name="branch_code" id="reqBranchCode" class="form-select" required>
                                <option value="">-- Pilih Cabang --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= $b['branch_code'] ?>"><?= $b['branch_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end justify-content-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRequestRow()">
                                <i class="fas fa-plus me-1"></i> Tambah Item
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="d-flex justify-content-end mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRequestRow()">
                            <i class="fas fa-plus me-1"></i> Tambah Item
                        </button>
                    </div>
                    <?php endif; ?>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle">
                            <thead class="bg-light small">
                                <tr>
                                    <th style="width: 60%">Nama Item</th>
                                    <th style="width: 30%">Qty</th>
                                    <th style="width: 10%"></th>
                                </tr>
                            </thead>
                            <tbody id="requestItemsContainer"></tbody>
                        </table>
                    </div>

                    <div class="mb-3"><label class="form-label">Catatan Request</label><textarea name="notes" class="form-control" rows="2" placeholder="Catatan untuk semua item..."></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Kirim Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editStock(data) {
    document.getElementById('modalTitle').innerText = 'Edit Stok Sparepart';
    document.getElementById('stockId').value = data.id;
    document.getElementById('partCode').value = data.part_code;
    document.getElementById('partName').value = data.part_name;
    document.getElementById('partStock').value = data.stock;
    document.getElementById('partLoc').value = data.location;
    document.getElementById('partSell').value = data.sell_price;
    
    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
    const branchSelect = document.getElementById('branchCode');
    branchSelect.value = data.branch_code || '';
    branchSelect.disabled = true;
    
    if (!document.getElementById('hiddenBranchCode')) {
        let hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'branch_code';
        hiddenInput.id = 'hiddenBranchCode';
        hiddenInput.value = data.branch_code || '';
        document.getElementById('branchCode').parentNode.appendChild(hiddenInput);
    } else {
        document.getElementById('hiddenBranchCode').value = data.branch_code || '';
    }
    <?php endif; ?>

    new bootstrap.Modal(document.getElementById('stockModal')).show();
}

function reportBroken(data) {
    document.getElementById('brokenStockId').value = data.id;
    document.getElementById('brokenPartName').value = data.part_name;
    document.getElementById('brokenCurrentStock').innerText = data.stock;
    
    const snField = document.getElementById('brokenSnField');
    const qtyInput = document.getElementById('brokenQty');

    if (data.type === 'device') {
        snField.style.display = 'block';
        qtyInput.value = 1;
        qtyInput.readOnly = true;
        snField.querySelector('input').required = true;
    } else {
        snField.style.display = 'none';
        qtyInput.readOnly = false;
        snField.querySelector('input').required = false;
        snField.querySelector('input').value = '';
    }
    
    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
    if (document.getElementById('brokenBranchCode')) {
        document.getElementById('brokenBranchCode').value = data.branch_code || '';
    }
    <?php endif; ?>

    new bootstrap.Modal(document.getElementById('brokenModal')).show();
}

const centralSpareparts = <?= json_encode($central_spareparts) ?>;
const centralDevices = <?= json_encode($central_devices) ?>;

function openRequestModal() {
    document.getElementById('requestItemsContainer').innerHTML = '';
    addRequestRow(); // Add first row
    new bootstrap.Modal(document.getElementById('requestModal')).show();
}

function addRequestRow() {
    const container = document.getElementById('requestItemsContainer');
    const rowId = Date.now();
    
    let optionsHtml = '<optgroup label="Sparepart">';
    centralSpareparts.forEach(sp => {
        optionsHtml += `<option value="${sp.item_code}" data-name="${sp.item_name}">${sp.item_name} (${sp.item_code})</option>`;
    });
    optionsHtml += '</optgroup><optgroup label="Device">';
    centralDevices.forEach(dv => {
        optionsHtml += `<option value="${dv.model_code}" data-name="${dv.unit_name}">${dv.unit_name} (${dv.model_code})</option>`;
    });
    optionsHtml += '</optgroup>';

    const html = `
        <tr id="req-row-${rowId}">
            <td>
                <select class="form-select form-select-sm" onchange="updateRequestRow(${rowId}, this)" required>
                    <option value="">-- Pilih Item --</option>
                    ${optionsHtml}
                </select>
                <input type="hidden" name="items[${rowId}][part_code]" id="req-code-${rowId}">
                <input type="hidden" name="items[${rowId}][part_name]" id="req-name-${rowId}">
            </td>
            <td>
                <input type="number" name="items[${rowId}][quantity]" class="form-control form-control-sm text-center" value="1" min="1" required>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-link text-danger p-0" onclick="document.getElementById('req-row-${rowId}').remove()"><i class="fas fa-times"></i></button>
            </td>
        </tr>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function updateRequestRow(rowId, selectEl) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    if (selectedOption.value) {
        document.getElementById(`req-code-${rowId}`).value = selectedOption.value;
        document.getElementById(`req-name-${rowId}`).value = selectedOption.getAttribute('data-name');
    }
}

// Auto Open Surat Jalan di Tab Baru
const urlParams = new URLSearchParams(window.location.search);
const printId = urlParams.get('print_id');
if (printId) {
    window.open('print_broken_transfer.php?id=' + printId + '&branch=' + (urlParams.get('branch') || ''), '_blank');
}
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
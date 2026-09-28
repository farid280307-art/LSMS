<?php
$allowed_roles = ['gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';


$pdo = getDbConnection();
$is_central = empty($_SESSION['user']['cabang']); // Cek apakah gudang pusat atau cabang

// Tentukan tabel berdasarkan lokasi
$table_stock = $is_central ? 'stock_buffer_pusat' : 'stock_buffer_cabang';
$col_name = $is_central ? 'item_name' : 'part_name';
$col_code = $is_central ? 'item_code' : 'part_code';
$col_qty = $is_central ? 'quantity' : 'stock';

// --- 1. STATISTIK UTAMA ---
$stats = [
    'total_units' => 0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0,
    'pending_requests' => 0
];

// Total Unit & Stock Alerts
$stmt = $pdo->query("SELECT 
    SUM($col_qty) as total_units,
    SUM(CASE WHEN $col_qty < 5 AND $col_qty > 0 THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN $col_qty = 0 THEN 1 ELSE 0 END) as out_of_stock
FROM $table_stock");
$res = $stmt->fetch();
$stats['total_units'] = $res['total_units'] ?? 0;
$stats['low_stock_count'] = $res['low_stock'];
$stats['out_of_stock_count'] = $res['out_of_stock'];

// Requests Count
if ($is_central) {
    // Pusat: Request masuk status pending
    $stmt = $pdo->query("SELECT COUNT(*) FROM stock_requests WHERE status = 'pending'");
} else {
    // Cabang: Request saya status pending
    $my_branch = $_SESSION['user']['cabang'];
    // Perlu koneksi ke pusat untuk cek request
    $pdo_pusat = getDbConnection('pusat');
    $stmt = $pdo_pusat->prepare("SELECT COUNT(*) FROM stock_requests WHERE branch_code = ? AND status = 'pending'");
    $stmt->execute([$my_branch]);
}
$stats['pending_requests'] = $stmt->fetchColumn();


// --- 2. DATA LISTS ---

// A. Stok Menipis (Limit 5)
$stmt = $pdo->query("SELECT * FROM $table_stock WHERE $col_qty < 5 ORDER BY $col_qty ASC LIMIT 5");
$low_stock_items = $stmt->fetchAll();

// B. Riwayat Pergerakan (History)
$history_logs = [];
if ($is_central) {
    // Pusat: Ambil dari transfer_stock (Barang Keluar ke Cabang)
    $stmt = $pdo->query("SELECT t.*, b.branch_name 
                         FROM transfer_stock t 
                         LEFT JOIN branches b ON t.to_branch_id = b.id 
                         ORDER BY t.transfer_date DESC, t.id DESC LIMIT 5");
    $history_logs = $stmt->fetchAll();
} else {
    // Cabang: Ambil dari stock_history
    $stmt = $pdo->query("SELECT h.*, s.part_name, s.part_code 
                         FROM stock_history h 
                         JOIN stock_buffer_cabang s ON h.sparepart_id = s.id 
                         ORDER BY h.log_time DESC LIMIT 5");
    $history_logs = $stmt->fetchAll();
}

// C. Request Stok (Table)
$requests_list = [];
if ($is_central) {
    // Pusat: Request Masuk (Pending)
    $stmt = $pdo->query("SELECT r.*, b.branch_name 
                         FROM stock_requests r 
                         LEFT JOIN branches b ON r.branch_code = b.branch_code 
                         WHERE r.status = 'pending' 
                         ORDER BY r.request_date ASC LIMIT 5");
    $requests_list = $stmt->fetchAll();
} else {
    // Cabang: Request Saya (Terbaru)
    $my_branch = $_SESSION['user']['cabang'];
    $pdo_pusat = getDbConnection('pusat');
    $stmt = $pdo_pusat->prepare("SELECT * FROM stock_requests WHERE branch_code = ? ORDER BY request_date DESC LIMIT 5");
    $stmt->execute([$my_branch]);
    $requests_list = $stmt->fetchAll();
}

require_once __DIR__ . '/../../views/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/../../views/layouts/sidebar.php'; ?>


<div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg navbar-light bg-white px-4 py-3 sticky-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center me-3">
                <button class="btn btn-light text-primary border-0 d-md-none" id="sidebarToggleMobile"><i class="fas fa-bars fa-lg"></i></button>
                <button class="btn btn-light text-primary border-0" id="navbarToggleDesktop"><i class="fas fa-bars fa-lg"></i></button>
            </div>
            <ul class="navbar-nav ms-auto mt-2 mt-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?> (Gudang <?= $is_central ? 'Pusat' : 'Cabang' ?>)
                    </a>
                    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                        <a class="dropdown-item" href="<?= BASE_URL ?>auth/logout.php">Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container-fluid p-4 mt-4">
        <!-- Statistik Utama -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Total Fisik Unit</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['total_units']) ?></h3>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
                                <i class="fas fa-boxes fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Stok Menipis (< 5)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['low_stock_count']) ?></h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Stok Kosong (0)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['out_of_stock_count']) ?></h3>
                            </div>
                            <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
                                <i class="fas fa-times-circle fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1"><?= $is_central ? 'Request Masuk' : 'Request Pending' ?></h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['pending_requests']) ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-dolly-flatbed fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Kolom Kiri: Riwayat & Request -->
            <div class="col-lg-8">
                <!-- Riwayat Pergerakan -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-history me-2"></i>Riwayat Pergerakan Stok</h6>
                        <a href="<?= BASE_URL ?>modules/riwayat/index.php?tab=stock" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Waktu</th>
                                        <th>Item</th>
                                        <th>Aksi</th>
                                        <th>Qty</th>
                                        <?php if($is_central): ?><th>Tujuan</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($history_logs)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($history_logs as $log): ?>
                                        <tr>
                                            <?php if($is_central): ?>
                                                <!-- Tampilan Pusat (Transfer) -->
                                                <td class="ps-3 small text-muted"><?= date('d/m H:i', strtotime($log['transfer_date'])) ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($log['item_code']) ?></td>
                                                <td><span class="badge bg-info">Transfer Keluar</span></td>
                                                <td class="fw-bold text-danger">-<?= number_format($log['quantity']) ?></td>
                                                <td><?= htmlspecialchars($log['branch_name']) ?></td>
                                            <?php else: ?>
                                                <!-- Tampilan Cabang (Stock History) -->
                                                <td class="ps-3 small text-muted"><?= date('d/m H:i', strtotime($log['log_time'])) ?></td>
                                                <td>
                                                    <div class="fw-bold small"><?= htmlspecialchars($log['part_name']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($log['part_code']) ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($log['change_type'] == 'in'): ?>
                                                        <span class="badge bg-success">Masuk</span>
                                                    <?php elseif ($log['change_type'] == 'out'): ?>
                                                        <span class="badge bg-danger">Keluar</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Adjust</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold <?= $log['change_type'] == 'out' ? 'text-danger' : 'text-success' ?>">
                                                    <?= $log['change_type'] == 'out' ? '-' : '+' ?><?= number_format($log['quantity_change']) ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Request Stok -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-truck-loading me-2"></i><?= $is_central ? 'Request Masuk (Pending)' : 'Status Request Saya' ?></h6>
                        <a href="<?= BASE_URL ?>modules/<?= $is_central ? 'stock_buffer' : 'stock_device' ?>/index.php?tab=requests" class="btn btn-sm btn-outline-dark">Kelola</a>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(empty($requests_list)): ?>
                                <li class="list-group-item text-center text-muted py-3">Tidak ada data request.</li>
                            <?php else: ?>
                                <?php foreach($requests_list as $req): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold small"><?= htmlspecialchars($req['item_name']) ?></div>
                                        <div class="text-muted small">
                                            Qty: <strong><?= $req['quantity'] ?></strong> &bull; 
                                            <?= $is_central ? htmlspecialchars($req['branch_name']) : date('d/m/Y', strtotime($req['request_date'])) ?>
                                        </div>
                                    </div>
                                    <?php if($is_central): ?>
                                        <a href="<?= BASE_URL ?>modules/stock_buffer/index.php?tab=requests" class="btn btn-sm btn-primary">Proses</a>
                                    <?php else: ?>
                                        <?php 
                                            $badge = match($req['status']) {
                                                'pending' => 'warning',
                                                'approved' => 'success',
                                                'rejected' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>
                                        <span class="badge bg-<?= $badge ?>"><?= ucfirst($req['status']) ?></span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Kolom Kanan: Aksi & Alert -->
            <div class="col-lg-4">
                <!-- Aksi Cepat -->
                <div class="card border-0 shadow-sm mb-4 bg-primary text-white">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3"><i class="fas fa-bolt me-2"></i>Aksi Cepat</h5>
                        <div class="d-grid gap-2">
                            <a href="<?= BASE_URL ?>modules/<?= $is_central ? 'stock_buffer' : 'stock_device' ?>/index.php" class="btn btn-light text-primary fw-bold">
                                <i class="fas fa-tasks me-2"></i>Kelola Stok
                            </a>
                            <?php if ($is_central): ?>
                            <a href="<?= BASE_URL ?>modules/stock_buffer/index.php?tab=requests" class="btn btn-outline-light fw-bold">
                                <i class="fas fa-dolly-flatbed me-2"></i>Proses Request
                            </a>
                            <?php else: ?>
                            <a href="<?= BASE_URL ?>modules/stock_device/index.php?tab=requests" class="btn btn-outline-light fw-bold">
                                <i class="fas fa-hand-holding-medical me-2"></i>Request ke Pusat
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Stok Menipis -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold text-danger py-3"><i class="fas fa-exclamation-circle me-2"></i>Stok Menipis (< 5)</div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(empty($low_stock_items)): ?>
                                <li class="list-group-item text-center text-muted py-3">Stok aman.</li>
                            <?php else: ?>
                            <?php foreach($low_stock_items as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($s[$col_name]) ?></span>
                                <span class="badge bg-danger rounded-pill"><?= $s[$col_qty] ?></span>
                            </li>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
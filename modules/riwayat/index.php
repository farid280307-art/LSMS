<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'gudang', 'teknisi', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();
$role = $_SESSION['user']['role'];
$search = $_GET['search'] ?? '';

// Definisi Hak Akses per Tab
$roles_stock = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang', 'kepala_cabang', 'admin'];
$roles_service = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'teknisi', 'cs'];

$show_stock = in_array($role, $roles_stock);
$show_service = in_array($role, $roles_service);

// Tentukan Tab Aktif
$active_tab = '';
if (isset($_GET['tab'])) {
    if ($_GET['tab'] === 'stock' && $show_stock) $active_tab = 'stock';
    elseif ($_GET['tab'] === 'service' && $show_service) $active_tab = 'service';
}

if (empty($active_tab)) {
    if ($show_stock) $active_tab = 'stock';
    elseif ($show_service) $active_tab = 'service';
}

// --- LOGIC: RIWAYAT STOCK ---
$history_stock = [];
if ($show_stock && $active_tab === 'stock') {
    $params = [];
    if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) || ($role === 'gudang' && empty($_SESSION['user']['cabang']))) {
        global $db_configs;
        $union_parts = [];
        $branch_dbs = array_filter($db_configs, function($key) {
            return strpos($key, 'cabang_') === 0;
        }, ARRAY_FILTER_USE_KEY);

        foreach ($branch_dbs as $branch_code => $config) {
            $db_name = $config['dbname'];
            // Join dengan stock_buffer_cabang untuk nama barang
            $union_parts[] = "
                SELECT h.*, s.part_name, s.part_code, '$branch_code' as branch_code 
                FROM `$db_name`.stock_history h
                JOIN `$db_name`.stock_buffer_cabang s ON h.sparepart_id = s.id
            ";
        }

        if (!empty($union_parts)) {
            $union_sql = implode(' UNION ALL ', $union_parts);
            $sql = "SELECT t.*, b.branch_name, u.full_name as user_name
                    FROM ({$union_sql}) as t
                    LEFT JOIN `lsms_pusat`.branches b ON t.branch_code = b.branch_code
                    LEFT JOIN `lsms_pusat`.users u ON t.user_id = u.id";
            
            if (!empty($search)) {
                $sql .= " WHERE t.part_name LIKE ? OR t.part_code LIKE ? OR t.notes LIKE ?";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            $sql .= " ORDER BY t.log_time DESC";
        } else {
            $sql = "";
        }
    } else {
        // Untuk cabang
        $sql = "SELECT h.*, s.part_name, s.part_code, u.full_name as user_name
                FROM stock_history h
                JOIN stock_buffer_cabang s ON h.sparepart_id = s.id
                LEFT JOIN lsms_pusat.users u ON h.user_id = u.id";
        
        if (!empty($search)) {
            $sql .= " WHERE s.part_name LIKE ? OR s.part_code LIKE ? OR h.notes LIKE ?";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $sql .= " ORDER BY h.log_time DESC";
    }

    if (!empty($sql)) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history_stock = $stmt->fetchAll();
        } catch (PDOException $e) {
            $error_stock = "Data riwayat stok belum tersedia.";
        }
    }
}

// --- LOGIC: RIWAYAT SERVICE ---
$history_service = [];
if ($show_service && $active_tab === 'service') {
    $params_svc = [];
    if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
        global $db_configs;
        $union_parts = [];
        $branch_dbs = array_filter($db_configs, function($key) {
            return strpos($key, 'cabang_') === 0;
        }, ARRAY_FILTER_USE_KEY);

        foreach ($branch_dbs as $branch_code => $config) {
            $db_name = $config['dbname'];
            // Mengambil data service logs
            $union_parts[] = "
                SELECT l.*, s.service_code, s.created_at, s.status,
                       COALESCE(c.name, dl.name) as customer_name, 
                       d.brand, d.model,
                       '$branch_code' as branch_code
                FROM `$db_name`.service_logs l
                JOIN `$db_name`.services s ON l.service_id = s.id
                JOIN `$db_name`.devices d ON s.device_id = d.id
                LEFT JOIN `$db_name`.customers c ON s.customer_id = c.id
                LEFT JOIN `lsms_pusat`.dealers dl ON s.dealer_id = dl.id
            ";
        }

        if (!empty($union_parts)) {
            $union_sql = implode(' UNION ALL ', $union_parts);
            $sql_svc = "SELECT t.*, b.branch_name, u.full_name as user_name
                        FROM ({$union_sql}) as t
                        LEFT JOIN `lsms_pusat`.branches b ON t.branch_code = b.branch_code
                        LEFT JOIN `lsms_pusat`.users u ON t.user_id = u.id";
            
            if (!empty($search)) {
                $sql_svc .= " WHERE t.service_code LIKE ? OR t.description LIKE ?";
                $params_svc[] = "%$search%";
                $params_svc[] = "%$search%";
            }
            $sql_svc .= " ORDER BY t.log_time DESC";
        }
    } else {
        // Local branch
        $sql_svc = "SELECT l.*, s.service_code, s.created_at, s.status,
                           u.full_name as user_name,
                           COALESCE(c.name, dl.name) as customer_name,
                           d.brand, d.model
                    FROM service_logs l
                    JOIN services s ON l.service_id = s.id
                    JOIN devices d ON s.device_id = d.id
                    LEFT JOIN customers c ON s.customer_id = c.id
                    LEFT JOIN lsms_pusat.dealers dl ON s.dealer_id = dl.id
                    LEFT JOIN lsms_pusat.users u ON l.user_id = u.id";
        
        if (!empty($search)) {
            $sql_svc .= " WHERE s.service_code LIKE ? OR l.description LIKE ?";
            $params_svc[] = "%$search%";
            $params_svc[] = "%$search%";
        }
        $sql_svc .= " ORDER BY l.log_time DESC";
    }

    if (!empty($sql_svc)) {
        try {
            $stmt = $pdo->prepare($sql_svc);
            $stmt->execute($params_svc);
            $history_service = $stmt->fetchAll();
        } catch (PDOException $e) {
            // Fallback jika tabel service_logs belum ada
            $history_service = [];
        }
    }
}

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
            <h2 class="fw-bold text-dark mb-4">Riwayat & Laporan</h2>
            
            <?php if (isset($error_stock)): ?>
                <div class="alert alert-warning"><?= $error_stock ?></div>
            <?php endif; ?>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4">
                <?php if ($show_stock): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'stock' ? 'active' : '' ?>" href="?tab=stock">Pergerakan Stock</a>
                </li>
                <?php endif; ?>
                <?php if ($show_service): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'service' ? 'active' : '' ?>" href="?tab=service">Riwayat Service</a>
                </li>
                <?php endif; ?>
            </ul>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <!-- Filter Tanggal bisa ditambahkan di sini -->
                        </div>
                        <div class="col-md-6">
                            <form method="GET" class="d-flex">
                                <input type="hidden" name="tab" value="<?= $active_tab ?>">
                                <input type="text" name="search" class="form-control me-2" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="btn btn-outline-primary">Cari</button>
                            </form>
                        </div>
                    </div>

                    <!-- TAB CONTENT: STOCK -->
                    <?php if ($active_tab === 'stock' && $show_stock): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Waktu</th>
                                    <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) || ($role === 'gudang' && empty($_SESSION['user']['cabang']))): ?>
                                    <th>Cabang</th>
                                    <?php endif; ?>
                                    <th>Barang</th>
                                    <th>Tipe</th>
                                    <th>Jumlah</th>
                                    <th>Stok Akhir</th>
                                    <th>User</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history_stock)): ?>
                                    <tr><td colspan="<?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) || ($role === 'gudang' && empty($_SESSION['user']['cabang']))) ? 8 : 7 ?>" class="text-center py-4 text-muted">Belum ada data riwayat.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history_stock as $h): ?>
                                    <tr>
                                        <td class="ps-3 text-muted small"><?= date('d/M/Y H:i', strtotime($h['log_time'])) ?></td>
                                        <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) || ($role === 'gudang' && empty($_SESSION['user']['cabang']))): ?>
                                        <td><?= htmlspecialchars($h['branch_name'] ?? $h['branch_code'] ?? '-') ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($h['part_name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($h['part_code']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($h['change_type'] == 'in'): ?>
                                                <span class="badge bg-success">Masuk</span>
                                            <?php elseif ($h['change_type'] == 'out'): ?>
                                                <span class="badge bg-danger">Keluar</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Adjustment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold <?= $h['change_type'] == 'out' ? 'text-danger' : 'text-success' ?>">
                                            <?= $h['change_type'] == 'out' ? '-' : '+' ?><?= number_format($h['quantity_change']) ?>
                                        </td>
                                        <td><?= number_format($h['quantity_after']) ?></td>
                                        <td><?= htmlspecialchars($h['user_name'] ?? 'System') ?></td>
                                        <td><?= htmlspecialchars($h['notes']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- TAB CONTENT: SERVICE -->
                    <?php if ($active_tab === 'service' && $show_service): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Waktu Log</th>
                                    <th>Kode Service</th>
                                    <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                    <th>Cabang</th>
                                    <?php endif; ?>
                                    <th>Pelanggan</th>
                                    <th>Unit</th>
                                    <th>Aksi / Perubahan</th>
                                    <th>Deskripsi Detail</th>
                                    <th>Oleh</th>
                                    <th class="text-end pe-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history_service)): ?>
                                    <tr><td colspan="<?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) ? 8 : 7 ?>" class="text-center py-4 text-muted">Belum ada riwayat pergerakan service.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history_service as $s): ?>
                                    <tr>
                                        <td class="ps-3 text-muted small">
                                            <?= date('d/M/Y H:i', strtotime($s['log_time'])) ?>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-primary"><?= htmlspecialchars($s['service_code']) ?></span>
                                            <br><small class="text-muted">Masuk: <?= date('d/M/y', strtotime($s['created_at'])) ?></small>
                                        </td>
                                        <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                        <td><?= htmlspecialchars($s['branch_name'] ?? $s['branch_code'] ?? '-') ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($s['customer_name']) ?></td>
                                        <td><?= htmlspecialchars($s['brand']) ?> <?= htmlspecialchars($s['model']) ?></td>
                                        <td>
                                            <?php
                                            $action_labels = [
                                                'created' => 'Service Dibuat',
                                                'assign_tech' => 'Teknisi Ditugaskan',
                                                'status_update' => 'Update Status',
                                                'item_added' => 'Item Ditambah',
                                                'payment' => 'Pembayaran',
                                                'taken' => 'Unit Diambil',
                                                'cancelled' => 'Dibatalkan'
                                            ];
                                            ?>
                                            <span class="badge bg-secondary"><?= $action_labels[$s['action']] ?? strtoupper(str_replace('_', ' ', $s['action'])) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($s['description']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($s['user_name'] ?? 'System') ?></td>
                                        <td class="text-end pe-3">
                                            <a href="../service/view.php?id=<?= $s['service_id'] ?><?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) && isset($s['branch_code'])) ? '&branch='.$s['branch_code'] : '' ?>" class="btn btn-sm btn-outline-info" title="Lihat Service"><i class="fas fa-eye"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'teknisi'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();
$role = $_SESSION['user']['role'];
$user_id = $_SESSION['user']['id'];
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$date_filter = $_GET['date_filter'] ?? '';
global $db_configs;
$central_db_name = $db_configs['pusat']['dbname'];

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Filter Query Dasar
$where_clauses = [];
$params = [];

// Filter khusus Teknisi: Hanya lihat tugas sendiri atau yang belum ada teknisi (opsional)
if ($role === 'teknisi') {
    $where_clauses[] = "(technician_id = :tech_id OR (status = 'diagnosis' AND technician_id IS NULL))";
    $params['tech_id'] = $user_id;
}

// Filter Status
if (!empty($status_filter)) {
    $where_clauses[] = "status = :status_filter";
    $params['status_filter'] = $status_filter;
}
// Filter Tanggal Masuk
if (!empty($date_filter)) {
    $where_clauses[] = "DATE(created_at) = :date_filter";
    $params['date_filter'] = $date_filter;
}

if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    // Superadmin sees an aggregated view from all branches

    $union_parts = [];
    // Filter to get only branch databases
    $branch_dbs = array_filter($db_configs, function($key) {
        return strpos($key, 'cabang_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($branch_dbs as $branch_code => $config) {
        $db_name = $config['dbname'];
        
        // Cek apakah kolom dealer_id ada di tabel services cabang ini
        $check_sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'services' AND COLUMN_NAME = 'dealer_id'";
        $stmt_check = $pdo->prepare($check_sql);
        $stmt_check->execute(['dbname' => $db_name]);
        $has_dealer_id = $stmt_check->fetchColumn() > 0;

        // Secara eksplisit mendefinisikan kolom untuk mencegah error kardinalitas di UNION.
        // Ini memastikan setiap SELECT memiliki jumlah kolom yang sama.
        $select_columns = "
            s.id, s.service_code, s.customer_id, 
            " . ($has_dealer_id ? "s.dealer_id" : "NULL as dealer_id") . ",
            s.device_id, s.reported_problem, s.technician_id, s.status,
            s.estimated_cost, s.final_cost, s.created_by_user_id,
            s.created_at, s.updated_at
        ";

        $customer_col = "c.name";
        $dealer_join = "";
        if ($has_dealer_id) {
            $customer_col = "COALESCE(c.name, dl.name)";
            $dealer_join = "LEFT JOIN `{$central_db_name}`.dealers dl ON s.dealer_id = dl.id";
        }

        // Note: backticks around dbname for safety
        $union_parts[] = "
            (SELECT {$select_columns}, {$customer_col} as customer_name, d.model, d.brand, '{$branch_code}' as branch_code,
                    mu.warranty_start, mu.warranty_end, d.serial_number
             FROM `{$db_name}`.services s
             LEFT JOIN `{$db_name}`.customers c ON s.customer_id = c.id
             {$dealer_join}
             JOIN `{$db_name}`.devices d ON s.device_id = d.id
             LEFT JOIN `{$central_db_name}`.master_units mu ON d.serial_number = mu.serial_number)
        ";
    }

    // Bangun klausa WHERE untuk superadmin setelah query utama disiapkan
    if (!empty($search)) {
        $where_clauses[] = "(t.service_code LIKE :search_sa1 OR t.customer_name LIKE :search_sa2 OR t.brand LIKE :search_sa3 OR t.model LIKE :search_sa4 OR t.serial_number LIKE :search_sa5)";
        $params['search_sa1'] = "%$search%";
        $params['search_sa2'] = "%$search%";
        $params['search_sa3'] = "%$search%";
        $params['search_sa4'] = "%$search%";
        $params['search_sa5'] = "%$search%";
    }
    // Adjust parameter names for superadmin's union query
    if (!empty($status_filter)) {
        $where_clauses = array_map(function($clause) { return str_replace('status = :status_filter', 't.status = :status_filter', $clause); }, $where_clauses);
    }
    if (!empty($date_filter)) {
        $where_clauses = array_map(function($clause) { return str_replace('DATE(created_at) = :date_filter', 'DATE(t.created_at) = :date_filter', $clause); }, $where_clauses);
    }
    if ($role === 'teknisi') { // Adjust technician filter for superadmin's union query
        $where_clauses = array_map(function($clause) { 
            $clause = str_replace('technician_id = :tech_id', 't.technician_id = :tech_id', $clause);
            $clause = str_replace("status = 'diagnosis'", "t.status = 'diagnosis'", $clause);
            $clause = str_replace('technician_id IS NULL', 't.technician_id IS NULL', $clause);
            return $clause;
        }, $where_clauses);
    }

    if (!empty($union_parts)) {
        $union_sql = implode(' UNION ALL ', $union_parts);
        
        $where_sql_super = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $sql = "SELECT t.*, u.full_name as technician_name, b.branch_name
                FROM ({$union_sql}) as t
                LEFT JOIN `{$central_db_name}`.users u ON t.technician_id = u.id
                LEFT JOIN `{$central_db_name}`.branches b ON t.branch_code = b.branch_code
                $where_sql_super
                ORDER BY t.created_at DESC";
    } else {
        $sql = ""; // No branches configured, empty result
    }
} else {
    // Existing query for branch users
    // Cek apakah kolom dealer_id ada di database saat ini
    $current_db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $check_sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'services' AND COLUMN_NAME = 'dealer_id'";
    $stmt_check = $pdo->prepare($check_sql);
    $stmt_check->execute(['dbname' => $current_db_name]);
    $has_dealer_id = $stmt_check->fetchColumn() > 0;

    $customer_col = "c.name";
    $dealer_join = "";
    if ($has_dealer_id) {
        $customer_col = "COALESCE(c.name, dl.name)";
        $dealer_join = "LEFT JOIN `{$central_db_name}`.dealers dl ON s.dealer_id = dl.id";
    }

    // Bangun klausa WHERE untuk user cabang sekarang setelah kita tahu join dealer ada atau tidak
    if (!empty($search)) {
        $search_parts = [
            "s.service_code LIKE :search_branch1",
            "c.name LIKE :search_branch2",
            "d.brand LIKE :search_branch3",
            "d.model LIKE :search_branch4",
            "d.serial_number LIKE :search_branch5"
        ];
        $params['search_branch1'] = "%$search%";
        $params['search_branch2'] = "%$search%";
        $params['search_branch3'] = "%$search%";
        $params['search_branch4'] = "%$search%";
        $params['search_branch5'] = "%$search%";

        if ($has_dealer_id) {
            $search_parts[] = "dl.name LIKE :search_branch6";
            $params['search_branch6'] = "%$search%";
        }
        $where_clauses[] = "(" . implode(' OR ', $search_parts) . ")";
    }    
    $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Perlu join manual untuk search karena kolom ada di tabel lain
    $sql = "SELECT s.*, {$customer_col} as customer_name, d.model, d.brand,
                   u.full_name as technician_name,
                   mu.warranty_start, mu.warranty_end, d.serial_number
            FROM services s
            LEFT JOIN customers c ON s.customer_id = c.id
            {$dealer_join}
            JOIN devices d ON s.device_id = d.id
            LEFT JOIN `{$central_db_name}`.users u ON s.technician_id = u.id
            LEFT JOIN `{$central_db_name}`.master_units mu ON d.serial_number = mu.serial_number
            $where_sql
            ORDER BY s.created_at DESC";
}

$services = [];
if (!empty($sql)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $services = $stmt->fetchAll();
}

// Helper untuk warna badge status
function getStatusBadge($status) {
    // Disesuaikan dengan ENUM di database: 'pending','diagnosis','on progress','waiting','completed'
    // Ditambah status lain yang mungkin digunakan secara logis di aplikasi (taken, cancelled)
    $colors = [
        'pending' => 'secondary',
        'diagnosis' => 'info',
        'on_progress' => 'primary', // Menggunakan underscore sesuai nama kolom
        'waiting_sparepart' => 'warning', // Menggunakan underscore sesuai nama kolom
        'finished' => 'success', // Menggunakan 'finished' sesuai nama kolom
        'taken' => 'success',
        'cancelled' => 'danger'
    ];
    
    $labels = [
        'pending' => 'Menunggu',
        'diagnosis' => 'Diagnosis',
        'on_progress' => 'Sedang Dikerjakan',
        'waiting_sparepart' => 'Menunggu Sparepart',
        'finished' => 'Selesai',
        'taken' => 'Sudah Diambil',
        'cancelled' => 'Dibatalkan'
    ];

    $display_status = $labels[$status] ?? strtoupper(str_replace('_', ' ', $status)); // Format untuk tampilan
    $color = $colors[$status] ?? 'secondary'; // Ambil warna berdasarkan status asli
    return "<span class='badge bg-$color'>" . $display_status . "</span>";
}

require_once __DIR__ . '/../../views/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/../../views/layouts/sidebar.php'; ?>
    
    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-light bg-white px-4 py-3 sticky-top">
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="fw-bold text-dark m-0">Daftar Service</h2>
                <?php if (in_array($role, ['cs', 'admin', 'superadmin', 'kepala_admin', 'kepala_cabang'])): ?>
                    <a href="create.php" class="btn btn-primary shadow-sm"><i class="fas fa-plus me-2"></i>Input Service Baru</a>
                <?php endif; ?>
            </div>
            
            <?php
            $messages = [
                'assigned' => ['class' => 'success', 'text' => 'Teknisi berhasil ditugaskan.'],
                'saved' => ['class' => 'success', 'text' => 'Data service berhasil diperbarui.'],
                'deleted' => ['class' => 'success', 'text' => 'Data service berhasil dihapus.'],
                'delete_failed' => ['class' => 'danger', 'text' => 'Gagal menghapus data service.'],
                'csrf_error' => ['class' => 'danger', 'text' => 'Sesi tidak valid. Silakan coba lagi.'],
                'not_found' => ['class' => 'warning', 'text' => 'Data service tidak ditemukan.'],
            ];
            if (isset($_GET['msg']) && isset($messages[$_GET['msg']])):
                $msg = $messages[$_GET['msg']];
            ?>
                <div class="alert alert-<?= htmlspecialchars($msg['class']) ?> alert-dismissible fade show" role="alert"><?= htmlspecialchars($msg['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-3">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Cari Kode, Pelanggan, SN..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select name="status_filter" class="form-select">
                                <option value="">-- Semua Status --</option>
                                <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Menunggu</option>
                                <option value="diagnosis" <?= $status_filter == 'diagnosis' ? 'selected' : '' ?>>Diagnosis</option>
                                <option value="on_progress" <?= $status_filter == 'on_progress' ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                                <option value="waiting_sparepart" <?= $status_filter == 'waiting_sparepart' ? 'selected' : '' ?>>Menunggu Sparepart</option>
                                <option value="finished" <?= $status_filter == 'finished' ? 'selected' : '' ?>>Selesai</option>
                                <option value="taken" <?= $status_filter == 'taken' ? 'selected' : '' ?>>Sudah Diambil</option>
                                <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="date_filter" class="form-control" value="<?= htmlspecialchars($date_filter) ?>" title="Filter Tanggal Masuk">
                        </div>
                        <div class="col-md-auto">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button>
                        </div>
                        <?php if (!empty($search) || !empty($status_filter) || !empty($date_filter)): ?>
                        <div class="col-md-auto">
                            <a href="index.php" class="btn btn-outline-secondary w-100"><i class="fas fa-undo me-1"></i> Reset</a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Data Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary border-bottom-0">
                                <tr>
                                    <th class="ps-4 py-3 border-0">Kode Service</th>
                                    <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                        <th class="py-3 border-0">Cabang</th>
                                    <?php endif; ?>
                                    <th class="py-3 border-0">Tanggal</th>
                                    <th class="py-3 border-0">Pelanggan</th>
                                    <th class="py-3 border-0">Perangkat</th>
                                    <th class="py-3 border-0">Teknisi</th>
                                    <th class="py-3 border-0">Status</th>
                                    <th class="pe-4 py-3 text-end border-0">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($services)): ?>
                                    <tr><td colspan="<?= ($role === 'superadmin') ? 8 : 7 ?>" class="text-center py-4 text-muted">Belum ada data service.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($services as $svc): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="fw-bold text-primary"><?= htmlspecialchars($svc['service_code']) ?></span>
                                        </td>
                                        <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                            <td><?= htmlspecialchars($svc['branch_name'] ?? 'N/A') ?></td>
                                        <?php endif; ?>
                                        <td class="text-muted small">
                                            <i class="far fa-calendar-alt me-1"></i><?= date('d/M/y', strtotime($svc['created_at'])) ?>
                                        </td>
                                        <td>
                                            <div class="fw-medium text-dark"><?= htmlspecialchars($svc['customer_name']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($svc['brand']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($svc['model']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($svc['technician_name'])): ?>
                                                <div class="d-flex align-items-center">
                                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary fw-bold me-2" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                                        <?= strtoupper(substr($svc['technician_name'], 0, 1)) ?>
                                                    </div>
                                                    <span class="small"><?= htmlspecialchars($svc['technician_name']) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border">Belum Assign</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= getStatusBadge($svc['status']) ?></td>
                                        <td class="pe-4 text-end">
                                            <?php
                                                $is_svc_under_warranty = false;
                                                // Cek jika ada tanggal garansi dan hari ini berada di dalam rentang tersebut
                                                $today_idx = date('Y-m-d');
                                                if (!empty($svc['warranty_start']) && !empty($svc['warranty_end']) && $today_idx >= $svc['warranty_start'] && $today_idx <= $svc['warranty_end']) {
                                                    $is_svc_under_warranty = true;
                                                }

                                                $view_url = "view.php?id=" . urlencode($svc['id']);
                                                $edit_url = "edit.php?id=" . urlencode($svc['id']);
                                                $pay_url = "pay.php?id=" . urlencode($svc['id']);
                                                if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
                                                    $branch_param = "&branch=" . urlencode($svc['branch_code']);
                                                    $view_url .= $branch_param;
                                                    $edit_url .= $branch_param;
                                                    $pay_url .= $branch_param;
                                                }
                                            ?>
                                            <div class="btn-group">
                                            <?php if ($svc['status'] === 'finished' && !$is_svc_under_warranty && in_array($role, ['superadmin', 'kepala_admin', 'admin', 'cs'])): ?>
                                                <a href="<?= $pay_url ?>" class="btn btn-sm btn-success" title="Bayar / Ambil">
                                                    <i class="fas fa-cash-register"></i>
                                                </a>
                                            <?php endif; ?>
                                                <a href="<?= $view_url ?>" class="btn btn-sm btn-outline-primary" title="Lihat Detail"><i class="fas fa-eye"></i></a>
                                            <?php if (in_array($role, ['superadmin', 'kepala_admin', 'admin', 'cs'])): ?>
                                                <a href="<?= $edit_url ?>" class="btn btn-sm btn-outline-info" title="Edit">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </a>
                                                <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus data service ini? Tindakan ini tidak dapat diurungkan.');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                                                        <input type="hidden" name="branch_code" value="<?= $svc['branch_code'] ?>">
                                                    <?php endif; ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-end" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            </div>
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

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

// Gunakan koneksi pusat secara eksplisit karena system_logs hanya ada di pusat
$pdo_pusat = getDbConnection('pusat');
$role = $_SESSION['user']['role'];

// --- GET FILTERS ---
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role_filter'] ?? '';
$branch_filter = $_GET['branch_filter'] ?? '';
$action_filter = $_GET['action_filter'] ?? '';
$date_start = $_GET['date_start'] ?? '';
$date_end = $_GET['date_end'] ?? '';

// --- PAGINATION ---
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

// --- FETCH DATA FOR FILTERS ---
$roles = $pdo_pusat->query("SELECT id, role_name FROM roles ORDER BY role_name ASC")->fetchAll();
$branches = $pdo_pusat->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll();
$actions = $pdo_pusat->query("SELECT DISTINCT action FROM system_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// --- BUILD QUERY ---
$base_sql = "FROM system_logs l
             LEFT JOIN users u ON l.user_id = u.id
             LEFT JOIN roles r ON u.role_id = r.id
             LEFT JOIN branches b ON u.branch_id = b.id";

$where_clauses = [];
$params = [];

// Filter by search keyword
if (!empty($search)) {
    $where_clauses[] = "(u.full_name LIKE :search OR l.action LIKE :search OR l.description LIKE :search)";
    $params[':search'] = "%$search%";
}

// Filter by date range
if (!empty($date_start)) {
    $where_clauses[] = "DATE(l.log_time) >= :date_start";
    $params[':date_start'] = $date_start;
}
if (!empty($date_end)) {
    $where_clauses[] = "DATE(l.log_time) <= :date_end";
    $params[':date_end'] = $date_end;
}

// Filter by role
if (!empty($role_filter)) {
    $where_clauses[] = "u.role_id = :role_filter";
    $params[':role_filter'] = $role_filter;
}

// Filter by action
if (!empty($action_filter)) {
    $where_clauses[] = "l.action = :action_filter";
    $params[':action_filter'] = $action_filter;
}

// --- ROLE-BASED ACCESS CONTROL ---
if (in_array($role, ['kepala_cabang', 'admin', 'gudang', 'cs', 'teknisi'])) {
    // These roles can only see logs from their own branch
    $stmt_branch_id = $pdo_pusat->prepare("SELECT id FROM branches WHERE branch_code = ?");
    $stmt_branch_id->execute([$_SESSION['user']['cabang']]);
    $user_branch_id = $stmt_branch_id->fetchColumn();

    if ($user_branch_id) {
        $where_clauses[] = "u.branch_id = :user_branch_id";
        $params[':user_branch_id'] = $user_branch_id;
    }

    if ($role === 'gudang') {
        // Gudang only sees stock-related logs
        $where_clauses[] = "(l.action LIKE '%stock%' OR l.action LIKE '%transfer%' OR l.action LIKE '%item%' OR l.action LIKE '%request%')";
    }
} elseif (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    // Superadmin and central heads can filter by branch
    if (!empty($branch_filter)) {
        if ($branch_filter === 'pusat') {
            $where_clauses[] = "u.branch_id IS NULL";
        } else {
            $where_clauses[] = "u.branch_id = :branch_filter";
            $params[':branch_filter'] = $branch_filter;
        }
    }
}

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// --- GET TOTAL COUNT FOR PAGINATION ---
$count_sql = "SELECT COUNT(l.id) " . $base_sql . $where_sql;
$stmt_count = $pdo_pusat->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// --- GET PAGINATED DATA ---
$data_sql = "SELECT l.*, u.full_name as user_name, u.email, r.role_name, b.branch_name " . $base_sql . $where_sql . " ORDER BY l.log_time DESC LIMIT :limit OFFSET :offset";
$stmt_data = $pdo_pusat->prepare($data_sql);

// Bind all params including pagination
foreach ($params as $key => &$val) {
    $stmt_data->bindParam($key, $val);
}
$stmt_data->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt_data->bindParam(':offset', $offset, PDO::PARAM_INT);

$history_system = [];
$error_system = '';
try {
    $stmt_data->execute();
    $history_system = $stmt_data->fetchAll();
} catch (PDOException $e) {
    $error_system = "Gagal memuat log sistem: " . $e->getMessage();
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
            <h2 class="fw-bold text-dark mb-4">Log Sistem</h2>
            
            <?php if (!empty($error_system)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_system) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <!-- Filter Form -->
                    <form method="GET" class="mb-4">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small">Pencarian</label>
                                <input type="text" name="search" class="form-control" placeholder="User, aksi, deskripsi..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Dari Tanggal</label>
                                <input type="date" name="date_start" class="form-control" value="<?= htmlspecialchars($date_start) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Sampai Tanggal</label>
                                <input type="date" name="date_end" class="form-control" value="<?= htmlspecialchars($date_end) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Jenis Aksi</label>
                                <select name="action_filter" class="form-select">
                                    <option value="">-- Semua Aksi --</option>
                                    <?php foreach ($actions as $action): ?>
                                        <option value="<?= htmlspecialchars($action) ?>" <?= ($action_filter == $action) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $action))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                            <div class="col-md-3">
                                <label class="form-label small">Role & Cabang</label>
                                <div class="input-group">
                                    <select name="role_filter" class="form-select">
                                        <option value="">-- Semua Role --</option>
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?= $r['id'] ?>" <?= ($role_filter == $r['id']) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['role_name']))) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="branch_filter" class="form-select">
                                        <option value="">-- Semua Cabang --</option>
                                        <option value="pusat" <?= ($branch_filter == 'pusat') ? 'selected' : '' ?>>Pusat</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?= $b['id'] ?>" <?= ($branch_filter == $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Waktu</th>
                                    <th>User</th>
                                    <th>Role / Cabang</th>
                                    <th>Aksi</th>
                                    <th>Deskripsi</th>
                                    <th>IP Address</th>
                                    <th class="text-end">Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history_system)): ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted">Belum ada log sistem.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history_system as $log): ?>
                                    <tr>
                                        <td class="ps-3 text-muted small"><?= date('d/M/Y H:i:s', strtotime($log['log_time'])) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($log['user_name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($log['email']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($log['role_name']) ?></span>
                                            <div class="small text-muted mt-1"><?= htmlspecialchars($log['branch_name'] ?? 'Pusat') ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $log['action'] == 'login' ? 'success' : ($log['action'] == 'logout' ? 'danger' : 'info') ?>">
                                                <?= strtoupper($log['action']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($log['description']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($log['ip_address']) ?></td>
                                        <td class="text-end">
                                            <a href="detail.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
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
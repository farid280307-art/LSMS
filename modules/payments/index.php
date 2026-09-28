<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();
$role = $_SESSION['user']['role'];
$search = $_GET['search'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Query Data Pembayaran
$params = [];
if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    global $db_configs;
    $union_parts = [];
    $branch_dbs = array_filter($db_configs, function($key) {
        return strpos($key, 'cabang_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($branch_dbs as $branch_code => $config) {
        $db_name = $config['dbname'];
        // Cek ketersediaan kolom dealer_id untuk kompatibilitas
        $pdo_check = getDbConnection($branch_code);
        $has_dealer_id = $pdo_check->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'services' AND COLUMN_NAME = 'dealer_id'")->fetchColumn() > 0;

        $customer_join = "LEFT JOIN `$db_name`.customers c ON s.customer_id = c.id";
        $dealer_join = $has_dealer_id ? "LEFT JOIN `lsms_pusat`.dealers dl ON s.dealer_id = dl.id" : "";
        $customer_name_col = $has_dealer_id ? "COALESCE(c.name, dl.name)" : "c.name";

        $union_parts[] = "
            SELECT p.*, s.service_code, $customer_name_col as customer_name, '$branch_code' as branch_code 
            FROM `$db_name`.payments p
            JOIN `$db_name`.services s ON p.service_id = s.id
            $customer_join
            $dealer_join
        ";
    }

    if (!empty($union_parts)) {
        $union_sql = implode(' UNION ALL ', $union_parts);
        $sql = "SELECT t.*, b.branch_name, u.full_name as user_name
                FROM ({$union_sql}) as t
                LEFT JOIN `lsms_pusat`.branches b ON t.branch_code = b.branch_code
                LEFT JOIN `lsms_pusat`.users u ON t.received_by_user_id = u.id";
        
        $where_clauses = [];
        if (!empty($search)) {
            $where_clauses[] = "(t.payment_code LIKE ? OR t.service_code LIKE ? OR t.customer_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if (!empty($date_filter)) {
            $where_clauses[] = "DATE(t.payment_date) = ?";
            $params[] = $date_filter;
        }
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $sql .= " ORDER BY t.payment_date DESC";
    } else {
        $sql = "";
    }
} else {
    // For branch users
    $sql = "SELECT p.*, s.service_code, COALESCE(c.name, dl.name) as customer_name, u.full_name as user_name
            FROM payments p
            JOIN services s ON p.service_id = s.id
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN `lsms_pusat`.dealers dl ON s.dealer_id = dl.id
            LEFT JOIN `lsms_pusat`.users u ON p.received_by_user_id = u.id";
    
    $where_clauses = [];
    if (!empty($search)) {
        $where_clauses[] = "(p.payment_code LIKE ? OR s.service_code LIKE ? OR c.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (!empty($date_filter)) {
        $where_clauses[] = "DATE(p.payment_date) = ?";
        $params[] = $date_filter;
    }
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(' AND ', $where_clauses);
    }
    $sql .= " ORDER BY p.payment_date DESC";
}

$payments = [];
if (!empty($sql)) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Gagal memuat data pembayaran: " . $e->getMessage();
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
            <h2 class="fw-bold text-dark mb-4">Riwayat Pembayaran</h2>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" class="d-flex justify-content-end mb-3">
                        <input type="text" name="search" class="form-control me-2" placeholder="Cari Kode / Pelanggan..." value="<?= htmlspecialchars($search) ?>">
                        <input type="date" name="date" class="form-control me-2" style="max-width: 200px;" value="<?= htmlspecialchars($date_filter) ?>">
                        <button type="submit" class="btn btn-outline-primary">Cari</button>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Kode Bayar</th>
                                    <th>Tanggal</th>
                                    <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                    <th>Cabang</th>
                                    <?php endif; ?>
                                    <th>Tiket Service</th>
                                    <th>Pelanggan</th>
                                    <th>Metode</th>
                                    <th class="text-end">Jumlah</th>
                                    <th>Kasir</th>
                                    <th class="text-end pe-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="<?= in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) ? 9 : 8 ?>" class="text-center py-4 text-muted">Belum ada data pembayaran.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($p['payment_code']) ?></td>
                                        <td><?= date('d/M/Y', strtotime($p['payment_date'])) ?></td>
                                        <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                        <td><?= htmlspecialchars($p['branch_name'] ?? $p['branch_code'] ?? '-') ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($p['service_code']) ?></td>
                                        <td><?= htmlspecialchars($p['customer_name']) ?></td>
                                        <td><span class="badge bg-secondary"><?= strtoupper($p['payment_method']) ?></span></td>
                                        <td class="text-end fw-bold">Rp <?= number_format($p['amount'], 0, ',', '.') ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($p['user_name'] ?? 'N/A') ?></td>
                                        <td class="text-end pe-3">
                                            <?php 
                                                $invoice_url = "../service/invoice.php?id=" . urlencode($p['service_id']); 
                                                if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) && isset($p['branch_code'])) {
                                                    $invoice_url .= "&branch=" . urlencode($p['branch_code']);
                                                }
                                            ?>
                                            <a href="<?= $invoice_url ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak Invoice">
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

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
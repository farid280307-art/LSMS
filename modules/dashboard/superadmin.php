<?php
$allowed_roles = ['superadmin'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection('pusat');

// --- 1. STATISTIK GLOBAL (AGREGASI) ---
$total_cabang = $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();
$total_user_aktif = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

$global_stats = [
    'service_today' => 0,
    'revenue_month' => 0,
    'pending_requests' => 0
];

// Data Grafik
$chart_revenue = []; // Harian bulan ini
$chart_branch_service = []; // Service bulan ini per cabang

// Siapkan array tanggal bulan ini
$days_in_month = date('t');
for ($i = 1; $i <= $days_in_month; $i++) {
    $chart_revenue[$i] = 0;
}

global $db_configs;
$db_status = [];

// Ambil nama cabang untuk label yang lebih user-friendly
$branch_map = [];
try {
    $stmt = $pdo->query("SELECT branch_code, branch_name FROM branches");
    $branch_map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* Ignore */ }

// Loop semua cabang untuk agregasi data
foreach ($db_configs as $key => $cfg) {
    $label = ($key === 'pusat') ? 'Pusat' : ($branch_map[$key] ?? ucfirst(str_replace('cabang_', '', $key)));
    $is_online = false;

    try {
        // Tes Koneksi & Ambil Data
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4";
        $options = [PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $pdo_branch = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        $is_online = true;

        if ($key !== 'pusat') {
            // 1. Service Hari Ini
            $stmt = $pdo_branch->query("SELECT COUNT(*) FROM services WHERE DATE(created_at) = CURDATE()");
            $global_stats['service_today'] += $stmt->fetchColumn();

            // 2. Pendapatan Bulan Ini (Total)
            $stmt = $pdo_branch->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");
            $global_stats['revenue_month'] += $stmt->fetchColumn();

            // 3. Data Grafik Pendapatan Harian
            $stmt = $pdo_branch->query("SELECT DAY(payment_date) as day, SUM(amount) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) GROUP BY DAY(payment_date)");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $chart_revenue[$row['day']] += $row['total'];
            }

            // 4. Data Grafik Service per Cabang
            $stmt = $pdo_branch->query("SELECT COUNT(*) FROM services WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
            $chart_branch_service[$label] = $stmt->fetchColumn();
        }

    } catch (PDOException $e) {
        $is_online = false;
    }
    $db_status[] = ['label' => $label, 'status' => $is_online ? 'Online' : 'Offline', 'color' => $is_online ? 'success' : 'danger'];
}

// Request Stok Pending (Pusat)
$stmt = $pdo->query("SELECT COUNT(*) FROM stock_requests WHERE status = 'pending'");
$global_stats['pending_requests'] = $stmt->fetchColumn();

// --- 2. DATA LISTS ---

// Log Aktivitas Terbaru
$logs = $pdo->query("SELECT l.*, u.full_name, r.role_name, b.branch_name
                     FROM system_logs l 
                     LEFT JOIN users u ON l.user_id = u.id 
                     LEFT JOIN roles r ON u.role_id = r.id
                     LEFT JOIN branches b ON u.branch_id = b.id
                     ORDER BY l.log_time DESC LIMIT 7")->fetchAll();

require_once __DIR__ . '/../../views/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/../../views/layouts/sidebar.php'; ?>

<div id="page-content-wrapper">
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white px-4 py-3 sticky-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-light text-primary border-0 d-md-none" id="sidebarToggleMobile"><i class="fas fa-bars fa-lg"></i></button>                
                <button class="btn btn-light text-primary border-0" id="navbarToggleDesktop"><i class="fas fa-bars fa-lg"></i></button>
            </div>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav ms-auto mt-2 mt-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?> (Superadmin)
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <a class="dropdown-item" href="<?= BASE_URL ?>auth/logout.php">Logout</a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid p-4 mt-4">
        <!-- Statistik Global -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Total Cabang</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($total_cabang) ?></h3>
                                <small class="text-muted"><?= number_format($total_user_aktif) ?> User Aktif</small>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-network-wired fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Pendapatan Global (Bln Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0">Rp <?= number_format($global_stats['revenue_month']/1000000, 1, ',', '.') ?>jt</h3>
                                <small class="text-muted">Akumulasi semua cabang</small>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                <i class="fas fa-chart-line fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Service Hari Ini</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($global_stats['service_today']) ?></h3>
                                <small class="text-muted">Unit masuk global</small>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
                                <i class="fas fa-tools fa-lg"></i>
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
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Request Stok Pending</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($global_stats['pending_requests']) ?></h3>
                                <small class="text-muted">Perlu Approval</small>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-dolly-flatbed fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grafik & Status -->
        <div class="row mb-4">
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-area me-2"></i>Tren Pendapatan Global (<?= date('F Y') ?>)</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 300px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-server me-2"></i>Status Database</h6>
                        <span class="badge bg-light text-dark border">Real-time</span>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($db_status as $db): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                <span class="fw-bold text-secondary"><?= htmlspecialchars($db['label']) ?></span>
                                <span class="badge bg-<?= $db['color'] ?>">
                                    <?= $db['status'] ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Log Aktivitas -->
            <div class="col-lg-8 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-shield-alt me-2 text-danger"></i>Log Aktivitas Sistem</h6>
                        <a href="<?= BASE_URL ?>modules/system_logs/index.php" class="btn btn-sm btn-link text-decoration-none">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Waktu</th>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="ps-3 small text-muted"><?= date('d/M H:i', strtotime($log['log_time'])) ?></td>
                                        <td>
                                            <div class="fw-bold small"><?= htmlspecialchars($log['full_name'] ?? 'System') ?></div>
                                            <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($log['branch_name'] ?? 'Pusat') ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['role_name'] ?? '-') ?></span></td>
                                        <td class="small text-truncate" style="max-width: 250px;">
                                            <span class="fw-bold text-<?= $log['action']=='login'?'success':($log['action']=='logout'?'danger':'primary') ?>"><?= strtoupper($log['action']) ?></span>
                                            <span class="text-muted ms-1"><?= htmlspecialchars($log['description']) ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Shortcut & Grafik Cabang -->
            <div class="col-lg-4 mb-4">
                <!-- Shortcut -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-th me-2"></i>Pusat Kontrol</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="<?= BASE_URL ?>modules/users/index.php" class="btn btn-outline-primary text-start"><i class="fas fa-users-cog me-2"></i> Manajemen User & Role</a>
                            <a href="<?= BASE_URL ?>modules/stock_buffer/index.php" class="btn btn-outline-success text-start"><i class="fas fa-boxes me-2"></i> Stok Buffer Pusat</a>
                            <a href="<?= BASE_URL ?>modules/dealers/index.php" class="btn btn-outline-info text-start text-dark"><i class="fas fa-handshake me-2"></i> Manajemen Dealer</a>
                            <a href="<?= BASE_URL ?>modules/riwayat/index.php" class="btn btn-outline-secondary text-start"><i class="fas fa-file-alt me-2"></i> Laporan Lengkap</a>
                        </div>
                    </div>
                </div>

                <!-- Grafik Cabang -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-chart-pie me-2"></i>Service per Cabang (Bln Ini)</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 200px;">
                            <canvas id="branchChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div> <!-- End wrapper -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Revenue Chart
    const ctxRevenue = document.getElementById('revenueChart');
    if (ctxRevenue) {
        new Chart(ctxRevenue, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_keys($chart_revenue)) ?>,
                datasets: [{
                    label: 'Pendapatan Global',
                    data: <?= json_encode(array_values($chart_revenue)) ?>,
                    backgroundColor: 'rgba(13, 202, 240, 0.1)',
                    borderColor: 'rgba(13, 202, 240, 1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(value) { return 'Rp ' + (value/1000000).toFixed(1) + 'jt'; } } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 2. Branch Chart
    const ctxBranch = document.getElementById('branchChart');
    if (ctxBranch) {
        new Chart(ctxBranch, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($chart_branch_service)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($chart_branch_service)) ?>,
                    backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6610f2'],
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
                },
                cutout: '60%'
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
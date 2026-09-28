<?php
$allowed_roles = ['kepala_admin'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection('pusat');

// --- 1. GLOBAL STATS AGGREGATION ---
$stats = [
    'revenue_today' => 0,
    'service_trx_today' => 0,
    'sales_trx_today' => 0,
    'pending_requests' => 0
];

// Get Pending Requests Count (Central DB)
$stmt = $pdo->query("SELECT COUNT(*) FROM stock_requests WHERE status = 'pending'");
$stats['pending_requests'] = $stmt->fetchColumn();

// Get Global Financials (Iterate Branches)
global $db_configs;
$branch_dbs = array_filter($db_configs, function($key) { return strpos($key, 'cabang_') === 0; }, ARRAY_FILTER_USE_KEY);

// Prepare chart data (Last 7 days)
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_data[$date] = 0;
}

foreach ($branch_dbs as $branch_code => $config) {
    $pdo_branch = getDbConnection($branch_code);
    if ($pdo_branch) {
        try {
            // 1. Service Revenue (Payments)
            // Today
            $stmt = $pdo_branch->query("SELECT COUNT(*), SUM(amount) FROM payments WHERE DATE(payment_date) = CURDATE()");
            $res = $stmt->fetch(PDO::FETCH_NUM);
            $stats['service_trx_today'] += $res[0];
            $stats['revenue_today'] += $res[1];

            // Chart Data (Service)
            $stmt = $pdo_branch->query("SELECT DATE(payment_date) as pdate, SUM(amount) FROM payments WHERE payment_date >= DATE(NOW() - INTERVAL 7 DAY) GROUP BY DATE(payment_date)");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if (isset($chart_data[$row[0]])) $chart_data[$row[0]] += $row[1];
            }

            // 2. Direct Sales Revenue (Purchases)
            // Today
            $stmt = $pdo_branch->query("SELECT COUNT(*), SUM(total_price) FROM purchases WHERE DATE(purchase_date) = CURDATE()");
            $res = $stmt->fetch(PDO::FETCH_NUM);
            $stats['sales_trx_today'] += $res[0];
            $stats['revenue_today'] += $res[1];

            // Chart Data (Sales)
            $stmt = $pdo_branch->query("SELECT DATE(purchase_date) as pdate, SUM(total_price) FROM purchases WHERE purchase_date >= DATE(NOW() - INTERVAL 7 DAY) GROUP BY DATE(purchase_date)");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if (isset($chart_data[$row[0]])) $chart_data[$row[0]] += $row[1];
            }

        } catch (PDOException $e) {
            // Ignore connection errors
        }
    }
}

// --- 2. DATA FETCHING ---

// Recent Pending Requests
$stmt = $pdo->query("SELECT r.*, b.branch_name 
                     FROM stock_requests r 
                     LEFT JOIN branches b ON r.branch_code = b.branch_code 
                     WHERE r.status = 'pending' 
                     ORDER BY r.request_date ASC LIMIT 5");
$pending_requests_list = $stmt->fetchAll();

// Recent Admin Logs
$stmt = $pdo->query("SELECT l.*, u.full_name 
                     FROM system_logs l 
                     LEFT JOIN users u ON l.user_id = u.id 
                     WHERE u.role_id IN (SELECT id FROM roles WHERE role_name IN ('admin', 'kepala_admin', 'superadmin')) 
                     ORDER BY l.log_time DESC LIMIT 7");
$admin_logs = $stmt->fetchAll();

// Prepare Chart JS Data
$chart_labels = [];
$chart_values = [];
foreach ($chart_data as $date => $val) {
    $chart_labels[] = date('d M', strtotime($date));
    $chart_values[] = $val;
}

require_once __DIR__ . '/../../views/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/../../views/layouts/sidebar.php'; ?>

<div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg navbar-light bg-white px-4 py-3 shadow-sm sticky-top mb-4">
        <div class="container-fluid">
            <div class="d-flex align-items-center me-3">
                <button class="btn btn-light text-primary border-0 d-md-none" id="sidebarToggleMobile"><i class="fas fa-bars fa-lg"></i></button>
                <button class="btn btn-light text-primary border-0" id="navbarToggleDesktop"><i class="fas fa-bars fa-lg"></i></button>
            </div>
            <div class="fw-bold text-primary">Dashboard Kepala Admin</div>
            <div class="ms-auto">Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?></div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Total Pendapatan (Hari Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0">Rp <?= number_format($stats['revenue_today'], 0, ',', '.') ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                <i class="fas fa-wallet fa-lg"></i>
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
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Transaksi Service</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['service_trx_today']) ?></h3>
                                <small class="text-muted">Selesai hari ini</small>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-tools fa-lg"></i>
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
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Transaksi Penjualan</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['sales_trx_today']) ?></h3>
                                <small class="text-muted">Direct Sales hari ini</small>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
                                <i class="fas fa-shopping-cart fa-lg"></i>
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
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['pending_requests']) ?></h3>
                                <small class="text-muted">Perlu Approval</small>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-truck-loading fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Revenue Chart -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i>Tren Pendapatan (7 Hari Terakhir)</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 300px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Pending Requests Table -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-warning"><i class="fas fa-clipboard-list me-2"></i>Request Stok Menunggu Persetujuan</h6>
                        <a href="<?= BASE_URL ?>modules/stock_buffer/index.php?tab=requests" class="btn btn-sm btn-outline-warning">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Tanggal</th>
                                        <th>Cabang</th>
                                        <th>Item</th>
                                        <th>Qty</th>
                                        <th class="text-end pe-3">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pending_requests_list)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Tidak ada request pending.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pending_requests_list as $req): ?>
                                        <tr>
                                            <td class="ps-3 small"><?= date('d/m H:i', strtotime($req['request_date'])) ?></td>
                                            <td class="fw-bold"><?= htmlspecialchars($req['branch_name']) ?></td>
                                            <td><?= htmlspecialchars($req['item_name']) ?></td>
                                            <td class="fw-bold"><?= number_format($req['quantity']) ?></td>
                                            <td class="text-end pe-3">
                                                <a href="<?= BASE_URL ?>modules/stock_buffer/index.php?tab=requests" class="btn btn-sm btn-primary">Proses</a>
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

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Quick Actions -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-bolt me-2 text-warning"></i>Aksi Cepat</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="<?= BASE_URL ?>modules/stock_buffer/index.php" class="btn btn-outline-primary text-start">
                                <i class="fas fa-boxes me-2"></i> Kelola Stok Pusat
                            </a>
                            <a href="<?= BASE_URL ?>modules/stock_buffer/index.php?tab=requests" class="btn btn-outline-warning text-start text-dark">
                                <i class="fas fa-check-double me-2"></i> Approval Request Stok
                            </a>
                            <a href="<?= BASE_URL ?>modules/dealers/index.php" class="btn btn-outline-info text-start text-dark">
                                <i class="fas fa-handshake me-2"></i> Kelola Dealer
                            </a>
                            <a href="<?= BASE_URL ?>modules/riwayat/index.php" class="btn btn-outline-secondary text-start">
                                <i class="fas fa-history me-2"></i> Lihat Laporan Lengkap
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Admin Logs -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-user-shield me-2 text-danger"></i>Aktivitas Admin</h6>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if (empty($admin_logs)): ?>
                                <li class="list-group-item text-center text-muted py-3">Belum ada aktivitas.</li>
                            <?php else: ?>
                                <?php foreach ($admin_logs as $log): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <span class="fw-bold small text-dark"><?= htmlspecialchars($log['full_name']) ?></span>
                                        <span class="text-muted" style="font-size: 0.75rem;"><?= date('d/m H:i', strtotime($log['log_time'])) ?></span>
                                    </div>
                                    <div class="small text-secondary lh-sm">
                                        <span class="badge bg-light text-dark border me-1"><?= strtoupper($log['action']) ?></span>
                                        <?= htmlspecialchars($log['description']) ?>
                                    </div>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('revenueChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Total Pendapatan (Global)',
                    data: <?= json_encode($chart_values) ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: 'rgba(25, 135, 84, 1)',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
});
</script>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

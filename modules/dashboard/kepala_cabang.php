<?php
$allowed_roles = ['kepala_cabang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection(); // Otomatis connect ke cabang user
$my_branch = $_SESSION['user']['cabang'];

// 1. Statistik Service
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_all_time,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as total_today,
    SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as total_month,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status IN ('diagnosis', 'on_progress', 'waiting_sparepart') THEN 1 ELSE 0 END) as processing,
    SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) as finished,
    SUM(CASE WHEN status = 'taken' THEN 1 ELSE 0 END) as taken,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
FROM services");
$svc_stats = $stmt->fetch();

// 2. Pendapatan Cabang (Bulan Ini)
$stmt = $pdo->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");
$revenue_month = $stmt->fetchColumn() ?: 0;

// 3. Grafik Pendapatan Harian (Bulan Ini)
$chart_labels = [];
$chart_data = [];
$days_in_month = date('t');
for ($i = 1; $i <= $days_in_month; $i++) {
    $chart_labels[] = $i;
    $chart_data[$i] = 0;
}

$stmt = $pdo->query("SELECT DAY(payment_date) as day, SUM(amount) as total 
                     FROM payments 
                     WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) 
                     GROUP BY DAY(payment_date)");
while ($row = $stmt->fetch()) {
    $chart_data[$row['day']] = $row['total'];
}

// 4. Stok Menipis
$stmt = $pdo->query("SELECT * FROM stock_buffer_cabang WHERE stock < 5 ORDER BY stock ASC LIMIT 5");
$low_stock = $stmt->fetchAll();

// 5. Service Terbaru
$stmt = $pdo->query("SELECT s.service_code, s.status, c.name as customer_name, d.model 
                     FROM services s 
                     LEFT JOIN customers c ON s.customer_id = c.id 
                     JOIN devices d ON s.device_id = d.id 
                     ORDER BY s.created_at DESC LIMIT 5");
$recent_services = $stmt->fetchAll();

// 6. Riwayat Request ke Pusat (Perlu koneksi ke Pusat)
$pdo_pusat = getDbConnection('pusat');
$stmt = $pdo_pusat->prepare("SELECT * FROM stock_requests WHERE branch_code = ? ORDER BY request_date DESC LIMIT 5");
$stmt->execute([$my_branch]);
$my_requests = $stmt->fetchAll();

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
            <div class="fw-bold text-primary">Dashboard Kepala Cabang</div>
            <div class="ms-auto">Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?></div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        <!-- Kartu Statistik Utama -->
        <div class="row g-4 mb-4">
            <!-- Pendapatan -->
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Pendapatan (Bulan Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0">Rp <?= number_format($revenue_month, 0, ',', '.') ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                <i class="fas fa-wallet fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Service Hari Ini -->
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Service Masuk (Hari Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($svc_stats['total_today']) ?></h3>
                                <small class="text-muted">Total Bulan Ini: <?= number_format($svc_stats['total_month']) ?></small>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-clipboard-list fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Sedang Diproses -->
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Sedang Diproses</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($svc_stats['processing']) ?></h3>
                                <small class="text-muted">Pending: <?= number_format($svc_stats['pending']) ?></small>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
                                <i class="fas fa-cogs fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Selesai (Belum Diambil) -->
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Selesai (Belum Diambil)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($svc_stats['finished']) ?></h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-check-circle fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grafik & Visualisasi -->
        <div class="row mb-4">
            <!-- Grafik Pendapatan -->
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i>Tren Pendapatan Harian (<?= date('F Y') ?>)</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 300px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Grafik Status Service -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-pie me-2"></i>Proporsi Status Service</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 300px; position: relative;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail Data -->
        <div class="row">
            <!-- Service Terbaru -->
            <div class="col-lg-5 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark">Service Terbaru</h6>
                        <a href="<?= BASE_URL ?>modules/service/index.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Kode</th>
                                        <th>Pelanggan</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_services as $s): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary"><?= $s['service_code'] ?></td>
                                        <td>
                                            <div class="fw-bold small"><?= htmlspecialchars($s['customer_name']) ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($s['model']) ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_color = match($s['status']) {
                                                'pending' => 'secondary',
                                                'diagnosis', 'on_progress', 'waiting_sparepart' => 'primary',
                                                'finished' => 'success',
                                                'taken' => 'dark',
                                                default => 'light'
                                            };
                                            ?>
                                            <span class="badge bg-<?= $badge_color ?>"><?= strtoupper(str_replace('_', ' ', $s['status'])) ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stok Menipis -->
            <div class="col-lg-4 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Stok Menipis</h6>
                        <a href="<?= BASE_URL ?>modules/stock_device/index.php" class="btn btn-sm btn-outline-danger">Kelola</a>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(empty($low_stock)): ?>
                                <li class="list-group-item text-center text-muted py-3">Stok aman.</li>
                            <?php else: ?>
                            <?php foreach($low_stock as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($s['part_name']) ?></span>
                                <span class="badge bg-danger rounded-pill"><?= $s['stock'] ?></span>
                            </li>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Riwayat Request -->
            <div class="col-lg-3 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-warning"><i class="fas fa-history me-2"></i>Request Stok</h6>
                        <a href="<?= BASE_URL ?>modules/stock_device/index.php?tab=requests" class="btn btn-sm btn-outline-warning">Baru</a>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(empty($my_requests)): ?>
                                <li class="list-group-item text-center text-muted py-3">Belum ada request.</li>
                            <?php else: ?>
                                <?php foreach($my_requests as $r): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold small"><?= htmlspecialchars($r['item_name']) ?></span>
                                        <span class="badge bg-<?= $r['status']=='approved'?'success':($r['status']=='rejected'?'danger':'warning') ?> rounded-pill" style="font-size: 0.65rem;"><?= strtoupper($r['status']) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between small text-muted">
                                        <span>Qty: <?= $r['quantity'] ?></span>
                                        <span><?= date('d/m', strtotime($r['request_date'])) ?></span>
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
    // 1. Revenue Chart
    const ctxRevenue = document.getElementById('revenueChart');
    if (ctxRevenue) {
        new Chart(ctxRevenue, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Pendapatan Harian',
                    data: <?= json_encode(array_values($chart_data)) ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: 'rgba(25, 135, 84, 1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(value) { return 'Rp ' + (value/1000) + 'k'; } } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 2. Status Chart
    const ctxStatus = document.getElementById('statusChart');
    if (ctxStatus) {
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Proses', 'Selesai', 'Diambil', 'Batal'],
                datasets: [{
                    data: [
                        <?= $svc_stats['pending'] ?>, 
                        <?= $svc_stats['processing'] ?>, 
                        <?= $svc_stats['finished'] ?>, 
                        <?= $svc_stats['taken'] ?>, 
                        <?= $svc_stats['cancelled'] ?>
                    ],
                    backgroundColor: ['#6c757d', '#0dcaf0', '#198754', '#212529', '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                },
                cutout: '70%'
            }
        });
    }
});
</script>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

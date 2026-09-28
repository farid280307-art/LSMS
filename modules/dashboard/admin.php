<?php
$allowed_roles = ['admin'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();

// 1. Statistik Service & Pendapatan
$stmt = $pdo->query("SELECT 
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as svc_today,
    SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as svc_month,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status IN ('diagnosis', 'on_progress') THEN 1 ELSE 0 END) as processing,
    SUM(CASE WHEN status = 'waiting_sparepart' THEN 1 ELSE 0 END) as waiting,
    SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) as ready,
    SUM(CASE WHEN status = 'taken' THEN 1 ELSE 0 END) as taken
FROM services");
$stats = $stmt->fetch();

// Pendapatan
$stmt = $pdo->query("SELECT 
    SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END) as rev_today,
    SUM(CASE WHEN MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) THEN amount ELSE 0 END) as rev_month
FROM payments");
$rev_stats = $stmt->fetch();

// 2. Grafik Pendapatan Harian (Bulan Ini)
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

// 3. Service Siap Bayar / Ambil (Finished)
$stmt = $pdo->query("SELECT s.id, s.service_code, c.name as customer_name, d.model, 
                     (SELECT SUM(price) FROM service_items WHERE service_id = s.id) as est_cost
                     FROM services s 
                     LEFT JOIN customers c ON s.customer_id = c.id 
                     JOIN devices d ON s.device_id = d.id 
                     WHERE s.status = 'finished' 
                     ORDER BY s.updated_at DESC LIMIT 5");
$ready_services = $stmt->fetchAll();

// 4. Service Terbaru Masuk
$stmt = $pdo->query("SELECT s.service_code, s.status, c.name as customer_name, s.created_at 
                     FROM services s 
                     LEFT JOIN customers c ON s.customer_id = c.id 
                     ORDER BY s.created_at DESC LIMIT 5");
$recent_services = $stmt->fetchAll();

// 5. Stok Menipis
$stmt = $pdo->query("SELECT part_name, stock FROM stock_buffer_cabang WHERE stock < 5 ORDER BY stock ASC LIMIT 5");
$low_stock = $stmt->fetchAll();

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
            <div class="fw-bold text-primary">Dashboard Admin</div>
            <div class="ms-auto">Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?></div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        <!-- Statistik Utama -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Service Masuk (Hari Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['svc_today']) ?></h3>
                                <small class="text-muted">Bulan ini: <?= number_format($stats['svc_month']) ?></small>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-clipboard-list fa-lg"></i>
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
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Pendapatan (Hari Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0">Rp <?= number_format($rev_stats['rev_today'], 0, ',', '.') ?></h3>
                                <small class="text-muted">Bulan ini: Rp <?= number_format($rev_stats['rev_month']/1000000, 1, ',', '.') ?>jt</small>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                <i class="fas fa-wallet fa-lg"></i>
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
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Sedang Diproses</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['processing']) ?></h3>
                                <small class="text-muted">Pending: <?= number_format($stats['pending']) ?></small>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
                                <i class="fas fa-cogs fa-lg"></i>
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
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Siap Diambil</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['ready']) ?></h3>
                                <small class="text-muted">Menunggu pembayaran</small>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-check-circle fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <!-- Grafik Pendapatan -->
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i>Tren Pendapatan Harian</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 250px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Grafik Status -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-pie me-2"></i>Status Service</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 250px; position: relative;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Kolom Kiri: Service Terbaru -->
            <div class="col-lg-6 mb-4">
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
                                        <td><?= htmlspecialchars($s['customer_name']) ?></td>
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

            <!-- Kolom Kanan: Siap Bayar & Aksi Cepat -->
            <div class="col-lg-6">
                <!-- Siap Bayar -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-success"><i class="fas fa-cash-register me-2"></i>Siap Diambil / Bayar</h6>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(empty($ready_services)): ?>
                                <li class="list-group-item text-center text-muted py-3">Tidak ada unit menunggu pembayaran.</li>
                            <?php else: ?>
                                <?php foreach($ready_services as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold"><?= $s['service_code'] ?> - <?= htmlspecialchars($s['customer_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($s['model']) ?></small>
                                    </div>
                                    <a href="<?= BASE_URL ?>modules/service/pay.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-success">
                                        Bayar Rp <?= number_format($s['est_cost'] ?? 0, 0, ',', '.') ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="row">
                    <!-- Aksi Cepat -->
                    <div class="col-md-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted fw-bold mb-3">Aksi Cepat</h6>
                                <div class="d-grid gap-2">
                                    <a href="<?= BASE_URL ?>modules/service/create.php" class="btn btn-primary"><i class="fas fa-plus-circle me-2"></i>Service Baru</a>
                                    <a href="<?= BASE_URL ?>modules/purchases/index.php" class="btn btn-outline-primary"><i class="fas fa-shopping-cart me-2"></i>Penjualan Langsung</a>
                                    <a href="<?= BASE_URL ?>modules/stock_device/index.php" class="btn btn-outline-info"><i class="fas fa-boxes me-2"></i>Cek Stok</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Stok Menipis -->
                    <div class="col-md-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white fw-bold text-danger py-2">Stok Menipis</div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php if(empty($low_stock)): ?>
                                        <li class="list-group-item text-center text-muted small py-2">Aman.</li>
                                    <?php else: ?>
                                        <?php foreach($low_stock as $s): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                            <span class="small text-truncate" style="max-width: 100px;"><?= htmlspecialchars($s['part_name']) ?></span>
                                            <span class="badge bg-danger rounded-pill"><?= $s['stock'] ?></span>
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
                    label: 'Pendapatan',
                    data: <?= json_encode(array_values($chart_data)) ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 2,
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
                labels: ['Pending', 'Proses', 'Tunggu Part', 'Selesai'],
                datasets: [{
                    data: [
                        <?= $stats['pending'] ?>, 
                        <?= $stats['processing'] ?>, 
                        <?= $stats['waiting'] ?>, 
                        <?= $stats['ready'] ?>
                    ],
                    backgroundColor: ['#6c757d', '#0dcaf0', '#ffc107', '#198754'],
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
                },
                cutout: '65%'
            }
        });
    }
});
</script>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

<?php
$allowed_roles = ['kepala_pusat'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection('pusat');

// 1. Ringkasan Global (Agregasi dari semua cabang)
$total_sales_month = 0;
$total_service_today = 0;
$total_service_month = 0;
$total_revenue_global = 0;
$service_per_branch = [];

global $db_configs;
$branch_dbs = array_filter($db_configs, function($key) { return strpos($key, 'cabang_') === 0; }, ARRAY_FILTER_USE_KEY);

foreach ($branch_dbs as $branch_code => $config) {
    $pdo_branch = getDbConnection($branch_code);
    if ($pdo_branch) {
        try {
            // Service Hari Ini
            $stmt = $pdo_branch->query("SELECT COUNT(*) FROM services WHERE DATE(created_at) = CURDATE()");
            $total_service_today += $stmt->fetchColumn();

            // Service Bulan Ini
            $stmt = $pdo_branch->query("SELECT COUNT(*) FROM services WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
            $cnt_month = $stmt->fetchColumn();
            $total_service_month += $cnt_month;

            // Pendapatan Global
            $stmt = $pdo_branch->query("SELECT SUM(amount) FROM payments");
            $total_revenue_global += $stmt->fetchColumn();

            // Total Penjualan Langsung (Direct Sales) Bulan Ini
            $stmt = $pdo_branch->query("SELECT SUM(total_price) FROM purchases WHERE MONTH(purchase_date) = MONTH(CURRENT_DATE()) AND YEAR(purchase_date) = YEAR(CURRENT_DATE())");
            $total_sales_month += $stmt->fetchColumn();

            // Data Grafik Service per Cabang
            $stmt_name = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_code = ?");
            $stmt_name->execute([$branch_code]);
            $b_name = $stmt_name->fetchColumn() ?: $branch_code;
            $service_per_branch[$b_name] = $cnt_month;
        } catch (PDOException $e) {
            // Abaikan cabang jika tabel tidak ada
        }
    }
}

// 2. Stok Pusat (Buffer) - Warning
$stmt_device = $pdo->query("SELECT * FROM stock_buffer_pusat WHERE quantity < 10 AND item_type = 'device' ORDER BY quantity ASC LIMIT 5");
$low_stock_device = $stmt_device->fetchAll();

$stmt_sparepart = $pdo->query("SELECT * FROM stock_buffer_pusat WHERE quantity < 10 AND item_type = 'sparepart' ORDER BY quantity ASC LIMIT 5");
$low_stock_sparepart = $stmt_sparepart->fetchAll();

// 3. Request Stok Pending
$stmt = $pdo->query("SELECT r.*, b.branch_name FROM stock_requests r LEFT JOIN branches b ON r.branch_code = b.branch_code WHERE r.status = 'pending' ORDER BY r.request_date DESC LIMIT 5");
$pending_requests = $stmt->fetchAll();

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
            <div class="fw-bold text-primary">Dashboard Kepala Pusat</div>
            <div class="ms-auto">Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?></div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        <!-- Ringkasan -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 bg-primary text-white">
                    <div class="card-body">
                        <h6 class="text-uppercase opacity-75">Total Service (Hari Ini)</h6>
                        <h2 class="fw-bold mb-0"><?= number_format($total_service_today) ?></h2>
                        <small>Total bulan ini: <?= number_format($total_service_month) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 bg-success text-white">
                    <div class="card-body">
                        <h6 class="text-uppercase opacity-75">Total Pendapatan Global (Service)</h6>
                        <h2 class="fw-bold mb-0">Rp <?= number_format($total_revenue_global, 0, ',', '.') ?></h2>
                        <small>Akumulasi dari semua cabang</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 bg-info text-white">
                    <div class="card-body">
                        <h6 class="text-uppercase opacity-75">Total Penjualan (Direct)</h6>
                        <h2 class="fw-bold mb-0">Rp <?= number_format($total_sales_month, 0, ',', '.') ?></h2>
                        <small>Total bulan ini</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 bg-warning text-dark">
                    <div class="card-body">
                        <h6 class="text-uppercase opacity-75">Request Stok Pending</h6>
                        <h2 class="fw-bold mb-0"><?= count($pending_requests) ?></h2>
                        <small>Perlu persetujuan</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Stok Kritis -->
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Stok Pusat Menipis</div>
                    <div class="card-body p-0">
                        <h6 class="small text-uppercase fw-bold text-muted px-3 pt-3">Device (< 10)</h6>
                        <ul class="list-group list-group-flush">
                            <?php if(empty($low_stock_device)): ?>
                                <li class="list-group-item text-muted py-2"><small>Stok device aman.</small></li>
                            <?php else: ?>
                                <?php foreach($low_stock_device as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center"><?= htmlspecialchars($s['item_name']) ?> <span class="badge bg-danger rounded-pill"><?= $s['quantity'] ?></span></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                        <h6 class="small text-uppercase fw-bold text-muted px-3 pt-3 border-top">Sparepart (< 10)</h6>
                        <ul class="list-group list-group-flush">
                            <?php if(empty($low_stock_sparepart)): ?>
                                <li class="list-group-item text-muted py-2"><small>Stok sparepart aman.</small></li>
                            <?php else: ?>
                                <?php foreach($low_stock_sparepart as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center"><?= htmlspecialchars($s['item_name']) ?> <span class="badge bg-danger rounded-pill"><?= $s['quantity'] ?></span></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="card-footer bg-white text-center">
                        <a href="<?= BASE_URL ?>modules/stock_buffer/index.php" class="text-decoration-none small">Kelola Stok Pusat &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Request Stok -->
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-bold text-primary"><i class="fas fa-truck-loading me-2"></i>Request Stok Cabang</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Cabang</th>
                                        <th>Item</th>
                                        <th>Qty</th>
                                        <th>Tgl</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($pending_requests)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada request pending.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($pending_requests as $r): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold"><?= htmlspecialchars($r['branch_name']) ?></td>
                                            <td><?= htmlspecialchars($r['item_name']) ?></td>
                                            <td><?= number_format($r['quantity']) ?></td>
                                            <td class="small text-muted"><?= date('d/m', strtotime($r['request_date'])) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-white text-center">
                        <a href="<?= BASE_URL ?>modules/stock_buffer/index.php?tab=requests" class="text-decoration-none small">Lihat Semua Request &rarr;</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grafik Service per Cabang (Placeholder) -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Performa Service Cabang (Bulan Ini)</div>
            <div class="card-body" style="height: 300px;">
                <canvas id="serviceBranchChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('serviceBranchChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($service_per_branch)) ?>,
                datasets: [{
                    label: 'Jumlah Service',
                    data: <?= json_encode(array_values($service_per_branch)) ?>,
                    backgroundColor: 'rgba(37, 99, 235, 0.8)',
                    borderColor: 'rgba(37, 99, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

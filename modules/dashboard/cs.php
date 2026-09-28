<?php
$allowed_roles = ['cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();

// 1. Statistik Ringkas
$stmt = $pdo->query("SELECT 
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as svc_today,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) as ready_pickup,
    SUM(CASE WHEN status = 'diagnosis' THEN 1 ELSE 0 END) as diagnosis
FROM services");
$stats = $stmt->fetch();

// Cek ketersediaan kolom dealer_id untuk kompatibilitas
$db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
$has_dealer = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'services' AND COLUMN_NAME = 'dealer_id'")->fetchColumn();

$customer_col = "c.name";
$phone_col = "c.phone";
$dealer_join = "";
if ($has_dealer) {
    $customer_col = "COALESCE(c.name, dl.name)";
    $phone_col = "COALESCE(c.phone, dl.phone)";
    $dealer_join = "LEFT JOIN lsms_pusat.dealers dl ON s.dealer_id = dl.id";
}

// 2. Daftar Service Siap Diambil (Finished) - Prioritas CS untuk menghubungi customer
$stmt = $pdo->query("SELECT s.id, s.service_code, $customer_col as customer_name, $phone_col as phone, d.model, s.updated_at
                     FROM services s 
                     LEFT JOIN customers c ON s.customer_id = c.id 
                     $dealer_join
                     JOIN devices d ON s.device_id = d.id 
                     WHERE s.status = 'finished' 
                     ORDER BY s.updated_at DESC LIMIT 5");
$ready_services = $stmt->fetchAll();

// 3. Service Terbaru Masuk
$stmt = $pdo->query("SELECT s.service_code, s.status, $customer_col as customer_name, s.created_at 
                     FROM services s 
                     LEFT JOIN customers c ON s.customer_id = c.id 
                     $dealer_join
                     ORDER BY s.created_at DESC LIMIT 5");
$recent_services = $stmt->fetchAll();

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
            <div class="fw-bold text-primary"><i class="fas fa-headset me-2"></i>Dashboard Customer Service</div>
            <div class="ms-auto">Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?></div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        <!-- Statistik Utama -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Service Masuk (Hari Ini)</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['svc_today']) ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-clipboard-list fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Menunggu Diagnosis</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['pending'] + $stats['diagnosis']) ?></h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-clock fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Siap Diambil</h6>
                                <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['ready_pickup']) ?></h3>
                                <small class="text-success fw-bold">Hubungi Customer!</small>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                <i class="fas fa-check-circle fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Kolom Kiri: Data & List -->
            <div class="col-lg-8">
                <!-- Siap Diambil (Prioritas) -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-success"><i class="fas fa-phone-alt me-2"></i>Unit Selesai (Hubungi Pelanggan)</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Tiket</th>
                                        <th>Pelanggan</th>
                                        <th>Unit</th>
                                        <th class="text-end pe-3">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($ready_services)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">Tidak ada unit yang menunggu diambil.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($ready_services as $s): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold"><?= $s['service_code'] ?></td>
                                            <td>
                                                <div class="fw-bold small"><?= htmlspecialchars($s['customer_name'] ?? 'Tanpa Nama') ?></div>
                                                <a href="https://wa.me/<?= preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $s['phone'] ?? '')) ?>" target="_blank" class="text-decoration-none small text-success">
                                                    <i class="fab fa-whatsapp me-1"></i> <?= htmlspecialchars($s['phone'] ?? '-') ?>
                                                </a>
                                            </td>
                                            <td class="small text-muted"><?= htmlspecialchars($s['model']) ?></td>
                                            <td class="text-end pe-3">
                                                <a href="<?= BASE_URL ?>modules/service/view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Service Terbaru -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark">Input Terbaru</h6>
                        <a href="<?= BASE_URL ?>modules/service/index.php" class="btn btn-sm btn-link text-decoration-none">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Tiket</th>
                                        <th>Pelanggan</th>
                                        <th>Status</th>
                                        <th class="text-end pe-3">Waktu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($recent_services)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($recent_services as $s): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-primary"><?= $s['service_code'] ?></td>
                                            <td><?= htmlspecialchars($s['customer_name'] ?? 'Tanpa Nama') ?></td>
                                            <td>
                                                <?php
                                                $badge_color = match($s['status']) {
                                                    'pending' => 'secondary',
                                                    'diagnosis' => 'info',
                                                    'on_progress' => 'primary',
                                                    'waiting_sparepart' => 'warning',
                                                    'finished' => 'success',
                                                    'taken' => 'dark',
                                                    default => 'light'
                                                };
                                                ?>
                                                <span class="badge bg-<?= $badge_color ?> rounded-pill" style="font-size: 0.7rem;"><?= strtoupper(str_replace('_', ' ', $s['status'])) ?></span>
                                            </td>
                                            <td class="text-end pe-3 small text-muted"><?= date('H:i', strtotime($s['created_at'])) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kolom Kanan: Aksi & Tools -->
            <div class="col-lg-4">
                <!-- Input Service Baru (Big Button) -->
                <div class="card border-0 shadow-sm mb-4 bg-primary text-white">
                    <div class="card-body text-center p-4">
                        <h5 class="fw-bold mb-3">Customer Baru?</h5>
                        <a href="<?= BASE_URL ?>modules/service/create.php" class="btn btn-light text-primary fw-bold w-100 py-2 shadow-sm">
                            <i class="fas fa-plus-circle me-2"></i> Input Service
                        </a>
                    </div>
                </div>

                <!-- Cek Status -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-search me-2"></i>Cek Status Service</h6>
                    </div>
                    <div class="card-body">
                        <form action="<?= BASE_URL ?>modules/service/index.php" method="GET">
                            <div class="input-group">
                                <input type="text" name="search" class="form-control" placeholder="Masukkan Kode / Nama / SN..." required>
                                <button class="btn btn-primary" type="submit">Cari</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

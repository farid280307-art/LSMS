<?php
$allowed_roles = ['teknisi'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();
$user_id = $_SESSION['user']['id'];

// Handle Quick Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_update') {
    $svc_id = $_POST['service_id'];
    $new_status = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE services SET status = ? WHERE id = ? AND technician_id = ?");
    $stmt->execute([$new_status, $svc_id, $user_id]);
    
    // Log
    $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'status_update', ?, ?, NOW())")
        ->execute([$svc_id, "Quick update: " . strtoupper(str_replace('_', ' ', $new_status)), $user_id]);
    
    header("Location: " . BASE_URL . "modules/dashboard/teknisi.php"); // Refresh
    exit;
}

// Daftar Service Assigned
$stmt = $pdo->prepare("SELECT s.*, d.brand, d.model, d.serial_number, c.name as customer_name,
                       (SELECT GROUP_CONCAT(description SEPARATOR ', ') FROM service_items WHERE service_id = s.id AND item_type = 'sparepart') as spareparts_used
                       FROM services s 
                       JOIN devices d ON s.device_id = d.id 
                       LEFT JOIN customers c ON s.customer_id = c.id 
                       WHERE s.technician_id = ? AND s.status NOT IN ('taken', 'cancelled')
                       ORDER BY FIELD(s.status, 'diagnosis', 'on_progress', 'waiting_sparepart', 'finished'), s.updated_at DESC");
$stmt->execute([$user_id]);
$my_jobs = $stmt->fetchAll();

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
            <div class="fw-bold text-primary"><i class="fas fa-tools me-2"></i>Dashboard Teknisi</div>
            <div class="ms-auto">Halo, <?= htmlspecialchars($_SESSION['user']['full_name']) ?></div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0">Antrian Pekerjaan</h4>
            <span class="badge bg-primary rounded-pill fs-6"><?= count($my_jobs) ?> Unit</span>
        </div>

        <div class="row">
            <?php if(empty($my_jobs)): ?>
                <div class="col-12 text-center py-5 text-muted">
                    <div class="mb-3"><i class="fas fa-clipboard-check fa-4x text-light"></i></div>
                    <h5>Tidak ada pekerjaan aktif.</h5>
                    <p>Semua service telah diselesaikan atau belum ada yang ditugaskan.</p>
                </div>
            <?php else: ?>
                <?php foreach($my_jobs as $job): ?>
                <div class="col-md-6 col-xl-4 mb-4">
                    <div class="card border-0 shadow-sm h-100">
                        <!-- Header Status -->
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <span class="fw-bold text-primary">
                                <i class="fas fa-ticket-alt me-1"></i> <?= $job['service_code'] ?>
                            </span>
                            <?php
                                $status_badges = [
                                    'diagnosis' => 'bg-info text-white',
                                    'on_progress' => 'bg-primary',
                                    'waiting_sparepart' => 'bg-warning text-dark',
                                    'finished' => 'bg-success'
                                ];
                                $badge_class = $status_badges[$job['status']] ?? 'bg-secondary';
                            ?>
                            <span class="badge <?= $badge_class ?>">
                                <?= strtoupper(str_replace('_', ' ', $job['status'])) ?>
                            </span>
                        </div>

                        <div class="card-body">
                            <!-- Info Unit -->
                            <h5 class="card-title fw-bold mb-1"><?= htmlspecialchars($job['brand'] . ' ' . $job['model']) ?></h5>
                            <p class="text-muted small mb-3"><i class="fas fa-barcode me-1"></i> SN: <?= htmlspecialchars($job['serial_number']) ?></p>
                            
                            <!-- Masalah -->
                            <div class="mb-3">
                                <small class="text-muted fw-bold text-uppercase">Keluhan:</small>
                                <p class="mb-0 bg-light p-2 rounded small text-dark border">
                                    <?= nl2br(htmlspecialchars($job['reported_problem'])) ?>
                                </p>
                            </div>

                            <!-- Sparepart -->
                            <?php if(!empty($job['spareparts_used'])): ?>
                            <div class="mb-3">
                                <small class="text-muted fw-bold text-uppercase">Sparepart Terpasang:</small>
                                <p class="mb-0 small text-success"><i class="fas fa-check-circle me-1"></i> <?= htmlspecialchars($job['spareparts_used']) ?></p>
                            </div>
                            <?php endif; ?>

                            <hr class="my-3">
                            
                            <!-- Action Buttons -->
                            <div class="d-grid gap-2">
                                <?php if($job['status'] == 'diagnosis'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="quick_update">
                                        <input type="hidden" name="service_id" value="<?= $job['id'] ?>">
                                        <input type="hidden" name="status" value="on_progress">
                                        <button class="btn btn-primary w-100">
                                            <i class="fas fa-play me-2"></i>Mulai Pengerjaan
                                        </button>
                                    </form>
                                <?php elseif($job['status'] == 'on_progress'): ?>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="quick_update">
                                                <input type="hidden" name="service_id" value="<?= $job['id'] ?>">
                                                <input type="hidden" name="status" value="waiting_sparepart">
                                                <button class="btn btn-warning w-100 text-dark" title="Tunggu Sparepart">
                                                    <i class="fas fa-pause me-1"></i> Pending
                                                </button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="quick_update">
                                                <input type="hidden" name="service_id" value="<?= $job['id'] ?>">
                                                <input type="hidden" name="status" value="finished">
                                                <button class="btn btn-success w-100" title="Selesai">
                                                    <i class="fas fa-check me-1"></i> Selesai
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php elseif($job['status'] == 'waiting_sparepart'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="quick_update">
                                        <input type="hidden" name="service_id" value="<?= $job['id'] ?>">
                                        <input type="hidden" name="status" value="on_progress">
                                        <button class="btn btn-primary w-100">
                                            <i class="fas fa-play me-2"></i>Lanjut Kerjakan
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-secondary w-100" disabled>
                                        <i class="fas fa-clock me-2"></i>Menunggu Diambil
                                    </button>
                                <?php endif; ?>
                                
                                <a href="<?= BASE_URL ?>modules/service/view.php?id=<?= $job['id'] ?>" class="btn btn-outline-secondary btn-sm mt-1">
                                    Lihat Detail & Input Sparepart
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

</div> <!-- End wrapper -->

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

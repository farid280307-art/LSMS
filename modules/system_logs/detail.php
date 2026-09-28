<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
$pdo_pusat = getDbConnection('pusat');

// Ambil detail log
$sql = "SELECT l.*, u.full_name as user_name, u.email, r.role_name, b.branch_name 
        FROM system_logs l
        LEFT JOIN users u ON l.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE l.id = ?";

$stmt = $pdo_pusat->prepare($sql);
$stmt->execute([$id]);
$log = $stmt->fetch();

if (!$log) {
    header("Location: index.php");
    exit();
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="fw-bold text-dark m-0">Detail Log Sistem</h2>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> Kembali</a>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary">Log ID: #<?= $log['id'] ?></h5>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase small fw-bold">Waktu Kejadian</h6>
                            <p class="fs-5 mb-0"><?= date('d F Y H:i:s', strtotime($log['log_time'])) ?></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h6 class="text-muted text-uppercase small fw-bold">Jenis Aksi</h6>
                            <span class="badge bg-<?= $log['action'] == 'login' ? 'success' : ($log['action'] == 'logout' ? 'danger' : 'info') ?> fs-6">
                                <?= strtoupper($log['action']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <hr>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase small fw-bold">User</h6>
                            <p class="mb-1 fw-bold fs-5"><?= htmlspecialchars($log['user_name'] ?? 'Unknown') ?></p>
                            <p class="text-muted mb-0"><?= htmlspecialchars($log['email'] ?? '-') ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase small fw-bold">Role & Cabang</h6>
                            <p class="mb-1"><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['role_name'] ?? '-') ?></span></p>
                            <p class="text-muted mb-0"><?= htmlspecialchars($log['branch_name'] ?? 'Pusat') ?></p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="text-muted text-uppercase small fw-bold">Deskripsi</h6>
                        <div class="p-3 bg-light rounded border">
                            <?= nl2br(htmlspecialchars($log['description'])) ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h6 class="text-muted text-uppercase small fw-bold">IP Address</h6>
                            <p class="font-monospace mb-0"><?= htmlspecialchars($log['ip_address']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase small fw-bold">User Agent (Perangkat)</h6>
                            <p class="small text-muted mb-0"><?= htmlspecialchars($log['user_agent']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
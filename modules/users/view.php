<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection('pusat');
$role = $_SESSION['user']['role'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php?msg=not_found');
    exit();
}

$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.is_active, u.branch_id,
                              r.role_name,
                              b.branch_name
                       FROM users u
                       JOIN roles r ON u.role_id = r.id
                       LEFT JOIN branches b ON u.branch_id = b.id
                       WHERE u.id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: index.php?msg=not_found');
    exit();
}

if ($role === 'kepala_cabang') {
    $stmt_my_branch = $pdo->prepare("SELECT id FROM branches WHERE branch_code = ?");
    $stmt_my_branch->execute([$_SESSION['user']['cabang']]);
    $my_branch_id = $stmt_my_branch->fetchColumn();

    if ((string)$user['branch_id'] !== (string)$my_branch_id) {
        header('Location: index.php?msg=forbidden');
        exit();
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
            <h2 class="fw-bold text-dark mb-4">Detail User</h2>

            <div class="card shadow-sm">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-md-3 text-muted">ID</dt>
                        <dd class="col-md-9"><?= (int)$user['id'] ?></dd>

                        <dt class="col-md-3 text-muted">Nama Lengkap</dt>
                        <dd class="col-md-9"><?= htmlspecialchars($user['full_name']) ?></dd>

                        <dt class="col-md-3 text-muted">Email</dt>
                        <dd class="col-md-9"><?= htmlspecialchars($user['email']) ?></dd>

                        <dt class="col-md-3 text-muted">Role</dt>
                        <dd class="col-md-9"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role_name']))) ?></dd>

                        <dt class="col-md-3 text-muted">Cabang</dt>
                        <dd class="col-md-9"><?= htmlspecialchars($user['branch_name'] ?? 'Pusat') ?></dd>

                        <dt class="col-md-3 text-muted">Status</dt>
                        <dd class="col-md-9">
                            <?php if ($user['is_active']): ?>
                                <span class="badge bg-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Nonaktif</span>
                            <?php endif; ?>
                        </dd>
                    </dl>

                    <div class="d-flex gap-2 mt-4">
                        <a href="edit.php?id=<?= (int)$user['id'] ?>" class="btn btn-primary">Edit</a>
                        <a href="index.php" class="btn btn-light">Kembali</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

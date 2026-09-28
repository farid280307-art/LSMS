<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

// Dealer tersentralisasi di database pusat
$pdo = getDbConnection('pusat');
$role = $_SESSION['user']['role'];
$search = $_GET['search'] ?? '';

// Query Data Dealer
$sql = "SELECT * FROM dealers";
$params = [];

if (!empty($search)) {
    $sql .= " WHERE name LIKE ? OR phone LIKE ? OR address LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dealers = $stmt->fetchAll();

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
            <h2 class="fw-bold text-dark mb-4">Manajemen Dealer</h2>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'saved'): ?>
                <div class="alert alert-success">Data dealer berhasil disimpan.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
                <div class="alert alert-success">Data dealer berhasil dihapus.</div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <a href="create.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i> Tambah Dealer</a>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" class="d-flex">
                                <input type="text" name="search" class="form-control me-2" placeholder="Cari nama, telepon..." value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="btn btn-outline-primary">Cari</button>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Nama Dealer</th>
                                    <th>Telepon</th>
                                    <th>Alamat</th>
                                    <th class="text-end pe-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dealers)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">Belum ada data dealer.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($dealers as $d): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold"><?= htmlspecialchars($d['name']) ?></td>
                                        <td><?= htmlspecialchars($d['phone']) ?></td>
                                        <td><?= htmlspecialchars($d['address']) ?></td>
                                        <td class="text-end pe-3">
                                            <a href="edit.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-info text-white me-1"><i class="fas fa-edit"></i></a>
                                            <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus dealer ini?');">
                                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                            </form>
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
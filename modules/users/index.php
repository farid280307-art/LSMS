<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection('pusat');
$role = $_SESSION['user']['role'];

$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role_filter'] ?? '';
$branch_filter = $_GET['branch_filter'] ?? '';

$roles = $pdo->query("SELECT id, role_name FROM roles ORDER BY role_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll();

$my_branch_id = null;
if ($role === 'kepala_cabang') {
    $stmt_my_branch = $pdo->prepare("SELECT id FROM branches WHERE branch_code = ?");
    $stmt_my_branch->execute([$_SESSION['user']['cabang']]);
    $my_branch_id = $stmt_my_branch->fetchColumn();
}

$sql = "SELECT u.id, u.full_name, u.email, u.is_active, u.branch_id, u.role_id,
               r.role_name,
               b.branch_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN branches b ON u.branch_id = b.id";

$where_clauses = [];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(u.full_name LIKE :search OR u.email LIKE :search)";
    $params['search'] = "%$search%";
}

if ($role_filter !== '') {
    $where_clauses[] = "u.role_id = :role_id";
    $params['role_id'] = $role_filter;
}

if ($branch_filter !== '') {
    if ($branch_filter === 'pusat') {
        $where_clauses[] = "u.branch_id IS NULL";
    } else {
        $where_clauses[] = "u.branch_id = :branch_id";
        $params['branch_id'] = $branch_filter;
    }
}

if ($role === 'kepala_cabang') {
    $where_clauses[] = "u.branch_id = :my_branch_id";
    $params['my_branch_id'] = $my_branch_id;
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY u.full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$messages = [
    'saved' => ['type' => 'success', 'text' => 'Data user berhasil disimpan.'],
    'deleted' => ['type' => 'success', 'text' => 'Data user berhasil dihapus.'],
    'delete_self_failed' => ['type' => 'danger', 'text' => 'Anda tidak dapat menghapus akun Anda sendiri.'],
    'not_found' => ['type' => 'warning', 'text' => 'Data user tidak ditemukan.'],
    'forbidden' => ['type' => 'danger', 'text' => 'Akses ditolak. Anda hanya dapat mengelola user cabang Anda.'],
    'save_failed' => ['type' => 'danger', 'text' => $_GET['err_msg'] ?? 'Gagal menyimpan data user.'],
    'delete_failed' => ['type' => 'danger', 'text' => $_GET['err_msg'] ?? 'Gagal menghapus data user.'],
];

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
            <h2 class="fw-bold text-dark mb-4">Manajemen User</h2>

            <?php if (isset($_GET['msg']) && isset($messages[$_GET['msg']])): ?>
                <div class="alert alert-<?= $messages[$_GET['msg']]['type'] ?>">
                    <?= htmlspecialchars($messages[$_GET['msg']]['text']) ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4 mb-2 mb-md-0">
                            <a href="create.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i> Tambah User Baru
                            </a>
                        </div>
                        <div class="col-md-8">
                            <form method="GET" class="d-flex justify-content-end">
                                <input type="text" name="search" class="form-control me-2" placeholder="Cari nama atau email..." value="<?= htmlspecialchars($search) ?>">
                                <select name="role_filter" class="form-select me-2" style="max-width: 200px;">
                                    <option value="">-- Semua Role --</option>
                                    <?php foreach ($roles as $r): ?>
                                        <option value="<?= $r['id'] ?>" <?= ($role_filter == $r['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['role_name']))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="branch_filter" class="form-select me-2" style="max-width: 200px;" <?= $role === 'kepala_cabang' ? 'disabled' : '' ?>>
                                    <option value="">-- Semua Cabang --</option>
                                    <?php if ($role !== 'kepala_cabang'): ?>
                                        <option value="pusat" <?= ($branch_filter == 'pusat') ? 'selected' : '' ?>>Pusat</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?= $b['id'] ?>" <?= ($branch_filter == $b['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['branch_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <button type="submit" class="btn btn-outline-primary">Filter</button>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Nama Lengkap</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Cabang</th>
                                    <th>Status</th>
                                    <th class="text-end pe-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">Data user tidak ditemukan.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold"><?= htmlspecialchars($user['full_name']) ?></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role_name']))) ?></span></td>
                                            <td><?= htmlspecialchars($user['branch_name'] ?? 'Pusat') ?></td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge bg-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="view.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-secondary me-1" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-info text-white me-1" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user']['id']): ?>
                                                    <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus user ini?');">
                                                        <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Hapus">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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

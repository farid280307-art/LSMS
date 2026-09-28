<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection('pusat');
$role = $_SESSION['user']['role'];
$error = '';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php?msg=not_found');
    exit();
}

$stmt_user = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.is_active, u.branch_id, u.role_id,
                                  r.role_name,
                                  b.branch_name
                           FROM users u
                           JOIN roles r ON u.role_id = r.id
                           LEFT JOIN branches b ON u.branch_id = b.id
                           WHERE u.id = ?");
$stmt_user->execute([$id]);
$user = $stmt_user->fetch();

if (!$user) {
    header('Location: index.php?msg=not_found');
    exit();
}

$my_branch_id = null;
$my_branch_name = null;
if ($role === 'kepala_cabang') {
    $stmt_my_branch = $pdo->prepare("SELECT id, branch_name FROM branches WHERE branch_code = ?");
    $stmt_my_branch->execute([$_SESSION['user']['cabang']]);
    $my_branch = $stmt_my_branch->fetch();
    $my_branch_id = $my_branch['id'] ?? null;
    $my_branch_name = $my_branch['branch_name'] ?? '-';

    if ((string)$user['branch_id'] !== (string)$my_branch_id) {
        header('Location: index.php?msg=forbidden');
        exit();
    }
}

$roles = $pdo->query("SELECT id, role_name FROM roles ORDER BY role_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = $_POST['role_id'] ?? '';
    $branch_id = $_POST['branch_id'] === '' ? null : ($_POST['branch_id'] ?? null);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($role === 'kepala_cabang') {
        $branch_id = $my_branch_id;
    }

    try {
        if ($full_name === '' || $email === '' || $role_id === '') {
            throw new Exception('Nama, email, dan role wajib diisi.');
        }

        if ($password !== '') {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt_update = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, password = ?, role_id = ?, branch_id = ?, is_active = ? WHERE id = ?");
            $stmt_update->execute([$full_name, $email, $hashed_password, $role_id, $branch_id, $is_active, $id]);
        } else {
            $stmt_update = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role_id = ?, branch_id = ?, is_active = ? WHERE id = ?");
            $stmt_update->execute([$full_name, $email, $role_id, $branch_id, $is_active, $id]);
        }

        header('Location: index.php?msg=saved');
        exit();
    } catch (Exception $e) {
        $error = 'Gagal menyimpan: ' . $e->getMessage();
    }

    $stmt_user->execute([$id]);
    $user = $stmt_user->fetch();
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
            <h2 class="fw-bold text-dark mb-4">Edit User</h2>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap</label>
                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password Baru</label>
                                <input type="password" name="password" class="form-control">
                                <small class="text-muted">Kosongkan jika tidak ingin mengubah password.</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select name="role_id" id="roleId" class="form-select" required onchange="toggleBranchField()">
                                    <option value="">-- Pilih Role --</option>
                                    <?php foreach ($roles as $r): ?>
                                        <option value="<?= $r['id'] ?>" data-role-name="<?= htmlspecialchars($r['role_name']) ?>" <?= ($user['role_id'] == $r['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['role_name']))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($role === 'kepala_cabang'): ?>
                            <div class="mb-3">
                                <label class="form-label">Cabang</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($my_branch_name) ?>" disabled>
                                <input type="hidden" name="branch_id" value="<?= (int)$my_branch_id ?>">
                            </div>
                        <?php else: ?>
                            <div class="mb-3" id="branchField" style="display:none;">
                                <label class="form-label">Cabang</label>
                                <select name="branch_id" id="branchId" class="form-select">
                                    <option value="">-- Pilih Cabang --</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= ($user['branch_id'] == $b['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="isActive" name="is_active" <?= $user['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isActive">Akun Aktif</label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                            <a href="index.php" class="btn btn-light">Batal</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($role !== 'kepala_cabang'): ?>
<script>
function toggleBranchField() {
    const roleSelect = document.getElementById('roleId');
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
    const roleName = (selectedOption.dataset.roleName || '').toLowerCase().trim();
    const branchField = document.getElementById('branchField');
    const branchSelect = document.getElementById('branchId');
    const branchRoles = ['kepala_cabang', 'admin', 'cs', 'teknisi', 'gudang'];

    if (branchRoles.includes(roleName)) {
        branchField.style.display = 'block';
        branchSelect.required = true;
    } else {
        branchField.style.display = 'none';
        branchSelect.required = false;
        branchSelect.value = '';
    }
}

document.addEventListener('DOMContentLoaded', toggleBranchField);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

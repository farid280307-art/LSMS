<?php
$role = $_SESSION['user']['role'] ?? '';

// =====================
// BUFFER MENU ITEMS
// =====================
ob_start();
?>

<?php
// Tentukan URL dashboard berdasarkan role
$dashboard_file = __DIR__ . '/../../modules/dashboard/' . $role . '.php';
$dashboard_url = BASE_URL . 'modules/dashboard/' . $role . '.php';

// Jika file dashboard untuk role tersebut tidak ada, gunakan fallback
if (!file_exists($dashboard_file)) {
    $dashboard_url = BASE_URL . 'modules/service/index.php'; // Fallback ke halaman service
}

// Tentukan status 'active' untuk menu dashboard
$is_active = (
    // Aktif jika sedang berada di dalam folder /modules/dashboard/
    strpos($_SERVER['REQUEST_URI'], 'modules/dashboard/') !== false ||
    // Aktif jika URL adalah fallback (service/index.php) DAN dashboard khusus tidak ada
    (!file_exists($dashboard_file) && strpos($_SERVER['REQUEST_URI'], 'modules/service/index.php') !== false)
);
?>
<a href="<?= $dashboard_url ?>" class="list-group-item list-group-item-action <?= $is_active ? 'active' : '' ?>">
    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
</a>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','cs'])): ?>
<a href="<?= BASE_URL ?>modules/dealers/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'dealers') !== false ? 'active' : '' ?>">
    <i class="fas fa-handshake"></i> <span>Dealer</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','cs','teknisi'])): ?>
<a href="<?= BASE_URL ?>modules/service/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'modules/service') !== false ? 'active' : '' ?>">
    <i class="fas fa-tools"></i> <span>Service</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','cs','teknisi'])): ?>
<a href="<?= BASE_URL ?>modules/warranty-check/"
   class="list-group-item list-group-item-action <?= (strpos($_SERVER['REQUEST_URI'],'modules/warranty-check') !== false || strpos($_SERVER['REQUEST_URI'],'warranty-check') !== false) ? 'active' : '' ?>">
    <i class="fas fa-shield-alt"></i> <span>Cek Garansi</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','cs'])): ?>
<a href="<?= BASE_URL ?>modules/payments/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'payments') !== false ? 'active' : '' ?>">
    <i class="fas fa-cash-register"></i> <span>Pembayaran</span>
</a>
<?php endif; ?>

<?php
$is_pusat_gudang = ($role === 'gudang' && empty($_SESSION['user']['cabang']));
?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin']) || $is_pusat_gudang): ?>
<a href="<?= BASE_URL ?>modules/stock_buffer/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'stock_buffer') !== false ? 'active' : '' ?>">
    <i class="fas fa-boxes"></i> <span>Stok Pusat (Buffer)</span>
</a>
<?php endif; ?>

<?php if (!$is_pusat_gudang && in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','gudang','teknisi'])): ?>
<a href="<?= BASE_URL ?>modules/stock_device/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'stock_device') !== false ? 'active' : '' ?>">
    <i class="fas fa-laptop"></i> <span>Stok Cabang</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['gudang', 'superadmin'])): ?>
<a href="<?= BASE_URL ?>modules/master-unit"
   class="list-group-item list-group-item-action <?= (strpos($_SERVER['REQUEST_URI'],'modules/master-unit') !== false || strpos($_SERVER['REQUEST_URI'],'gudang/master-unit') !== false) ? 'active' : '' ?>">
    <i class="fas fa-tags"></i> <span>Master Unit</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','cs'])): ?>
<a href="<?= BASE_URL ?>modules/purchases/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'purchases') !== false ? 'active' : '' ?>">
    <i class="fas fa-shopping-cart"></i> <span>Pembelian</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','admin','gudang','teknisi','cs'])): ?>
<a href="<?= BASE_URL ?>modules/riwayat/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'riwayat') !== false ? 'active' : '' ?>">
    <i class="fas fa-history"></i> <span>Riwayat</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang'])): ?>
<a href="<?= BASE_URL ?>modules/users/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'users') !== false ? 'active' : '' ?>">
    <i class="fas fa-user-cog"></i> <span>Manajemen User</span>
</a>
<?php endif; ?>

<?php if (in_array($role, ['superadmin','kepala_pusat','kepala_admin','kepala_cabang','gudang'])): ?>
<a href="<?= BASE_URL ?>modules/system_logs/index.php"
   class="list-group-item list-group-item-action <?= strpos($_SERVER['REQUEST_URI'],'system_logs') !== false ? 'active' : '' ?>">
    <i class="fas fa-file-alt"></i> <span>System Logs</span>
</a>
<?php endif; ?>

<?php
$menu_items = ob_get_clean();
?>

<!-- ===================== DESKTOP SIDEBAR ===================== -->
<div id="sidebar-wrapper" class="d-none d-md-flex flex-column">

    <div class="sidebar-header flex-shrink-0">
        <a class="brand-link" href="<?= BASE_URL ?>public/index.php">
            <span class="brand-text-strong">LSMS</span>
        </a>
    </div>

    <div class="list-group list-group-flush flex-grow-1 overflow-auto">
        <?= $menu_items ?>
    </div>

    <div class="sidebar-footer border-top p-3 bg-light flex-shrink-0">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold"
                 style="width:38px;height:38px;">
                <?= strtoupper(substr($_SESSION['user']['full_name'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="flex-grow-1 ms-2 overflow-hidden">
                <div class="fw-bold text-truncate"><?= htmlspecialchars($_SESSION['user']['full_name'] ?? 'User') ?></div>
                <div class="text-muted small"><?= ucfirst($_SESSION['user']['role'] ?? 'Role') ?></div>
            </div>
            <a href="<?= BASE_URL ?>auth/logout.php" 
               class="btn btn-sm btn-outline-danger border-0" 
               data-bs-toggle="modal" 
               data-bs-target="#logoutConfirmModal"
               role="button">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</div>

<!-- ===================== MOBILE SIDEBAR ===================== -->
<div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileSidebar">
    <div class="offcanvas-header border-bottom">
        <h5 class="fw-bold text-primary">LSMS</h5>
        <button class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>

    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="list-group list-group-flush flex-grow-1 overflow-auto">
            <?= $menu_items ?>
        </div>

        <div class="sidebar-footer border-top p-3 bg-light">
            <div class="d-flex align-items-center">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold"
                     style="width:38px;height:38px;">
                    <?= strtoupper(substr($_SESSION['user']['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="flex-grow-1 ms-2 overflow-hidden">
                    <div class="fw-bold text-truncate"><?= htmlspecialchars($_SESSION['user']['full_name'] ?? 'User') ?></div>
                    <div class="text-muted small"><?= ucfirst($_SESSION['user']['role'] ?? 'Role') ?></div>
                </div>
                <a href="<?= BASE_URL ?>auth/logout.php" 
                   class="btn btn-sm btn-outline-danger border-0" 
                   data-bs-toggle="modal" 
                   data-bs-target="#logoutConfirmModal"
                   role="button">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ===================== LOGOUT CONFIRMATION MODAL (Bootstrap 5) ===================== -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">
                    <i class="fas fa-sign-out-alt text-danger me-2"></i>Konfirmasi Logout
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Apakah Anda yakin ingin keluar dari sistem?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Batal
                </button>
                <a href="<?= BASE_URL ?>auth/logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt me-1"></i> Ya, Logout
                </a>
            </div>
        </div>
    </div>
</div>





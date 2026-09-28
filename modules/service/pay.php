<?php
$allowed_roles = ['superadmin', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/warranty_helpers.php';

$id = $_GET['id'] ?? 0;
$role = $_SESSION['user']['role'];

// Branch override logic for superadmin
$branch_override = (in_array($_SESSION['user']['role'], ['superadmin', 'kepala_admin'])) ? ($_GET['branch'] ?? null) : null;
$pdo = getDbConnection($branch_override);
ensureServiceItemsWarrantySchema($pdo);

// Fetch Service Data
$sql = "SELECT s.*, COALESCE(c.name, dl.name) as customer_name, COALESCE(c.phone, dl.phone) as phone, 
               d.brand, d.model, d.serial_number
        FROM services s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN lsms_pusat.dealers dl ON s.dealer_id = dl.id
        JOIN devices d ON s.device_id = d.id
        WHERE s.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) {
    die("Service tidak ditemukan.");
}

// Validasi Status
if ($service['status'] !== 'finished') {
    // Jika sudah diambil, redirect kembali ke view
    if ($service['status'] === 'taken') {
        $redirect_url = "view.php?id=$id";
        if ($branch_override) $redirect_url .= "&branch=$branch_override";
        header("Location: $redirect_url");
        exit;
    }
    die("Status service belum selesai (Finished). Tidak dapat melakukan pembayaran.");
}

// Ambil Items (Biaya)
$stmt = $pdo->prepare("SELECT * FROM service_items WHERE service_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$total_cost = 0;
foreach ($items as $item) $total_cost += $item['price'];

// Handle Payment Processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi Role untuk memproses (Admin/Superadmin)
    if (!in_array($role, ['admin', 'superadmin', 'kepala_admin', 'kepala_cabang', 'cs'])) {
        $error = "Anda tidak memiliki hak akses untuk memproses pembayaran.";
    } else {
        $amount = $total_cost; // Gunakan total yang dihitung server untuk keamanan
        $method = $_POST['payment_method'];
        $pay_code = 'PAY-' . date('ymd') . rand(100,999);

        try {
            $pdo->beginTransaction();
            
            // Insert Payment
            $stmt = $pdo->prepare("INSERT INTO payments (service_id, payment_code, amount, payment_method, received_by_user_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $pay_code, $amount, $method, $_SESSION['user']['id']]);
            
            // Update Service Status -> Taken
            $stmt = $pdo->prepare("UPDATE services SET status = 'taken', final_cost = ? WHERE id = ?");
            $stmt->execute([$amount, $id]);

            // Sinkronkan garansi sparepart setelah service selesai/taken
            applyWarrantyToServiceItems($pdo, (int)$id);
            
            // Log History: Payment
            $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'payment', ?, ?, NOW())")
                ->execute([$id, "Pembayaran diterima ($method) sebesar Rp " . number_format($amount) . ". Unit diambil.", $_SESSION['user']['id']]);

            $pdo->commit();
            
            // Redirect ke view dengan pesan sukses
            $redirect_url = "view.php?id=$id&msg=paid";
            if ($branch_override) $redirect_url .= "&branch=$branch_override";
            header("Location: $redirect_url");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal memproses pembayaran: " . $e->getMessage();
        }
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
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h4 class="mb-0 fw-bold text-primary">Halaman Pembayaran</h4>
                        </div>
                        <div class="card-body p-4">
                            
                            <?php if (isset($error)): ?>
                                <div class="alert alert-danger"><?= $error ?></div>
                            <?php endif; ?>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Informasi Service</h6>
                                    <p class="mb-1 fw-bold fs-5"><?= $service['service_code'] ?></p>
                                    <p class="mb-0 text-muted"><?= date('d F Y', strtotime($service['created_at'])) ?></p>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <h6 class="text-muted text-uppercase small fw-bold">Pelanggan</h6>
                                    <p class="mb-1 fw-bold"><?= $service['customer_name'] ?></p>
                                    <p class="mb-0"><?= $service['brand'] ?> <?= $service['model'] ?></p>
                                </div>
                            </div>

                            <hr>

                            <h6 class="fw-bold mb-3">Rincian Biaya</h6>
                            <table class="table table-bordered mb-4">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Deskripsi Item / Jasa</th>
                                        <th class="text-end" style="width: 150px;">Harga</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($item['description']) ?>
                                            <span class="badge bg-secondary ms-2" style="font-size: 0.7em;"><?= $item['item_type'] == 'sparepart' ? 'Sparepart' : 'Jasa' ?></span>
                                        </td>
                                        <td class="text-end">Rp <?= number_format($item['price'], 0, ',', '.') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-active fw-bold">
                                        <td class="text-end">TOTAL TAGIHAN</td>
                                        <td class="text-end fs-5 text-primary">Rp <?= number_format($total_cost, 0, ',', '.') ?></td>
                                    </tr>
                                </tbody>
                            </table>

                            <form method="POST" onsubmit="return confirm('Pastikan uang sudah diterima. Lanjutkan proses?');">
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Metode Pembayaran</label>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <input type="radio" class="btn-check" name="payment_method" id="payCash" value="cash" checked>
                                            <label class="btn btn-outline-primary w-100 py-3" for="payCash">
                                                <i class="fas fa-money-bill-wave fa-lg mb-2 d-block"></i> Tunai (Cash)
                                            </label>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="radio" class="btn-check" name="payment_method" id="payTransfer" value="transfer">
                                            <label class="btn btn-outline-primary w-100 py-3" for="payTransfer">
                                                <i class="fas fa-university fa-lg mb-2 d-block"></i> Transfer Bank
                                            </label>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="radio" class="btn-check" name="payment_method" id="payDebit" value="debit">
                                            <label class="btn btn-outline-primary w-100 py-3" for="payDebit">
                                                <i class="fas fa-credit-card fa-lg mb-2 d-block"></i> Debit / QRIS
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-success btn-lg fw-bold">
                                        <i class="fas fa-check-circle me-2"></i> Konfirmasi Pembayaran & Selesai
                                    </button>
                                    <?php 
                                        $back_url = "view.php?id=" . $service['id'];
                                        if ($branch_override) $back_url .= "&branch=" . urlencode($branch_override);
                                    ?>
                                    <a href="<?= $back_url ?>" class="btn btn-light text-muted">Batal / Kembali</a>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

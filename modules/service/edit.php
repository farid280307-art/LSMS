<?php
$allowed_roles = ['superadmin', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$role = $_SESSION['user']['role'];
$service_id = $_GET['id'] ?? null;
$branch_code = $_GET['branch'] ?? null;
$error = '';

if (!$service_id) {
    header("Location: index.php");
    exit();
}

// Determine DB connection
$pdo = getDbConnection($branch_code);

// Fetch existing service data
$sql = "SELECT s.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, 
               d.brand, d.model, d.serial_number, d.physical_condition, d.device_password
        FROM services s
        LEFT JOIN customers c ON s.customer_id = c.id
        JOIN devices d ON s.device_id = d.id
        WHERE s.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$service_id]);
$service = $stmt->fetch();

if (!$service) {
    header("Location: index.php?msg=not_found");
    exit();
}

// Ambil Data Dealer (Dari Database Pusat)
$pdo_pusat = getDbConnection('pusat');
$dealers = $pdo_pusat->query("SELECT id, name FROM dealers ORDER BY name ASC")->fetchAll();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Workaround untuk skema database yang mungkin memiliki constraint ketat atau perbedaan struktur
        $pdo->exec('SET foreign_key_checks = 0');
        $pdo->beginTransaction();

        $service_type = $_POST['service_type'] ?? 'personal';
        $customer_id = $service['customer_id']; // Keep existing by default
        $dealer_id = $service['dealer_id']; // Keep existing by default

        // 1. Handle Customer / Dealer
        if ($service_type === 'dealer') {
            $dealer_id = $_POST['dealer_id'];
            $customer_id = null; // Unset customer if switching to dealer
        } else {
            $phone = $_POST['phone'];
            $name = $_POST['name'];
            $address = $_POST['address'];
            $dealer_id = null; // Unset dealer if switching to personal

            // If there was no customer before (switching from dealer to personal)
            if (!$customer_id) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
                $stmt->execute([$phone]);
                $existing_customer = $stmt->fetch();
                if ($existing_customer) {
                    $customer_id = $existing_customer['id'];
                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, address = ? WHERE id = ?");
                    $stmt->execute([$name, $address, $customer_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $phone, $address]);
                    $customer_id = $pdo->lastInsertId();
                }
            } else {
                // There was an existing customer, update them safely
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND id != ?");
                $stmt->execute([$phone, $customer_id]);
                if ($stmt->fetch()) {
                    throw new Exception("Nomor HP sudah digunakan oleh pelanggan lain.");
                }
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $address, $customer_id]);
            }
        }

        // Ambil nama database saat ini untuk pengecekan kolom
        $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();

        // 2. Update Device
        // Cek apakah kolom dealer_id ada di tabel devices
        $check_device_sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'dealer_id'";
        $stmt_check_device = $pdo->prepare($check_device_sql);
        $stmt_check_device->execute(['dbname' => $db_name]);
        $device_has_dealer_id = $stmt_check_device->fetchColumn() > 0;

        $device_sql = "UPDATE devices SET customer_id = ?, brand = ?, model = ?, serial_number = ?, physical_condition = ?, device_password = ?";
        $device_params = [
            $customer_id,
            $_POST['brand'], $_POST['model'],
            $_POST['sn'], $_POST['condition'], $_POST['device_pass'],
        ];

        if ($device_has_dealer_id) {
            $device_sql .= ", dealer_id = ?";
            $device_params[] = $dealer_id;
        }

        $device_sql .= " WHERE id = ?";
        $device_params[] = $service['device_id'];

        $stmt = $pdo->prepare($device_sql);
        $stmt->execute($device_params);

        // 3. Update Service Ticket
        // Cek apakah kolom dealer_id ada di tabel services
        $check_service_sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'services' AND COLUMN_NAME = 'dealer_id'";
        $stmt_check_service = $pdo->prepare($check_service_sql);
        $stmt_check_service->execute(['dbname' => $db_name]);
        $service_has_dealer_id = $stmt_check_service->fetchColumn() > 0;

        $service_sql = "UPDATE services SET customer_id = ?, reported_problem = ?";
        $service_params = [$customer_id, $_POST['problem']];

        if ($service_has_dealer_id) {
            $service_sql .= ", dealer_id = ?";
            $service_params[] = $dealer_id;
        }

        $service_sql .= " WHERE id = ?";
        $service_params[] = $service_id;

        $stmt = $pdo->prepare($service_sql);
        $stmt->execute($service_params);

        $pdo->commit();
        $pdo->exec('SET foreign_key_checks = 1');
        header("Location: index.php?msg=saved");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET foreign_key_checks = 1');
        $error = "Gagal memperbarui data: " . $e->getMessage();
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
            <h3 class="mb-4 fw-bold text-dark">Edit Service <span class="text-primary"><?= htmlspecialchars($service['service_code']) ?></span></h3>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="card border-0 shadow-sm p-4">
                <h5 class="text-primary fw-bold mb-3 border-bottom pb-2">1. Data Pelanggan / Dealer</h5>
                
                <?php if ($service['dealer_id']): ?>
                    <!-- Tampilan Dealer -->
                    <input type="hidden" name="service_type" value="dealer">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Jenis Pelanggan</label>
                        <input type="text" class="form-control-plaintext fw-bold" value="Dealer" readonly>
                    </div>
                    <div id="dealerForm">
                        <div class="mb-3">
                            <label class="form-label">Pilih Dealer</label>
                            <select name="dealer_id" class="form-select">
                                <option value="">-- Pilih Dealer --</option>
                                <?php foreach ($dealers as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($service['dealer_id'] == $d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Tampilan Customer Personal -->
                    <input type="hidden" name="service_type" value="personal">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Jenis Pelanggan</label>
                        <input type="text" class="form-control-plaintext fw-bold" value="Customer (Perorangan)" readonly>
                    </div>
                    <div id="personalForm">
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Nama Lengkap</label><input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($service['customer_name'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Nomor HP (WhatsApp)</label><input type="text" name="phone" class="form-control" required value="<?= htmlspecialchars($service['customer_phone'] ?? '') ?>"></div>
                        </div>
                        <div class="mb-3"><label class="form-label">Alamat</label><textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($service['customer_address'] ?? '') ?></textarea></div>
                    </div>
                <?php endif; ?>

                <div class="my-4"></div>
                <h5 class="text-primary fw-bold mb-3 border-bottom pb-2">2. Data Perangkat</h5>
                
                <!-- Serial Number Pindah ke Atas -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Serial Number (SN)</label>
                        <input type="text" name="sn" id="inputSN" class="form-control" readonly value="<?= htmlspecialchars($service['serial_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status Garansi</label>
                        <input type="text" id="warrantyInfo" class="form-control" readonly placeholder="Mengecek...">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Unit</label>
                        <input type="text" name="brand" id="inputBrand" class="form-control" readonly required value="<?= htmlspecialchars($service['brand']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Item Code</label>
                        <input type="text" name="model" id="inputModel" class="form-control" readonly required value="<?= htmlspecialchars($service['model']) ?>">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-12"><label class="form-label">Password Perangkat (Jika ada)</label><input type="text" name="device_pass" class="form-control" value="<?= htmlspecialchars($service['device_password'] ?? '') ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label">Kelengkapan & Kondisi Fisik</label><textarea name="condition" class="form-control"><?= htmlspecialchars($service['physical_condition'] ?? '') ?></textarea></div>
                <div class="mb-3"><label class="form-label fw-bold">Keluhan / Masalah</label><textarea name="problem" class="form-control" rows="3" required><?= htmlspecialchars($service['reported_problem']) ?></textarea></div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <a href="index.php" class="btn btn-light me-md-2">Batal</a>
                    <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    checkSN(); // Panggil checkSN saat halaman dimuat untuk mengisi data awal
});

function checkSN() {
    const sn = document.getElementById('inputSN').value;
    const warrantyInfo = document.getElementById('warrantyInfo');
    const serviceId = '<?= $service_id ?>';

    if (!sn) {
        warrantyInfo.value = 'SN tidak ada.';
        return;
    }

    // Tampilkan loading
    warrantyInfo.value = 'Mengecek...';

    fetch('check_sn.php?sn=' + encodeURIComponent(sn) + '&ignore_id=' + serviceId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const end = data.data.warranty_end || '-';
                
                if (data.data.is_warranty) {
                    warrantyInfo.value = `Garansi Aktif (Exp: ${end})`;
                } else {
                    warrantyInfo.value = `Garansi Habis (Exp: ${end})`;
                }
            } else {
                // Jika SN tidak ditemukan di DB pusat, jangan tampilkan error, cukup info netral.
                warrantyInfo.value = 'Info garansi tidak tersedia';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            warrantyInfo.value = 'Gagal mengecek garansi.';
        });
}
</script>
<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

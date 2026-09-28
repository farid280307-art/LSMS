<?php
$allowed_roles = ['superadmin', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$role = $_SESSION['user']['role'];
$pdo = getDbConnection();
$error = '';
$success = '';

// Ambil Data Cabang untuk Dropdown (Khusus Superadmin)
$branches = [];
if (in_array($role, ['superadmin', 'kepala_admin'])) {
    $stmt = $pdo->query("SELECT branch_code, branch_name FROM lsms_pusat.branches ORDER BY branch_name ASC");
    $branches = $stmt->fetchAll();
}

// Ambil Data Dealer (Dari Database Pusat)
$pdo_pusat = getDbConnection('pusat');
$dealers = $pdo_pusat->query("SELECT id, name FROM dealers ORDER BY name ASC")->fetchAll();

// Ambil Data Referensi Master Unit (Dari Database Pusat) untuk input service
$master_unit_references = [];
try {
    $master_unit_references = $pdo_pusat->query("SELECT id, brand, model, category FROM master_units ORDER BY brand ASC, model ASC")->fetchAll();
} catch (Throwable $e) {
    $master_unit_references = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($_POST['branch_code'])) {
        $pdo = getDbConnection($_POST['branch_code']);
    }

    try {
        // Workaround for schemas where customer_id is NOT NULL but has a foreign key.
        // This temporarily allows inserting a record without a valid customer_id (which defaults to 0).
        // The proper fix is to make the customer_id column nullable in the branch database.
        $pdo->exec('SET foreign_key_checks = 0');

        $pdo->beginTransaction();

        $service_type = $_POST['service_type'] ?? 'personal';
        $customer_id = null;
        $dealer_id = null;


        // 1. Handle Customer / Dealer
        if ($service_type === 'dealer') {
            $dealer_id = $_POST['dealer_id'];
        } else {
          
            // Logika Pelanggan Umum
            $phone = $_POST['phone'];
            $name = $_POST['name'];
            $address = $_POST['address'];

            $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? ");
            $stmt->execute([$phone]);
            $customer = $stmt->fetch();

            if ($customer) {
                $customer_id = $customer['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address) VALUES (?, ?, ?)");
                $stmt->execute([$name, $phone, $address]);
                $customer_id = $pdo->lastInsertId();
            }
        }

       // Ambil nama database yang benar (karena $_POST['branch_code'] hanyalah key config, bukan nama DB)
       $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();

        // Cek apakah SN sedang aktif service (Server-side Validation - Cross Branch)
        $sn_check = $_POST['sn'];
        
        global $db_configs;
        $branch_dbs = array_filter($db_configs, function($key) {
            return strpos($key, 'cabang_') === 0;
        }, ARRAY_FILTER_USE_KEY);

        foreach ($branch_dbs as $branch_key => $config) {
            $pdo_branch = getDbConnection($branch_key);
            if (!$pdo_branch) continue;

            $check_active_sql = "SELECT s.service_code, s.status 
                                 FROM services s 
                                 JOIN devices d ON s.device_id = d.id 
                                 WHERE d.serial_number = ? 
                                 AND s.status NOT IN ('taken', 'cancelled') 
                                 LIMIT 1";
            $stmt_check_active = $pdo_branch->prepare($check_active_sql);
            $stmt_check_active->execute([$sn_check]);
            $existing_active = $stmt_check_active->fetch();

            if ($existing_active) {
                // Ambil nama cabang
                $pdo_pusat = getDbConnection('pusat');
                $stmt_b = $pdo_pusat->prepare("SELECT branch_name FROM branches WHERE branch_code = ?");
                $stmt_b->execute([$branch_key]);
                $b_name = $stmt_b->fetchColumn() ?: $branch_key;

                throw new Exception("Unit dengan SN $sn_check sedang dalam status " . strtoupper(str_replace('_', ' ', $existing_active['status'])) . " di $b_name (Tiket: " . $existing_active['service_code'] . "). Harap selesaikan service sebelumnya.");
            }
        }

        // 2. Handle Device (Dynamically build query to avoid NOT NULL constraint errors)
        $device_cols = ['brand', 'model', 'serial_number', 'physical_condition', 'device_password'];
        $device_params = [
            $_POST['brand'],
            $_POST['model'],
            $_POST['sn'],
            $_POST['condition'],
            $_POST['device_pass']
        ];

        if ($customer_id) {
            $device_cols[] = 'customer_id';
            $device_params[] = $customer_id;
        }
        
        if ($dealer_id) {
            // Only add dealer_id if the column exists in the target table
            $check_device_sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'dealer_id'";
            $stmt_check_device = $pdo->prepare($check_device_sql);
            $stmt_check_device->execute(['dbname' => $db_name]);
            if ($stmt_check_device->fetchColumn() > 0) {
                $device_cols[] = 'dealer_id';
                $device_params[] = $dealer_id;
            }
        }

        $device_placeholders = implode(', ', array_fill(0, count($device_params), '?'));
        $device_sql = "INSERT INTO devices (" . implode(', ', $device_cols) . ") VALUES ($device_placeholders)";
        $stmt = $pdo->prepare($device_sql);
        $stmt->execute($device_params);
        $device_id = $pdo->lastInsertId();

        // 3. Create Service Ticket (Dynamically build query)
        $service_cols = ['service_code', 'device_id', 'reported_problem', 'status', 'created_by_user_id'];
        $service_params = [
            'SVC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)),
            $device_id,
            $_POST['problem'],
            'pending',
            $_SESSION['user']['id']
        ];

        if ($customer_id) {
            $service_cols[] = 'customer_id';
            $service_params[] = $customer_id;
        }

        if ($dealer_id) {
            // Only add dealer_id if the column exists in the target table
            $check_service_sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'services' AND COLUMN_NAME = 'dealer_id'";
            $stmt_check_service = $pdo->prepare($check_service_sql);
            $stmt_check_service->execute(['dbname' => $db_name]);
            if ($stmt_check_service->fetchColumn() > 0) {
                $service_cols[] = 'dealer_id';
                $service_params[] = $dealer_id;
            }
        }

        $service_placeholders = implode(', ', array_fill(0, count($service_params), '?'));
        $service_sql = "INSERT INTO services (" . implode(', ', $service_cols) . ") VALUES ($service_placeholders)";
        $stmt = $pdo->prepare($service_sql);
        $stmt->execute($service_params);
        $new_service_id = $pdo->lastInsertId();

        // Log History: Service Created
        $log_desc = "Service diterima. Masalah: " . $_POST['problem'];
        $pdo->prepare("INSERT INTO service_logs (service_id, action, description, user_id, log_time) VALUES (?, 'created', ?, ?, NOW())")
            ->execute([$new_service_id, $log_desc, $_SESSION['user']['id']]);
       
        $pdo->commit();

        // Re-enable foreign key checks after the transaction is complete.
        $pdo->exec('SET foreign_key_checks = 1');

        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // IMPORTANT: Always re-enable foreign key checks, even on failure.
        $pdo->exec('SET foreign_key_checks = 1');
        $error = "Gagal menyimpan data: " . $e->getMessage();
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
            <h3 class="mb-4 fw-bold text-dark">Input Service Baru</h3>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="card border-0 shadow-sm p-4">
                <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                <h5 class="text-primary fw-bold mb-3 border-bottom pb-2">0. Pilih Cabang</h5>
                <div class="mb-3">
                    <label class="form-label">Lokasi Service</label>
                    <select name="branch_code" class="form-select" required>
                        <option value="">-- Pilih Cabang --</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= htmlspecialchars($b['branch_code']) ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <h5 class="text-primary fw-bold mb-3 border-bottom pb-2">1. Data Pelanggan / Dealer</h5>
                
                <div class="mb-3">
                    <label class="form-label d-block fw-bold">Jenis Pelanggan</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="service_type" id="typePersonal" value="personal" checked onchange="toggleServiceType()">
                        <label class="form-check-label" for="typePersonal">Customer</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="service_type" id="typeDealer" value="dealer" onchange="toggleServiceType()">
                        <label class="form-check-label" for="typeDealer">Dealer</label>
                    </div>
                </div>

                <!-- Form Pelanggan Umum -->
                <div id="personalForm">
                   <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nomor HP (WhatsApp)</label>
                            <input type="text" name="phone" class="form-control" required placeholder="0812..." oninput="this.value = this.value.replace(/[^0-9]/g, '')" inputmode="numeric">
                        </div>
                    </div>
                   <div class="mb-3">
                        <label class="form-label">Alamat</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <!-- Form Dealer -->
                <div id="dealerForm" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label">Pilih Dealer</label>
                        <select name="dealer_id" class="form-select">
                            <option value="">-- Pilih Dealer --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="my-4"></div>
                <h5 class="text-primary fw-bold mb-3 border-bottom pb-2">2. Data Perangkat</h5>
                
                <!-- Serial Number Pindah ke Atas -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Serial Number (SN)</label>
                        <div class="input-group">
                            <input type="text" name="sn" id="inputSN" class="form-control" onchange="checkSN()" required>
                            <button class="btn btn-outline-primary" type="button" onclick="checkSN()"><i class="fas fa-search"></i> Cek</button>
                        </div>
                        <div id="snFeedback" class="form-text mt-1">Tekan Enter atau tombol Cek untuk auto-fill data.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status Garansi</label>
                        <input type="text" id="warrantyInfo" class="form-control" readonly>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Referensi Master Unit (Opsional)</label>
                    <input
                        type="text"
                        id="masterUnitReference"
                        class="form-control"
                        list="masterUnitReferenceOptions"
                        placeholder="Cari brand / model dari master unit..."
                        autocomplete="off"
                    >
                    <datalist id="masterUnitReferenceOptions"></datalist>
                    <div id="masterUnitReferenceFeedback" class="form-text text-muted mt-1">
                        Pilih referensi master unit untuk mengisi Unit dan Item Code otomatis.
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Unit</label>
                        <input type="text" name="brand" id="inputBrand" class="form-control" readonly required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Item Code</label>
                        <input type="text" name="model" id="inputModel" class="form-control" readonly required>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Password Perangkat (Jika ada)</label>
                        <input type="text" name="device_pass" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kelengkapan & Kondisi Fisik</label>
                    <textarea name="condition" class="form-control"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Keluhan / Masalah</label>
                    <textarea name="problem" class="form-control" rows="3" required></textarea>
                </div>
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <button type="button" onclick="history.back()" class="btn btn-light me-md-2">Batal</button>
                    <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i> Simpan Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const masterUnitReferenceInput = document.getElementById('masterUnitReference');
const masterUnitReferenceOptions = document.getElementById('masterUnitReferenceOptions');
const masterUnitReferenceFeedback = document.getElementById('masterUnitReferenceFeedback');
const inputBrandField = document.getElementById('inputBrand');
const inputModelField = document.getElementById('inputModel');

const masterUnitLookupMap = new Map();
const masterUnitReferenceRows = <?= json_encode($master_unit_references, JSON_UNESCAPED_UNICODE) ?>;

function toggleServiceType() {
    const type = document.querySelector('input[name="service_type"]:checked').value;
    const personalForm = document.getElementById('personalForm');
    const dealerForm = document.getElementById('dealerForm');
    
   
    if (type === 'personal') {
        personalForm.style.display = 'block';
        dealerForm.style.display = 'none';
        
        document.querySelector('[name="phone"]').required = true;
        document.querySelector('[name="name"]').required = true;
        document.querySelector('[name="dealer_id"]').required = false;
    } else {
        personalForm.style.display = 'none';
        dealerForm.style.display = 'block';
        
        document.querySelector('[name="phone"]').required = false;
        document.querySelector('[name="name"]').required = false;
        document.querySelector('[name="dealer_id"]').required = true;
    }
}

function escapeReferenceHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function applyMasterUnitReferenceByLabel(label) {
    const selected = masterUnitLookupMap.get(label);
    if (!selected) {
        return false;
    }

    inputBrandField.value = selected.brand || '';
    inputModelField.value = selected.model || '';
    masterUnitReferenceFeedback.className = 'form-text text-success mt-1';
    masterUnitReferenceFeedback.textContent = 'Referensi master unit diterapkan ke field Unit dan Item Code.';
    return true;
}

function loadMasterUnitReferences() {
    if (!masterUnitReferenceInput || !masterUnitReferenceOptions) {
        return;
    }

    const normalizedRows = Array.isArray(masterUnitReferenceRows)
        ? masterUnitReferenceRows
            .map((row) => ({
                brand: String(row.brand || '').trim(),
                model: String(row.model || '').trim(),
                category: String(row.category || '').trim(),
            }))
            .filter((row) => row.brand !== '' || row.model !== '')
        : [];

    masterUnitLookupMap.clear();

    const uniqueLabels = [];
    const seen = new Set();

    normalizedRows.forEach((row) => {
        const labelParts = [row.brand, row.model, row.category].filter(Boolean);
        const label = labelParts.join(' | ');
        if (!label || seen.has(label)) {
            return;
        }
        seen.add(label);
        uniqueLabels.push(label);
        masterUnitLookupMap.set(label, row);
    });

    masterUnitReferenceOptions.innerHTML = uniqueLabels
        .sort((a, b) => a.localeCompare(b))
        .map((label) => `<option value="${escapeReferenceHtml(label)}"></option>`)
        .join('');

    masterUnitReferenceFeedback.className = 'form-text text-muted mt-1';
    masterUnitReferenceFeedback.textContent = `Referensi siap digunakan (${uniqueLabels.length} data).`;
}

if (masterUnitReferenceInput) {
    masterUnitReferenceInput.addEventListener('change', function (event) {
        const label = String(event.target.value || '').trim();
        if (label === '') {
            return;
        }

        const applied = applyMasterUnitReferenceByLabel(label);
        if (!applied) {
            masterUnitReferenceFeedback.className = 'form-text text-warning mt-1';
            masterUnitReferenceFeedback.textContent = 'Referensi tidak ditemukan. Pilih nilai dari daftar yang tersedia.';
        }
    });
}

function checkSN() {
    const sn = document.getElementById('inputSN').value;
    const warrantyInfo = document.getElementById('warrantyInfo');
    const inputBrand = document.getElementById('inputBrand');
    const inputModel = document.getElementById('inputModel');
    const snFeedback = document.getElementById('snFeedback');
    const submitBtn = document.querySelector('button[type="submit"]');
    
    // Ambil branch code jika ada (untuk superadmin)
    let branchCode = '';
    const branchSelect = document.querySelector('select[name="branch_code"]');
    if (branchSelect) {
        branchCode = branchSelect.value;
    }

    if (!sn) return;

    // Tampilkan loading
    warrantyInfo.value = '';
    snFeedback.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengecek data...';
    snFeedback.className = 'form-text text-primary mt-1';
    submitBtn.disabled = true; // Disable tombol simpan saat checking

    fetch('check_sn.php?sn=' + encodeURIComponent(sn) + '&branch=' + encodeURIComponent(branchCode))
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                // Jika ada active service atau error lain
                snFeedback.innerHTML = '<i class="fas fa-ban text-danger"></i> ' + data.message;
                snFeedback.className = 'form-text text-danger mt-1 fw-bold';
                inputBrand.value = '';
                inputModel.value = '';
                warrantyInfo.value = '';
                submitBtn.disabled = true; // Tetap disable
                return;
            }
            
            // Jika aman, enable tombol
            submitBtn.disabled = false;

            if (data.status === 'success') {
                // Auto-fill Data
                inputBrand.value = data.data.unit_name;
                inputModel.value = data.data.model_code;
                
                // Tampilkan Info Garansi
                const start = data.data.warranty_start || '-';
                const end = data.data.warranty_end || '-';
                
                if (data.data.is_warranty) {
                    warrantyInfo.value = `Garansi Aktif (Exp: ${end})`;
                } else {
                    warrantyInfo.value = `Garansi Habis (Exp: ${end})`;
                }
                snFeedback.innerHTML = '<i class="fas fa-check-circle text-success"></i> Data unit ditemukan.';
                snFeedback.className = 'form-text text-success mt-1';
            } else {
                // Jika tidak ditemukan
                warrantyInfo.value = '';
                inputBrand.value = '';
                inputModel.value = '';
                snFeedback.innerHTML = '<i class="fas fa-exclamation-triangle text-danger"></i> SN tidak ditemukan di database pusat.';
                snFeedback.className = 'form-text text-danger mt-1';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            snFeedback.innerHTML = 'Gagal terhubung ke server.';
            snFeedback.className = 'form-text text-danger mt-1';
            submitBtn.disabled = false;
        });
}

loadMasterUnitReferences();
</script>
<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>




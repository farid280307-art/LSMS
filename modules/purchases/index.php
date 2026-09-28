<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$pdo = getDbConnection();
$role = $_SESSION['user']['role'];
$search = $_GET['search'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Ambil Data Cabang untuk Dropdown (Khusus Superadmin)
$branches = [];
if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    $stmt = $pdo->query("SELECT branch_code, branch_name FROM lsms_pusat.branches ORDER BY branch_name ASC");
    $branches = $stmt->fetchAll();
}

// Ambil Data Dealer (Dari Database Pusat)
$pdo_pusat = getDbConnection('pusat');
$dealers = $pdo_pusat->query("SELECT id, name FROM dealers ORDER BY name ASC")->fetchAll();

// Ambil semua data stok dari cabang yang relevan untuk dropdown dinamis
$all_branch_stock = [];
if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    global $db_configs;
    $branch_dbs = array_filter($db_configs, function($key) { return strpos($key, 'cabang_') === 0; }, ARRAY_FILTER_USE_KEY);
    foreach ($branch_dbs as $branch_code => $config) {
        $pdo_branch = getDbConnection($branch_code);
        if ($pdo_branch) {
            try {
                $branch_stock = $pdo_branch->query("SELECT id, part_name, type, sell_price, stock, '$branch_code' as branch_code FROM stock_buffer_cabang WHERE stock > 0")->fetchAll();
                $all_branch_stock = array_merge($all_branch_stock, $branch_stock);
            } catch (PDOException $e) {
                // Abaikan jika tabel tidak ada di cabang
            }
        }
    }
} else {
    // Untuk pengguna cabang
    $branch_code = $_SESSION['user']['cabang'];
    $all_branch_stock = $pdo->query("SELECT id, part_name, type, sell_price, stock, '$branch_code' as branch_code FROM stock_buffer_cabang WHERE stock > 0")->fetchAll();
}

// Query Data Pembelian
$params = [];
if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    global $db_configs;
    $union_parts = [];
    $branch_dbs = array_filter($db_configs, function($key) {
        return strpos($key, 'cabang_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($branch_dbs as $branch_code => $config) {
        $db_name = $config['dbname'];
        $union_parts[] = "SELECT *, '$branch_code' as branch_code FROM `$db_name`.purchases";
    }

    if (!empty($union_parts)) {
        $union_sql = implode(' UNION ALL ', $union_parts);
        $sql = "SELECT t.*, b.branch_name 
                FROM ({$union_sql}) as t
                LEFT JOIN `lsms_pusat`.branches b ON t.branch_code = b.branch_code";
        
        $where_clauses = [];
        if (!empty($search)) {
            $where_clauses[] = "(t.purchase_code LIKE ? OR t.item_name LIKE ? OR t.supplier_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if (!empty($date_filter)) {
            $where_clauses[] = "DATE(t.purchase_date) = ?";
            $params[] = $date_filter;
        }
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $sql .= " ORDER BY t.purchase_date DESC";
    } else {
        $sql = "";
    }
} else {
    $sql = "SELECT * FROM purchases";
    $where_clauses = [];
    if (!empty($search)) {
        $where_clauses[] = "(purchase_code LIKE ? OR item_name LIKE ? OR supplier_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (!empty($date_filter)) {
        $where_clauses[] = "DATE(purchase_date) = ?";
        $params[] = $date_filter;
    }
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(' AND ', $where_clauses);
    }
    $sql .= " ORDER BY purchase_date DESC";
}

$purchases = [];
if (!empty($sql)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll();
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
            <h2 class="fw-bold text-dark mb-4">Manajemen Penjualan (Direct Sales)</h2>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error' && isset($_GET['err_msg'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($_GET['err_msg']) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'saved'): ?>
                <div class="alert alert-success">Transaksi penjualan berhasil disimpan.</div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#purchaseModal">
                                <i class="fas fa-plus me-2"></i> Input Penjualan
                            </button>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" class="d-flex">
                                <input type="text" name="search" class="form-control me-2" placeholder="Cari Kode / Item / Pelanggan..." value="<?= htmlspecialchars($search) ?>">
                                <input type="date" name="date" class="form-control me-2" value="<?= htmlspecialchars($date_filter) ?>">
                                <button type="submit" class="btn btn-outline-primary">Cari</button>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Kode Transaksi</th>
                                    <th>Tanggal</th>
                                    <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                    <th>Cabang</th>
                                    <?php endif; ?>
                                    <th>Pelanggan / Dealer</th>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th class="text-end pe-3">Total Harga</th>
                                    <th class="text-end pe-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($purchases)): ?>
                                    <tr><td colspan="<?= (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])) ? 8 : 7 ?>" class="text-center py-4 text-muted">Belum ada data penjualan.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($purchases as $p): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($p['purchase_code']) ?></td>
                                        <td><?= date('d/M/Y', strtotime($p['purchase_date'])) ?></td>
                                        <?php if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin'])): ?>
                                        <td><?= htmlspecialchars($p['branch_name'] ?? $p['branch_code'] ?? '-') ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($p['supplier_name']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($p['item_name']) ?>
                                            <span class="badge bg-secondary ms-1"><?= ucfirst($p['item_type']) ?></span>
                                        </td>
                                        <td><?= number_format($p['quantity']) ?></td>
                                        <td class="text-end pe-3 fw-bold">Rp <?= number_format($p['total_price'], 0, ',', '.') ?></td>
                                        <td class="text-end pe-3">
                                            <?php $invoice_url = "invoice.php?code=" . urlencode($p['purchase_code']); 
                                                  if (in_array($role, ['superadmin', 'kepala_pusat', 'kepala_admin']) && isset($p['branch_code'])) $invoice_url .= "&branch=" . urlencode($p['branch_code']); ?>
                                            <a href="<?= $invoice_url ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak Invoice">
                                                <i class="fas fa-print"></i>
                                            </a>
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

<!-- Modal Input Pembelian -->
<div class="modal fade" id="purchaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Input Penjualan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process_input.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    
                    <!-- Section 1: Informasi Transaksi -->
                    <div class="row g-3 mb-4">
                        <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
                        <div class="col-md-6">
                            <label class="form-label small text-muted text-uppercase fw-bold">Cabang</label>
                            <select name="branch_code" class="form-select" required>
                                <option value="">-- Pilih Cabang --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= htmlspecialchars($b['branch_code']) ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-<?= (in_array($role, ['superadmin', 'kepala_admin'])) ? '6' : '12' ?>">
                            <label class="form-label small text-muted text-uppercase fw-bold">Tanggal</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small text-muted text-uppercase fw-bold d-block">Pembeli</label>
                            <div class="d-flex gap-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="buyer_type" id="typePersonal" value="personal" checked onchange="toggleBuyerType()">
                                    <label class="form-check-label" for="typePersonal">Umum</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="buyer_type" id="typeDealer" value="dealer" onchange="toggleBuyerType()">
                                    <label class="form-check-label" for="typeDealer">Dealer</label>
                                </div>
                            </div>
                            
                            <div id="personalBuyerForm">
                                <input type="text" name="supplier_name" class="form-control" placeholder="Nama Pelanggan" required>
                            </div>

                            <div id="dealerBuyerForm" style="display:none;">
                                <select name="dealer_id" class="form-select">
                                    <option value="">-- Pilih Dealer --</option>
                                    <?php foreach ($dealers as $d): ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Daftar Barang (Tabel) -->
                    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                        <h6 class="fw-bold m-0 text-primary"><i class="fas fa-shopping-cart me-2"></i>Keranjang Belanja</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItemRow()">
                            <i class="fas fa-plus me-1"></i> Tambah Barang
                        </button>
                    </div>
                    <div class="alert alert-info py-2 small">
                        Untuk sparepart, isi durasi garansi (0/3/6 bulan). Serial number sparepart bersifat opsional.
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-borderless align-middle">
                            <thead class="table-light small text-muted">
                                <tr>
                                    <th style="width: 20%">Tipe</th>
                                    <th style="width: 35%">Nama Barang</th>
                                    <th style="width: 15%">Qty</th>
                                    <th style="width: 25%" class="text-end">Subtotal</th>
                                    <th style="width: 5%"></th>
                                </tr>
                            </thead>
                            <tbody id="items-container">
                                <!-- Rows added via JS -->
                            </tbody>
                            <tfoot class="border-top">
                                <tr>
                                    <td colspan="3" class="text-end fw-bold text-muted">Total Estimasi:</td>
                                    <td class="text-end fw-bold text-primary fs-5" id="grand-total">Rp 0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-link text-muted text-decoration-none" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Simpan Transaksi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const allStockData = <?= json_encode($all_branch_stock) ?>;

function toggleBuyerType() {
    const type = document.querySelector('input[name="buyer_type"]:checked').value;
    const personalForm = document.getElementById('personalBuyerForm');
    const dealerForm = document.getElementById('dealerBuyerForm');
    const personalInput = document.querySelector('[name="supplier_name"]');
    const dealerInput = document.querySelector('[name="dealer_id"]');

    if (type === 'personal') {
        personalForm.style.display = 'block';
        dealerForm.style.display = 'none';
        personalInput.required = true;
        dealerInput.required = false;
    } else {
        personalForm.style.display = 'none';
        dealerForm.style.display = 'block';
        personalInput.required = false;
        dealerInput.required = true;
    }
}

function addItemRow() {
    const container = document.getElementById('items-container');
    const rowId = Date.now(); // Gunakan timestamp untuk ID unik
    
    const html = `
        <tr id="row-${rowId}">
            <td>
                <select name="items[${rowId}][item_type]" class="form-select form-select-sm" onchange="populateItems(${rowId})" required>
                    <option value="">-- Tipe --</option>
                    <option value="sparepart">Sparepart</option>
                    <option value="device">Device</option>
                </select>
            </td>
            <td>
                <select name="items[${rowId}][item_id]" id="item-select-${rowId}" class="form-select form-select-sm mb-1" required onchange="updatePriceInfo(${rowId})">
                    <option value="">-- Pilih --</option>
                </select>
                <div id="serial-row-${rowId}" style="display:none;">
                    <input type="text" name="items[${rowId}][serial_number]" id="serial-input-${rowId}" class="form-control form-control-sm bg-light mb-1" placeholder="Serial Number (Opsional)" style="font-size: 0.85rem;">
                    <div id="serial-help-${rowId}" class="text-muted" style="font-size: 0.72rem;">Serial number opsional</div>
                </div>
                <div id="warranty-row-${rowId}" style="display:none;">
                    <select name="items[${rowId}][warranty_duration]" id="warranty-${rowId}" class="form-select form-select-sm mt-1" style="font-size: 0.85rem;">
                        <option value="0">Garansi: 0 bulan</option>
                        <option value="3">Garansi: 3 bulan</option>
                        <option value="6">Garansi: 6 bulan</option>
                    </select>
                </div>
                <div class="text-muted" style="font-size: 0.75rem;">Stok: <span id="stock-${rowId}">-</span></div>
            </td>
            <td>
                <input type="number" name="items[${rowId}][quantity]" id="qty-${rowId}" class="form-control form-control-sm text-center" value="1" min="1" required onchange="updatePriceInfo(${rowId})" onkeyup="updatePriceInfo(${rowId})">
            </td>
            <td class="text-end">
                <div class="fw-bold" id="subtotal-${rowId}">Rp 0</div>
                <input type="hidden" id="price-input-${rowId}" value="0">
            </td>
            <td class="text-end">
                <button type="button" class="btn btn-link text-danger p-0" onclick="removeRow(${rowId})"><i class="fas fa-times"></i></button>
            </td>
        </tr>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function removeRow(rowId) {
    document.getElementById(`row-${rowId}`).remove();
    calculateGrandTotal();
}

function populateItems(rowId) {
    const typeSelect = document.querySelector(`select[name="items[${rowId}][item_type]"]`);
    const itemSelect = document.getElementById(`item-select-${rowId}`);
    const serialRow = document.getElementById(`serial-row-${rowId}`);
    const serialInput = document.getElementById(`serial-input-${rowId}`);
    const serialHelp = document.getElementById(`serial-help-${rowId}`);
    const warrantyRow = document.getElementById(`warranty-row-${rowId}`);
    const warrantyInput = document.getElementById(`warranty-${rowId}`);
    const qtyInput = document.getElementById(`qty-${rowId}`);
    
    const type = typeSelect.value;
    itemSelect.innerHTML = '<option value="">-- Pilih Item --</option>'; // Reset

    let branchCode = '<?= $role !== 'superadmin' ? $_SESSION['user']['cabang'] : '' ?>';
    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
    const branchSelect = document.querySelector('select[name="branch_code"]');
    if (branchSelect) {
        branchCode = branchSelect.value;
    }
    if (!branchCode) {
        itemSelect.innerHTML = '<option value="">-- Pilih Cabang Dahulu --</option>';
        return;
    }
    <?php endif; ?>

    if (!type) {
        itemSelect.innerHTML = '<option value="">-- Pilih Tipe Item Dahulu --</option>';
        serialRow.style.display = 'none';
        warrantyRow.style.display = 'none';
        serialInput.required = false;
        serialInput.value = '';
        warrantyInput.value = '0';
        qtyInput.readOnly = false;
        return;
    }

    const filteredStock = allStockData.filter(item => item.type === type && item.branch_code === branchCode);

    if (filteredStock.length === 0) {
        itemSelect.innerHTML = '<option value="">-- Stok Kosong --</option>';
    } else {
        filteredStock.forEach(item => {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = `${item.part_name} (${item.part_code})`;
            option.dataset.price = item.sell_price;
            option.dataset.stock = item.stock;
            itemSelect.appendChild(option);
        });
    }

    // Toggle serial + warranty field
    if (type === 'device') {
        serialRow.style.display = 'block';
        warrantyRow.style.display = 'none';
        qtyInput.value = 1;
        qtyInput.readOnly = true;
        serialInput.required = true;
        serialInput.placeholder = 'Scan SN Unit (Wajib)';
        serialHelp.textContent = 'Serial number wajib untuk item device';
        warrantyInput.value = '0';
    } else if (type === 'sparepart') {
        serialRow.style.display = 'block';
        warrantyRow.style.display = 'block';
        qtyInput.readOnly = false;
        serialInput.required = false;
        serialInput.placeholder = 'Serial Number Sparepart (Opsional)';
        serialHelp.textContent = 'Serial number opsional. Jika kosong, tracking pakai invoice + item';
    } else {
        serialRow.style.display = 'none';
        warrantyRow.style.display = 'none';
        qtyInput.readOnly = false;
        serialInput.required = false;
        serialInput.value = '';
        warrantyInput.value = '0';
    }
    updatePriceInfo(rowId); // Reset price info
}

function updatePriceInfo(rowId) {
    const itemSelect = document.getElementById(`item-select-${rowId}`);
    const selectedOption = itemSelect.options[itemSelect.selectedIndex];
    const price = parseFloat(selectedOption.dataset.price || 0);
    const stock = selectedOption.dataset.stock || '-';
    const qty = parseFloat(document.getElementById(`qty-${rowId}`).value || 0);
    const subtotal = price * qty;
    
    document.getElementById(`price-input-${rowId}`).value = price;
    document.getElementById(`subtotal-${rowId}`).innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(subtotal);
    document.getElementById(`stock-${rowId}`).innerText = stock;
    
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let total = 0;
    const container = document.getElementById('items-container');
    const rows = container.querySelectorAll('tr');
    rows.forEach(row => {
        const rowId = row.id.replace('row-', '');
        const price = parseFloat(document.getElementById(`price-input-${rowId}`).value || 0);
        const qty = parseFloat(document.getElementById(`qty-${rowId}`).value || 0);
        total += price * qty;
    });
    document.getElementById('grand-total').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
}

document.addEventListener('DOMContentLoaded', function() {
    toggleBuyerType();
    addItemRow(); // Add first row by default
    <?php if (in_array($role, ['superadmin', 'kepala_admin'])): ?>
    document.querySelector('select[name="branch_code"]').addEventListener('change', function() {
        // Reset all rows when branch changes
        document.getElementById('items-container').innerHTML = '';
        addItemRow();
    });
    <?php endif; ?>
});
</script>
<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

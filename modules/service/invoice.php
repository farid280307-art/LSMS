<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'teknisi'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
$branch_override = (in_array($_SESSION['user']['role'], ['superadmin', 'kepala_pusat'])) ? ($_GET['branch'] ?? null) : null;
$pdo = getDbConnection($branch_override);

// 1. Fetch Service, Device, Customer/Dealer Data
$sql = "SELECT s.*, 
               COALESCE(c.name, dl.name) as customer_name, 
               COALESCE(c.phone, dl.phone) as customer_phone,
               COALESCE(c.address, dl.address) as customer_address,
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
    die("Invoice tidak ditemukan.");
}

// 2. Fetch Service Items
$stmt = $pdo->prepare("SELECT * FROM service_items WHERE service_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// 3. Fetch Payment Data
$stmt = $pdo->prepare("SELECT p.*, u.full_name as receiver_name 
                       FROM payments p 
                       LEFT JOIN lsms_pusat.users u ON p.received_by_user_id = u.id
                       WHERE p.service_id = ? ORDER BY p.payment_date DESC LIMIT 1");
$stmt->execute([$id]);
$payment = $stmt->fetch();

// 4. Fetch Branch Data
$branch_info = null;
$branch_code_to_fetch = $branch_override ?: ($_SESSION['user']['cabang'] ?? null);
if ($branch_code_to_fetch) {
    $pdo_pusat = getDbConnection('pusat');
    $stmt_branch = $pdo_pusat->prepare("SELECT * FROM branches WHERE branch_code = ?");
    $stmt_branch->execute([$branch_code_to_fetch]);
    $branch_info = $stmt_branch->fetch();
}

// Calculate total
$total_cost = 0;
foreach ($items as $item) {
    $total_cost += $item['price'];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INVOICE - <?= htmlspecialchars($payment['payment_code'] ?? $service['service_code']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --line: #1e293b;
            --page-bg: #f1f5f9;
            --accent: #2563eb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--page-bg);
            font-family: 'Inter', Arial, sans-serif;
            color: var(--ink);
            min-width: 210mm;
        }

        .toolbar {
            width: 210mm;
            margin: 15px auto 5px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #1e293b;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }

        .btn-primary { background: #1e293b; color: #fff; }
        .btn-light { background: #fff; color: #1e293b; }
        .btn:hover { opacity: 0.9; }

        .sheet {
            width: 210mm;
            min-height: auto;
            margin: 10px auto;
            background: #fff;
            padding: 10mm;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            position: relative;
            overflow: hidden;
        }

        /* Watermark LUNAS */
        .paid-stamp {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 120px;
            font-weight: 900;
            color: rgba(34, 197, 94, 0.15);
            border: 15px solid rgba(34, 197, 94, 0.15);
            padding: 10px 40px;
            border-radius: 20px;
            pointer-events: none;
            text-transform: uppercase;
            z-index: 0;
        }

        .head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            z-index: 1;
        }

        .logo-img {
            max-height: 50px;
            margin-bottom: 10px;
        }

        .branch-name {
            font-size: 22px;
            font-weight: 800;
            margin: 0;
            color: var(--accent);
        }

        .branch-info {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.4;
            max-width: 300px;
        }

        .invoice-title-box {
            text-align: right;
        }

        .invoice-label {
            font-size: 32px;
            font-weight: 900;
            margin: 0;
            letter-spacing: -1px;
            color: var(--line);
        }

        .invoice-meta {
            margin-top: 5px;
            font-size: 13px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 30px;
            z-index: 1;
        }

        .info-section h6 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--muted);
            margin: 0 0 8px 0;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }

        .info-content p {
            margin: 2px 0;
            font-size: 13px;
        }

        .info-content strong {
            font-size: 15px;
            display: block;
            margin-bottom: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            z-index: 1;
        }

        th {
            background: #f8fafc;
            text-align: left;
            padding: 12px 10px;
            font-size: 12px;
            text-transform: uppercase;
            border-bottom: 2px solid var(--line);
        }

        td {
            padding: 12px 10px;
            font-size: 13px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .col-no { width: 40px; text-align: center; color: var(--muted); }
        .col-price { width: 150px; text-align: right; font-weight: 600; }

        .summary-box {
            margin-left: auto;
            width: 300px;
            z-index: 1;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }

        .summary-total {
            border-top: 2px solid var(--line);
            margin-top: 5px;
            padding-top: 10px;
            font-weight: 800;
            font-size: 18px;
            color: var(--accent);
        }

        .notes-section {
            margin-top: 40px;
            font-size: 11px;
            color: var(--muted);
            line-height: 1.5;
            z-index: 1;
        }

        .footer {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--muted);
            z-index: 1;
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { 
                box-shadow: none; 
                margin: 0; 
                padding: 5mm;
                width: 100%;
            }
            .paid-stamp { color: rgba(34, 197, 94, 0.1) !important; border-color: rgba(34, 197, 94, 0.1) !important; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a href="javascript:history.back()" class="btn btn-light"><i class="fas fa-arrow-left"></i> Kembali</a>
    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Invoice</button>
</div>

<main class="sheet">
    <?php if ($payment): ?>
        <div class="paid-stamp">LUNAS</div>
    <?php endif; ?>

    <header class="head">
        <div>
            <img src="../../public/assets/img/logo.png" alt="Logo" class="logo-img">
            <h1 class="branch-name"><?= htmlspecialchars($branch_info['branch_name'] ?? 'Service Center') ?></h1>
            <div class="branch-info">
                <?= nl2br(htmlspecialchars($branch_info['address'] ?? '')) ?><br>
                <strong>Telp/WA:</strong> <?= htmlspecialchars($branch_info['phone'] ?? '-') ?>
            </div>
        </div>
        <div class="invoice-title-box">
            <h2 class="invoice-label">INVOICE</h2>
            <div class="invoice-meta">
                <strong>No:</strong> <?= htmlspecialchars($payment['payment_code'] ?? 'N/A') ?><br>
                <strong>Tanggal:</strong> <?= date('d/m/Y', strtotime($payment['payment_date'] ?? $service['updated_at'])) ?>
            </div>
        </div>
    </header>

    <section class="info-grid">
        <div class="info-section">
            <h6>Pelanggan</h6>
            <div class="info-content">
                <strong><?= htmlspecialchars($service['customer_name']) ?></strong>
                <p><?= htmlspecialchars($service['customer_phone'] ?? '-') ?></p>
                <p><?= htmlspecialchars($service['customer_address'] ?? 'Alamat tidak tersedia') ?></p>
            </div>
        </div>
        <div class="info-section">
            <h6>Detail Unit & Service</h6>
            <div class="info-content">
                <p><strong>Tiket:</strong> <?= htmlspecialchars($service['service_code']) ?></p>
                <p><strong>Unit:</strong> <?= htmlspecialchars($service['brand'] . ' ' . $service['model']) ?></p>
                <p><strong>S/N:</strong> <?= htmlspecialchars($service['serial_number']) ?></p>
            </div>
        </div>
    </section>

    <table>
        <thead>
            <tr>
                <th class="col-no">No.</th>
                <th>Deskripsi Perbaikan / Sparepart</th>
                <th class="text-end">Harga</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($items as $item): ?>
            <tr>
                <td class="col-no"><?= $i++ ?></td>
                <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($item['description']) ?></div>
                    <div style="font-size: 11px; color: var(--muted);"><?= $item['item_type'] == 'sparepart' ? 'Penggantian Komponen' : 'Biaya Jasa Service' ?></div>
                </td>
                <td class="col-price">Rp <?= number_format($item['price'], 0, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary-box">
        <div class="summary-row">
            <span>Subtotal</span>
            <span>Rp <?= number_format($total_cost, 0, ',', '.') ?></span>
        </div>
        <div class="summary-row summary-total">
            <span>TOTAL AKHIR</span>
            <span>Rp <?= number_format($total_cost, 0, ',', '.') ?></span>
        </div>
        <?php if ($payment): ?>
            <div class="summary-row" style="margin-top: 10px; font-size: 12px;">
                <span>Metode Pembayaran</span>
                <span class="badge bg-secondary"><?= strtoupper($payment['payment_method']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="notes-section">
        <strong>Catatan Syarat & Ketentuan:</strong>
        <ul>
            <li>Garansi service berlaku selama 7 hari kalender sejak tanggal pengambilan untuk keluhan yang sama.</li>
            <li>Garansi tidak berlaku apabila segel rusak, terkena cairan, atau terjadi kerusakan fisik akibat kelalaian penggunaan.</li>
            <li>Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan kecuali ada perjanjian sebelumnya.</li>
            <li>Invoice ini adalah bukti pembayaran yang sah.</li>
        </ul>
    </div>

    <footer class="footer">
        <span>Kasir: <?= htmlspecialchars($payment['receiver_name'] ?? 'System') ?></span>
        <span style="font-weight: bold;">Terima Kasih Atas Kepercayaan Anda</span>
        <span>Printed: <?= date('d/m/Y H:i') ?></span>
    </footer>
</main>

</body>
</html>
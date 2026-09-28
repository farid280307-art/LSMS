<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$code = $_GET['code'] ?? '';
$branch_override = (in_array($_SESSION['user']['role'], ['superadmin', 'kepala_pusat'])) ? ($_GET['branch'] ?? null) : null;
$pdo = getDbConnection($branch_override);

// 1) Coba ambil data dari tabel sales (versi baru).
$sales = null;
$items = [];

try {
    $stmt_sales = $pdo->prepare(
        "SELECT s.*, u.full_name as seller_name
         FROM sales s
         LEFT JOIN lsms_pusat.users u ON s.created_by_user_id = u.id
         WHERE s.invoice_code = ?
         LIMIT 1"
    );
    $stmt_sales->execute([$code]);
    $sales = $stmt_sales->fetch();

    if ($sales) {
        $stmt_sales_items = $pdo->prepare(
            "SELECT
                item_name,
                item_type,
                quantity,
                price AS unit_price,
                (quantity * price) AS total_price
             FROM sales_items
             WHERE sales_id = ?
             ORDER BY id ASC"
        );
        $stmt_sales_items->execute([$sales['id']]);
        $items = $stmt_sales_items->fetchAll() ?: [];
    }
} catch (Throwable $e) {
    $sales = null;
    $items = [];
}

// 2) Fallback ke tabel purchases (versi lama/legacy).
$purchase = null;
try {
    $sql = "SELECT p.*,
                   u.full_name as seller_name
            FROM purchases p
            LEFT JOIN lsms_pusat.users u ON p.created_by_user_id = u.id
            WHERE p.purchase_code = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code]);
    $purchase = $stmt->fetch();
} catch (Throwable $e) {
    $purchase = null;
}

if (!$sales && !$purchase) {
    die("Data transaksi tidak ditemukan.");
}

if (empty($items) && $purchase) {
    $stmt = $pdo->prepare("SELECT * FROM purchases WHERE purchase_code = ?");
    $stmt->execute([$purchase['purchase_code']]);
    $items = $stmt->fetchAll() ?: [];
}

if (!$purchase && $sales) {
    $purchase = [
        'purchase_code' => $sales['invoice_code'],
        'purchase_date' => $sales['created_at'],
        'supplier_name' => $sales['customer_name'] ?: 'Pelanggan Umum',
        'payment_method' => 'CASH',
        'seller_name' => $sales['seller_name'] ?? 'System',
    ];
}

// 3. Ambil Data Cabang
$branch_info = null;
$branch_code_to_fetch = $branch_override ?: ($_SESSION['user']['cabang'] ?? null);
if ($branch_code_to_fetch) {
    $pdo_pusat = getDbConnection('pusat');
    $stmt_branch = $pdo_pusat->prepare("SELECT * FROM branches WHERE branch_code = ?");
    $stmt_branch->execute([$branch_code_to_fetch]);
    $branch_info = $stmt_branch->fetch();
}

$total_amount = 0;
foreach ($items as $item) {
    $total_amount += (float)$item['total_price'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INVOICE PEMBELIAN - <?= htmlspecialchars($purchase['purchase_code'] ?? 'N/A') ?></title>
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
            overflow-x: auto;
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
        .sheet {
            width: 210mm;
            min-width: 210mm;
            min-height: auto;
            margin: 10px auto;
            background: #fff;
            padding: 10mm; /* Margin kecil sesuai permintaan sebelumnya */
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            position: relative;
            flex-shrink: 0;
        }
        .paid-stamp {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 100px;
            font-weight: 900;
            color: rgba(34, 197, 94, 0.12);
            border: 12px solid rgba(34, 197, 94, 0.12);
            padding: 10px 40px;
            border-radius: 20px;
            text-transform: uppercase;
            pointer-events: none;
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
        .branch-name { font-size: 22px; font-weight: 800; color: var(--accent); margin: 0; }
        .branch-info { font-size: 11px; color: var(--muted); line-height: 1.4; }
        .invoice-label { font-size: 28px; font-weight: 900; margin: 0; color: var(--line); }
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
        .info-content p { margin: 2px 0; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; z-index: 1; }
        th { background: #f8fafc; padding: 12px 10px; font-size: 12px; text-transform: uppercase; text-align: left; border-bottom: 2px solid var(--line); }
        td { padding: 12px 10px; font-size: 13px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .text-end { text-align: right; }
        .summary-box { margin-left: auto; width: 300px; z-index: 1; }
        .summary-total { border-top: 2px solid var(--line); margin-top: 5px; padding-top: 10px; font-weight: 800; font-size: 18px; color: var(--accent); }
        .footer { margin-top: auto; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); }
        
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; margin: 0; width: 100%; padding: 5mm; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a href="javascript:history.back()" class="btn btn-light"><i class="fas fa-arrow-left"></i> Kembali</a>
    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Invoice</button>
</div>

<main class="sheet">
    <div class="paid-stamp">LUNAS</div>

    <header class="head">
        <div>
            <img src="../../public/assets/img/logo.png" alt="Logo" class="logo-img">
            <h1 class="branch-name"><?= htmlspecialchars($branch_info['branch_name'] ?? 'Service Center') ?></h1>
            <div class="branch-info">
                <?= nl2br(htmlspecialchars($branch_info['address'] ?? '')) ?><br>
                <strong>WA:</strong> <?= htmlspecialchars($branch_info['phone'] ?? '-') ?>
            </div>
        </div>
        <div class="text-end">
            <h2 class="invoice-label">INVOICE</h2>
            <div style="font-size: 12px; margin-top: 5px;">
                <strong>No:</strong> <?= htmlspecialchars($purchase['purchase_code']) ?><br>
                <strong>Tanggal:</strong> <?= date('d/m/Y', strtotime($purchase['purchase_date'])) ?>
            </div>
        </div>
    </header>

    <section class="info-grid">
        <div class="info-section">
            <h6>Pelanggan / Dealer</h6>
            <div class="info-content">
                <strong><?= htmlspecialchars($purchase['supplier_name'] ?? 'Pelanggan Umum') ?></strong>
                <p>-</p>
                <p>-</p>
            </div>
        </div>
        <div class="info-section">
            <h6>Metode Pembayaran</h6>
            <div class="info-content">
                <p><strong>Status:</strong> <span style="color: #16a34a; font-weight: bold;">LUNAS</span></p>
                <p><strong>Metode:</strong> <?= strtoupper(htmlspecialchars($purchase['payment_method'] ?? 'CASH')) ?></p>
            </div>
        </div>
    </section>

    <table>
        <thead>
            <tr>
                <th style="width: 40px; text-align: center;">No.</th>
                <th>Item / Sparepart</th>
                <th style="width: 60px; text-align: center;">Qty</th>
                <th style="width: 120px;" class="text-end">Harga Satuan</th>
                <th style="width: 120px;" class="text-end">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($items as $item): ?>
            <tr>
                <td style="text-align: center;"><?= $i++ ?></td>
                <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($item['item_name']) ?></div>
                    <div style="font-size: 10px; color: var(--muted);">Part Code: <?= htmlspecialchars($item['part_code'] ?? '-') ?></div>
                </td>
                <td style="text-align: center;"><?= number_format($item['quantity'] ?? 1) ?></td>
                <td class="text-end">Rp <?= number_format($item['unit_price'] ?? 0, 0, ',', '.') ?></td>
                <td class="text-end" style="font-weight: 600;">Rp <?= number_format($item['total_price'] ?? 0, 0, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary-box">
        <div class="summary-total text-end" style="display: flex; justify-content: space-between;">
            <span>TOTAL AKHIR</span>
            <span>Rp <?= number_format($total_amount, 0, ',', '.') ?></span>
        </div>
    </div>

    <div style="margin-top: 30px; font-size: 10px; color: var(--muted);">
        <strong>Catatan:</strong>
        <ul style="padding-left: 15px; margin-top: 5px;">
            <li>Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan.</li>
            <li>Mohon simpan invoice ini sebagai bukti pembelian yang sah.</li>
            <li>Garansi sparepart (jika ada) berlaku sesuai dengan ketentuan masing-masing item.</li>
        </ul>
    </div>

    <footer class="footer">
        <span>Petugas: <?= htmlspecialchars($purchase['seller_name'] ?? 'System') ?></span>
        <span style="font-weight: bold;">Terima Kasih Atas Pembelian Anda</span>
        <span>Waktu Cetak: <?= date('d/m/Y H:i') ?></span>
    </footer>
</main>

</body>
</html>

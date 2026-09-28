<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$ids_param = $_GET['ids'] ?? $_GET['id'] ?? '';
$pdo = getDbConnection('pusat');

if (empty($ids_param)) {
    die("Data transfer tidak ditemukan.");
}

// Convert comma-separated IDs to array for query
$ids = explode(',', $ids_param);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Ambil Data Transfer (Banyak Baris)
$sql = "SELECT t.*, b.branch_name, b.address as branch_address, b.phone as branch_phone, 
               s.item_name, s.item_type, u.full_name as sender_name
        FROM transfer_stock t
        LEFT JOIN branches b ON t.to_branch_id = b.id
        LEFT JOIN stock_buffer_pusat s ON t.item_code = s.item_code
        LEFT JOIN users u ON t.created_by_user_id = u.id
        WHERE t.id IN ($placeholders)";

$stmt = $pdo->prepare($sql);
$stmt->execute($ids);
$raw_transfers = $stmt->fetchAll();

// 1. Deduplicate & 2. Aggregate Items
$aggregated_transfers = [];
$seen_ids = []; // Untuk mencegah duplikasi baris akibat JOIN

foreach ($raw_transfers as $rt) {
    if (isset($seen_ids[$rt['id']])) continue;
    $seen_ids[$rt['id']] = true;

    $key = $rt['item_code']; // Group by Item Code
    if (!isset($aggregated_transfers[$key])) {
        $aggregated_transfers[$key] = $rt;
        $aggregated_transfers[$key]['quantity'] = 0; // Reset qty untuk akumulasi
    }
    $aggregated_transfers[$key]['quantity'] += $rt['quantity'];
}

// Re-index array
$transfers = array_values($aggregated_transfers);

if (empty($transfers)) {
    die("Data transfer tidak ditemukan.");
}

// Ambil data header dari item pertama (asumsi semua item dalam satu batch transfer memiliki tujuan & pengirim sama)
$header = $transfers[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan - <?= htmlspecialchars($header['id']) ?></title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
        }
        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            -webkit-print-color-adjust: exact;
            margin: 0;
            padding: 0;
        }
        .invoice-container {
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .header-title { font-size: 24px; font-weight: 800; color: var(--primary); letter-spacing: -0.5px; }
        .status-badge {
            background: #dcfce7; color: #166534;
            padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase;
        }
        .info-label { font-size: 11px; text-transform: uppercase; color: var(--text-light); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-value { font-size: 14px; font-weight: 500; }
        
        table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        th { text-align: left; padding: 12px 16px; background-color: var(--bg-light); color: var(--text-light); font-size: 12px; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 14px; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        
        .footer-note { margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 12px; color: var(--text-light); line-height: 1.6; }
        
        .signature-section { margin-top: 50px; display: flex; justify-content: space-between; }
        .signature-box { width: 200px; text-align: center; }
        
        .btn { padding: 10px 20px; border-radius: 6px; font-weight: 500; cursor: pointer; border: none; font-size: 14px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: var(--text-dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .d-flex { display: flex; }
        .justify-content-center { justify-content: center; }
        .gap-2 { gap: 0.5rem; }
        .my-4 { margin-top: 1.5rem; margin-bottom: 1.5rem; }

        @media print {
            body { background-color: #fff; }
            .invoice-container { box-shadow: none; margin: 0; max-width: 100%; padding: 0; }
            .actions { display: none; }
        }
        @media (max-width: 768px) {
            .invoice-container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>

<div class="invoice-container">
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px;">
        <div>
            <div class="header-title">SURAT JALAN</div>
        </div>
        <div class="text-end">
            <div class="info-label">No. Surat Jalan</div>
            <div class="info-value" style="font-size: 16px;">SJ-<?= date('Ymd', strtotime($header['transfer_date'])) ?>-<?= str_pad($header['id'], 4, '0', STR_PAD_LEFT) ?></div>
            <div class="info-label" style="margin-top: 10px;">Tanggal Kirim</div>
            <div class="info-value"><?= date('d F Y', strtotime($header['transfer_date'])) ?></div>
        </div>
    </div>

    <!-- Sender & Receiver -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 40px;">
        <div style="width: 45%;">
            <div class="info-label">Pengirim</div>
            <div class="info-value" style="font-size: 16px; margin-bottom: 4px;">LSMS PUSAT</div>
            <div style="color: var(--text-light); font-size: 13px; line-height: 1.5;">
                Gudang Pusat<br>
                Laptop Service Management System
            </div>
            <div style="margin-top: 8px; font-size: 13px;">
                <strong>PIC:</strong> <?= htmlspecialchars($header['sender_name'] ?? '-') ?>
            </div>
        </div>
        <div style="width: 45%;">
            <div class="info-label">Penerima</div>
            <div class="info-value" style="font-size: 16px; margin-bottom: 4px;"><?= htmlspecialchars($header['branch_name'] ?? '') ?></div>
            <div style="color: var(--text-light); font-size: 13px; line-height: 1.5;">
                <?= nl2br(htmlspecialchars($header['branch_address'] ?? '')) ?><br>
                Telp: <?= htmlspecialchars($header['branch_phone'] ?? '-') ?>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 20%;">Kode Barang</th>
                <th style="width: 40%;">Nama Barang</th>
                <th class="text-center" style="width: 10%;">Qty</th>
                <th style="width: 25%;">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($transfers as $t): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($t['item_code']) ?></div>
                </td>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($t['item_name']) ?></div>
                    <div style="font-size: 12px; color: var(--text-light);"><?= ucfirst($t['item_type']) ?></div>
                </td>
                <td class="text-center" style="font-weight: 600;"><?= number_format($t['quantity']) ?></td>
                <td style="color: var(--text-light); font-size: 13px;"><?= htmlspecialchars($t['notes']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="info-label" style="margin-bottom: 60px;">Penerima</div>
            <div style="border-top: 1px solid var(--border); padding-top: 8px;">
                <div class="info-value"><?= htmlspecialchars($header['branch_name'] ?? '....................') ?></div>
            </div>
        </div>
        <div class="signature-box">
            <div class="info-label" style="margin-bottom: 60px;">Hormat Kami,</div>
            <div style="border-top: 1px solid var(--border); padding-top: 8px;">
                <div class="info-value"><?= htmlspecialchars($header['sender_name'] ?? '....................') ?></div>
            </div>
        </div>
    </div>
    
    <!-- Footer Notes -->
    <div class="footer-note">
        <strong>Catatan:</strong><br>
        1. Harap periksa kondisi barang saat diterima.<br>
        2. Segera konfirmasi penerimaan barang di sistem setelah fisik diterima.<br>
        3. Dokumen ini sah sebagai bukti serah terima barang antar cabang.
    </div>
</div>

<div class="actions d-flex justify-content-center gap-2 my-4">
    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Surat Jalan</button>
    <button onclick="window.close()" class="btn btn-secondary"><i class="fas fa-times"></i> Tutup</button>
</div>

</body>
</html>
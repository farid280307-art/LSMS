<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
$branch_code = $_GET['branch'] ?? $_SESSION['user']['cabang'] ?? null;

if (!$branch_code && in_array($_SESSION['user']['role'], ['superadmin', 'kepala_pusat', 'kepala_admin'])) {
    die("Branch code required.");
}

$pdo = getDbConnection($branch_code);

// Ambil Data Item Rusak
$sql = "SELECT b.*, u.full_name as sender_name 
        FROM broken_buffers b
        LEFT JOIN lsms_pusat.users u ON b.reported_by_user_id = u.id
        WHERE b.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die("Data tidak ditemukan.");
}

// Ambil Data Cabang Pengirim
$pdo_pusat = getDbConnection('pusat');
$stmt_branch = $pdo_pusat->prepare("SELECT * FROM branches WHERE branch_code = ?");
$stmt_branch->execute([$branch_code]);
$branch = $stmt_branch->fetch();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan Retur - <?= htmlspecialchars($item['id']) ?></title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #dc2626; /* Merah untuk Retur */
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
        .info-label { font-size: 11px; text-transform: uppercase; color: var(--text-light); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-value { font-size: 14px; font-weight: 500; }
        
        table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        th { text-align: left; padding: 12px 16px; background-color: var(--bg-light); color: var(--text-light); font-size: 12px; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 14px; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        
        .signature-section { margin-top: 50px; display: flex; justify-content: space-between; }
        .signature-box { width: 200px; text-align: center; }
        
        .btn { padding: 10px 20px; border-radius: 6px; font-weight: 500; cursor: pointer; border: none; font-size: 14px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #b91c1c; }
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
            <div class="header-title">SURAT JALAN (RETUR)</div>
            <div style="margin-top: 8px; font-size: 14px; color: var(--text-light);">Pengiriman Barang Rusak ke Pusat</div>
        </div>
        <div class="text-end">
            <div class="info-label">No. Dokumen</div>
            <div class="info-value" style="font-size: 16px;">RET-<?= date('Ymd', strtotime($item['reported_at'])) ?>-<?= str_pad($item['id'], 4, '0', STR_PAD_LEFT) ?></div>
            <div class="info-label" style="margin-top: 10px;">Tanggal Kirim</div>
            <div class="info-value"><?= date('d F Y') ?></div>
        </div>
    </div>

    <!-- Sender & Receiver -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 40px;">
        <div style="width: 45%;">
            <div class="info-label">Pengirim (Cabang)</div>
            <div class="info-value" style="font-size: 16px; margin-bottom: 4px;"><?= htmlspecialchars($branch['branch_name'] ?? 'Cabang') ?></div>
            <div style="color: var(--text-light); font-size: 13px; line-height: 1.5;">
                <?= nl2br(htmlspecialchars($branch['address'] ?? '')) ?><br>
                Telp: <?= htmlspecialchars($branch['phone'] ?? '-') ?>
            </div>
            <div style="margin-top: 8px; font-size: 13px;">
                <strong>PIC:</strong> <?= htmlspecialchars($item['sender_name'] ?? '-') ?>
            </div>
        </div>
        <div style="width: 45%;">
            <div class="info-label">Penerima</div>
            <div class="info-value" style="font-size: 16px; margin-bottom: 4px;">LSMS PUSAT</div>
            <div style="color: var(--text-light); font-size: 13px; line-height: 1.5;">
                Gudang Pusat / Bagian Retur<br>
                Laptop Service Management System
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 25%;">Kode Barang</th>
                <th style="width: 35%;">Nama Barang</th>
                <th class="text-center" style="width: 10%;">Qty</th>
                <th style="width: 25%;">Keterangan / SN</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td><div style="font-weight: 500;"><?= htmlspecialchars($item['part_code']) ?></div></td>
                <td><div style="font-weight: 500;"><?= htmlspecialchars($item['part_name']) ?></div></td>
                <td class="text-center" style="font-weight: 600;"><?= number_format($item['quantity']) ?></td>
                <td style="color: var(--text-light); font-size: 13px;">
                    <?= htmlspecialchars($item['notes']) ?>
                    <?php if(!empty($item['serial_number'])): ?>
                        <br><strong>SN:</strong> <?= htmlspecialchars($item['serial_number']) ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="info-label" style="margin-bottom: 60px;">Penerima (Pusat)</div>
            <div style="border-top: 1px solid var(--border); padding-top: 8px;">
                <div class="info-value">....................</div>
            </div>
        </div>
        <div class="signature-box">
            <div class="info-label" style="margin-bottom: 60px;">Pengirim (Cabang)</div>
            <div style="border-top: 1px solid var(--border); padding-top: 8px;">
                <div class="info-value"><?= htmlspecialchars($item['sender_name'] ?? '....................') ?></div>
            </div>
        </div>
    </div>
</div>

<div class="actions d-flex justify-content-center gap-2 my-4">
    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Surat Jalan</button>
    <button onclick="window.close()" class="btn btn-secondary"><i class="fas fa-times"></i> Tutup</button>
</div>

</body>
</html>
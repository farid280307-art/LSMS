<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'teknisi'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$id = (int)($_GET['id'] ?? 0);
$raw_type = strtolower(trim((string)($_GET['type'] ?? 'masuk')));
$doc_type = ($raw_type === 'keluar') ? 'keluar' : 'masuk';
$is_service_in = $doc_type === 'masuk';

$branch_override = (in_array($_SESSION['user']['role'], ['superadmin', 'kepala_pusat', 'kepala_admin'], true)) ? ($_GET['branch'] ?? null) : null;
$pdo = getDbConnection($branch_override);

$sql = "SELECT s.id AS service_id, s.service_code, s.status, s.reported_problem, s.created_at, s.updated_at,
               COALESCE(c.name, dl.name) AS customer_name,
               COALESCE(c.phone, dl.phone) AS customer_phone,
               d.id AS device_id, d.brand, d.model, d.serial_number, d.physical_condition
        FROM services s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN lsms_pusat.dealers dl ON s.dealer_id = dl.id
        JOIN devices d ON s.device_id = d.id
        WHERE s.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    die("Data service tidak ditemukan.");
}

$branch_info = null;
$pdo_pusat = getDbConnection('pusat');
$branch_code_to_fetch = $branch_override ?: ($_SESSION['user']['cabang'] ?? null);
if ($branch_code_to_fetch) {
    $stmt_branch = $pdo_pusat->prepare("SELECT branch_name, address, phone FROM branches WHERE branch_code = ? LIMIT 1");
    $stmt_branch->execute([$branch_code_to_fetch]);
    $branch_info = $stmt_branch->fetch(PDO::FETCH_ASSOC);
}

$center_name = $branch_info['branch_name'] ?? 'Service Center Bandung';
$center_address_line1 = $branch_info['address'] ?? 'Ruko Segitiga Mas Kosambi Blok E10-11 Jl. A. Yani 221-223 Kel. Merdeka, Kec. Sumur Bandung.';
$center_address_line2 = $branch_info ? '' : 'Kota Bandung';
$center_wa = $branch_info['phone'] ?? '081130785168';

$unit_db_code = null;
$unit_db_name = null;
$warranty_label = 'No';
if (!empty($service['serial_number'])) {
    $stmt_unit = $pdo_pusat->prepare("SELECT unit_name, model_code, warranty_start, warranty_end FROM master_units WHERE serial_number = ? LIMIT 1");
    $stmt_unit->execute([$service['serial_number']]);
    $unit_data = $stmt_unit->fetch(PDO::FETCH_ASSOC);
    if ($unit_data) {
        $unit_db_code = $unit_data['model_code'];
        $unit_db_name = $unit_data['unit_name'];
        $today = date('Y-m-d');
        if (!empty($unit_data['warranty_start']) && !empty($unit_data['warranty_end']) && $today >= $unit_data['warranty_start'] && $today <= $unit_data['warranty_end']) {
            $warranty_label = 'Yes';
        }
    }
}

$date_source = $is_service_in ? ($service['created_at'] ?? '') : ($service['updated_at'] ?? $service['created_at'] ?? '');
$date_timestamp = $date_source ? strtotime($date_source) : false;
$doc_date = $date_timestamp ? date('d-m-Y', $date_timestamp) : '-';

$doc_label = $is_service_in ? 'SERVICE IN' : 'SERVICE OUT';
$doc_title = $is_service_in ? 'Surat Tanda Terima (Service Masuk)' : 'Surat Keluar (Service Out)';
$printed_by = $_SESSION['user']['username'] ?? ($_SESSION['user']['full_name'] ?? 'admin.lsms');
$item_code = $unit_db_code ?: strtoupper('NB' . substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($service['brand'] . $service['model'])), 0, 12));
if ($item_code === 'NB' && !$unit_db_code) {
    $item_code = 'NB' . str_pad((string)($service['device_id'] ?? $service['service_id']), 6, '0', STR_PAD_LEFT);
}
$item_name = $unit_db_name ?: trim((string)($service['brand'] ?? '') . ' ' . (string)($service['model'] ?? ''));
$problem_text = trim((string)($service['reported_problem'] ?? '-'));
$problem_inline = preg_replace('/\s+/', ' ', $problem_text);
$notes = trim((string)($service['physical_condition'] ?? ''));
$warranty_label_upper = strtoupper($warranty_label);

$receipt_base = "receipt.php?id=" . urlencode((string)$id);
if (!empty($branch_override)) {
    $receipt_base .= "&branch=" . urlencode((string)$branch_override);
}
$service_in_url = $receipt_base . "&type=masuk";
$service_out_url = $receipt_base . "&type=keluar";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($doc_title) ?> - <?= htmlspecialchars($service['service_code']) ?></title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #4b5563;
            --line: #111827;
            --page-bg: #f3f4f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            font-family: Arial, sans-serif;
            color: var(--ink);
            min-width: 210mm;
            overflow-x: auto;
        }

        .toolbar {
            width: 210mm;
            margin: 12px auto 6px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid #1f2937;
            background: #1f2937;
            color: #fff;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            line-height: 1;
        }

        .btn.alt {
            background: #fff;
            color: #111;
        }

        .sheet {
            width: 210mm;
            min-width: 210mm;
            min-height: auto;
            margin: 10px auto;
            background: #fff;
            border: 1px solid #d1d5db;
            padding: 10mm;
            display: flex;
            flex-direction: column;
            position: relative;
            flex-shrink: 0;
        }

        .head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .left-head {
            width: 66%;
        }

        .logo-img {
            max-height: 45px;
            max-width: 250px;
            object-fit: contain;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .branch {
            margin-top: 5px;
            font-size: 20px;
            font-weight: 700;
        }

        .addr {
            margin: 1px 0;
            font-size: 12px;
            line-height: 1.25;
        }

        .right-head {
            width: 34%;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .service-code {
            margin: 0 0 5px;
            font-size: 18px;
            line-height: 1.1;
            font-weight: 700;
            text-align: right;
            word-break: break-word;
            color: #1e293b;
        }

        .barcode-wrap {
            max-width: 210px;
        }

        #barcode {
            width: 100%;
            height: 34px;
        }

        .barcode-text {
            font-size: 11px;
            letter-spacing: 0.4px;
            text-align: center;
            margin-top: 1px;
            color: var(--muted);
            font-weight: 700;
        }

        .meta-row {
            display: flex;
            justify-content: flex-end;
            gap: 22px;
            font-size: 12px;
            font-weight: 700;
            margin-top: 6px;
        }

        .line {
            border-top: 1px solid var(--line);
            margin: 3px 0 8px;
        }

        .customer-name {
            margin: 10px 0 4px;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            color: #1e293b;
        }

        .customer-phone {
            margin: 0 0 7px;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th {
            text-align: left;
            font-size: 12px;
            padding: 2px 2px 6px;
            line-height: 1.15;
            vertical-align: top;
        }

        thead tr {
            border-bottom: 1px solid var(--line);
        }

        td {
            font-size: 12px;
            padding: 8px 2px;
            vertical-align: top;
            line-height: 1.28;
        }

        /* Penyesuaian Lebar Kolom agar Konsisten 100% */
        .col-no { width: 30px; text-align: center; }
        .col-item { width: auto; }
        .col-sn { width: 150px; }
        .col-qty { width: 50px; text-align: center; }
        .col-war { width: 80px; text-align: center; }
        .col-war-sin { width: 80px; text-align: center; }

        .item-wrap {
            word-break: break-word;
        }

        .item-wrap .main-item {
            font-weight: 700;
            margin-bottom: 1px;
        }
        
        .item-wrap .sub-item {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 1px;
        }

        .item-wrap .prob-item {
            font-style: italic;
            margin-top: 2px;
        }

        .table-line {
            border-top: 1px solid var(--line);
            margin-top: 4px;
        }

        .notes {
            margin-top: 4px;
            font-size: 12px;
            min-height: 20px;
        }

        .signature-container {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            font-size: 12px;
        }

        .sign-block {
            text-align: center;
            width: 200px;
        }

        .sign-space {
            height: 50px;
        }

        .sign-note {
            text-align: center;
            flex: 1;
            font-weight: 700;
            margin-top: 62px;
            padding: 0 10px;
        }

        .footer {
            border-top: 1px solid var(--line);
            padding-top: 7px;
            display: flex;
            justify-content: space-between;
            font-size: 12px;
        }

        .center-text {
            text-align: center;
        }

        .inline-row {
            display: flex;
            gap: 8px;
            font-size: 12px;
            line-height: 1.2;
            margin: 4px 0;
        }

        .inline-label {
            font-weight: 700;
            min-width: 110px;
        }

        .equipment-title {
            margin: 8px 0 4px;
            font-size: 12px;
            font-weight: 700;
        }

        .equipment-list {
            display: flex;
            justify-content: flex-start;
            gap: 25px;
            font-size: 12px;
            margin: 4px 0 8px;
        }

        .remark-block {
            flex: 1.5;
            text-align: left;
            font-size: 11px;
            color: var(--muted);
        }

        .remark-title {
            font-weight: 700;
            margin-bottom: 2px;
        }

        .remark-text {
            margin: 0;
            line-height: 1.2;
            white-space: pre-line;
        }

        .footer {
            border-top: 1px solid var(--line);
            margin-top: 6px;
            padding-top: 4px;
            display: flex;
            justify-content: space-between;
            font-size: 12px;
        }

        .sign-note {
            white-space: normal;
        }

        @media print {
            @page {
                size: A4;
                margin: 5mm;
            }

            body {
                background: #fff;
            }

            .toolbar {
                display: none !important;
            }

            .sheet {
                width: auto;
                min-height: auto;
                border: 0;
                margin: 0;
                padding: 0;
            }

            .head {
                display: flex !important;
                flex-direction: row !important;
                align-items: flex-start !important;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a class="btn alt" href="<?= htmlspecialchars($service_in_url) ?>">Surat Masuk</a>
        <a class="btn alt" href="<?= htmlspecialchars($service_out_url) ?>">Surat Keluar</a>
        <button class="btn" type="button" onclick="window.print()">Print</button>
        <a class="btn alt" href="javascript:history.back()">Kembali</a>
    </div>

    <?php if ($is_service_in): ?>
    <main class="sheet">
        <section class="head">
            <div class="left-head">
                <img src="../../public/assets/img/logo.png" alt="Logo" class="logo-img">
                <div class="branch"><?= htmlspecialchars($center_name) ?></div>
                <p class="addr"><?= htmlspecialchars($center_address_line1) ?></p>
                <?php if ($center_address_line2): ?>
                    <p class="addr"><?= htmlspecialchars($center_address_line2) ?></p>
                <?php endif; ?>
                <p class="addr">WA: <?= htmlspecialchars((string)$center_wa) ?></p>
            </div>
            <div class="right-head">
                <p class="service-code"><?= htmlspecialchars($service['service_code']) ?></p>
                <div class="barcode-wrap">
                    <svg id="barcode" aria-label="barcode"></svg>
                    <div class="barcode-text"><?= htmlspecialchars($service['service_code']) ?></div>
                </div>
            </div>
        </section>

        <div class="meta-row">
            <span>SERVICE IN (SURAT TERIMA)</span>
            <span><?= htmlspecialchars($doc_date) ?></span>
        </div>
        <div class="line"></div>

        <h2 class="customer-name"><?= htmlspecialchars($service['customer_name'] ?? '-') ?></h2>
        <div class="customer-phone"><?= htmlspecialchars($service['customer_phone'] ?? '-') ?></div>

        <table>
            <thead>
                <tr>
                    <th class="col-item">Item Code / Model</th>
                    <th class="col-sn">Serial Number</th>
                    <th class="col-qty center-text">Qty</th>
                    <th class="col-war-sin">Warranty</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="item-wrap">
                            <div class="main-item"><?= htmlspecialchars($item_code) ?></div>
                            <div class="sub-item"><?= htmlspecialchars($item_name !== '' ? $item_name : '-') ?></div>
                        </div>
                    </td>
                    <td class="col-sn"><?= htmlspecialchars($service['serial_number'] ?? '-') ?></td>
                    <td class="col-qty center-text">1</td>
                    <td class="col-war-sin"><?= htmlspecialchars($warranty_label_upper) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="table-line"></div>
        <div class="inline-row">
            <span class="inline-label">Item Condition :</span>
            <span><?= htmlspecialchars($notes !== '' ? $notes : '-') ?></span>
        </div>

        <div class="equipment-title">Set Equipment (Kelengkapan):</div>
        <div class="equipment-list">
            <span>[ ] Battery</span>
            <span>[ ] Charger</span>
            <span>[ ] Kartu Garansi</span>
            <span>[ ] Invoice</span>
            <span>[ ] Lainnya: __________</span>
        </div>

        <div class="line"></div>
        <div class="inline-row">
            <span class="inline-label">Problem (Keluhan):</span>
            <span><?= htmlspecialchars($problem_inline !== '' ? $problem_inline : '-') ?></span>
        </div>

        <section class="signature-container">
            <div class="remark-block">
                <div class="remark-title">Remark / Syarat:</div>
                <p class="remark-text">- Tidak bertanggung jawab terhadap kehilangan data.
- Unit yang tidak diambil > 1 bulan di luar tanggung jawab kami.</p>
            </div>
            <div class="sign-block">
                <div>Customer,</div>
                <div class="sign-space"></div>
                <div>( ........................................ )</div>
            </div>
            <div class="sign-block">
                <div>CS,</div>
                <div class="sign-space"></div>
                <div>( ........................................ )</div>
            </div>
        </section>

        <footer class="footer">
            <span>Printed By : <?= htmlspecialchars($printed_by) ?></span>
            <span>Page 1 of 1</span>
        </footer>
    </main>
    <?php else: ?>
    <main class="sheet">
        <section class="head">
            <div class="left-head">
                <img src="../../public/assets/img/logo.png" alt="Logo" class="logo-img">
                <div class="branch"><?= htmlspecialchars($center_name) ?></div>
                <p class="addr"><?= htmlspecialchars($center_address_line1) ?></p>
                <?php if ($center_address_line2): ?>
                    <p class="addr"><?= htmlspecialchars($center_address_line2) ?></p>
                <?php endif; ?>
                <p class="addr">WA: <?= htmlspecialchars((string)$center_wa) ?></p>
            </div>
            <div class="right-head">
                <p class="service-code"><?= htmlspecialchars($service['service_code']) ?></p>
                <div class="barcode-wrap">
                    <svg id="barcode" aria-label="barcode"></svg>
                    <div class="barcode-text"><?= htmlspecialchars($service['service_code']) ?></div>
                </div>
            </div>
        </section>

        <div class="meta-row">
            <span><?= htmlspecialchars($doc_label) ?></span>
            <span><?= htmlspecialchars($doc_date) ?></span>
        </div>
        <div class="line"></div>

        <h2 class="customer-name"><?= htmlspecialchars($service['customer_name'] ?? '-') ?></h2>
        <div class="customer-phone"><?= htmlspecialchars($service['customer_phone'] ?? '-') ?></div>

        <table>
            <thead>
                <tr>
                    <th class="col-no">No.</th>
                    <th class="col-item">Item Code<br>Item Description<br>Problem</th>
                    <th class="col-sn">Serial Number</th>
                    <th class="col-war">Warranty</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="col-no">1</td>
                    <td class="col-item">
                        <div class="item-wrap">
                            <div class="main-item"><?= htmlspecialchars($item_code) ?></div>
                            <div class="sub-item"><?= htmlspecialchars($item_name !== '' ? $item_name : '-') ?></div>
                            <div class="prob-item">Prob: <?= htmlspecialchars($problem_inline !== '' ? $problem_inline : '-') ?></div>
                        </div>
                    </td>
                    <td class="col-sn"><?= htmlspecialchars($service['serial_number'] ?? '-') ?></td>
                    <td class="col-war center-text"><?= htmlspecialchars($warranty_label_upper) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="table-line"></div>
        <div class="notes">Notes : <?= htmlspecialchars($notes !== '' ? $notes : '-') ?></div>

        <div class="signature-container">
            <div class="sign-block">
                <div>Customer,</div>
                <div class="sign-space"></div>
                <div>( ........................................ )</div>
            </div>
            <div class="sign-note">
                NOTE: Mohon dicek kembali unit yang sudah diservice
            </div>
            <div class="sign-block">
                <div>CS,</div>
                <div class="sign-space"></div>
                <div>( ........................................ )</div>
            </div>
        </div>

        <footer class="footer">
            <span>Printed By : <?= htmlspecialchars($printed_by) ?></span>
            <span>Page 1 of 1</span>
        </footer>
    </main>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        (function () {
            const barcodeValue = <?= json_encode((string)($service['service_code'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const svg = document.getElementById('barcode');
            if (!svg) {
                return;
            }

            function fallbackBarcode(target, text) {
                target.innerHTML = '';
                target.setAttribute('viewBox', '0 0 220 42');
                let x = 2;
                for (let i = 0; i < text.length; i += 1) {
                    const code = text.charCodeAt(i);
                    const width = (code % 3) + 1;
                    const height = 25 + (code % 10);
                    const gap = (code % 2) + 1;
                    const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    rect.setAttribute('x', String(x));
                    rect.setAttribute('y', '3');
                    rect.setAttribute('width', String(width));
                    rect.setAttribute('height', String(height));
                    rect.setAttribute('fill', '#111');
                    target.appendChild(rect);
                    x += width + gap;
                    if (x > 217) break;
                }
            }

            if (!barcodeValue) {
                fallbackBarcode(svg, 'LSMS');
                return;
            }

            if (window.JsBarcode) {
                window.JsBarcode('#barcode', barcodeValue, {
                    format: 'CODE128',
                    width: 1.25,
                    height: 32,
                    margin: 0,
                    displayValue: false
                });
                return;
            }

            fallbackBarcode(svg, barcodeValue);
        })();
    </script>
</body>
</html>

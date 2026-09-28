<?php
require_once __DIR__ . '/../../config/app.php';

$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin', 'cs', 'teknisi'];
require_once __DIR__ . '/../../auth/check.php';

require_once __DIR__ . '/../../views/layouts/header.php';
?>

<style>
    .warranty-check-page {
        width: min(1220px, 100%);
        margin: 0 auto;
    }

    .warranty-check-hero {
        text-align: center;
        margin-bottom: 1rem;
    }

    .warranty-check-hero h1 {
        margin: 0;
        font-size: clamp(1.4rem, 2.7vw, 2rem);
        font-weight: 800;
        color: #0f3a8a;
    }

    .warranty-check-hero p {
        margin: 0.5rem 0 0;
        color: #475569;
        font-weight: 500;
    }

    .warranty-check-panel {
        background: #fff;
        border: 1px solid #dbeafe;
        border-radius: 1rem;
        box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
        padding: 1rem;
    }

    .warranty-check-page .form-label {
        font-size: 0.8rem;
        font-weight: 700;
        color: #334155;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .warranty-check-page .form-control,
    .warranty-check-page .form-select,
    .warranty-check-page .btn {
        border-radius: 0.75rem;
        min-height: 44px;
    }

    .btn-checker {
        font-weight: 700;
        border: none;
        background: linear-gradient(135deg, #1d4ed8, #1e40af);
        box-shadow: 0 10px 16px rgba(29, 78, 216, 0.28);
    }

    .summary-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.82rem;
        font-weight: 700;
        border-radius: 999px;
        padding: 0.35rem 0.8rem;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #1e3a8a;
    }

    .table-card {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        overflow: hidden;
    }

    .warranty-check-page .table thead th {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        font-size: 0.8rem;
        color: #334155;
    }

    .warranty-check-page .table tbody td {
        vertical-align: middle;
        font-size: 0.89rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.22rem 0.6rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
        border: 1px solid transparent;
    }

    .status-active {
        color: #166534;
        background: #dcfce7;
        border-color: #bbf7d0;
    }

    .status-expired {
        color: #991b1b;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .claim-badge {
        font-size: 0.72rem;
        border-radius: 999px;
        padding: 0.2rem 0.55rem;
        background: #e0f2fe;
        color: #0c4a6e;
        border: 1px solid #bae6fd;
        font-weight: 700;
    }

    .loading-note {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #1e40af;
        font-weight: 600;
    }
</style>

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
            <main class="warranty-check-page">
                <section class="warranty-check-hero">
                    <h1>Garansi Sparepart Penjualan</h1>
                </section>

                <section class="warranty-check-panel mb-3">
                    <form id="checkForm" novalidate>
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-8">
                                <label class="form-label" for="keyword">Keyword (SN / Invoice / Service Code)</label>
                                <input
                                    type="text"
                                    id="keyword"
                                    name="keyword"
                                    class="form-control"
                                    placeholder="Contoh: SN-SPR-001, TRX-260410-123, atau SVC-20260413-9F19"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <label class="form-label" for="statusFilter">Filter Status</label>
                                <select id="statusFilter" name="status_filter" class="form-select">
                                    <option value="all">Semua</option>
                                    <option value="active">Active</option>
                                    <option value="expired">Expired</option>
                                    <option value="expiring_soon">Akan Habis (<= 7 Hari)</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-6 d-grid">
                                <button type="submit" id="submitBtn" class="btn btn-primary btn-checker">
                                    <span id="btnText">Cek Garansi</span>
                                    <span id="btnSpinner" class="spinner-border spinner-border-sm ms-2 d-none" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    </form>

                    <div id="loadingNote" class="loading-note mt-3 d-none">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>Memproses data garansi sparepart...</span>
                    </div>

                    <div id="feedback" class="alert mt-3 mb-0 d-none" role="alert"></div>
                </section>

                <section id="resultSection" class="d-none">
                    <div class="d-flex flex-wrap gap-2 mb-3" id="summaryChips"></div>

                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Invoice / Service</th>
                                        <th>Serial Number</th>
                                        <th>Sparepart</th>
                                        <th>Tanggal Beli</th>
                                        <th>Durasi Garansi</th>
                                        <th>Garansi Berakhir</th>
                                        <th>Status</th>
                                        <th>Keterangan</th>
                                        <th class="text-end pe-3">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="resultBody"></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>
</div>

<script>
    const form = document.getElementById('checkForm');
    const keywordInput = document.getElementById('keyword');
    const statusFilter = document.getElementById('statusFilter');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const btnSpinner = document.getElementById('btnSpinner');
    const loadingNote = document.getElementById('loadingNote');
    const feedback = document.getElementById('feedback');
    const resultSection = document.getElementById('resultSection');
    const resultBody = document.getElementById('resultBody');
    const summaryChips = document.getElementById('summaryChips');
    const warrantyApiUrl = '<?= BASE_URL ?>modules/warranty-check/api.php';

    function setLoading(isLoading) {
        submitBtn.disabled = isLoading;
        btnSpinner.classList.toggle('d-none', !isLoading);
        loadingNote.classList.toggle('d-none', !isLoading);
        btnText.textContent = isLoading ? 'Memproses' : 'Cek Garansi';
    }

    function showFeedback(type, message) {
        feedback.className = `alert alert-${type} mt-3 mb-0`;
        feedback.textContent = message;
        feedback.classList.remove('d-none');
    }

    function hideFeedback() {
        feedback.classList.add('d-none');
        feedback.textContent = '';
    }

    function formatDate(dateString) {
        if (!dateString) return '-';

        const dt = new Date(String(dateString).slice(0, 10) + 'T00:00:00');
        if (Number.isNaN(dt.getTime())) return dateString;

        const dd = String(dt.getDate()).padStart(2, '0');
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const yyyy = dt.getFullYear();
        return `${dd}-${mm}-${yyyy}`;
    }

    function formatDuration(duration) {
        const value = Number(duration || 0);
        return `${value} bulan`;
    }

    function renderSummary(summary) {
        const chips = [
            `<span class="summary-chip"><i class="fas fa-list"></i>Total hasil: ${summary.total_items ?? 0}</span>`,
            `<span class="summary-chip"><i class="fas fa-check-circle"></i>Active: ${summary.active_count ?? 0}</span>`,
            `<span class="summary-chip"><i class="fas fa-times-circle"></i>Expired: ${summary.expired_count ?? 0}</span>`,
        ];

        if ((summary.expiring_soon_count ?? 0) > 0) {
            chips.push(`<span class="summary-chip"><i class="fas fa-bell"></i>Akan habis <= 7 hari: ${summary.expiring_soon_count}</span>`);
        }

        summaryChips.innerHTML = chips.join('');
    }

    function renderRows(items) {
        if (!Array.isArray(items) || items.length === 0) {
            resultBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Data tidak ditemukan</td></tr>';
            return;
        }

        resultBody.innerHTML = items.map((item) => {
            const isActive = item.warranty_status === 'active';
            const badgeClass = isActive ? 'status-active' : 'status-expired';
            const statusText = isActive ? 'Active' : 'Expired';
            const noteText = isActive ? 'Garansi masih berlaku' : 'Garansi telah habis';
            const isClaimed = Number(item.is_warranty_claim || 0) === 1;
            const claimAllowedBySource = Number(item.claim_available ?? 1) === 1;
            const isServiceSource = Boolean(item.service_code);
            const canClaim = isActive && !isClaimed && claimAllowedBySource;

            const claimButton = canClaim
                ? `<button type="button" class="btn btn-sm btn-outline-primary claim-btn"
                        data-sales-item-id="${item.sales_item_id}"
                        data-branch-code="${item.branch_code}">
                        Claim (Rp 0)
                   </button>`
                : (isClaimed
                    ? '<span class="claim-badge">Claimed</span>'
                    : (isServiceSource
                        ? '<span class="text-muted small">Claim via menu Service</span>'
                        : '<span class="text-muted small">-</span>'));

            const referenceCode = item.service_code || item.invoice_code || '-';
            const referenceType = isServiceSource ? 'Service' : 'Invoice';
            const serialMain = item.part_serial_number || item.serial_number || '-';
            const unitSerialNote = isServiceSource && item.unit_serial_number && item.part_serial_number
                ? `<div class="small text-muted">SN Unit: ${item.unit_serial_number}</div>`
                : '';

            return `
                <tr>
                    <td class="ps-3">
                        <div class="fw-semibold">${referenceCode}</div>
                        <div class="small text-muted">${referenceType} - ${item.branch_name || '-'}</div>
                    </td>
                    <td>
                        <div>${serialMain}</div>
                        ${unitSerialNote}
                    </td>
                    <td>
                        <div class="fw-medium">${item.item_name || '-'}</div>
                        <div class="small text-muted">Qty: ${item.quantity || 0}</div>
                    </td>
                    <td>${formatDate(item.sales_date)}</td>
                    <td>${formatDuration(item.warranty_duration)}</td>
                    <td>${formatDate(item.warranty_end)}</td>
                    <td>
                        <span class="status-badge ${badgeClass}">
                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                            ${statusText}
                        </span>
                    </td>
                    <td>
                        <div>${item.message || noteText}</div>
                        <div class="small text-muted">${noteText}</div>
                    </td>
                    <td class="text-end pe-3">${claimButton}</td>
                </tr>
            `;
        }).join('');
    }

    async function fetchWarrantyData() {
        const keyword = keywordInput.value.trim();

        if (!keyword) {
            showFeedback('danger', 'Isi keyword SN, Invoice Code, atau Service Code terlebih dahulu.');
            return;
        }

        hideFeedback();
        resultSection.classList.add('d-none');
        setLoading(true);

        const params = new URLSearchParams();
        params.set('keyword', keyword);
        params.set('status_filter', statusFilter.value);

        try {
            const response = await fetch(`${warrantyApiUrl}?${params.toString()}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
            });

            const rawText = await response.text();
            let payload;
            try {
                payload = JSON.parse(rawText);
            } catch (parseError) {
                throw new Error('INVALID_JSON');
            }

            if (!response.ok || !payload.success) {
                showFeedback('danger', payload.message || 'Data tidak ditemukan');
                return;
            }

            const items = Array.isArray(payload.data) ? payload.data : [];
            renderSummary(payload.summary || {});
            renderRows(items);
            resultSection.classList.remove('d-none');

            if (items.length === 0) {
                showFeedback('warning', payload.message || 'Data garansi sparepart tidak ditemukan.');
            } else {
                hideFeedback();
            }
        } catch (error) {
            const message = error && error.message === 'INVALID_JSON'
                ? 'Respons API tidak valid. Periksa konfigurasi server/API.'
                : 'Gagal terhubung ke server. Silakan coba lagi.';
            showFeedback('danger', message);
        } finally {
            setLoading(false);
        }
    }

    async function handleClaimWarranty(salesItemId, branchCode, buttonEl) {
        if (!salesItemId || !branchCode) {
            return;
        }

        const confirmed = window.confirm('Proses warranty claim untuk item ini? Biaya claim akan menjadi Rp 0.');
        if (!confirmed) {
            return;
        }

        buttonEl.disabled = true;
        buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

        try {
            const response = await fetch(warrantyApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    action: 'claim',
                    sales_item_id: Number(salesItemId),
                    branch_code: String(branchCode),
                }),
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                showFeedback('danger', payload.message || 'Gagal memproses claim garansi.');
                return;
            }

            showFeedback('success', payload.message || 'Warranty claim berhasil diproses.');
            await fetchWarrantyData();
        } catch (error) {
            showFeedback('danger', 'Terjadi kesalahan saat memproses claim.');
        } finally {
            buttonEl.disabled = false;
            buttonEl.textContent = 'Claim (Rp 0)';
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        await fetchWarrantyData();
    });

    resultBody.addEventListener('click', async (event) => {
        const button = event.target.closest('.claim-btn');
        if (!button) {
            return;
        }

        const salesItemId = button.getAttribute('data-sales-item-id');
        const branchCode = button.getAttribute('data-branch-code');
        await handleClaimWarranty(salesItemId, branchCode, button);
    });
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>

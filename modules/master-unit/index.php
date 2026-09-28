<?php
$allowed_roles = ['gudang', 'superadmin'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../views/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/../../views/layouts/sidebar.php'; ?>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-light bg-white px-4 py-3 shadow-sm sticky-top">
            <div class="container-fluid">
                <div class="d-flex align-items-center">
                    <button class="btn btn-light text-primary border-0 d-md-none" id="sidebarToggleMobile">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                    <button class="btn btn-light text-primary border-0" id="navbarToggleDesktop">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
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
            <h2 class="fw-bold text-dark mb-4">Data Master Unit</h2>

            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-lg-5">
                            <label class="form-label small text-muted mb-1">Pencarian</label>
                            <input
                                type="text"
                                id="searchInput"
                                class="form-control"
                                placeholder="Cari SN, nama unit, model code, atau current branch..."
                                autocomplete="off"
                            >
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label small text-muted mb-1">Filter Current Branch</label>
                            <select id="currentBranchFilter" class="form-select">
                                <option value="">Semua Branch</option>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label small text-muted mb-1">Filter Status Garansi</label>
                            <select id="warrantyStatusFilter" class="form-select">
                                <option value="">Semua Status</option>
                                <option value="PAID">PAID</option>
                                <option value="UNPAID">UNPAID</option>
                            </select>
                        </div>
                        <div class="col-lg-1 d-grid align-items-end">
                            <button type="button" id="resetFilterBtn" class="btn btn-outline-secondary mt-lg-4">Reset</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3" style="width: 70px;">No</th>
                                    <th class="sortable-column" data-sort="serial_number" role="button">SN <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                    <th class="sortable-column" data-sort="unit_name" role="button">Nama Unit <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                    <th class="sortable-column" data-sort="model_code" role="button">Model Code <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                    <th class="sortable-column" data-sort="warranty_start" role="button">Warranty Start <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                    <th class="sortable-column" data-sort="warranty_end" role="button">Warranty End <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                    <th class="sortable-column" data-sort="warranty_payment_status" role="button">Status Garansi <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                    <th class="sortable-column" data-sort="current_branch" role="button">Current Branch <i class="fas fa-sort ms-1 small text-muted"></i></th>
                                </tr>
                            </thead>
                            <tbody id="masterUnitTbody">
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                        Memuat data master unit...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
                        <div id="tableInfo" class="small text-muted">Menyiapkan data...</div>
                        <nav aria-label="Master unit pagination">
                            <ul class="pagination pagination-sm mb-0" id="paginationList"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const masterUnitState = {
    rows: [],
    filteredRows: [],
    page: 1,
    pageSize: 10,
    search: '',
    branch: '',
    warrantyStatus: '',
    sortBy: 'serial_number',
    sortDir: 'asc',
};

const tbodyEl = document.getElementById('masterUnitTbody');
const tableInfoEl = document.getElementById('tableInfo');
const paginationEl = document.getElementById('paginationList');
const searchInputEl = document.getElementById('searchInput');
const currentBranchFilterEl = document.getElementById('currentBranchFilter');
const warrantyStatusFilterEl = document.getElementById('warrantyStatusFilter');
const resetFilterBtnEl = document.getElementById('resetFilterBtn');
const sortableHeaders = document.querySelectorAll('.sortable-column');

function normalizeText(value) {
    return String(value || '').trim().toLowerCase();
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderFilterOptions() {
    const branches = Array.from(new Set(
        masterUnitState.rows
            .map((row) => String(row.current_branch || '').trim())
            .filter((value) => value !== '')
    )).sort((a, b) => a.localeCompare(b));

    const currentValue = currentBranchFilterEl.value;
    currentBranchFilterEl.innerHTML = '<option value="">Semua Branch</option>' +
        branches.map((branch) => `<option value="${escapeHtml(branch)}">${escapeHtml(branch)}</option>`).join('');

    if (branches.includes(currentValue)) {
        currentBranchFilterEl.value = currentValue;
    } else {
        currentBranchFilterEl.value = '';
    }
}

function sortRows(rows) {
    const sorted = [...rows];
    const key = masterUnitState.sortBy;
    const dir = masterUnitState.sortDir === 'asc' ? 1 : -1;

    sorted.sort((a, b) => {
        const aVal = normalizeText(a[key]);
        const bVal = normalizeText(b[key]);
        if (aVal < bVal) return -1 * dir;
        if (aVal > bVal) return 1 * dir;
        return (a.id - b.id) * dir;
    });

    return sorted;
}

function applyFilters() {
    const keyword = normalizeText(masterUnitState.search);
    const branch = normalizeText(masterUnitState.branch);
    const warrantyStatus = normalizeText(masterUnitState.warrantyStatus);

    let rows = masterUnitState.rows.filter((row) => {
        const matchesSearch = keyword === '' ||
            normalizeText(row.serial_number).includes(keyword) ||
            normalizeText(row.unit_name).includes(keyword) ||
            normalizeText(row.model_code).includes(keyword) ||
            normalizeText(row.warranty_payment_status).includes(keyword) ||
            normalizeText(row.current_branch).includes(keyword);

        const matchesBranch = branch === '' || normalizeText(row.current_branch) === branch;
        const matchesWarrantyStatus = warrantyStatus === '' || normalizeText(row.warranty_payment_status) === warrantyStatus;

        return matchesSearch && matchesBranch && matchesWarrantyStatus;
    });

    masterUnitState.filteredRows = sortRows(rows);

    const totalPages = Math.max(1, Math.ceil(masterUnitState.filteredRows.length / masterUnitState.pageSize));
    if (masterUnitState.page > totalPages) {
        masterUnitState.page = totalPages;
    }
    if (masterUnitState.page < 1) {
        masterUnitState.page = 1;
    }

    renderTable();
    renderPagination();
    updateSortIcons();
}

function renderTable() {
    const total = masterUnitState.filteredRows.length;
    if (total === 0) {
        tbodyEl.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Data master unit tidak ditemukan.</td></tr>';
        tableInfoEl.textContent = '0 data';
        return;
    }

    const startIndex = (masterUnitState.page - 1) * masterUnitState.pageSize;
    const endIndex = Math.min(startIndex + masterUnitState.pageSize, total);
    const pageRows = masterUnitState.filteredRows.slice(startIndex, endIndex);

    tbodyEl.innerHTML = pageRows.map((row, idx) => {
        const number = startIndex + idx + 1;
        return `
            <tr>
                <td class="ps-3 fw-semibold">${number}</td>
                <td>${escapeHtml(row.serial_number || '-')}</td>
                <td>${escapeHtml(row.unit_name || '-')}</td>
                <td>${escapeHtml(row.model_code || '-')}</td>
                <td>${escapeHtml(formatDateValue(row.warranty_start))}</td>
                <td>${escapeHtml(formatDateValue(row.warranty_end))}</td>
                <td>${renderWarrantyStatusBadge(row.warranty_payment_status)}</td>
                <td>${escapeHtml(row.current_branch || '-')}</td>
            </tr>
        `;
    }).join('');

    tableInfoEl.textContent = `Menampilkan ${startIndex + 1}-${endIndex} dari ${total} data`;
}

function buildPaginationItem(label, targetPage, disabled, active) {
    const li = document.createElement('li');
    li.className = `page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}`;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'page-link';
    button.textContent = label;
    button.disabled = disabled;
    button.addEventListener('click', () => {
        if (disabled) return;
        masterUnitState.page = targetPage;
        renderTable();
        renderPagination();
    });

    li.appendChild(button);
    return li;
}

function renderPagination() {
    const totalRows = masterUnitState.filteredRows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / masterUnitState.pageSize));
    const current = masterUnitState.page;

    paginationEl.innerHTML = '';

    if (totalRows === 0) {
        return;
    }

    paginationEl.appendChild(buildPaginationItem('Prev', current - 1, current === 1, false));

    const maxVisible = 5;
    let start = Math.max(1, current - Math.floor(maxVisible / 2));
    let end = Math.min(totalPages, start + maxVisible - 1);
    start = Math.max(1, end - maxVisible + 1);

    for (let page = start; page <= end; page++) {
        paginationEl.appendChild(buildPaginationItem(String(page), page, false, page === current));
    }

    paginationEl.appendChild(buildPaginationItem('Next', current + 1, current === totalPages, false));
}

function updateSortIcons() {
    sortableHeaders.forEach((header) => {
        const icon = header.querySelector('i');
        if (!icon) return;

        if (header.dataset.sort === masterUnitState.sortBy) {
            icon.className = masterUnitState.sortDir === 'asc'
                ? 'fas fa-sort-up ms-1 small text-primary'
                : 'fas fa-sort-down ms-1 small text-primary';
        } else {
            icon.className = 'fas fa-sort ms-1 small text-muted';
        }
    });
}

function formatDateValue(value) {
    const text = String(value || '').trim();
    if (text === '') {
        return '-';
    }

    const parts = text.split('-');
    if (parts.length === 3) {
        return `${parts[2]}-${parts[1]}-${parts[0]}`;
    }

    return text;
}

function renderWarrantyStatusBadge(status) {
    const text = String(status || 'UNPAID').trim().toUpperCase();
    if (text === 'PAID') {
        return '<span class="badge bg-success-subtle text-success border border-success-subtle">PAID</span>';
    }
    return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle">UNPAID</span>';
}

async function loadMasterUnits() {
    tbodyEl.innerHTML = `
        <tr>
            <td colspan="8" class="text-center py-4 text-muted">
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                Memuat data master unit...
            </td>
        </tr>
    `;

    try {
        const response = await fetch('<?= BASE_URL ?>api/master-units', {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        });

        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Gagal mengambil data master unit.');
        }

        if (!Array.isArray(payload)) {
            throw new Error('Format response API master unit tidak valid.');
        }

        masterUnitState.rows = payload.map((row) => ({
            id: Number(row.id) || 0,
            serial_number: String(row.serial_number || '').trim(),
            unit_name: String(row.unit_name || '').trim(),
            model_code: String(row.model_code || '').trim(),
            warranty_start: String(row.warranty_start || '').trim(),
            warranty_end: String(row.warranty_end || '').trim(),
            warranty_payment_status: String(row.warranty_payment_status || '').trim().toUpperCase(),
            current_branch: String(row.current_branch || '').trim(),
        }));

        renderFilterOptions();
        applyFilters();
    } catch (error) {
        tbodyEl.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-danger">
                    ${escapeHtml(error.message || 'Terjadi kesalahan saat mengambil data.')}
                </td>
            </tr>
        `;
        tableInfoEl.textContent = 'Gagal memuat data';
        paginationEl.innerHTML = '';
    }
}

searchInputEl.addEventListener('input', (event) => {
    masterUnitState.search = event.target.value;
    masterUnitState.page = 1;
    applyFilters();
});

currentBranchFilterEl.addEventListener('change', (event) => {
    masterUnitState.branch = event.target.value;
    masterUnitState.page = 1;
    applyFilters();
});

warrantyStatusFilterEl.addEventListener('change', (event) => {
    masterUnitState.warrantyStatus = event.target.value;
    masterUnitState.page = 1;
    applyFilters();
});

resetFilterBtnEl.addEventListener('click', () => {
    masterUnitState.search = '';
    masterUnitState.branch = '';
    masterUnitState.warrantyStatus = '';
    masterUnitState.page = 1;
    searchInputEl.value = '';
    currentBranchFilterEl.value = '';
    warrantyStatusFilterEl.value = '';
    applyFilters();
});

sortableHeaders.forEach((header) => {
    header.addEventListener('click', () => {
        const newSort = header.dataset.sort;
        if (!newSort) return;

        if (masterUnitState.sortBy === newSort) {
            masterUnitState.sortDir = masterUnitState.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            masterUnitState.sortBy = newSort;
            masterUnitState.sortDir = 'asc';
        }

        applyFilters();
    });
});

loadMasterUnits();
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>


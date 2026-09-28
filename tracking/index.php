<?php
require_once __DIR__ . '/../config/app.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking Service Laptop | <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-0: #f8fafc;
            --bg-1: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #475569;
            --line: #e2e8f0;
            --card: #ffffff;
            --blue: #2563eb;
            --blue-soft: #dbeafe;
            --green: #16a34a;
            --gray: #cbd5e1;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at 0% 0%, #eff6ff 0%, rgba(239, 246, 255, 0) 40%),
                radial-gradient(circle at 100% 100%, #ecfeff 0%, rgba(236, 254, 255, 0) 45%),
                linear-gradient(135deg, var(--bg-0) 0%, var(--bg-1) 100%);
        }

        .tracking-page {
            width: min(1100px, 100% - 2rem);
            margin: 0 auto;
            padding: 3rem 0 2.5rem;
        }

        .hero {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            font-weight: 800;
            color: #0b3f9c;
        }

        .hero p {
            margin: 0.6rem auto 0;
            color: var(--text-muted);
            font-weight: 500;
        }

        .tracking-panel {
            background: var(--card);
            border-radius: 1rem;
            border: 1px solid #dbeafe;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            padding: 1.4rem;
        }

        .form-label {
            font-size: 0.86rem;
            color: #334155;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .form-control {
            border-radius: 0.75rem;
            border-color: #cbd5e1;
            min-height: 46px;
            font-weight: 500;
        }

        .form-control:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.12);
        }

        .btn-track {
            min-height: 46px;
            border-radius: 0.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.25);
        }

        .btn-track:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
        }

        .loading-note {
            margin-top: 0.8rem;
            color: #1e40af;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-wrapper {
            margin-top: 1.4rem;
            display: grid;
            gap: 1rem;
        }

        .data-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .card-box {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 0.95rem;
            padding: 1rem;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
        }

        .card-box h3 {
            margin: 0 0 0.8rem;
            font-size: 0.88rem;
            font-weight: 800;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .item-row {
            margin-bottom: 0.65rem;
        }

        .item-row:last-child {
            margin-bottom: 0;
        }

        .item-label {
            display: block;
            color: #64748b;
            font-size: 0.78rem;
            margin-bottom: 0.1rem;
        }

        .item-value {
            font-weight: 600;
            color: #0f172a;
            word-break: break-word;
        }

        .status-strip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.82rem;
            font-weight: 700;
            color: #1e3a8a;
            background: #dbeafe;
            border-radius: 999px;
            padding: 0.35rem 0.65rem;
            margin-top: 0.1rem;
        }

        .progress-box {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 0.95rem;
            padding: 1rem;
            overflow: hidden;
        }

        .progress-box h3,
        .timeline-box h3 {
            margin: 0 0 1rem;
            font-size: 0.88rem;
            font-weight: 800;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .steps-track {
            display: flex;
            align-items: flex-start;
            overflow-x: auto;
            padding: 0.2rem 0 0.7rem;
        }

        .step-item {
            flex: 1 1 160px;
            min-width: 148px;
            text-align: center;
            position: relative;
            padding: 0 0.4rem;
        }

        .step-item::before {
            content: '';
            position: absolute;
            top: 0.55rem;
            left: -50%;
            width: 100%;
            height: 4px;
            background: var(--gray);
            z-index: 0;
        }

        .step-item:first-child::before {
            display: none;
        }

        .step-item .dot {
            width: 1.05rem;
            height: 1.05rem;
            border-radius: 999px;
            border: 3px solid var(--gray);
            background: #fff;
            margin: 0 auto 0.6rem;
            position: relative;
            z-index: 2;
        }

        .step-item .label {
            font-weight: 700;
            font-size: 0.8rem;
            color: #64748b;
            white-space: nowrap;
        }

        .step-item.done::before {
            background: var(--green);
        }

        .step-item.done .dot {
            background: var(--green);
            border-color: var(--green);
        }

        .step-item.done .label {
            color: #166534;
        }

        .step-item.active::before {
            background: var(--green);
        }

        .step-item.active .dot {
            background: var(--green);
            border-color: var(--green);
        }

        .step-item.active .label {
            color: #166534;
        }

        .timeline-box {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 0.95rem;
            padding: 1rem;
        }

        .timeline {
            position: relative;
            margin-left: 0.2rem;
        }

        .timeline-item {
            position: relative;
            padding-left: 1.5rem;
            padding-bottom: 1rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.28rem;
            top: 0.65rem;
            bottom: -0.35rem;
            width: 2px;
            background: #dbeafe;
        }

        .timeline-item:last-child::before {
            display: none;
        }

        .timeline-dot {
            position: absolute;
            left: 0;
            top: 0.45rem;
            width: 0.58rem;
            height: 0.58rem;
            border-radius: 50%;
            background: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .timeline-date {
            font-size: 0.78rem;
            color: #475569;
            font-weight: 700;
            margin-bottom: 0.15rem;
        }

        .timeline-desc {
            font-size: 0.94rem;
            color: #0f172a;
            font-weight: 500;
        }

        @media (max-width: 992px) {
            .data-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .tracking-page {
                width: min(1100px, 100% - 1rem);
                padding-top: 1.4rem;
            }

            .tracking-panel {
                padding: 1rem;
            }

            .step-item {
                flex: 0 0 148px;
            }
        }
    </style>
</head>
<body>
    <main class="tracking-page">
        <section class="hero">
            <h1>Tracking Service Laptop</h1>
            <p>Masukkan kode service untuk melihat status perbaikan</p>
        </section>

        <section class="tracking-panel">
            <form id="trackingForm" novalidate>
                <div class="row g-3 align-items-end">
                    <div class="col-lg-5 col-md-6">
                        <label for="serviceCode" class="form-label">Service Code</label>
                        <input
                            type="text"
                            id="serviceCode"
                            name="service_code"
                            class="form-control"
                            placeholder="Contoh: SVCRITDI-26031477"
                            autocomplete="off"
                        >
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <label for="phone" class="form-label">Nomor HP (Opsional)</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            class="form-control"
                            placeholder="Contoh: 08123456789"
                            inputmode="numeric"
                            autocomplete="off"
                        >
                    </div>
                    <div class="col-lg-3 col-md-12 d-grid">
                        <button type="submit" id="submitBtn" class="btn btn-primary btn-track">
                            <span id="btnText">Cek Status</span>
                            <span id="btnSpinner" class="spinner-border spinner-border-sm ms-2 d-none" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </form>

            <div id="loadingNote" class="loading-note d-none">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span>Memproses pencarian data service...</span>
            </div>

            <div id="feedback" class="alert mt-3 mb-0 d-none" role="alert"></div>
        </section>

        <section id="resultSection" class="result-wrapper d-none">
            <div class="data-grid">
                <article class="card-box">
                    <h3>Data Customer</h3>
                    <div class="item-row">
                        <span class="item-label">Nama</span>
                        <span class="item-value" id="customerName">-</span>
                    </div>
                    <div class="item-row">
                        <span class="item-label">Nomor HP</span>
                        <span class="item-value" id="customerPhone">-</span>
                    </div>
                </article>

                <article class="card-box">
                    <h3>Data Perangkat</h3>
                    <div class="item-row">
                        <span class="item-label">Brand + Model</span>
                        <span class="item-value" id="deviceInfo">-</span>
                    </div>
                    <div class="item-row">
                        <span class="item-label">Serial Number</span>
                        <span class="item-value" id="deviceSN">-</span>
                    </div>
                </article>

                <article class="card-box">
                    <h3>Data Service</h3>
                    <div class="item-row">
                        <span class="item-label">Service Code</span>
                        <span class="item-value" id="serviceCodeValue">-</span>
                    </div>
                    <div class="item-row">
                        <span class="item-label">Tanggal Masuk</span>
                        <span class="item-value" id="serviceDate">-</span>
                    </div>
                    <div class="item-row">
                        <span class="item-label">Cabang</span>
                        <span class="item-value" id="serviceBranch">-</span>
                    </div>
                    <div class="item-row">
                        <span class="item-label">Keluhan</span>
                        <span class="item-value" id="serviceProblem">-</span>
                    </div>
                    <div class="status-strip" id="statusLabel">Status: Pending</div>
                </article>
            </div>

            <article class="progress-box">
                <h3>Status Service (Visual)</h3>
                <div id="stepsTrack" class="steps-track"></div>
            </article>

            <article class="timeline-box">
                <h3>Timeline Service</h3>
                <div id="timelineList" class="timeline"></div>
            </article>
        </section>
    </main>

    <script>
        const trackingForm = document.getElementById('trackingForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnSpinner = document.getElementById('btnSpinner');
        const loadingNote = document.getElementById('loadingNote');
        const feedback = document.getElementById('feedback');
        const resultSection = document.getElementById('resultSection');

        const statusSteps = [
            { key: 'pending', label: 'Pending' },
            { key: 'diagnosis', label: 'Diagnosis' },
            { key: 'on_progress', label: 'On Progress' },
            { key: 'waiting_sparepart', label: 'Waiting Sparepart' },
            { key: 'finished', label: 'Finished' },
            { key: 'taken', label: 'Taken' }
        ];

        const statusLabelMap = {
            pending: 'Pending',
            diagnosis: 'Diagnosis',
            on_progress: 'On Progress',
            waiting_sparepart: 'Waiting Sparepart',
            finished: 'Finished',
            taken: 'Taken',
            cancelled: 'Cancelled'
        };

        function escapeHtml(value) {
            return String(value ?? '-')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function normalizeStatus(status) {
            const normalized = String(status || 'pending').toLowerCase().replace(/\s+/g, '_').trim();

            if (normalized === 'on progress') {
                return 'on_progress';
            }

            if (normalized === 'waiting') {
                return 'waiting_sparepart';
            }

            if (normalized === 'completed') {
                return 'finished';
            }

            return normalized;
        }

        function formatDate(dateString, includeTime = false) {
            if (!dateString) {
                return '-';
            }

            const dt = new Date(dateString.replace(' ', 'T'));
            if (Number.isNaN(dt.getTime())) {
                return escapeHtml(dateString);
            }

            const dd = String(dt.getDate()).padStart(2, '0');
            const mm = String(dt.getMonth() + 1).padStart(2, '0');
            const yyyy = dt.getFullYear();

            if (!includeTime) {
                return `${dd}-${mm}-${yyyy}`;
            }

            const hh = String(dt.getHours()).padStart(2, '0');
            const ii = String(dt.getMinutes()).padStart(2, '0');
            return `${dd}-${mm}-${yyyy} ${hh}:${ii}`;
        }

        function setLoading(isLoading) {
            submitBtn.disabled = isLoading;
            btnSpinner.classList.toggle('d-none', !isLoading);
            loadingNote.classList.toggle('d-none', !isLoading);
            btnText.textContent = isLoading ? 'Memproses' : 'Cek Status';
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

        function renderSteps(currentStatus) {
            const stepsTrack = document.getElementById('stepsTrack');
            const normalizedStatus = normalizeStatus(currentStatus);
            const currentIndex = statusSteps.findIndex((step) => step.key === normalizedStatus);
            const isTakenFinal = normalizedStatus === 'taken';

            const html = statusSteps.map((step, index) => {
                let className = '';

                if (currentIndex >= 0) {
                    if (index < currentIndex) {
                        className = 'done';
                    } else if (index === currentIndex) {
                        className = isTakenFinal ? 'done' : 'active';
                    }
                }

                return `
                    <div class="step-item ${className}">
                        <div class="dot"></div>
                        <div class="label">${step.label}</div>
                    </div>
                `;
            }).join('');

            stepsTrack.innerHTML = html;
        }

        function renderTimeline(items) {
            const timelineList = document.getElementById('timelineList');

            if (!Array.isArray(items) || items.length === 0) {
                timelineList.innerHTML = '<div class="text-muted">Belum ada aktivitas service.</div>';
                return;
            }

            const html = items.map((log) => {
                const logDate = formatDate(log.log_time, true);
                const description = escapeHtml(log.description || '-');

                return `
                    <div class="timeline-item">
                        <span class="timeline-dot"></span>
                        <div class="timeline-date">[${logDate}]</div>
                        <div class="timeline-desc">${description}</div>
                    </div>
                `;
            }).join('');

            timelineList.innerHTML = html;
        }

        function renderResult(data) {
            const customer = data.customer || {};
            const device = data.device || {};
            const service = data.service || {};
            const status = normalizeStatus(service.status);

            document.getElementById('customerName').textContent = customer.name || '-';
            document.getElementById('customerPhone').textContent = customer.phone || '-';
            document.getElementById('deviceInfo').textContent = `${device.brand || '-'} ${device.model || ''}`.trim();
            document.getElementById('deviceSN').textContent = device.serial_number || '-';
            document.getElementById('serviceCodeValue').textContent = service.service_code || '-';
            document.getElementById('serviceDate').textContent = formatDate(service.created_at);
            document.getElementById('serviceBranch').textContent = service.branch_name || '-';
            document.getElementById('serviceProblem').textContent = service.reported_problem || '-';
            document.getElementById('statusLabel').textContent = `Status: ${statusLabelMap[status] || status}`;

            renderSteps(status);
            renderTimeline(data.timeline || []);

            resultSection.classList.remove('d-none');
        }

        trackingForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            hideFeedback();
            resultSection.classList.add('d-none');

            const serviceCodeInput = document.getElementById('serviceCode');
            const phoneInput = document.getElementById('phone');
            const serviceCode = serviceCodeInput.value.trim();
            const phone = phoneInput.value.trim();

            if (!serviceCode) {
                showFeedback('danger', 'Service code wajib diisi');
                serviceCodeInput.focus();
                return;
            }

            const params = new URLSearchParams();
            params.set('service_code', serviceCode);

            if (phone) {
                params.set('phone', phone);
            }

            setLoading(true);

            try {
                const response = await fetch(`api.php?${params.toString()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const payload = await response.json();

                if (!response.ok || !payload.success) {
                    showFeedback('danger', payload.message || 'Data tidak ditemukan');
                    return;
                }

                renderResult(payload.data || {});
            } catch (error) {
                showFeedback('danger', 'Terjadi gangguan koneksi. Silakan coba lagi.');
            } finally {
                setLoading(false);
            }
        });
    </script>
</body>
</html>



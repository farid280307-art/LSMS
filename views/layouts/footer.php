    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Script untuk toggle sidebar
        window.addEventListener('DOMContentLoaded', event => {
            const SIDEBAR_STATE_KEY = 'lsms_sidebar_collapsed';

            const isSidebarCollapsed = () => {
                try {
                    const stored = localStorage.getItem(SIDEBAR_STATE_KEY);
                    return stored === null || stored === '1';
                } catch (e) {
                    return true;
                }
            };

            const saveSidebarState = (collapsed) => {
                try {
                    localStorage.setItem(SIDEBAR_STATE_KEY, collapsed ? '1' : '0');
                } catch (e) {}
            };

            if (isSidebarCollapsed()) {
                document.body.classList.add('sb-sidenav-toggled');
            }

            // 1. Desktop Toggle (Collapse Sidebar)
            const toggleSidebar = event => {
                event.preventDefault();
                document.body.classList.toggle('sb-sidenav-toggled');
                saveSidebarState(document.body.classList.contains('sb-sidenav-toggled'));
            };

            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);

            const navbarToggleDesktop = document.getElementById('navbarToggleDesktop');
            if (navbarToggleDesktop) navbarToggleDesktop.addEventListener('click', toggleSidebar);

            // 2. Mobile Toggle (Open Offcanvas)
            const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');
            const mobileSidebarEl = document.getElementById('mobileSidebar');
            
            if (sidebarToggleMobile && mobileSidebarEl) {
                const bsOffcanvas = new bootstrap.Offcanvas(mobileSidebarEl);
                sidebarToggleMobile.addEventListener('click', event => {
                    event.preventDefault();
                    bsOffcanvas.show();
                });
            }

            // Global pagination untuk semua tabel (max 10 baris per halaman)
            const paginateTables = (pageSize = 10) => {
                const tables = document.querySelectorAll('table.table');

                tables.forEach(table => {
                    if (table.dataset.paginated === '1') return;
                    const tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
                    if (!tbody) return;

                    const rows = Array.from(tbody.rows);
                    const totalRows = rows.length;
                    const totalPages = Math.ceil(totalRows / pageSize);

                    if (totalPages <= 1) return;

                    table.dataset.paginated = '1';

                    const host = table.closest('.table-responsive') || table.parentElement;
                    const nav = document.createElement('nav');
                    nav.className = 'mt-3 mb-4';

                    const ul = document.createElement('ul');
                    ul.className = 'pagination pagination-sm justify-content-center mb-0';
                    nav.appendChild(ul);

                    // sisipkan pagination setelah container tabel
                    host.parentNode.insertBefore(nav, host.nextSibling);

                    const setRowsVisible = page => {
                        const safePage = Math.min(Math.max(1, page), totalPages);
                        const start = (safePage - 1) * pageSize;
                        const end = start + pageSize;

                        rows.forEach((row, idx) => {
                            row.style.display = (idx >= start && idx < end) ? '' : 'none';
                        });

                        table.dataset.page = String(safePage);
                    };

                    const buildItem = ({ label, page, disabled, active, ariaLabel }) => {
                        const li = document.createElement('li');
                        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');

                        const a = document.createElement('a');
                        a.className = 'page-link';
                        a.href = '#';
                        a.innerText = label;
                        if (ariaLabel) a.setAttribute('aria-label', ariaLabel);

                        a.addEventListener('click', e => {
                            e.preventDefault();
                            if (disabled) return;
                            render(page);
                        });

                        li.appendChild(a);
                        return li;
                    };

                    const render = page => {
                        setRowsVisible(page);
                        const current = Number(table.dataset.page || '1');

                        // rebuild pagination
                        ul.innerHTML = '';

                        const windowSize = 5; // jumlah nomor halaman yang ditampilkan
                        let startPage = Math.max(1, current - Math.floor(windowSize / 2));
                        let endPage = Math.min(totalPages, startPage + windowSize - 1);
                        startPage = Math.max(1, endPage - windowSize + 1);

                        ul.appendChild(buildItem({
                            label: '«',
                            page: 1,
                            disabled: current === 1,
                            active: false,
                            ariaLabel: 'First'
                        }));
                        ul.appendChild(buildItem({
                            label: '‹',
                            page: current - 1,
                            disabled: current === 1,
                            active: false,
                            ariaLabel: 'Previous'
                        }));

                        for (let p = startPage; p <= endPage; p++) {
                            ul.appendChild(buildItem({
                                label: String(p),
                                page: p,
                                disabled: false,
                                active: p === current
                            }));
                        }

                        ul.appendChild(buildItem({
                            label: '›',
                            page: current + 1,
                            disabled: current === totalPages,
                            active: false,
                            ariaLabel: 'Next'
                        }));
                        ul.appendChild(buildItem({
                            label: '»',
                            page: totalPages,
                            disabled: current === totalPages,
                            active: false,
                            ariaLabel: 'Last'
                        }));
                    };

                    render(1);
                });
            };

            paginateTables(10);

            // Chart.js Area Chart Example
            const ctx = document.getElementById('myAreaChart');
            if (ctx && typeof dashboardData !== 'undefined') {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dashboardData.labels,
                        datasets: [{
                            label: "Pendapatan",
                            lineTension: 0.3,
                            backgroundColor: "rgba(37, 99, 235, 0.05)",
                            borderColor: "rgba(37, 99, 235, 1)",
                            pointRadius: 3,
                            pointBackgroundColor: "rgba(37, 99, 235, 1)",
                            pointBorderColor: "rgba(37, 99, 235, 1)",
                            pointHoverRadius: 3,
                            pointHoverBackgroundColor: "rgba(37, 99, 235, 1)",
                            pointHoverBorderColor: "rgba(37, 99, 235, 1)",
                            pointHitRadius: 10,
                            pointBorderWidth: 2,
                            data: dashboardData.data,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        layout: {
                            padding: { left: 10, right: 25, top: 25, bottom: 0 }
                        },
                        scales: {
                            x: { grid: { display: false, drawBorder: false } },
                            y: {
                                ticks: {
                                    // Include a dollar sign in the ticks
                                    callback: function(value, index, values) {
                                        return 'Rp ' + (value / 1000) + 'k';
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>

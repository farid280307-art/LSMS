<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome untuk Ikon -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2563eb; /* Royal Blue yang modern */
            --primary-hover: #1d4ed8;
            --bg-light: #f1f5f9; /* Slate 100 */
            --text-dark: #1e293b; /* Slate 800 */
            --sidebar-width: 240px;
            --sidebar-collapsed-width: 64px;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
        }

        /* Card Styling Minimalis */
        .card {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            background-color: white;
        }
        .card-header {
            background-color: white;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            padding: 1rem 1.5rem;
        }

        /* Button Styling */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.5rem 1rem;
            font-weight: 500;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        /* Navbar Content */
        #page-content-wrapper .navbar {
            border-bottom: 1px solid #e2e8f0; /* Divider */
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); /* Shadow Tipis */
            margin-bottom: 0 !important; /* Menghapus margin bawah bawaan */
        }

        /* Layout Wrapper */
        #wrapper { overflow-x: hidden; }
        
        #sidebar-wrapper { 
            height: 100vh;
            margin-left: 0;
            transition: width 0.3s ease-in-out;
            width: var(--sidebar-width);
            background-color: white;
            border-right: 1px solid #e2e8f0;
            position: sticky;
            top: 0; /* fallback */
            left: 0;
            overflow: hidden;
            z-index: 1040; /* Lebih tinggi dari Navbar (1020-1030) */
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 1.5rem;
            height: 71px; /* Menyamai tinggi navbar */
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-header .brand-link {
            text-decoration: none;
            white-space: nowrap;
            transition: opacity 0.2s ease, transform 0.2s ease, width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-header .brand-text-strong {
            font-weight: 800;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--primary-color);
        }

        .sidebar-header .brand-text-light {
            font-weight: 300;
            color: #334155;
            font-size: 1.5rem;
        }

        .list-group-item {
            border: none;
            padding: 0.8rem 1.5rem;
            margin: 0.25rem 1rem;
            border-radius: 0.5rem;
            color: #64748b;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: transparent;
            border-left: 4px solid transparent; /* Indikator tersembunyi */
            display: flex;
            align-items: center;
            gap: 0.75rem; /* Jarak antar icon dan text */
            white-space: nowrap; /* Mencegah teks turun ke bawah */
            min-width: 0; /* Izinkan konten flex untuk mengecil (anti overflow-x) */
        }

        .list-group-item:hover {
            background-color: #fff;
            color: #64748b;
            border-left-color: var(--primary-color); /* Munculkan indikator */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .list-group-item.active {
            background-color: var(--primary-color);
            color: white;
            border-left-color: var(--primary-color);
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.4);
        }

        /* Animasi Teks Menu */
        .list-group-item span {
            transition: opacity 0.2s ease, transform 0.2s ease;
            opacity: 1;
            transform: translateX(0);
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Hindari horizontal scroll di area menu sidebar (biarkan vertical scroll) */
        #sidebar-wrapper .list-group,
        #mobileSidebar .list-group {
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }

        /* Ikon tetap ukurannya */
        .list-group-item i {
            min-width: 20px;
            text-align: center;
        }

        /* Animasi Masuk Halaman (Entrance) */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        #page-content-wrapper > .container-fluid {
            animation: fadeInUp 0.6s cubic-bezier(0.25, 0.8, 0.25, 1) forwards;
        }

        /* Efek Hover pada Card */
        .card {
            transition: box-shadow 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        
        #page-content-wrapper { width: 100%; }
        
        /* Default: Hide desktop toggle button globally (Fix double icon on mobile) */
        #navbarToggleDesktop {
            display: none;
        }

        /* Desktop: Toggle hides sidebar (Collapse) */
        @media (min-width: 768px) {
            #page-content-wrapper { min-width: 0; width: 100%; }

            /* Sidebar tetap di tempat saat halaman discroll */
            #sidebar-wrapper {
                position: fixed;
                top: 0;
                left: 0;
            }

            /* Karena sidebar fixed (keluar dari flow), dorong konten ke kanan */
            #page-content-wrapper {
                margin-left: var(--sidebar-width);
                width: calc(100% - var(--sidebar-width));
            }

            body.sb-sidenav-toggled #sidebar-wrapper {
                margin-left: 0;
                width: 0;
                border-right: none;
            }

            body.sb-sidenav-toggled #page-content-wrapper {
                margin-left: 0;
                width: 100%;
            }
            
            /* Tombol Toggle di Navbar (Desktop Only) */
            #navbarToggleDesktop {
                display: block;
            }
        }
    </style>
</head>
<body>

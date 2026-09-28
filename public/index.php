<?php
ob_start();
// 1. Load Config & Database
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// 2. Cek Login (Middleware)
require_once __DIR__ . '/../auth/check.php';

// 3. Ambil Data User dari Session
$user = $_SESSION['user'];
$role = $user['role'];

// 4. Redirect ke Dashboard yang Sesuai

// Buat path ke file dashboard spesifik untuk role user
$dashboard_file = __DIR__ . '/../modules/dashboard/' . $role . '.php';
$dashboard_url = BASE_URL . 'modules/dashboard/' . $role . '.php';

// Jika file dashboard untuk role tersebut ada, arahkan ke sana.
if (file_exists($dashboard_file)) {
    header('Location: ' . $dashboard_url);
    exit();
} else {
    // Fallback: Jika tidak ada dashboard khusus, arahkan ke halaman daftar service
    // Ini adalah halaman default yang berguna untuk sebagian besar role.
    header('Location: ' . BASE_URL . 'modules/service/index.php');
    exit();
}

ob_end_flush();
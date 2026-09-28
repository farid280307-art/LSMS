<?php
/**
 * Middleware untuk Autentikasi dan Otorisasi
 * 
 * Cara Penggunaan:
 * 1. Definisikan variabel $allowed_roles di halaman yang ingin diproteksi.
 *    Contoh: $allowed_roles = ['superadmin', 'kepala_pusat'];
 * 2. Sertakan file ini menggunakan require_once.
 *    Contoh: require_once __DIR__ . '/../../auth/check.php';
 */

// Pastikan BASE_URL sudah didefinisikan untuk redirect
if (!defined('BASE_URL')) {
    // Asumsi file app.php ada di level yang sama dengan folder auth
    require_once __DIR__ . '/../config/app.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Cek Autentikasi: Apakah user sudah login?
if (!isset($_SESSION['user']['id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

// 1b. Pastikan email tersedia di session (untuk kebutuhan tampilan profile/sidebar).
// Dilakukan sekali per session jika key belum ada.
if (!isset($_SESSION['user']['email'])) {
    require_once __DIR__ . '/../config/database.php';

    try {
        $pdo = getDbConnection('pusat');
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user']['id']]);
        $email = $stmt->fetchColumn();
        if ($email) {
            $_SESSION['user']['email'] = $email;
        }
    } catch (Throwable $e) {
        // Abaikan jika DB bermasalah; halaman tetap bisa lanjut tanpa email.
    }
}

// 2. Cek Otorisasi: Apakah role user diizinkan mengakses halaman ini?
$user_role = $_SESSION['user']['role'] ?? '';
if (isset($allowed_roles) && is_array($allowed_roles) && !in_array($user_role, $allowed_roles)) {
    // Jika tidak diizinkan, kembalikan ke halaman utama (dashboard masing-masing)
    header('Location: ' . BASE_URL . 'public/index.php?error=access_denied');
    exit();
}

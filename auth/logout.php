<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// LOG SYSTEM: Logout
if (isset($_SESSION['user']['id'])) {
    $pdo = getDbConnection('pusat'); // Gunakan koneksi pusat untuk log
    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (?, 'logout', 'User logged out', ?, ?)");
    $stmt->execute([$_SESSION['user']['id'], $ip, $agent]);
}

// Hapus semua variabel session
$_SESSION = [];

// Hancurkan session
session_destroy();

// Redirect ke halaman login
header('Location: ' . BASE_URL . 'auth/login.php');
exit();

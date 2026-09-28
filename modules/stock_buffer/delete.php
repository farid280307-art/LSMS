<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'gudang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$pdo = getDbConnection('pusat');
$id = $_POST['id'] ?? '';

if (empty($id)) {
    header("Location: index.php?msg=delete_failed&err_msg=" . urlencode("ID item tidak valid."));
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM stock_buffer_pusat WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: index.php?msg=deleted");
    exit();
} catch (Exception $e) {
    header("Location: index.php?msg=delete_failed&err_msg=" . urlencode("Gagal menghapus data: " . $e->getMessage()));
    exit();
}


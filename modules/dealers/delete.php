<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang', 'admin'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $pdo = getDbConnection('pusat');
    
    $stmt = $pdo->prepare("DELETE FROM dealers WHERE id = ?");
    $stmt->execute([$id]);
    
    header("Location: index.php?msg=deleted");
    exit();
}
?>
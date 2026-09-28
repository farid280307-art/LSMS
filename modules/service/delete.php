<?php
$allowed_roles = ['superadmin', 'kepala_admin', 'kepala_cabang', 'admin'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

$role = $_SESSION['user']['role'];

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Delete Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: index.php?msg=csrf_error");
        exit();
    }

    $delete_id = $_POST['id'];
    $branch_code = $_POST['branch_code'] ?? null;
    $pdo = getDbConnection(); // Default connection

    // For superadmin, we must get the connection for the specific branch
    if (in_array($role, ['superadmin', 'kepala_admin']) && !empty($branch_code)) {
        $pdo = getDbConnection($branch_code);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$delete_id]);
        header("Location: index.php?msg=deleted");
        exit();
    } catch (PDOException $e) {
        header("Location: index.php?msg=delete_failed");
        exit();
    }
} else {
    // Redirect jika diakses tidak melalui POST
    header("Location: index.php");
    exit();
}
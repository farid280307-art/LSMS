<?php
$allowed_roles = ['superadmin', 'kepala_pusat', 'kepala_admin', 'kepala_cabang'];
require_once __DIR__ . '/../../auth/check.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$pdo = getDbConnection('pusat');
$role = $_SESSION['user']['role'];
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: index.php?msg=not_found');
    exit();
}

if ($id === (int)$_SESSION['user']['id']) {
    header('Location: index.php?msg=delete_self_failed');
    exit();
}

try {
    if ($role === 'kepala_cabang') {
        $stmt_target = $pdo->prepare('SELECT branch_id FROM users WHERE id = ?');
        $stmt_target->execute([$id]);
        $target_branch_id = $stmt_target->fetchColumn();

        if ($target_branch_id === false) {
            header('Location: index.php?msg=not_found');
            exit();
        }

        $stmt_my_branch = $pdo->prepare('SELECT id FROM branches WHERE branch_code = ?');
        $stmt_my_branch->execute([$_SESSION['user']['cabang']]);
        $my_branch_id = $stmt_my_branch->fetchColumn();

        if ((string)$target_branch_id !== (string)$my_branch_id) {
            header('Location: index.php?msg=forbidden');
            exit();
        }
    }

    $stmt_delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt_delete->execute([$id]);

    if ($stmt_delete->rowCount() === 0) {
        header('Location: index.php?msg=not_found');
        exit();
    }

    header('Location: index.php?msg=deleted');
    exit();
} catch (Exception $e) {
    header('Location: index.php?msg=delete_failed&err_msg=' . urlencode($e->getMessage()));
    exit();
}

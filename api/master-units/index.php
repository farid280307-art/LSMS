<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function masterUnitRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function connectMasterUnitDbSafely(array $config): ?PDO
{
    try {
        $dbName = (string)($config['dbname'] ?? '');
        if ($dbName === '') {
            return null;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string)($config['host'] ?? '127.0.0.1'),
            (string)($config['port'] ?? '3306'),
            $dbName
        );

        return new PDO(
            $dsn,
            (string)($config['user'] ?? ''),
            (string)($config['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}

function getMasterUnitColumns(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM master_units');
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['Field'])) {
                $columns[] = (string)$row['Field'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function findFirstExistingColumn(array $columns, array $candidates): string
{
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && hasColumn($columns, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $sql = 'SHOW TABLES LIKE ' . $pdo->quote($tableName);
        $stmt = $pdo->query($sql);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function findFirstExistingTable(PDO $pdo, array $candidates): string
{
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && tableExists($pdo, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    masterUnitRespond([
        'success' => false,
        'message' => 'Method tidak diizinkan',
    ], 405);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['gudang', 'superadmin'];
$userId = $_SESSION['user']['id'] ?? null;
$userRole = $_SESSION['user']['role'] ?? '';

if (empty($userId)) {
    masterUnitRespond([
        'success' => false,
        'message' => 'Sesi login berakhir. Silakan login kembali.',
    ], 401);
}

if (!in_array($userRole, $allowedRoles, true)) {
    masterUnitRespond([
        'success' => false,
        'message' => 'Anda tidak memiliki akses ke data master unit.',
    ], 403);
}

try {
    global $db_configs;

    if (!isset($db_configs['pusat']) || !is_array($db_configs['pusat'])) {
        throw new RuntimeException('Konfigurasi database pusat tidak ditemukan.');
    }

    $pdoCenter = connectMasterUnitDbSafely($db_configs['pusat']);

    if (!$pdoCenter instanceof PDO) {
        throw new RuntimeException('Koneksi ke database pusat tidak tersedia.');
    }

    $masterColumns = getMasterUnitColumns($pdoCenter);
    if (empty($masterColumns)) {
        throw new RuntimeException('Tabel master_units tidak ditemukan.');
    }

    $idExpr = hasColumn($masterColumns, 'id') ? 'mu.`id`' : '0';

    $serialNumberColumn = findFirstExistingColumn($masterColumns, ['serial_number', 'sn']);
    $serialNumberExpr = $serialNumberColumn !== '' ? sprintf('mu.%s', quoteIdentifier($serialNumberColumn)) : "''";

    $unitNameColumn = findFirstExistingColumn($masterColumns, ['unit_name', 'brand']);
    $unitNameExpr = $unitNameColumn !== '' ? sprintf('mu.%s', quoteIdentifier($unitNameColumn)) : "''";

    $masterModelCodeColumn = findFirstExistingColumn($masterColumns, ['model_code', 'model']);
    $modelCodeExpr = $masterModelCodeColumn !== ''
        ? sprintf('mu.%s', quoteIdentifier($masterModelCodeColumn))
        : "''";

    $warrantyStartExpr = hasColumn($masterColumns, 'warranty_start') ? 'mu.`warranty_start`' : 'NULL';
    $warrantyEndExpr = hasColumn($masterColumns, 'warranty_end') ? 'mu.`warranty_end`' : 'NULL';

    $currentBranchColumn = findFirstExistingColumn($masterColumns, ['current_branch', 'branch_code']);
    $currentBranchExpr = $currentBranchColumn !== '' ? sprintf('mu.%s', quoteIdentifier($currentBranchColumn)) : "''";

    $warrantyPaymentStatusExpr = "'UNPAID'";
    if (hasColumn($masterColumns, 'warranty_start') && hasColumn($masterColumns, 'warranty_end')) {
        $warrantyPaymentStatusExpr = "CASE
            WHEN (
                mu.`warranty_start` IS NOT NULL
                AND mu.`warranty_start` <> '0000-00-00'
                AND mu.`warranty_end` IS NOT NULL
                AND mu.`warranty_end` <> '0000-00-00'
                AND CURDATE() BETWEEN mu.`warranty_start` AND mu.`warranty_end`
            ) THEN 'PAID'
            ELSE 'UNPAID'
        END";
    } elseif (hasColumn($masterColumns, 'warranty_end')) {
        $warrantyPaymentStatusExpr = "CASE
            WHEN (
                mu.`warranty_end` IS NOT NULL
                AND mu.`warranty_end` <> '0000-00-00'
                AND CURDATE() <= mu.`warranty_end`
            ) THEN 'PAID'
            ELSE 'UNPAID'
        END";
    } elseif (hasColumn($masterColumns, 'warranty_start')) {
        $warrantyPaymentStatusExpr = "CASE
            WHEN (
                mu.`warranty_start` IS NOT NULL
                AND mu.`warranty_start` <> '0000-00-00'
                AND CURDATE() >= mu.`warranty_start`
            ) THEN 'PAID'
            ELSE 'UNPAID'
        END";
    }

    $modelCodeJoinClause = '';
    $modelCodeTableName = findFirstExistingTable($pdoCenter, ['model_code', 'model_codes']);
    if ($modelCodeTableName !== '' && $masterModelCodeColumn !== '') {
        $modelCodeColumnsStmt = $pdoCenter->query(sprintf('SHOW COLUMNS FROM %s', quoteIdentifier($modelCodeTableName)));
        $modelCodeColumns = array_map(
            static fn (array $row): string => (string)($row['Field'] ?? ''),
            $modelCodeColumnsStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $modelCodeKeyColumn = findFirstExistingColumn($modelCodeColumns, ['model_code', 'code', 'model']);
        if ($modelCodeKeyColumn !== '') {
            $modelCodeJoinClause = sprintf(
                ' LEFT JOIN %s mc ON mc.%s = mu.%s',
                quoteIdentifier($modelCodeTableName),
                quoteIdentifier($modelCodeKeyColumn),
                quoteIdentifier($masterModelCodeColumn)
            );

            $modelCodeExpr = sprintf(
                'COALESCE(mc.%s, mu.%s)',
                quoteIdentifier($modelCodeKeyColumn),
                quoteIdentifier($masterModelCodeColumn)
            );
        }
    }

    $sql = sprintf(
        "
        SELECT
            %s AS id,
            %s AS serial_number,
            %s AS unit_name,
            %s AS model_code,
            %s AS warranty_start,
            %s AS warranty_end,
            %s AS warranty_payment_status,
            %s AS current_branch
        FROM master_units mu
        %s
        ORDER BY serial_number ASC, unit_name ASC, model_code ASC, warranty_payment_status ASC, id ASC
    ",
        $idExpr,
        $serialNumberExpr,
        $unitNameExpr,
        $modelCodeExpr,
        $warrantyStartExpr,
        $warrantyEndExpr,
        $warrantyPaymentStatusExpr,
        $currentBranchExpr,
        $modelCodeJoinClause
    );

    $stmt = $pdoCenter->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    masterUnitRespond([
        'success' => false,
        'message' => 'Gagal mengambil data master unit dari database pusat.',
    ], 500);
}


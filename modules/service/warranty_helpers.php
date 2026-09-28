<?php

/**
 * Pastikan kolom garansi sparepart tersedia di tabel service_items.
 */
function ensureServiceItemsWarrantySchema(PDO $pdo): void
{
    static $checked = [];

    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') {
            return;
        }

        if (isset($checked[$dbName])) {
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'service_items'"
        );
        $stmt->execute(['db' => $dbName]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existingMap = array_flip($existing);

        $alterMap = [
            'warranty_duration' => "ADD COLUMN warranty_duration INT NOT NULL DEFAULT 0 AFTER price",
            'warranty_start' => "ADD COLUMN warranty_start DATE NULL AFTER warranty_duration",
            'warranty_end' => "ADD COLUMN warranty_end DATE NULL AFTER warranty_start",
            'warranty_status' => "ADD COLUMN warranty_status ENUM('active','expired') NOT NULL DEFAULT 'expired' AFTER warranty_end",
            'part_serial_number' => "ADD COLUMN part_serial_number VARCHAR(120) NULL AFTER warranty_status",
            'is_warranty_claim' => "ADD COLUMN is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0 AFTER part_serial_number",
        ];

        foreach ($alterMap as $column => $ddl) {
            if (!isset($existingMap[$column])) {
                $pdo->exec("ALTER TABLE service_items {$ddl}");
            }
        }

        $checked[$dbName] = true;
    } catch (Throwable $e) {
        // Tidak menghentikan alur utama jika sinkronisasi skema gagal.
    }
}

function normalizeWarrantyDuration($duration): int
{
    $duration = (int)$duration;
    return in_array($duration, [0, 3, 6], true) ? $duration : 0;
}

function checkWarrantyStatus(?string $warrantyEnd): string
{
    if (empty($warrantyEnd)) {
        return 'expired';
    }

    $today = date('Y-m-d');
    return $today <= $warrantyEnd ? 'active' : 'expired';
}

/**
 * @return array{duration:int,start:?string,end:?string,status:string}
 */
function calculateWarranty(?string $serviceCompletedDate, int $warrantyDuration): array
{
    $duration = normalizeWarrantyDuration($warrantyDuration);

    if ($duration <= 0 || empty($serviceCompletedDate)) {
        return [
            'duration' => $duration,
            'start' => null,
            'end' => null,
            'status' => 'expired',
        ];
    }

    $start = date('Y-m-d', strtotime($serviceCompletedDate));
    $end = date('Y-m-d', strtotime($start . " +{$duration} months"));

    return [
        'duration' => $duration,
        'start' => $start,
        'end' => $end,
        'status' => checkWarrantyStatus($end),
    ];
}

function getServiceCompletionDate(PDO $pdo, int $serviceId): ?string
{
    $stmt = $pdo->prepare('SELECT status, updated_at FROM services WHERE id = ? LIMIT 1');
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!in_array((string)$row['status'], ['finished', 'taken'], true)) {
        return null;
    }

    return date('Y-m-d', strtotime((string)($row['updated_at'] ?? 'now')));
}

function syncWarrantyStatusForService(PDO $pdo, int $serviceId): void
{
    ensureServiceItemsWarrantySchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, warranty_end FROM service_items WHERE service_id = ? AND item_type = 'sparepart'"
    );
    $stmt->execute([$serviceId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return;
    }

    $update = $pdo->prepare('UPDATE service_items SET warranty_status = ? WHERE id = ?');

    foreach ($rows as $row) {
        $status = checkWarrantyStatus($row['warranty_end'] ?? null);
        $update->execute([$status, $row['id']]);
    }
}

function applyWarrantyToServiceItems(PDO $pdo, int $serviceId): void
{
    ensureServiceItemsWarrantySchema($pdo);

    $serviceCompletedDate = getServiceCompletionDate($pdo, $serviceId);

    $stmt = $pdo->prepare(
        "SELECT id, warranty_duration, warranty_start, item_type, is_warranty_claim
         FROM service_items
         WHERE service_id = ? AND item_type = 'sparepart'"
    );
    $stmt->execute([$serviceId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return;
    }

    $update = $pdo->prepare(
        "UPDATE service_items
         SET warranty_duration = ?, warranty_start = ?, warranty_end = ?, warranty_status = ?
         WHERE id = ?"
    );

    foreach ($rows as $row) {
        $duration = normalizeWarrantyDuration($row['warranty_duration'] ?? 0);
        $isClaim = (int)($row['is_warranty_claim'] ?? 0) === 1;

        if ($isClaim) {
            $update->execute([0, null, null, 'expired', $row['id']]);
            continue;
        }

        $startBase = $row['warranty_start'] ?: $serviceCompletedDate;
        $warranty = calculateWarranty($startBase, $duration);

        if ($duration > 0 && empty($warranty['start']) && !empty($serviceCompletedDate)) {
            $warranty = calculateWarranty($serviceCompletedDate, $duration);
        }

        $update->execute([
            $warranty['duration'],
            $warranty['start'],
            $warranty['end'],
            $warranty['status'],
            $row['id'],
        ]);
    }

    syncWarrantyStatusForService($pdo, $serviceId);
}

/**
 * Cari referensi sparepart yang masih aktif garansinya untuk kebutuhan claim.
 * Mengembalikan data referensi jika ditemukan, null jika tidak.
 */
function findActiveWarrantyReferenceForPart(PDO $pdo, string $serialNumber, string $partKeyword, int $excludeServiceId): ?array
{
    if ($serialNumber === '' || $partKeyword === '') {
        return null;
    }

    ensureServiceItemsWarrantySchema($pdo);

    $sql = "
        SELECT si.id, si.service_id, si.description, si.warranty_end, s.service_code
        FROM service_items si
        JOIN services s ON s.id = si.service_id
        JOIN devices d ON d.id = s.device_id
        WHERE d.serial_number = :serial_number
          AND s.id <> :exclude_service_id
          AND si.item_type = 'sparepart'
          AND COALESCE(si.is_warranty_claim, 0) = 0
          AND si.warranty_end IS NOT NULL
          AND CURDATE() <= si.warranty_end
          AND si.description LIKE :keyword
        ORDER BY si.warranty_end DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'serial_number' => $serialNumber,
        'exclude_service_id' => $excludeServiceId,
        'keyword' => '%' . $partKeyword . '%',
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function warrantyDurationLabel(int $duration): string
{
    $duration = normalizeWarrantyDuration($duration);
    if ($duration <= 0) {
        return 'Tanpa Garansi';
    }

    return $duration . ' bulan';
}

<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_configs = [
    'pusat' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'lsms_pusat',
        'user' => 'root',
        'pass' => ''
    ],
    'cabang_1' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'lsms_jakarta',
        'user' => 'root',
        'pass' => '' 
    ],
    'cabang_2' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'lsms_bandung',
        'user' => 'root',
        'pass' => ''
    ],
    'cabang_3' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'lsms_surabaya',
        'user' => 'root',
        'pass' => ''
    ],
];

/**
 * @return PDO|null Objek koneksi PDO atau null jika gagal.
 */
function getDbConnection(string $force_branch = null): ?PDO
{
    
    static $connections = [];

    global $db_configs;

    // Tentukan role mana saja yang selalu mengakses database pusat.
    $central_access_roles = ['superadmin', 'kepala_pusat', 'kepala_admin'];

    $db_key = null;

    // Prioritas 1: Paksa koneksi ke cabang tertentu (untuk superadmin)
    if ($force_branch && isset($db_configs[$force_branch])) {
        $db_key = $force_branch;
    } 
    // Prioritas 2: Tentukan koneksi berdasarkan session user
    else if (isset($_SESSION['user']) && isset($_SESSION['user']['role'])) {
        $user_role = $_SESSION['user']['role'];
        $user_cabang = $_SESSION['user']['cabang'] ?? null; // e.g., 'cabang_1'

        // Jika role user tidak termasuk dalam role akses pusat,
        // maka gunakan koneksi cabang.
        if (!in_array($user_role, $central_access_roles) && !empty($user_cabang)) {
            // Pastikan key cabang ada di konfigurasi untuk menghindari error
            if (array_key_exists($user_cabang, $db_configs)) {
                $db_key = $user_cabang;
            }
        }
    }

    if ($db_key === null) {
        $db_key = 'pusat';
    }

    if (isset($connections[$db_key])) {
        return $connections[$db_key];
    }

    try {
        $config = $db_configs[$db_key];
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

       
        $connections[$db_key] = new PDO($dsn, $config['user'], $config['pass'], $options);
        
        return $connections[$db_key];

    } catch (PDOException $e) {
        
        die("Tidak dapat terhubung ke server database. Silakan hubungi administrator.");
    }
}

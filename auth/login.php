<?php
// Mulai session di baris paling atas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Sertakan file konfigurasi dan koneksi database
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// Jika user sudah login, arahkan ke halaman utama
if (isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'public/index.php');
    exit();
}

$error_message = '';

// Cek jika ada data yang dikirim dari form login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error_message = 'Email dan password tidak boleh kosong.';
    } else {
        try {
            // Koneksi akan otomatis ke database 'pusat' karena session belum ada
            $pdo = getDbConnection();

            // Query untuk mendapatkan data user, role, dan cabang dalam satu kali panggilan
            $sql = "SELECT 
                        u.id, u.email, u.password, u.full_name, u.is_active,
                        r.role_name,
                        b.branch_code
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN branches b ON u.branch_id = b.id
                    WHERE u.email = :email";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            // Verifikasi user dan password
            if ($user && password_verify($password, $user['password'])) {
                if ($user['is_active']) {
                    // Regenerasi session ID untuk keamanan
                    session_regenerate_id(true);

                    // Simpan data penting user ke dalam session
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => $user['role_name'],
                        'cabang' => $user['branch_code'] // Akan bernilai NULL jika user pusat
                    ];

                    // LOG SYSTEM: Login Berhasil
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $log_stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (?, 'login', 'User logged in', ?, ?)");
                    $log_stmt->execute([$user['id'], $ip, $agent]);

                    // Arahkan ke halaman utama setelah login berhasil
                    header('Location: ' . BASE_URL . 'public/index.php');
                    exit();
                } else {
                    $error_message = 'Akun Anda tidak aktif. Silakan hubungi administrator.';
                }
            } else {
                $error_message = 'Email atau password yang Anda masukkan salah.';
            }
        } catch (PDOException $e) {
            // Untuk production, log error ini, jangan tampilkan ke user
            // error_log($e->getMessage());
            $error_message = 'Terjadi masalah dengan koneksi database.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LSMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            background-color: #eff6ff; /* Light Blue Background */
            font-family: 'Inter', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container { 
            width: 100%;
            max-width: 400px; 
            padding: 2.5rem; 
            background: white; 
            border-radius: 1rem; 
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); 
        }
        .btn-primary { background-color: #2563eb; border: none; padding: 0.75rem; font-weight: 600; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .form-control { padding: 0.75rem; border-radius: 0.5rem; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-primary">LSMS</h3>
            <p class="text-muted">Laptop Service Management System</p>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="mb-3">
                <label for="email" class="form-label">Alamat Email</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="showPassword">
                <label class="form-check-label" for="showPassword">Tampilkan Password</label>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
    </div>

    <script>
        document.getElementById('showPassword').addEventListener('change', function() {
            var passwordInput = document.getElementById('password');
            if (this.checked) {
                passwordInput.type = 'text';
            } else {
                passwordInput.type = 'password';
            }
        });
    </script>
</body>
</html>

# Laptop Service Management System (LSMS)

LSMS adalah aplikasi berbasis web yang komprehensif untuk mengelola operasional pusat layanan laptop. Sistem ini dirancang untuk mendukung model bisnis multi-cabang dengan satu pusat data (`pusat`) dan beberapa cabang layanan (`cabang`), menyederhanakan alur kerja mulai dari penerimaan unit, pelacakan servis, hingga manajemen stok dan penjualan.

## Fitur Utama

*   **Kontrol Akses Berbasis Peran:** Izin akses yang terperinci untuk berbagai peran pengguna seperti Superadmin, Admin, Customer Service, Teknisi, Gudang, dan lainnya.
*   **Manajemen Servis:**
    *   Melacak status servis dari awal hingga selesai (Pending, Diagnosis, On Progress, Menunggu Sparepart, Selesai, Diambil).
    *   Menugaskan teknisi ke pekerjaan servis tertentu.
    *   Melihat riwayat servis yang detail untuk setiap unit.
*   **Manajemen Stok Multi-Cabang:**
    *   Manajemen stok terpusat (`Stok Pusat`) untuk pengadaan utama.
    *   Stok individual untuk setiap cabang layanan (`Stok Cabang`).
    *   Membuat dan mencetak Surat Jalan untuk transfer stok antar pusat dan cabang.
*   **Penjualan Langsung (Point of Sale):**
    *   Menjual sparepart atau perangkat secara langsung kepada pelanggan umum atau dealer.
    *   Menghasilkan invoice untuk setiap transaksi penjualan.
*   **Manajemen Pelanggan & Dealer:** Mengelola database pelanggan perorangan dan dealer bisnis.
*   **Pengecekan Nomor Seri (Serial Number):**
    *   Memeriksa apakah sebuah unit sedang dalam proses servis aktif di cabang mana pun untuk menghindari duplikasi.
    *   Memverifikasi status garansi unit dari database master.
*   **Manajemen Sistem & Pengguna:**
    *   Mengelola akun pengguna dan peran mereka.
    *   Melihat log aktivitas sistem untuk tujuan keamanan dan audit.

## Tumpukan Teknologi

*   **Backend:** PHP (Gaya prosedural dengan struktur modular)
*   **Database:** MySQL / MariaDB (Menggunakan PDO untuk koneksi)
*   **Frontend:** HTML, JavaScript, Bootstrap 5
*   **Server:** Apache (Direkomendasikan melalui XAMPP)

## Struktur Proyek

Struktur direktori utama dari proyek ini adalah sebagai berikut:

```
/
├── auth/             # Skrip otentikasi (login, logout, cek sesi)
├── config/           # Konfigurasi aplikasi (app.php) dan database (database.php)
├── modules/          # Fitur inti aplikasi, dipisahkan berdasarkan domain
│   ├── dashboard/
│   ├── purchases/    # Penjualan Langsung
│   ├── service/
│   ├── stock_buffer/ # Stok Pusat
│   └── ...
├── public/           # Root web publik dengan titik masuk utama (index.php)
└── views/            # Komponen UI yang dapat digunakan kembali (header, footer, sidebar)
```

## Pengaturan & Instalasi Lokal

Ikuti langkah-langkah ini untuk menjalankan proyek di lingkungan lokal Anda.

1.  **Prasyarat:**
    *   Lingkungan server web lokal seperti XAMPP atau WAMP (Apache, PHP, MySQL).

2.  **Salin Proyek:**
    *   Letakkan semua file proyek di dalam direktori root server web Anda (misalnya, `c:\xampp\htdocs\Laptop_Service_Management_System\`).

3.  **Pengaturan Database:**
    *   Sistem ini menggunakan arsitektur multi-database: satu untuk pusat (`lsms_pusat`) dan database terpisah untuk setiap cabang (misalnya, `lsms_cabang_jakarta`).
    *   Buat database yang diperlukan melalui phpMyAdmin atau klien MySQL pilihan Anda.
    *   Impor file skema `.sql` untuk database pusat dan cabang. *(Catatan: File skema harus disediakan secara terpisah)*.

4.  **Konfigurasi:**
    *   **Database:** Buka `config/database.php` dan perbarui kredensial koneksi (`host`, `user`, `pass`) serta nama database agar sesuai dengan pengaturan lokal Anda. Pastikan untuk mendefinisikan koneksi untuk `pusat` dan semua `cabang`.
    *   **Base URL:** `BASE_URL` dalam `config/app.php` dideteksi secara otomatis. Pastikan nama folder proyek Anda adalah `Laptop_Service_Management_System` atau perbarui path di file tersebut jika Anda menggunakan nama yang berbeda.

5.  **Akses Aplikasi:**
    *   Jalankan layanan Apache dan MySQL dari panel kontrol XAMPP.
    *   Buka browser web Anda dan arahkan ke `http://localhost/Laptop_Service_Management_System/auth/login.php`.

## Penggunaan

*   Masuk menggunakan kredensial pengguna yang sudah ada di database Anda.
*   Sistem akan mengarahkan Anda ke dasbor yang sesuai berdasarkan peran pengguna Anda.
*   Gunakan menu di sidebar untuk menavigasi antar fitur yang tersedia.

---
*Dokumen ini dibuat secara otomatis berdasarkan analisis kode sumber yang ada.*

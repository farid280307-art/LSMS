<?php

// Deteksi protokol (http/https)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";

// Tentukan BASE_URL otomatis berdasarkan server host
// Sesuaikan folder project jika berbeda: /Laptop_Service_Management_System/
define('BASE_URL', $protocol . "://" . $_SERVER['HTTP_HOST'] . "/Laptop_Service_Management_System/");

define('APP_NAME', 'LSMS - Laptop Service');

// Set timezone Indonesia Barat
date_default_timezone_set('Asia/Jakarta');
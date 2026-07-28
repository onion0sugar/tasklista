<?php
date_default_timezone_set('Europe/Warsaw');

define('DB_HOST', 'localhost');
define('DB_NAME', 'tasklist');
define('DB_USER', 'tasklist_user');
define('DB_PASS', 'zmien_haslo_db');
define('APP_URL', 'http://192.168.24.90/tasklist');

define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'zmien_haslo_admina');

define('MANAGER_USER', 'kierownik');
define('MANAGER_PASS', 'zmien_haslo_kierownika');

// SMTP – konfiguracja własnej skrzynki
define('SMTP_HOST',       'smtp.example.com');   // np. smtp.gmail.com / smtp.o2.pl
define('SMTP_PORT',       587);                  // 587 (TLS) lub 465 (SSL)
define('SMTP_ENCRYPTION', 'tls');                // 'tls' lub 'ssl'
define('SMTP_USER',       'raport@example.com'); // login do skrzynki
define('SMTP_PASS',       'haslo_skrzynki');     // hasło do skrzynki
define('SMTP_FROM_NAME',  'System Zadań');       // nazwa nadawcy
define('REPORT_TO',       'odbiorca@example.com'); // adres(y) docelowy raportu — wiele rozdziel przecinkami

function checkAutoLogin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin']) && empty($_SESSION['manager']) && isset($_COOKIE['remember_auth'])) {
        $parts = explode(':', $_COOKIE['remember_auth'], 2);
        if (count($parts) === 2) {
            [$role, $hash] = $parts;
            if ($role === 'admin') {
                $expected = hash('sha256', 'admin:' . ADMIN_USER . ':' . ADMIN_PASS);
                if (hash_equals($expected, $hash)) {
                    $_SESSION['admin'] = true;
                }
            } elseif ($role === 'manager') {
                $expected = hash('sha256', 'manager:' . MANAGER_USER . ':' . MANAGER_PASS);
                if (hash_equals($expected, $hash)) {
                    $_SESSION['manager'] = true;
                }
            }
        }
    }
}

function requireLogin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    checkAutoLogin();
    if (empty($_SESSION['admin'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireManager(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    checkAutoLogin();
    if (empty($_SESSION['admin']) && empty($_SESSION['manager'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function getSetting(string $key, ?string $default = null): ?string {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting(string $key, ?string $value): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = :value");
    $stmt->execute([':key' => $key, ':value' => $value]);
}

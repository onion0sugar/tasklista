<?php
// Fresh install only — run once, then delete this file.
require 'config.php';

$db = getDB();

// --- Lokalizacje ---
$db->exec("
CREATE TABLE IF NOT EXISTS locations (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Pracownicy ---
$db->exec("
CREATE TABLE IF NOT EXISTS employees (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Zadania (z location_id) ---
$db->exec("
CREATE TABLE IF NOT EXISTS tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    location_id INT DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Dzienne statusy zadań ---
$db->exec("
CREATE TABLE IF NOT EXISTS daily_tasks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    task_id    INT NOT NULL,
    date       DATE NOT NULL,
    status     TINYINT DEFAULT 0,
    scanned_by VARCHAR(255) DEFAULT NULL,
    scanned_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_task_date (task_id, date),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Logi ---
$db->exec("
CREATE TABLE IF NOT EXISTS logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    task_id    INT NOT NULL,
    task_name  VARCHAR(255) NOT NULL,
    action     VARCHAR(30) NOT NULL DEFAULT 'completed',
    scanned_by VARCHAR(255) DEFAULT NULL,
    date       DATE NOT NULL,
    logged_at  DATETIME NOT NULL,
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Ustawienia ---
$db->exec("
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(255) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

echo "Tabele utworzone (locations, employees, tasks, daily_tasks, logs, settings). Usuń plik setup.php z serwera.";

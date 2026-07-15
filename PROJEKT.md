# System Zadań QR — Dokumentacja Projektu

> **Cel:** System do codziennego zarządzania listą zadań z obsługą kodów QR, skanowania przez aparat w telefonie, dwoma poziomami uprawnień (administrator/kierownik) oraz automatycznymi raportami e-mail.

---

## 1. Struktura plików

```
tasklist/
├── config.php          (3539 B)  — Centralna konfiguracja (baza danych, SMTP, użytkownicy, helpers)
├── setup.php           (2297 B)  — Instalator bazy danych — uruchomić RAZ, potem usunąć!
├── login.php           (3183 B)  — Strona logowania (admin i kierownik)
├── logout.php          (176 B)   — Wylogowanie + czyszczenie cookie
├── index.php           (12897 B) — Panel administratora: lista zadań, filtr lokalizacji, modal QR
├── admin.php           (24353 B) — Panel zarządzania: zadania (CRUD + drag&drop), lokalizacje, pracownicy, ustawienia
├── manager.php         (7897 B)  — Panel kierownika: podgląd tylko-do-odczytu z filtrem lokalizacji
├── scan.php            (20143 B) — Skaner QR: aparat (html5-qrcode), wybór pracownika, potwierdzenie, blokada czasowa
├── print.php           (2030 B)  — Druk kodów QR (pojedynczo lub wszystkie)
├── logs.php            (7216 B)  — Logi systemowe z wyborem daty (scrollowana oś czasu)
├── report.php          (7195 B)  — Raport e-mail przez SMTP (PHPMailer) — uruchamiany przez cron
├── cron_reset.php      (719 B)   — Reset dzienny statusów zadań — uruchamiany przez cron
├── README.md                      — Pełna instrukcja instalacji (istniejąca)
├── INSTALACJA.md                  — Krótka instrukcja instalacji
├── PROJEKT.md                     — Niniejsza dokumentacja projektu (do użycia w kolejnych sesjach)
└── vendor/                        — Zależności PHP (PHPMailer przez Composer)
```

---

## 2. Baza danych — struktura tabel

### `locations`
| Kolumna | Typ | Opis |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | ID lokalizacji |
| `name` | VARCHAR(255) NOT NULL | Nazwa lokalizacji (np. "Piętro 1") |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Data utworzenia |

### `employees`
| Kolumna | Typ | Opis |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | ID pracownika |
| `name` | VARCHAR(255) NOT NULL | Imię i nazwisko |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Data utworzenia |

### `tasks`
| Kolumna | Typ | Opis |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | ID zadania |
| `name` | VARCHAR(255) NOT NULL | Nazwa zadania |
| `location_id` | INT DEFAULT NULL FK → locations(id) ON DELETE SET NULL | Przypisana lokalizacja |
| `sort_order` | INT NOT NULL DEFAULT 0 | Kolejność wyświetlania (drag&drop) |
| `active` | TINYINT DEFAULT 1 | Czy aktywne (1=aktywne, 0=nieaktywne) |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Data utworzenia |

### `daily_tasks`
| Kolumna | Typ | Opis |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | ID |
| `task_id` | INT NOT NULL FK → tasks(id) ON DELETE CASCADE | ID zadania |
| `date` | DATE NOT NULL | Data |
| `status` | TINYINT DEFAULT 0 | 0=oczekuje, 1=wykonane |
| `scanned_by` | VARCHAR(255) DEFAULT NULL | Kto wykonał (nazwa pracownika) |
| `scanned_at` | DATETIME DEFAULT NULL | Kiedy wykonano |
| **UNIQUE KEY** | `uq_task_date` (task_id, date) | Jeden wpis na zadanie/dzień |

### `logs`
| Kolumna | Typ | Opis |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | ID |
| `task_id` | INT NOT NULL | ID zadania (0 = zdarzenie systemowe) |
| `task_name` | VARCHAR(255) NOT NULL | Nazwa obiektu zdarzenia |
| `action` | VARCHAR(30) NOT NULL DEFAULT 'completed' | Rodzaj akcji (patrz lista poniżej) |
| `scanned_by` | VARCHAR(255) DEFAULT NULL | Kto wykonał |
| `date` | DATE NOT NULL | Data zdarzenia |
| `logged_at` | DATETIME NOT NULL | Dokładny czas |
| **INDEX** | `idx_date` (date) | Indeks po dacie |

### `settings`
| Kolumna | Typ | Opis |
|---|---|---|
| `setting_key` | VARCHAR(255) PK | Klucz ustawienia |
| `setting_value` | TEXT NULL | Wartość ustawienia |

---

## 3. Typy logów (`action`)

| Wartość `action` | Opis | Kolor (logs.php) |
|---|---|---|
| `completed` | Zadanie wykonane (scan) | #059669 / #d1fae5 |
| `repeat` | Ponowna próba | #d97706 / #fef3c7 |
| `created` | Zadanie utworzone | #2563eb / #dbeafe |
| `renamed` | Zadanie edytowane | #7c3aed / #ede9fe |
| `deleted` | Zadanie usunięte | #dc2626 / #fee2e2 |
| `activated` | Zadanie aktywowane | #2563eb / #dbeafe |
| `deactivated` | Zadanie dezaktywowane | #4b5563 / #f3f4f6 |
| `loc_created` | Nowa lokalizacja | #0891b2 / #ecfeff |
| `loc_deleted` | Usunięta lokalizacja | #4b5563 / #f3f4f6 |
| `emp_created` | Nowy pracownik | #0891b2 / #ecfeff |
| `emp_deleted` | Usunięty pracownik | #4b5563 / #f3f4f6 |
| `reset` | Reset dzienny | #475569 / #f1f5f9 |
| `report_sent` | Raport wysłany | #0891b2 / #ecfeff |
| `report_failed` | Raport — błąd | #dc2626 / #fee2e2 |
| `report_skipped` | Raport pominięty (wszystkie wykonane) | #475569 / #f1f5f9 |

---

## 4. Konfiguracja (`config.php`) — stałe i funkcje pomocnicze

### Stałe konfiguracyjne
| Stała | Opis |
|---|---|
| `DB_HOST` | Host bazy MySQL (domyślnie `localhost`) |
| `DB_NAME` | Nazwa bazy (domyślnie `tasklist`) |
| `DB_USER` | Użytkownik bazy (domyślnie `tasklist_user`) |
| `DB_PASS` | Hasło bazy |
| `APP_URL` | Pełny URL instalacji (np. `http://192.168.24.90/tasklist`) |
| `ADMIN_USER` | Login administratora |
| `ADMIN_PASS` | Hasło administratora |
| `MANAGER_USER` | Login kierownika |
| `MANAGER_PASS` | Hasło kierownika |
| `SMTP_HOST` | Serwer SMTP |
| `SMTP_PORT` | Port SMTP (587 dla TLS) |
| `SMTP_ENCRYPTION` | Szyfrowanie (`tls` lub `ssl`) |
| `SMTP_USER` | Login SMTP |
| `SMTP_PASS` | Hasło SMTP |
| `SMTP_FROM_NAME` | Nazwa nadawcy raportu |
| `REPORT_TO` | Adres(y) odbiorców raportu (przecinek = wiele) |

### Funkcje pomocnicze
| Funkcja | Lokalizacja | Opis |
|---|---|---|
| `checkAutoLogin()` | config.php:31 | Automatyczne logowanie przez cookie `remember_auth` |
| `requireLogin()` | config.php:52 | Wymaga sesji admina — redirect do login.php |
| `requireManager()` | config.php:60 | Wymaga sesji admina LUB kierownika |
| `getDB()` | config.php:68 | Zwraca singleton PDO z utf8mb4 i trybem wyjątków |
| `getSetting(key, default)` | config.php:83 | Pobiera wartość z tabeli `settings` |
| `setSetting(key, value)` | config.php:93 | Zapisuje/aktualizuje w tabeli `settings` |

---

## 5. Przepływ dostępu (autoryzacja)

```
login.php
  ├── POST username=admin + password=ADMIN_PASS
  │     → $_SESSION['admin'] = true
  │     → header('Location: index.php')
  │
  ├── POST username=kierownik + password=MANAGER_PASS
  │     → $_SESSION['manager'] = true
  │     → header('Location: manager.php')
  │
  └── Remember me (opcjonalnie)
        → cookie 'remember_auth' = 'role:sha256(role:user:pass)' — ważny 30 dni


index.php          → requireLogin()  → tylko admin
admin.php          → requireLogin()  → tylko admin
manager.php        → requireManager() → admin LUB kierownik
logs.php           → requireManager() → admin LUB kierownik
scan.php           → brak wymaganego logowania (publiczny — dostępny przez QR)
print.php          → requireLogin()  → tylko admin
report.php         → brak (uruchamiany z crona CLI)
cron_reset.php     → brak (uruchamiany z crona CLI)
```

---

## 6. Główne funkcjonalności

### 6.1. Panel administratora (`admin.php`)
- **Zakładki**: Zadania, Lokalizacje, Pracownicy, Ustawienia
- **CRUD zadań**: dodawanie, edycja (modal — nazwa + lokalizacja), dezaktywacja/aktywacja, usuwanie — z przypisaniem lokalizacji
- **Drag & drop kolejności**: przeciąganie wierszy, zapis przez AJAX (JSON → `admin.php?action=reorder`)
- **CRUD lokalizacji**: dodawanie/usuwanie
- **CRUD pracowników**: dodawanie/usuwanie
- **Ustawienia**: minimalna godzina skanowania dla każdego dnia tygodnia (zapisywane w tabeli `settings` jako `min_scan_hour_1`..`min_scan_hour_7`)
- **Zapamiętanie aktywnej zakładki**: localStorage `admin_active_tab`

### 6.2. Panel główny admina (`index.php`)
- Lista zadań na dziś z kartami (stan: wykonane/oczekuje)
- Pasek postępu (procent wykonania)
- Filtr według lokalizacji (select → GET `location_id`)
- Modal QR: każde zadanie ma przycisk → pokazuje QR + kopiowanie linku + wydruk PDF
- QR generowany przez zewnętrzne API: `https://api.qrserver.com/v1/create-qr-code/`

### 6.3. Panel kierownika (`manager.php`)
- To samo co `index.php` BEZ przycisków QR i bez linku do `admin.php`
- Tylko podgląd + filtr lokalizacji

### 6.4. Skaner QR (`scan.php`) — najważniejsza logika
- **Tryb ogólny** (bez `task_id`): pole tekstowe + przycisk aparatu
- **Tryb zadania** (`?task_id=N`):
  1. Walidacja: czy zadanie istnieje i jest aktywne → 404 jeśli nie
  2. Blokada czasowa: sprawdza `min_scan_hour_N` dla dnia tygodnia → blokada przed tą godziną
  3. Zapewnia wiersz w `daily_tasks` (INSERT IGNORE)
  4. Jeśli już wykonane → ekran "Wykonane!" + lista pozostałych w tej lokalizacji
  5. Formularz wyboru pracownika (select z `employees`)
  6. Opcja "Zapamiętaj na 8h" → cookie `remembered_employee`
  7. **Auto-potomwierdzenie**: jeśli cookie `remembered_employee` istnieje i brak blokady → potwierdza OD RAZU bez formularza
  8. Po potwierdzeniu: `UPDATE daily_tasks SET status=1, scanned_by, scanned_at` + INSERT do `logs`
  9. Pokazuje pozostałe zadania w tej samej lokalizacji (`remainingTasks`)
- **Aparat**: biblioteka `html5-qrcode` z unpkg.com, `facingMode: "environment"` (tylny aparat)
- **Parsowanie QR**: akceptuje samo ID (liczba) lub pełny URL z `?task_id=N`

### 6.5. Wydruk QR (`print.php`)
- Generuje siatkę kodów QR dla wszystkich aktywnych zadań (lub jednego, jeśli `?task_id=N`)
- Przycisk `window.print()` → drukarka / "Zapisz jako PDF"

### 6.6. Logi (`logs.php`)
- Scrollowana oś czasu z datami (14 ostatnich dni z danymi)
- Kolorowe badge dla każdego typu akcji
- Wybór daty → filtrowanie logów

### 6.7. Raport e-mail (`report.php`)
- Uruchamiany przez cron
- Grupuje zadania według lokalizacji (wykonane + niewykonane)
- Jeśli WSZYSTKO wykonane → `report_skipped`, nie wysyła maila
- Wysyła przez PHPMailer (SMTP) z tabelami HTML

### 6.8. Reset cron (`cron_reset.php`)
- INSERT IGNORE do `daily_tasks` dla dziś (zapewnia wpisy)
- UPDATE `status = 0` dla wszystkich dzisiejszych
- Loguje `reset` do tabeli `logs`

---

## 7. Zależności zewnętrzne

| Zasób | Typ | Używany w | Uwagi |
|---|---|---|---|
| `https://api.qrserver.com/v1/create-qr-code/` | REST API | `index.php`, `print.php` | Generuje obrazy QR — **wymaga Internetu** |
| `https://unpkg.com/html5-qrcode` | JS CDN | `scan.php` | Skaner QR w przeglądarce — **wymaga Internetu** |
| `phpmailer/phpmailer` (Composer) | PHP lib | `report.php` | Wysyłka e-mail przez SMTP |
| `vendor/autoload.php` | Composer | `report.php` | Autoloader PHPMailer |

---

## 8. Cron (automatyczne zadania)

```cron
# Reset dzienny o 6:00
0 6 * * * php /var/www/html/tasklist/cron_reset.php

# Raport e-mail o 15:00 (lub 23:00)
0 15 * * * php /var/www/html/tasklist/report.php
```

---

## 9. Kluczowe stałe w kodzie (szukane przez search_content)

| Symbol | Występuje w |
|---|---|
| `requireLogin()` | `admin.php`, `index.php`, `print.php` |
| `requireManager()` | `manager.php`, `logs.php` |
| `getDB()` | wszystkie pliki |
| `getSetting()` | `admin.php`, `scan.php` |
| `setSetting()` | `admin.php` |
| `edit` (action) | `admin.php` | POST — edycja nazwy i lokalizacji zadania (akcja 'renamed' w logach) |
| `checkAutoLogin()` | `config.php`, `login.php` |
| `APP_URL` | `config.php`, `index.php`, `print.php`, `scan.php` |
| `remembered_employee` (cookie) | `scan.php` |
| `remember_auth` (cookie) | `config.php`, `login.php`, `logout.php` |
| `min_scan_hour_` | `admin.php`, `scan.php` |
| `daily_tasks` | `index.php`, `manager.php`, `scan.php`, `cron_reset.php` |

---

## 10. Jak dodawać nowe funkcje

1. **Nowy plik PHP**: dodaj wpis w sekcji 1 (struktura plików) w `PROJEKT.md`
2. **Nowa tabela SQL**: dodaj schemat w sekcji 2, zaktualizuj `setup.php`
3. **Nowy typ logu**: dodaj wpis w sekcji 3 + w tablicy `$labels` w `logs.php`
4. **Nowa stała konfiguracyjna**: dodaj w sekcji 4 + w `config.php`
5. **Nowa funkcja pomocnicza**: dodaj w sekcji 4 tabeli funkcji
6. **Nowa zależność**: dodaj w sekcji 7
7. **Nowy cron**: dodaj w sekcji 8

---

## 11. Wymagania systemowe (skrót)

- PHP 7.4+ (zalecane 8.0+)
- Rozszerzenia: `pdo_mysql`, `mysqli`, `pdo`, `mbstring`, `json`, `session`, `hash`, `date`
- MySQL 5.7+ / MariaDB 10.2+
- Composer (do PHPMailer)
- Serwer WWW: Apache / Nginx
- Internet (QR server API + html5-qrcode CDN)

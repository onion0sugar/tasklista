# System Zadań QR – Instrukcja Instalacji & Dokumentacja Krok po Kroku

Lekki, nowoczesny i responsywny system do zarządzania codziennymi zadaniami z wykorzystaniem kodów QR. Każde zadanie jest przypisane do opcjonalnej lokalizacji. Zeskanowanie kodu QR smartfonem przenosi pracownika na dedykowaną stronę, gdzie wybiera on swoje nazwisko i zatwierdza wykonanie zadania. 

Idealne rozwiązanie dla magazynów, biur, obiektów usługowych, gastronomii, hoteli oraz wszędzie tam, gdzie ważna jest fizyczna obecność i kontrola realizacji procedur porządkowych lub serwisowych.

---

## Spis treści
1. [Funkcje systemu](#1-funkcje-systemu)
2. [Wymagania systemowe](#2-wymagania-systemowe)
3. [Instalacja krok po kroku na serwerze Linux (Ubuntu/Debian)](#3-instalacja-krok-po-kroku-na-serwerze-linux-ubuntudebian)
   - [Krok 1: Instalacja Apache, PHP, MariaDB](#krok-1-instalacja-apache-php-mariadb)
   - [Krok 2: Instalacja i konfiguracja Composer](#krok-2-instalacja-i-konfiguracja-composer)
   - [Krok 3: Tworzenie bazy danych](#krok-3-tworzenie-bazy-danych)
   - [Krok 4: Wdrożenie plików projektu i uprawnienia](#krok-4-wdrożenie-plików-projektu-i-uprawnienia)
   - [Krok 5: Instalacja zależności PHPMailer](#krok-5-instalacja-zależności-phpmailer)
   - [Krok 6: Konfiguracja pliku config.php](#krok-6-konfiguracja-pliku-configphp)
   - [Krok 7: Inicjalizacja bazy (setup.php)](#krok-7-inicjalizacja-bazy-setupphp)
   - [Krok 8: Konfiguracja zadań Cron (raporty i resety)](#krok-8-konfiguracja-zadań-cron-raporty-i-resety)
   - [Krok 9: Porządki poinstalacyjne](#krok-9-porządki-poinstalacyjne)
4. [Struktura plików projektu](#4-struktura-plików-projektu)
5. [Logowanie i dostęp](#5-logowanie-i-dostęp)
6. [Rozwiązywanie problemów (Q&A)](#6-rozwiązywanie-problemów-qa)

---

## 1. Funkcje systemu

- **Dwa poziomy uprawnień**:
  - **Administrator**: Zarządzanie zadaniami (dodawanie/dezaktywacja/usuwanie wraz z przypisywaniem do lokalizacji), bazą pracowników oraz bazą lokalizacji. Podgląd logów i wydruk PDF pojedynczych oraz wszystkich kodów QR.
  - **Kierownik**: Czysty panel bez kodów QR i konfiguracji. Dostęp do statusu wykonania na żywo wraz z dropdownem filtrującym po lokalizacji. Podgląd wykonawców i godzin skanów.
- **Szybki skan (mobile-first)**: Pracownicy nie muszą zakładać kont ani się logować. Skanują kod QR aparatem, wybierają pracownika, opcjonalnie zapamiętują go w ciasteczku (na czas zmiany 8h) i jednym kliknięciem zatwierdzają wykonanie.
- **Inteligentny proces skanowania**: Po zeskanowaniu i potwierdzeniu pracownik widzi na ekranie telefonu, jakie jeszcze inne zadania w tej samej lokalizacji pozostały dzisiaj do wykonania.
- **Codzienny raport e-mail (PHPMailer)**: Automatycznie wysyłany raport podsumowujący. Zadania są podzielone na **Wykonane** i **Niewykonane**, a wewnątrz nich dodatkowo **pogrupowane według lokalizacji**. Przy każdym zrealizowanym zadaniu widnieje nazwisko oraz dokładna godzina (np. `Kasia • 14:32`).
- **Logi systemowe**: Zapisywanie informacji o dodaniu/usunięciu zadania, o wysłanych raportach oraz o każdym wykonanym skanie.

---

## 2. Wymagania systemowe

- **System operacyjny**: Linux (rekomendowany Ubuntu 20.04 / 22.04 LTS lub Debian 11 / 12)
- **Serwer WWW**: Apache 2.4+ z włączonym modułem `rewrite`
- **Język skryptowy**: PHP 7.4+ (rekomendowane PHP 8.1+) z rozszerzeniami:
  - `php-mysql`
  - `php-mbstring`
- **Baza danych**: MariaDB 10.4+ lub MySQL 8.0+
- **Menedżer pakietów**: Composer (do pobrania PHPMailer)

---

## 3. Instalacja krok po kroku na serwerze Linux (Ubuntu/Debian)

### Krok 1: Instalacja Apache, PHP, MariaDB

Połącz się ze swoim serwerem przez SSH i wykonaj następujące polecenia:

```bash
# Aktualizacja repozytoriów i systemu
sudo apt update && sudo apt upgrade -y

# Instalacja serwera Apache
sudo apt install apache2 -y

# Instalacja PHP i wymaganych modułów
sudo apt install php libapache2-mod-php php-mysql php-mbstring php-curl -y

# Instalacja bazy danych MariaDB
sudo apt install mariadb-server -y

# Zabezpieczenie instalacji bazy danych (ustaw silne hasło root, odrzuć zdalne logowania itp.)
sudo mysql_secure_installation
```

Sprawdź, czy usługi działają poprawnie:
```bash
sudo systemctl status apache2
sudo systemctl status mariadb
php -v
```

---

### Krok 2: Instalacja i konfiguracja Composer

Composer jest wymagany do zainstalowania modułu PHPMailer odpowiedzialnego za wysyłanie raportów dziennych przez SMTP.

```bash
# Pobranie instalatora i instalacja globalna
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Nadanie uprawnień do uruchamiania
sudo chmod +x /usr/local/bin/composer

# Weryfikacja instalacji
composer --version
```

---

### Krok 3: Tworzenie bazy danych

Zaloguj się do powłoki bazy danych MariaDB jako root:
```bash
sudo mysql -u root -p
```

Wklej poniższy zestaw zapytań (zamień `SILNE_HASLO_BAZY` na własne hasło):
```sql
-- Utworzenie bazy danych z obsługą polskich znaków i emoji
CREATE DATABASE tasklist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Utworzenie użytkownika bazy danych
CREATE USER 'tasklist_user'@'localhost' IDENTIFIED BY 'SILNE_HASLO_BAZY';

-- Nadanie pełnych uprawnień lokalnych do nowej bazy
GRANT ALL PRIVILEGES ON tasklist.* TO 'tasklist_user'@'localhost';

-- Przeładowanie uprawnień
FLUSH PRIVILEGES;

-- Wyjście z konsoli
EXIT;
```

---

### Krok 4: Wdrożenie plików projektu i uprawnienia

```bash
# Utworzenie głównego katalogu aplikacji w katalogu Apache
sudo mkdir -p /var/www/html/tasklist

# Nadanie uprawnień wstępnych użytkownikowi Apache (www-data)
sudo chown -R www-data:www-data /var/www/html/tasklist
sudo chmod -R 755 /var/www/html/tasklist
```

Prześlij (np. przez SFTP/SCP lub git clone) wszystkie pliki projektu bezpośrednio do `/var/www/html/tasklist`.

---

### Krok 5: Instalacja zależności PHPMailer

Wejdź do katalogu aplikacji i zainstaluj zależności za pomocą Composera:
```bash
cd /var/www/html/tasklist
sudo -u www-data composer require phpmailer/phpmailer
```
Moduł PHPMailer zostanie zainstalowany w katalogu `/var/www/html/tasklist/vendor/`.

---

### Krok 6: Konfiguracja pliku `config.php`

Otwórz plik konfiguracyjny do edycji:
```bash
sudo nano /var/www/html/tasklist/config.php
```

Skonfiguruj odpowiednie stałe zgodnie z Twoją infrastrukturą:
```php
<?php
// Połączenie z bazą danych
define('DB_HOST', 'localhost');
define('DB_NAME', 'tasklist');
define('DB_USER', 'tasklist_user');
define('DB_PASS', 'SILNE_HASLO_BAZY'); // Wpisz hasło z Kroku 3

// Adres URL aplikacji (bez końcowego slasha)
define('APP_URL', 'http://TOWJ_ADRES_IP/tasklist'); 

// Dane logowania dla Administratora
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'zmien_haslo_admina'); // Zmień na własne bezpieczne hasło!

// Dane logowania dla Kierownika
define('MANAGER_USER', 'kierownik');
define('MANAGER_PASS', 'zmien_haslo_kierownika'); // Zmień na własne bezpieczne hasło!

// Konfiguracja serwera pocztowego SMTP do wysyłania codziennych raportów
define('SMTP_HOST',       'smtp.example.com');     // Serwer SMTP poczty
define('SMTP_PORT',       587);                    // Port SMTP (np. 587 dla TLS lub 465 dla SSL)
define('SMTP_ENCRYPTION', 'tls');                  // Sposób szyfrowania: 'tls' lub 'ssl'
define('SMTP_USER',       'raporty@example.com');  // Adres e-mail nadawcy
define('SMTP_PASS',       'haslo_skrzynki_smtp');  // Hasło lub token aplikacji
define('SMTP_FROM_NAME',  'System Zadań QR');      // Nazwa nadawcy maila
define('REPORT_TO',       'dyrekcja@example.com'); // Adres(y) odbiorców raportów (rozdziel przecinkami)
```

---

### Krok 7: Inicjalizacja bazy (`setup.php`)

Uruchom przeglądarkę i przejdź pod adres URL instalatora tabel bazy danych:
```
http://TWOJ_ADRES_SERWERA/tasklist/setup.php
```
Zostaną automatycznie utworzone tabele bazy danych: `locations`, `employees`, `tasks`, `daily_tasks` oraz `logs`. Na ekranie zobaczysz komunikat potwierdzający poprawną instalację.

---

### Krok 8: Konfiguracja zadań Cron (raporty i resety)

Aby system automatycznie resetował statusy zadań każdego dnia i wysyłał raporty mailowe o zadanej godzinie, musimy skonfigurować harmonogram zadań cron dla użytkownika `www-data`:

```bash
sudo crontab -u www-data -e
```

Wybierz jedną z poniższych opcji logowania działania skryptów:

#### Opcja A: Zapisywanie logów do plików w katalogu aplikacji (zalecane do diagnostyki)
Wklej na końcu pliku crontab poniższe wiersze:
```cron
# 1. Reset zadań każdej nocy o godzinie 00:01
1 0 * * * php /var/www/html/tasklist/cron_reset.php >> /var/www/html/tasklist/cron_reset.log 2>&1

# 2. Wysyłka dziennego raportu e-mail o godzinie 23:00
0 23 * * * php /var/www/html/tasklist/report.php >> /var/www/html/tasklist/cron_report.log 2>&1
```

**Ważne (Uprawnienia do plików logów):**
Aby cron (działający jako użytkownik `www-data`) mógł automatycznie utworzyć pliki logów i w nich zapisywać, cały katalog projektu musi należeć do `www-data`. Nadaj odpowiednie uprawnienia poniższymi poleceniami:
```bash
sudo chown -R www-data:www-data /var/www/html/tasklist
sudo chmod -R 775 /var/www/html/tasklist
```


---

#### Opcja B: Logowanie wyłącznie do bazy danych (brak tworzenia plików logów)
Oba skrypty automatycznie zapisują najważniejsze statusy i logi w bazie danych (tabeli `logs`). Jeśli wolisz nie generować plików tekstowych na serwerze, wklej w crontabie:
```cron
# 1. Reset zadań każdej nocy o godzinie 00:01
1 0 * * * php /var/www/html/tasklist/cron_reset.php > /dev/null 2>&1

# 2. Wysyłka dziennego raportu e-mail o godzinie 23:00
0 23 * * * php /var/www/html/tasklist/report.php > /dev/null 2>&1
```

*Wskazówka*: Jeśli chcesz, aby raport był generowany rano za dzień poprzedni (np. o 07:00), zmień wpis crona na `0 7 * * *` i w pliku `report.php` zastąp linię `$date = date('Y-m-d');` na `$date = date('Y-m-d', strtotime('yesterday'));`.

---

### Krok 9: Porządki poinstalacyjne

Ze względów bezpieczeństwa należy bezwzględnie usunąć pliki konfiguracyjne instalacji bazy danych, aby nikt nieupoważniony nie nadpisał bazy danych:

```bash
sudo rm /var/www/html/tasklist/setup.php
sudo rm -f /var/www/html/tasklist/migrate.php
```

---

## 4. Struktura plików projektu

Po wdrożeniu struktura katalogów w `/var/www/html/tasklist/` powinna wyglądać następująco:

```
tasklist/
├── config.php          # Centralny plik konfiguracyjny (baza, SMTP, hasła)
├── login.php           # Strona logowania do paneli
├── logout.php          # Obsługa wylogowania użytkownika
├── index.php           # Tablica główna administratora (zarządzanie widokiem, kody QR)
├── admin.php           # Panel konfiguracyjny admina (Zadania, Lokalizacje, Pracownicy)
├── manager.php         # Panel Kierownika (status zadań na żywo, filtry)
├── scan.php            # Strona skanu kodu QR (potwierdzanie wykonania przez pracownika)
├── print.php           # Generowanie / wydruk PDF z kodami QR
├── report.php          # Skrypt wysyłki raportu e-mail (uruchamiany przez Cron)
├── cron_reset.php      # Skrypt resetu statusów na kolejny dzień (uruchamiany przez Cron)
├── .htaccess           # Zabezpieczenie dostępu i konfiguracja przekierowań Apache
├── README.md           # Ta instrukcja i opis techniczny
├── INSTALACJA.md       # Instrukcja instalacji
└── vendor/             # Folder zależności bibliotek zewnętrznych (wygenerowany przez Composer)
```

---

## 5. Logowanie i dostęp

| Rola użytkownika | Adres logowania | Domyślne dane |
|------------------|-----------------|---------------|
| **Administrator** | `http://TWOJ_ADRES_IP/tasklist/login.php` | login: `admin` <br>hasło: `zmien_haslo_admina` |
| **Kierownik** | `http://TWOJ_ADRES_IP/tasklist/login.php` | login: `kierownik` <br>hasło: `zmien_haslo_kierownika` |

*Po pomyślnym zalogowaniu administrator zostaje przekierowany do widoku `index.php`, a kierownik do widoku `manager.php`*.

---

## 6. Rozwiązywanie problemów (Q&A)

#### 1. Brak wysyłki maili (błąd SMTP w logach)
- Sprawdź, czy dane podane w `config.php` (login, hasło, serwer SMTP i port) są prawidłowe.
- Upewnij się, czy Twój serwer nie blokuje ruchu wychodzącego na portach 587 i 465.
- Jeśli używasz Gmaila, upewnij się, że wygenerowałeś specjalne **Hasło do Aplikacji** (ustawienia konta Google -> Bezpieczeństwo) zamiast podawać domyślne hasło konta.

#### 2. Kody QR się nie ładują
- System korzysta z darmowego i szybkiego API zewnętrznego: `https://api.qrserver.com`. Upewnij się, że Twój serwer ma łączność internetową, a Twoje urządzenie / komputer ma dostęp do sieci.

#### 3. Błąd `Permission Denied` przy instalacji composera lub tworzeniu logów
- Upewnij się, że pliki i foldery należą do użytkownika serwera www (`www-data`). Zastosuj komendę:
  `sudo chown -R www-data:www-data /var/www/html/tasklist`

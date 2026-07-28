<?php
require 'config.php';

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
$today  = date('Y-m-d');
$db     = getDB();

$task = null;
$row = null;
$alreadyDone = false;
$employees = [];
$rememberedEmpId = 0;
$confirmed = false;
$confirmedBy = '';
$confirmedAt = '';
$error = '';
$remainingTasks = [];

if ($taskId > 0) {
    // --- Walidacja zadania ---
    $stmt = $db->prepare("SELECT id, name, location_id FROM tasks WHERE id = :id AND active = 1");
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        http_response_code(404);
        die('Zadanie nie istnieje lub jest nieaktywne.');
    }

    // --- Sprawdzenie limitu czasu (dzień tygodnia) ---
    $dayOfWeek = date('N'); // 1 (pon) - 7 (nie)
    $minHour = getSetting("min_scan_hour_$dayOfWeek");
    $isTimeBlocked = false;
    $errorTimeBlock = '';

    if ($minHour) {
        $currentTime = date('H:i');
        if ($currentTime < $minHour) {
            $isTimeBlocked = true;
            $daysPol = [
                1 => 'poniedziałek',
                2 => 'wtorek',
                3 => 'środę',
                4 => 'czwartek',
                5 => 'piątek',
                6 => 'sobotę',
                7 => 'niedzielę'
            ];
            $errorTimeBlock = "Skanowanie w " . $daysPol[$dayOfWeek] . " jest zablokowane do godziny " . $minHour . ". (Obecna godzina: " . $currentTime . ")";
        }
    }

    // Zapewnij wiersz w daily_tasks
    $db->prepare("INSERT IGNORE INTO daily_tasks (task_id, date, status) VALUES (:tid, :date, 0)")
       ->execute([':tid' => $taskId, ':date' => $today]);

    // Pobierz aktualny status
    $stmt = $db->prepare("SELECT status, scanned_by, scanned_at FROM daily_tasks WHERE task_id = :tid AND date = :date");
    $stmt->execute([':tid' => $taskId, ':date' => $today]);
    $row = $stmt->fetch();
    $alreadyDone = $row && $row['status'] == 1;

    // Lista pracowników
    $employees = $db->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();

    // Zapamiętany pracownik z cookie
    $rememberedEmpId = isset($_COOKIE['remembered_employee']) ? (int)$_COOKIE['remembered_employee'] : 0;

    // --- AUTO-POTWIERDZENIE: jeśli pracownik zapamiętany w cookie i brak blokady czasowej, zatwierdź od razu ---
    if ($rememberedEmpId > 0 && !$alreadyDone && !$isTimeBlocked && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $empName = '';
        foreach ($employees as $e) {
            if ((int)$e['id'] === $rememberedEmpId) {
                $empName = $e['name'];
                break;
            }
        }
        if ($empName !== '') {
            $now = date('Y-m-d H:i:s');
            $db->prepare("
                UPDATE daily_tasks
                SET status = 1, scanned_by = :by, scanned_at = :at
                WHERE task_id = :tid AND date = :date
            ")->execute([':by' => $empName, ':at' => $now, ':tid' => $taskId, ':date' => $today]);

            $db->prepare("
                INSERT INTO logs (task_id, task_name, action, scanned_by, date, logged_at)
                VALUES (:tid, :name, 'completed', :by, :date, NOW())
            ")->execute([':tid' => $taskId, ':name' => $task['name'], ':by' => $empName, ':date' => $today]);

            $alreadyDone = true;
            $row = [
                'status' => 1,
                'scanned_by' => $empName,
                'scanned_at' => $now
            ];
        }
    }

    // --- Obsługa potwierdzenia ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyDone && !$isTimeBlocked) {
        $empId    = (int)($_POST['employee_id'] ?? 0);
        $remember = !empty($_POST['remember']);

        // Znajdź pracownika
        $empName = '';
        foreach ($employees as $e) {
            if ((int)$e['id'] === $empId) {
                $empName = $e['name'];
                break;
            }
        }

        if (!$empName) {
            $error = 'Proszę wybrać pracownika z listy.';
        } else {
            // Obsługa cookie "zapamiętaj mnie"
            if ($remember) {
                // Cookie ważne 8h (do końca zmiany)
                setcookie('remembered_employee', $empId, time() + 8 * 3600, '/');
                $rememberedEmpId = $empId;
            } else {
                setcookie('remembered_employee', '', time() - 3600, '/');
                $rememberedEmpId = 0;
            }

            $now = date('Y-m-d H:i:s');

            // Oznacz jako wykonane
            $db->prepare("
                UPDATE daily_tasks
                SET status = 1, scanned_by = :by, scanned_at = :at
                WHERE task_id = :tid AND date = :date
            ")->execute([':by' => $empName, ':at' => $now, ':tid' => $taskId, ':date' => $today]);

            // Zapisz log
            $db->prepare("
                INSERT INTO logs (task_id, task_name, action, scanned_by, date, logged_at)
                VALUES (:tid, :name, 'completed', :by, :date, NOW())
            ")->execute([':tid' => $taskId, ':name' => $task['name'], ':by' => $empName, ':date' => $today]);

            $confirmed   = true;
            $confirmedBy = $empName;
            $confirmedAt = date('H:i', strtotime($now));
            
            // Odśwież status daily_tasks, aby pasował do sekcji "Właśnie wykonane"
            $alreadyDone = true;
            $row = [
                'status' => 1,
                'scanned_by' => $empName,
                'scanned_at' => $now
            ];
        }
    }

    // Pobierz pozostałe zadania do wykonania w tej samej lokalizacji dzisiaj
    if (!empty($task['location_id'])) {
        $stmt = $db->prepare("
            SELECT t.id, t.name
            FROM tasks t
            LEFT JOIN daily_tasks dt ON dt.task_id = t.id AND dt.date = :date
            WHERE t.location_id = :loc_id 
              AND t.active = 1 
              AND t.id != :task_id 
              AND (dt.status IS NULL OR dt.status = 0)
            ORDER BY t.sort_order, t.name
        ");
        $stmt->execute([
            ':date' => $today,
            ':loc_id' => $task['location_id'],
            ':task_id' => $taskId
        ]);
        $remainingTasks = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $taskId > 0 ? 'Skan zadania' : 'Skaner Kodów QR' ?></title>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
<style>
  * { box-sizing: border-box; }
  body { 
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
    max-width: 420px; 
    margin: 0 auto; 
    padding: 40px 20px; 
    text-align: center; 
    background: #f8fafc; 
    color: #1e293b;
  }
  .icon      { font-size: 4em; margin-bottom: 16px; display: inline-block; }
  .ok        { color: #10b981; }
  .dup       { color: #64748b; }
  h2         { margin: 0 0 8px; font-size: 1.3em; color: #0f172a; font-weight: 700; }
  p          { color: #475569; margin: 0 0 8px; font-size: 0.95em; }
  .meta      { font-size: .9em; color: #64748b; margin-top: 12px; border-top: 1px dashed #e2e8f0; padding-top: 12px; }
  .card      { 
    background: #fff; 
    border: 1px solid #e2e8f0; 
    border-radius: 12px; 
    padding: 24px 20px; 
    margin-top: 16px; 
    word-break: break-word; 
    overflow-wrap: break-word;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
  }

  /* Formularz wyboru */
  .form-group { text-align: left; margin-bottom: 16px; }
  label       { display: block; font-size: .85em; color: #475569; margin-bottom: 6px; font-weight: 600; }
  select      { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1em; background: #fff; outline: none; }
  select:focus { border-color: #64748b; box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.15); }
  .check-row  { display: flex; align-items: center; gap: 8px; font-size: .9em; color: #475569; margin-bottom: 20px; }
  .check-row input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
  .btn-confirm { 
    width: 100%; 
    padding: 14px; 
    background: #0f172a; 
    color: #fff; 
    border: none; 
    border-radius: 8px; 
    font-size: 1em; 
    font-weight: 600;
    cursor: pointer; 
    letter-spacing: .02em; 
    transition: background 0.2s;
  }
  .btn-confirm:hover { background: #1e293b; }
  .error { color: #ef4444; font-size: .9em; margin-bottom: 12px; text-align: left; }
  .no-employees { color: #ef4444; font-size: .9em; padding: 12px; background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; }

  /* Nowa sekcja szybkich skanów */
  .scanner-card { margin-top: 24px; text-align: left; }
  .scanner-card h3 { margin: 0 0 12px; font-size: 0.95em; color: #0f172a; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
  .scanner-card input[type=text] { 
    width: 100%; 
    padding: 12px; 
    border: 1px solid #cbd5e1; 
    border-radius: 8px; 
    font-size: 1em; 
    margin-bottom: 12px; 
    outline: none;
  }
  .scanner-card input[type=text]:focus { border-color: #64748b; box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.15); }
  
  .btn-camera { 
    width: 100%; 
    padding: 12px; 
    background: #f1f5f9; 
    color: #334155; 
    border: 1px solid #cbd5e1; 
    border-radius: 8px; 
    font-size: 0.95em; 
    cursor: pointer; 
    font-weight: 600; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    gap: 8px; 
    transition: all 0.2s; 
  }
  .btn-camera:hover { background: #e2e8f0; color: #0f172a; }

  /* Modal dla aparatu */
  .camera-modal { 
    display: none; 
    position: fixed; 
    z-index: 2000; 
    left: 0; 
    top: 0; 
    width: 100%; 
    height: 100%; 
    background: rgba(15, 23, 42, 0.85); 
    backdrop-filter: blur(4px); 
    align-items: center; 
    justify-content: center; 
  }
  .camera-modal.active { display: flex; }
  .camera-modal-content { 
    background: #fff; 
    width: 90%; 
    max-width: 400px; 
    border-radius: 16px; 
    padding: 20px; 
    text-align: center; 
    position: relative;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  }
  .camera-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .camera-modal-header h3 { margin: 0; font-size: 1.1em; color: #0f172a; font-weight: 700; }
  .close-camera { font-size: 1.7em; font-weight: bold; color: #94a3b8; cursor: pointer; line-height: 1; }
  .close-camera:hover { color: #0f172a; }
  #qr-reader { width: 100%; border-radius: 12px; overflow: hidden; background: #000; }
  .camera-modal-tip { margin-top: 12px; font-size: 0.85em; color: #64748b; font-weight: 500; }
</style>
<!-- Biblioteka skanera QR -->
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
</head>
<body>

<?php if ($taskId <= 0): ?>
  <!-- ── OGÓLNY SKANER (BEZ PARAMETRU TASK_ID) ─────────────────── -->
  <div class="icon"><i class="ph-bold ph-magnifying-glass"></i></div>
  <h2>Skanowanie punktu</h2>
  <p>Zeskanuj kod QR z punktu kontrolnego lub wpisz go ręcznie poniżej.</p>
  
  <div class="card">
    <form id="scanFormGeneral" onsubmit="event.preventDefault(); processScanInput();" style="text-align: left;">
      <div class="form-group" style="margin-bottom: 12px;">
        <label for="general_scan_input">Kod lub pełny link z kodu QR:</label>
        <input type="text" id="general_scan_input" placeholder="Zeskanuj / wpisz i kliknij Enter..." autocomplete="off" autofocus
               style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1.1em; outline: none;">
      </div>
      <button type="button" class="btn-camera" onclick="openCamera()">
        <span><i class="ph-bold ph-camera"></i></span> Uruchom aparat w telefonie
      </button>
    </form>
  </div>
  
<?php elseif ($isTimeBlocked): ?>
  <!-- ── BLOKADA CZASOWA ──────────────────────────────────────── -->
  <div class="icon dup"><i class="ph-bold ph-clock"></i></div>
  <h2>Skanowanie zablokowane</h2>
  <div class="card">
    <p style="font-weight: 600; font-size: 1.15em; color: #ef4444; margin-bottom: 12px; line-height: 1.4;">
      <?= htmlspecialchars($errorTimeBlock) ?>
    </p>
    <p style="color: #64748b; font-size: 0.9em; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
      Zadanie: <strong><?= htmlspecialchars($task['name']) ?></strong>
    </p>
  </div>
  
  <div style="margin-top: 24px;">
    <a href="scan.php" class="btn-camera" style="text-decoration: none;">
      <span><i class="ph-bold ph-arrow-left"></i></span> Wróć do skanera ogólnego
    </a>
  </div>

<?php elseif ($alreadyDone): ?>
  <!-- ── JUŻ WYKONANE / WŁAŚNIE POTWIERDZONE ───────────────────── -->
  <div class="icon ok"><i class="ph-bold ph-check"></i></div>
  <h2>Wykonane!</h2>
  <div class="card">
    <p style="font-weight: 600; font-size: 1.1em; color: #0f172a;"><?= htmlspecialchars($task['name']) ?></p>
    <?php if ($row && !empty($row['scanned_by'])): ?>
    <div class="meta">
      Wykonał(a): <strong><?= htmlspecialchars($row['scanned_by']) ?></strong><br>
      o godz. <strong><?= date('H:i', strtotime($row['scanned_at'])) ?></strong>
    </div>
    <?php endif; ?>
  </div>


  <!-- Przejście do kolejnego skanowania -->
  <div class="card scanner-card">
    <h3>Skanuj kolejny punkt</h3>
    <form id="scanFormNext" onsubmit="event.preventDefault(); processScanInput();">
      <input type="text" id="next_scan_input" placeholder="Zeskanuj kolejny kod QR..." autocomplete="off" autofocus>
      <button type="button" class="btn-camera" onclick="openCamera()">
        <span><i class="ph-bold ph-camera"></i></span> Uruchom aparat w telefonie
      </button>
    </form>
  </div>

  <?php if (!empty($remainingTasks)): ?>
  <div class="card" style="margin-top: 16px; text-align: left;">
    <h3 style="margin-top: 0; font-size: 0.9em; color: #475569; border-bottom: 1px dashed #e2e8f0; padding-bottom: 8px; font-weight: 700;">
      Pozostałe zadania w tej lokalizacji (<?= count($remainingTasks) ?>):
    </h3>
    <ul style="margin: 8px 0 0; padding-left: 20px; font-size: 0.88em; color: #1e293b; line-height: 1.6;">
      <?php foreach ($remainingTasks as $rt): ?>
        <li><?= htmlspecialchars($rt['name']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  

<?php else: ?>
  <!-- ── FORMULARZ POTWIERDZENIA ZADANIA ──────────────────────── -->
  <h2>Potwierdź wykonanie</h2>
  <div class="card">
    <p style="font-weight:700; font-size:1.1em; color: #0f172a; margin-bottom:20px"><?= htmlspecialchars($task['name']) ?></p>

    <?php if (empty($employees)): ?>
      <div class="no-employees">Brak pracowników w systemie.<br>Dodaj pracowników w panelu administracyjnym.</div>
    <?php else: ?>
      <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" action="scan.php?task_id=<?= $taskId ?>">
        <div class="form-group">
          <label for="employee_id">Kto wykonuje zadanie?</label>
          <select name="employee_id" id="employee_id" required>
            <option value="">— wybierz z listy —</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $rememberedEmpId === (int)$e['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="check-row">
          <input type="checkbox" name="remember" id="remember" value="1"
            <?= $rememberedEmpId ? 'checked' : '' ?>>
          <label for="remember" style="margin:0;font-size:.9em;cursor:pointer;user-select:none">Zapamiętaj mnie na tę zmianę (8h)</label>
        </div>

        <button type="submit" class="btn-confirm"><i class="ph-bold ph-check"></i>&nbsp; Potwierdź wykonanie</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Przejście do innego skanowania na dole formularza -->
  <div class="card scanner-card">
    <h3>Zmień punkt / skanuj inny</h3>
    <form id="scanFormNext" onsubmit="event.preventDefault(); processScanInput();">
      <input type="text" id="next_scan_input" placeholder="Zeskanuj inny kod QR..." autocomplete="off">
      <button type="button" class="btn-camera" onclick="openCamera()">
        <span><i class="ph-bold ph-camera"></i></span> Uruchom aparat w telefonie
      </button>
    </form>
  </div>
  
<?php endif; ?>

<!-- MODAL APARATU -->
<div id="cameraModal" class="camera-modal">
  <div class="camera-modal-content">
    <div class="camera-modal-header">
      <h3>Skanuj aparatrem</h3>
      <span class="close-camera" onclick="closeCamera()">&times;</span>
    </div>
    <div id="qr-reader"></div>
    <p class="camera-modal-tip">Skieruj obiektyw na kod QR</p>
  </div>
</div>

<script>
let html5QrCode = null;

function openCamera() {
  document.getElementById('cameraModal').classList.add('active');
  
  if (!html5QrCode) {
    html5QrCode = new Html5Qrcode("qr-reader");
  }
  
  const config = { fps: 15, qrbox: { width: 250, height: 250 } };
  
  html5QrCode.start(
    { facingMode: "environment" }, 
    config,
    (decodedText) => {
      html5QrCode.stop().then(() => {
        closeCamera();
        handleDecodedText(decodedText);
      }).catch((err) => {
        console.error("Błąd podczas zatrzymania kamery:", err);
        closeCamera();
        handleDecodedText(decodedText);
      });
    },
    (errorMessage) => {
      // Ignorujemy błędy ciągłego szukania kodu
    }
  ).catch((err) => {
    alert("Nie udało się uruchomić aparatu. Upewnij się, że zezwoliłeś na dostęp do kamery.\nBłąd: " + err);
    closeCamera();
  });
}

function closeCamera() {
  document.getElementById('cameraModal').classList.remove('active');
  if (html5QrCode) {
    try {
      html5QrCode.stop().catch(err => console.log("Kamera była już wyłączona lub błąd stop:", err));
    } catch(e) {}
  }
}

function handleDecodedText(text) {
  let taskId = null;
  text = text.trim();
  
  if (/^\d+$/.test(text)) {
    taskId = text;
  } else {
    try {
      const url = new URL(text);
      const params = new URLSearchParams(url.search);
      taskId = params.get('task_id');
    } catch (e) {
      const match = text.match(/[?&]task_id=(\d+)/);
      if (match) {
        taskId = match[1];
      }
    }
  }
  
  if (taskId) {
    window.location.href = 'scan.php?task_id=' + taskId;
  } else {
    alert('Zeskanowano nieznany kod: ' + text);
  }
}

function processScanInput() {
  const inputNext = document.getElementById('next_scan_input');
  const inputGeneral = document.getElementById('general_scan_input');
  const input = inputNext || inputGeneral;
  
  if (input && input.value) {
    handleDecodedText(input.value);
    input.value = '';
  }
}

// Obsługa automatycznego ustawienia focusa po zamknięciu modala kamery
document.getElementById('cameraModal').addEventListener('transitionend', () => {
  if (!document.getElementById('cameraModal').classList.contains('active')) {
    const input = document.getElementById('next_scan_input') || document.getElementById('general_scan_input');
    if (input) input.focus();
  }
});

// Automatyczne ustawienie focusa po załadowaniu strony
window.onload = function() {
  const input = document.getElementById('next_scan_input') || document.getElementById('general_scan_input');
  if (input) {
    input.focus();
    setTimeout(() => input.focus(), 50);
  }
};
</script>
</body>
</html>

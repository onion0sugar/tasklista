<?php
require 'config.php';
requireLogin();

$db  = getDB();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Akcja może być w POST (formularz) lub GET (AJAX z JSON body)
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // --- TASK ACTIONS ---
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $locId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        if ($name !== '') {
            $stmt = $db->prepare("INSERT INTO tasks (name, location_id) VALUES (:name, :loc_id)");
            $stmt->execute([':name' => $name, ':loc_id' => $locId]);
            $newId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (:tid, :name, 'created', CURDATE(), NOW())")
               ->execute([':tid' => $newId, ':name' => $name]);
            $msg = 'Zadanie zostało dodane.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name, active FROM tasks WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $t = $stmt->fetch();
        if ($t) {
            $newActive = 1 - $t['active'];
            $db->prepare("UPDATE tasks SET active = :active WHERE id = :id")->execute([':active' => $newActive, ':id' => $id]);
            $actionName = $newActive ? 'activated' : 'deactivated';
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (:tid, :name, :action, CURDATE(), NOW())")
               ->execute([':tid' => $id, ':name' => $t['name'], ':action' => $actionName]);
            $msg = 'Status zadania został zmieniony.';
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $locId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        if ($id > 0 && $name !== '') {
            $stmt = $db->prepare("SELECT name FROM tasks WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $old = $stmt->fetch();
            if ($old) {
                $db->prepare("UPDATE tasks SET name = :name, location_id = :loc_id WHERE id = :id")
                   ->execute([':name' => $name, ':loc_id' => $locId, ':id' => $id]);
                $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (:tid, :name, 'renamed', CURDATE(), NOW())")
                   ->execute([':tid' => $id, ':name' => $old['name'] . ' → ' . $name]);
                $msg = 'Zadanie zostało zaktualizowane.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name FROM tasks WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $toDelete = $stmt->fetch();
        if ($toDelete) {
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (:tid, :name, 'deleted', CURDATE(), NOW())")
               ->execute([':tid' => $id, ':name' => $toDelete['name']]);
            $db->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $id]);
            $msg = 'Zadanie zostało usunięte.';
        }
    } elseif ($action === 'reorder') {
        // Zapis kolejności przez AJAX (JSON body)
        $raw   = file_get_contents('php://input');
        $items = json_decode($raw, true);
        if (is_array($items)) {
            $stmt = $db->prepare("UPDATE tasks SET sort_order = :order WHERE id = :id");
            foreach ($items as $item) {
                if (isset($item['id'], $item['sort_order'])) {
                    $stmt->execute([':order' => (int)$item['sort_order'], ':id' => (int)$item['id']]);
                }
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    // --- LOCATION ACTIONS ---
    elseif ($action === 'add_location') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $db->prepare("INSERT INTO locations (name) VALUES (:name)")->execute([':name' => $name]);
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (0, :name, 'loc_created', CURDATE(), NOW())")
               ->execute([':name' => "Lokalizacja: " . $name]);
            $msg = 'Lokalizacja została dodana.';
        }
    } elseif ($action === 'delete_location') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name FROM locations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $loc = $stmt->fetch();
        if ($loc) {
            $db->prepare("DELETE FROM locations WHERE id = :id")->execute([':id' => $id]);
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (0, :name, 'loc_deleted', CURDATE(), NOW())")
               ->execute([':name' => "Lokalizacja: " . $loc['name']]);
            $msg = 'Lokalizacja została usunięta.';
        }
    }
    // --- EMPLOYEE ACTIONS ---
    elseif ($action === 'add_employee') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $db->prepare("INSERT INTO employees (name) VALUES (:name)")->execute([':name' => $name]);
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (0, :name, 'emp_created', CURDATE(), NOW())")
               ->execute([':name' => "Pracownik: " . $name]);
            $msg = 'Pracownik został dodany.';
        }
    } elseif ($action === 'delete_employee') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name FROM employees WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $emp = $stmt->fetch();
        if ($emp) {
            $db->prepare("DELETE FROM employees WHERE id = :id")->execute([':id' => $id]);
            $db->prepare("INSERT INTO logs (task_id, task_name, action, date, logged_at) VALUES (0, :name, 'emp_deleted', CURDATE(), NOW())")
               ->execute([':name' => "Pracownik: " . $emp['name']]);
            $msg = 'Pracownik został usunięty.';
        }
    } elseif ($action === 'save_settings') {
        for ($i = 1; $i <= 7; $i++) {
            $val = $_POST["min_scan_hour_$i"] ?? '';
            $val = trim($val);
            if ($val === '') {
                $val = null;
            }
            setSetting("min_scan_hour_$i", $val);
        }
        $msg = 'Ustawienia zostały zapisane.';
    }
}

// Pobierz dane
$tasks = $db->query("
    SELECT t.*, l.name AS location_name 
    FROM tasks t 
    LEFT JOIN locations l ON t.location_id = l.id 
    ORDER BY t.sort_order, t.name
")->fetchAll();

$locations = $db->query("SELECT * FROM locations ORDER BY name")->fetchAll();
$employees = $db->query("SELECT * FROM employees ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zarządzanie Systemem Zadań</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; background: #f8fafc; color: #1e293b; }
  h1 { margin: 0 0 4px; font-size: 1.8em; color: #0f172a; }
  .sub { color: #64748b; margin-bottom: 20px; font-size: 0.95em; }
  
  nav { margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 12px 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
  nav a { color: #3b82f6; text-decoration: none; font-weight: 500; font-size: 0.95em; }
  nav a:hover { text-decoration: underline; }
  
  .msg { background: #d1fae5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.95em; font-weight: 500; }
  
  .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
  .tab-btn { padding: 8px 16px; border: none; background: none; font-weight: 600; color: #64748b; cursor: pointer; border-radius: 6px; transition: all 0.2s; }
  .tab-btn.active { background: #0f172a; color: #fff; }
  .tab-btn:hover:not(.active) { background: #e2e8f0; color: #0f172a; }
  
  .tab-content { display: none; }
  .tab-content.active { display: block; }
  
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; }
  .card-title { font-size: 1.25em; font-weight: 600; color: #0f172a; margin: 0 0 16px; }
  
  form.add-form { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
  form.add-form input[type=text], form.add-form select { padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95em; outline: none; background: #fff; }
  form.add-form input[type=text] { flex: 2; min-width: 200px; }
  form.add-form select { flex: 1; min-width: 150px; }
  
  button { padding: 10px 18px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; font-size: 0.9em; font-weight: 600; background: #fff; color: #475569; transition: all 0.2s; }
  button:hover { background: #f1f5f9; }
  button.primary { background: #0f172a; color: #fff; border-color: #0f172a; }
  button.primary:hover { background: #1e293b; }
  button.danger { color: #ef4444; border-color: #fecaca; }
  button.danger:hover { background: #fef2f2; }
  
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
  th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.95em; }
  th { background: #f8fafc; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
  tr:last-child td { border-bottom: none; }
  .inactive td { color: #94a3b8; }
  .inactive td.actions button { opacity: 0.7; }
  
  .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 0.8em; font-weight: 600; }
  .badge.active { background: #d1fae5; color: #065f46; }
  .badge.inactive { background: #f1f5f9; color: #475569; }
  
  .loc-tag { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; background: #e2e8f0; color: #475569; font-weight: 500; }

  /* ── Drag & Drop ── */
  .drag-handle { cursor: grab; color: #cbd5e1; font-size: 1.1em; user-select: none; padding: 0 4px; transition: color 0.15s; }
  .drag-handle:hover { color: #64748b; }
  tr.dragging { opacity: 0.35; background: #f1f5f9; }
  tr.drag-over { box-shadow: inset 0 2px 0 0 #3b82f6; }
  .order-col { width: 42px; text-align: center; color: #94a3b8; font-size: 0.85em; }

  button.btn-edit { color: #2563eb; border-color: #bfdbfe; }
  button.btn-edit:hover { background: #eff6ff; }

  /* ── Modal edycji ── */
  .edit-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
  .edit-modal.active { display: flex; }
  .edit-modal-content { background-color: #fff; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; width: 90%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); animation: modalSlide 0.3s ease; }
  @keyframes modalSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  .edit-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .edit-modal-header h3 { margin: 0; font-size: 1.15em; font-weight: 600; color: #0f172a; }
  .close-edit-modal { font-size: 1.5em; font-weight: bold; color: #94a3b8; cursor: pointer; line-height: 1; transition: color 0.2s; }
  .close-edit-modal:hover { color: #0f172a; }
  .edit-modal form { display: flex; flex-direction: column; gap: 16px; }
  .edit-modal label { font-weight: 600; font-size: 0.9em; color: #475569; }
  .edit-modal input[type=text], .edit-modal select { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95em; outline: none; background: #fff; box-sizing: border-box; }
  .edit-modal input[type=text]:focus, .edit-modal select:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
  .edit-modal-actions { display: flex; gap: 10px; margin-top: 8px; }
  .edit-modal-actions button { flex: 1; padding: 10px; border-radius: 8px; font-size: 0.9em; font-weight: 600; cursor: pointer; transition: all 0.2s; }
  .edit-modal-actions .btn-cancel { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
  .edit-modal-actions .btn-cancel:hover { background: #f1f5f9; }
  .edit-modal-actions .btn-save { background: #0f172a; color: #fff; border: 1px solid #0f172a; }
  .edit-modal-actions .btn-save:hover { background: #1e293b; }

  /* Toast powiadomienie */
  #orderToast { position: fixed; bottom: 24px; right: 24px; background: #10b981; color: #fff; padding: 10px 18px; border-radius: 8px; font-size: 0.9em; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(8px); transition: opacity 0.25s, transform 0.25s; pointer-events: none; z-index: 999; }
  #orderToast.show { opacity: 1; transform: translateY(0); }

  /* Przycisk zapisu kolejności */
  #saveOrderBtn { display: none; padding: 8px 16px; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-size: 0.88em; font-weight: 600; cursor: pointer; transition: background 0.2s; }
  #saveOrderBtn:hover { background: #2563eb; }
  #saveOrderBtn.visible { display: inline-flex; align-items: center; gap: 6px; }
  .order-header-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
  .order-hint { font-size: 0.82em; color: #94a3b8; }
</style>
</head>
<body>

<h1>Panel Administracyjny</h1>
<div class="sub">Zarządzaj zadaniami, lokalizacjami i pracownikami</div>

<nav>
  <a href="index.php">&larr; Powrót do tablicy głównej</a>
  <a href="manager.php">Panel Kierownika &rarr;</a>
</nav>

<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="tabs">
  <button id="btn-tasks" class="tab-btn active" onclick="switchTab('tasks')">Zadania</button>
  <button id="btn-locations" class="tab-btn" onclick="switchTab('locations')">Lokalizacje</button>
  <button id="btn-employees" class="tab-btn" onclick="switchTab('employees')">Pracownicy</button>
  <button id="btn-settings" class="tab-btn" onclick="switchTab('settings')">Ustawienia</button>
</div>

<!-- ================= ZADANIA ================= -->
<div id="tasks-tab" class="tab-content active">
  <div class="card">
    <div class="card-title">Dodaj nowe zadanie</div>
    <form class="add-form" method="post">
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" placeholder="Nazwa zadania (np. Kontrola czystości biura)" required>
      <select name="location_id">
        <option value="">— wybierz lokalizację (opcjonalnie) —</option>
        <?php foreach ($locations as $loc): ?>
          <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="primary">Dodaj zadanie</button>
    </form>
  </div>


  <div class="card" style="padding: 0; overflow-x: auto;">
    <div style="padding: 16px 20px 0;">
      <div class="order-header-row">
        <span class="order-hint">☰ Przeciągnij wiersz, aby zmienić kolejność zadań</span>
        <button id="saveOrderBtn" onclick="saveOrder()">&#128190; Zapisz kolejność</button>
      </div>
    </div>
    <table id="tasksTable">
      <thead>
        <tr>
          <th class="order-col" title="Kolejność">#</th>
          <th></th>
          <th>Nazwa zadania</th>
          <th>Lokalizacja</th>
          <th>Status</th>
          <th style="text-align: right;">Akcje</th>
        </tr>
      </thead>
      <tbody id="tasksTbody">
        <?php foreach ($tasks as $i => $t): ?>
        <tr class="<?= $t['active'] ? '' : 'inactive' ?>" data-id="<?= $t['id'] ?>" draggable="true">
          <td class="order-col"><?= $i + 1 ?></td>
          <td><span class="drag-handle" title="Przeciągnij, aby zmienić kolejność">⠿</span></td>
          <td style="font-weight: 500;"><?= htmlspecialchars($t['name']) ?></td>
          <td>
            <?php if (!empty($t['location_name'])): ?>
              <span class="loc-tag"><?= htmlspecialchars($t['location_name']) ?></span>
            <?php else: ?>
              <span style="color: #cbd5e1; font-size: 0.9em;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $t['active'] ? 'active' : 'inactive' ?>">
              <?= $t['active'] ? 'Aktywne' : 'Nieaktywne' ?>
            </span>
          </td>
          <td class="actions" style="text-align: right;">
            <button type="button" class="btn-edit" onclick="openEditModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>', <?= $t['location_id'] ?? 'null' ?>)">Edytuj</button>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $t['id'] ?>">
              <button type="submit"><?= $t['active'] ? 'Dezaktywuj' : 'Aktywuj' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Czy na pewno chcesz usunąć to zadanie? Usunie to również jego dzisiejsze statusy.')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $t['id'] ?>">
              <button type="submit" class="danger">Usuń</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tasks)): ?>
        <tr><td colspan="6" style="color:#94a3b8; text-align:center; padding: 24px;">Brak zdefiniowanych zadań.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>



<!-- ================= LOKALIZACJE ================= -->
<div id="locations-tab" class="tab-content">
  <div class="card">
    <div class="card-title">Dodaj nową lokalizację</div>
    <form class="add-form" method="post">
      <input type="hidden" name="action" value="add_location">
      <input type="text" name="name" placeholder="Nazwa lokalizacji (np. Piętro 1, Magazyn A)" required>
      <button type="submit" class="primary">Dodaj lokalizację</button>
    </form>
  </div>

  <div class="card" style="padding: 0; overflow-x: auto;">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nazwa lokalizacji</th>
          <th style="text-align: right;">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($locations as $loc): ?>
        <tr>
          <td><?= $loc['id'] ?></td>
          <td style="font-weight: 500;"><?= htmlspecialchars($loc['name']) ?></td>
          <td style="text-align: right;">
            <form method="post" style="display:inline" onsubmit="return confirm('Czy na pewno chcesz usunąć tę lokalizację? Przypisane do niej zadania zostaną odpięte.')">
              <input type="hidden" name="action" value="delete_location">
              <input type="hidden" name="id" value="<?= $loc['id'] ?>">
              <button type="submit" class="danger">Usuń</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($locations)): ?>
        <tr><td colspan="3" style="color:#94a3b8; text-align:center; padding: 24px;">Brak zdefiniowanych lokalizacji.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================= PRACOWNICY ================= -->
<div id="employees-tab" class="tab-content">
  <div class="card">
    <div class="card-title">Dodaj nowego pracownika</div>
    <form class="add-form" method="post">
      <input type="hidden" name="action" value="add_employee">
      <input type="text" name="name" placeholder="Imię i nazwisko pracownika" required>
      <button type="submit" class="primary">Dodaj pracownika</button>
    </form>
  </div>

  <div class="card" style="padding: 0; overflow-x: auto;">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Imię i nazwisko</th>
          <th style="text-align: right;">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr>
          <td><?= $emp['id'] ?></td>
          <td style="font-weight: 500;"><?= htmlspecialchars($emp['name']) ?></td>
          <td style="text-align: right;">
            <form method="post" style="display:inline" onsubmit="return confirm('Czy na pewno chcesz usunąć tego pracownika?')">
              <input type="hidden" name="action" value="delete_employee">
              <input type="hidden" name="id" value="<?= $emp['id'] ?>">
              <button type="submit" class="danger">Usuń</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($employees)): ?>
        <tr><td colspan="3" style="color:#94a3b8; text-align:center; padding: 24px;">Brak zdefiniowanych pracowników.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================= USTAWIENIA ================= -->
<div id="settings-tab" class="tab-content">
  <div class="card">
    <div class="card-title">Minimalna godzina skanowania (dni tygodnia)</div>
    <p class="sub" style="margin-bottom: 16px;">Pracownicy nie będą mogli potwierdzić ani zeskanować zadania przed określoną godziną w danym dniu. Pozostaw puste pole, aby nie nakładać ograniczeń.</p>
    <form method="post" action="admin.php">
      <input type="hidden" name="action" value="save_settings">
      <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <?php 
        $days = [
            1 => 'Poniedziałek',
            2 => 'Wtorek',
            3 => 'Środa',
            4 => 'Czwartek',
            5 => 'Piątek',
            6 => 'Sobota',
            7 => 'Niedziela'
        ];
        foreach ($days as $num => $dayName):
            $val = getSetting("min_scan_hour_$num", '');
        ?>
          <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
            <label for="min_scan_hour_<?= $num ?>" style="font-weight: 600;"><?= $dayName ?></label>
            <input type="time" id="min_scan_hour_<?= $num ?>" name="min_scan_hour_<?= $num ?>" value="<?= htmlspecialchars($val ?? '') ?>" style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95em; outline: none; background: #fff;">
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="primary">Zapisz ustawienia</button>
    </form>
  </div>
</div>

<script>
/* ── Przełączanie zakładek ── */
function switchTab(tabId) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  const tabContent = document.getElementById(tabId + '-tab');
  if (tabContent) tabContent.classList.add('active');
  const tabBtn = document.getElementById('btn-' + tabId);
  if (tabBtn) tabBtn.classList.add('active');
  localStorage.setItem('admin_active_tab', tabId);
}

document.addEventListener('DOMContentLoaded', function () {
  const savedTab = localStorage.getItem('admin_active_tab') || 'tasks';
  switchTab(savedTab);

  /* ── Drag & Drop ── */
  const tbody  = document.getElementById('tasksTbody');
  if (!tbody) return;

  let dragSrc = null;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-id]')); }

  function renumberRows() {
    getRows().forEach((row, i) => {
      const cell = row.querySelector('.order-col');
      if (cell) cell.textContent = i + 1;
    });
  }

  function markDirty() {
    const btn = document.getElementById('saveOrderBtn');
    if (btn) btn.classList.add('visible');
  }

  tbody.addEventListener('dragstart', function (e) {
    dragSrc = e.target.closest('tr[data-id]');
    if (!dragSrc) return;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragSrc.dataset.id);
    setTimeout(() => dragSrc.classList.add('dragging'), 0);
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const target = e.target.closest('tr[data-id]');
    if (!target || target === dragSrc) return;
    // Wyczyść inne podświetlenia
    getRows().forEach(r => r.classList.remove('drag-over'));
    target.classList.add('drag-over');
  });

  tbody.addEventListener('dragleave', function (e) {
    const target = e.target.closest('tr[data-id]');
    if (target) target.classList.remove('drag-over');
  });

  tbody.addEventListener('drop', function (e) {
    e.preventDefault();
    const target = e.target.closest('tr[data-id]');
    getRows().forEach(r => r.classList.remove('drag-over'));
    if (!target || target === dragSrc) return;

    // Wstaw dragSrc przed lub po target
    const rows     = getRows();
    const srcIdx   = rows.indexOf(dragSrc);
    const tgtIdx   = rows.indexOf(target);
    if (srcIdx < tgtIdx) {
      tbody.insertBefore(dragSrc, target.nextSibling);
    } else {
      tbody.insertBefore(dragSrc, target);
    }
    renumberRows();
    markDirty();
  });

  tbody.addEventListener('dragend', function () {
    getRows().forEach(r => r.classList.remove('dragging', 'drag-over'));
    dragSrc = null;
  });
});

/* ── Zapis kolejności przez AJAX ── */
function saveOrder() {
  const tbody = document.getElementById('tasksTbody');
  const rows  = Array.from(tbody.querySelectorAll('tr[data-id]'));
  const items = rows.map((row, i) => ({ id: parseInt(row.dataset.id), sort_order: i + 1 }));

  const btn = document.getElementById('saveOrderBtn');
  btn.disabled = true;
  btn.textContent = 'Zapisywanie…';

  fetch('admin.php?action=reorder', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(items),
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(() => {
    btn.disabled = false;
    btn.innerHTML = '&#128190; Zapisz kolejność';
    btn.classList.remove('visible');
    showToast('Kolejność została zapisana ✓');
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = '&#128190; Zapisz kolejność';
    btn.classList.add('visible');
    alert('Błąd podczas zapisu kolejności.');
  });
}

function showToast(msg) {
  const t = document.getElementById('orderToast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}
</script>

<!-- Toast powiadomienie -->
<div id="orderToast"></div>

<!-- ================= MODAL EDYCJI ZADANIA ================= -->
<div id="editTaskModal" class="edit-modal">
  <div class="edit-modal-content">
    <div class="edit-modal-header">
      <h3>Edytuj zadanie</h3>
      <span class="close-edit-modal" onclick="closeEditModal()">&times;</span>
    </div>
    <form method="post" action="admin.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_task_id" value="">
      <div>
        <label for="edit_task_name">Nazwa zadania</label>
        <input type="text" name="name" id="edit_task_name" required>
      </div>
      <div>
        <label for="edit_task_location">Lokalizacja</label>
        <select name="location_id" id="edit_task_location">
          <option value="">— brak przypisanej lokalizacji —</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="edit-modal-actions">
        <button type="button" class="btn-cancel" onclick="closeEditModal()">Anuluj</button>
        <button type="submit" class="btn-save">Zapisz zmiany</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Modal edycji zadania ── */
function openEditModal(id, name, locationId) {
  document.getElementById('edit_task_id').value = id;
  document.getElementById('edit_task_name').value = name;
  const locSelect = document.getElementById('edit_task_location');
  if (locationId !== null && locationId !== undefined) {
    locSelect.value = locationId;
  } else {
    locSelect.value = '';
  }
  document.getElementById('editTaskModal').classList.add('active');
}

function closeEditModal() {
  document.getElementById('editTaskModal').classList.remove('active');
}

// Zamknij modal po kliknięciu poza zawartością
document.getElementById('editTaskModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});
</script>

</body>
</html>


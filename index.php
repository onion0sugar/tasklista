<?php
require 'config.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

// Zapewnij dzisiejsze wpisy
$db->prepare("
    INSERT IGNORE INTO daily_tasks (task_id, date, status)
    SELECT id, :date, 0 FROM tasks WHERE active = 1
")->execute([':date' => $today]);

// Pobierz lokalizacje do filtra
$locations = $db->query("SELECT id, name FROM locations ORDER BY name")->fetchAll();

// Filtrowanie po lokalizacji
$selectedLocation = isset($_GET['location_id']) ? $_GET['location_id'] : '';

$sql = "
    SELECT t.id, t.name, COALESCE(dt.status, 0) AS status,
           dt.scanned_by, dt.scanned_at, l.name AS location_name
    FROM tasks t
    LEFT JOIN daily_tasks dt ON dt.task_id = t.id AND dt.date = :date
    LEFT JOIN locations l ON t.location_id = l.id
    WHERE t.active = 1
";

$params = [':date' => $today];

if ($selectedLocation !== '') {
    if ($selectedLocation === 'none') {
        $sql .= " AND t.location_id IS NULL";
    } else {
        $sql .= " AND t.location_id = :location_id";
        $params[':location_id'] = (int)$selectedLocation;
    }
}

$sql .= " ORDER BY t.sort_order, t.name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$total = count($tasks);
$done  = array_sum(array_column($tasks, 'status'));
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista Zadań (Admin) – <?= $today ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 960px; margin: 0 auto; padding: 20px; background: #f8fafc; color: #1e293b; }
  
  header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
  h1 { margin: 0; font-size: 1.6em; color: #0f172a; }
  
  nav { margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; width: 100%; }
  nav a { color: #475569; text-decoration: none; font-size: 0.85em; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 8px; background: #fff; font-weight: 500; transition: all 0.2s; }
  nav a:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }
  nav a.logout { margin-left: auto; color: #94a3b8; }
  nav a.logout:hover { color: #ef4444; border-color: #fecaca; background: #fef2f2; }

  /* ── Pasek postępu (kompaktowy) ── */
  .stats-bar { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
  .stats-bar .label { font-weight: 600; font-size: 0.85em; color: #64748b; white-space: nowrap; }
  .stats-bar .count { font-weight: 700; color: #0f172a; font-size: 0.9em; white-space: nowrap; }
  .stats-bar .progress-wrap { flex: 1; min-width: 100px; }
  .stats-bar .progress { background: #e2e8f0; border-radius: 6px; height: 8px; overflow: hidden; }
  .stats-bar .progress-bar { background: #10b981; height: 100%; border-radius: 6px; transition: width .4s ease; }
  
  /* ── Filtr (kompaktowy) ── */
  .filter-bar { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
  .filter-bar label { font-weight: 600; font-size: 0.82em; color: #475569; white-space: nowrap; }
  .filter-bar select { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85em; outline: none; background-color: #fff; min-width: 180px; }
  .filter-bar select:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }

  /* ── Lista zadań (kompaktowa tabela) ── */
  .task-list { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
  .task-list th { background: #f8fafc; font-weight: 600; color: #475569; font-size: 0.78em; text-transform: uppercase; letter-spacing: 0.04em; padding: 10px 14px; border-bottom: 2px solid #e2e8f0; text-align: left; }
  .task-list td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 0.88em; vertical-align: middle; }
  .task-list tr:last-child td { border-bottom: none; }
  .task-list tr:hover td { background: #f8fafc; }
  
  .task-list .col-status { width: 90px; text-align: center; }
  .task-list .col-loc { width: 130px; }
  .task-list .col-who { width: 110px; }
  .task-list .col-time { width: 60px; text-align: center; }
  .task-list .col-qr { width: 50px; text-align: center; }
  
  .task-name { font-weight: 600; color: #0f172a; }
  .task-name.done { color: #64748b; }
  
  .loc-tag { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 0.78em; background: #f1f5f9; color: #475569; font-weight: 500; }
  
  .badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 0.75em; font-weight: 600; }
  .badge.done { background: #d1fae5; color: #059669; }
  .badge.pending { background: #fef3c7; color: #d97706; }
  
  .who-text { font-size: 0.82em; color: #475569; }
  .time-text { font-size: 0.82em; color: #94a3b8; }
  
  .btn-qr { padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer; background: #fff; font-size: 0.85em; color: #475569; transition: all 0.2s; line-height: 1; }
  .btn-qr:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }

  .no-tasks { text-align: center; padding: 32px; color: #64748b; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }

  /* ── Modal QR ── */
  .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
  .modal.active { display: flex; }
  .modal-content { background-color: #fff; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; width: 90%; max-width: 360px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); position: relative; animation: modalSlide 0.3s ease; }
  @keyframes modalSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  .close-modal { position: absolute; top: 12px; right: 16px; font-size: 1.5em; font-weight: bold; color: #94a3b8; cursor: pointer; transition: color 0.2s; }
  .close-modal:hover { color: #0f172a; }
  .modal-qr-img { width: 200px; height: 200px; display: block; margin: 16px auto; border: 1px solid #f1f5f9; padding: 8px; border-radius: 8px; }
  .modal-title { font-size: 1.15em; font-weight: 600; color: #0f172a; margin-top: 0; margin-bottom: 8px; padding-right: 20px; }
  .modal-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
  .modal-btn { width: 100%; padding: 10px; font-size: 0.9em; font-weight: 600; border-radius: 8px; display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; cursor: pointer; transition: all 0.2s; }
  .modal-btn.copy { background: #0f172a; color: #fff; border: 1px solid #0f172a; }
  .modal-btn.copy:hover { background: #1e293b; }
  .modal-btn.copy.copied { background: #10b981; border-color: #10b981; }
  .modal-btn.print { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
  .modal-btn.print:hover { background: #f1f5f9; color: #0f172a; }
</style>
</head>
<body>

<header>
  <div>
    <h1>Panel Administratora</h1>
    <div style="color: #64748b; font-size: 0.85em; margin-top: 2px;">Dziś jest: <strong><?= date('d.m.Y', strtotime($today)) ?></strong></div>
  </div>
</header>

<nav>
  <a href="admin.php">+ Zarządzaj systemem</a>
  <a href="logs.php">Logi systemowe</a>
  <a href="scan.php" target="_blank">&#128247; Skaner</a>
  <a href="print.php" target="_blank">&#128438; Drukuj QR</a>
  <a href="logout.php" class="logout">Wyloguj</a>
</nav>

<div class="stats-bar">
  <span class="label">Postęp:</span>
  <span class="count"><?= $done ?> / <?= $total ?> (<?= $total > 0 ? round($done / $total * 100) : 0 ?>%)</span>
  <?php if ($total > 0): ?>
  <div class="progress-wrap">
    <div class="progress">
      <div class="progress-bar" style="width:<?= round($done / $total * 100) ?>%"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="filter-bar">
  <label for="location_filter">Filtruj:</label>
  <select id="location_filter" onchange="filterLocation(this.value)">
    <option value="">Wszystkie lokalizacje</option>
    <?php foreach ($locations as $loc): ?>
    <option value="<?= $loc['id'] ?>" <?= $selectedLocation === (string)$loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
    <?php endforeach; ?>
    <option value="none" <?= $selectedLocation === 'none' ? 'selected' : '' ?>>Brak lokalizacji</option>
  </select>
</div>

<?php if (empty($tasks)): ?>
  <div class="no-tasks">Brak zadań pasujących do wybranego filtru.</div>
<?php else: ?>
<table class="task-list">
  <thead>
    <tr>
      <th>Zadanie</th>
      <th class="col-loc">Lokalizacja</th>
      <th class="col-status">Status</th>
      <th class="col-who">Wykonał</th>
      <th class="col-time">Godz.</th>
      <th class="col-qr">QR</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($tasks as $t):
        $url = APP_URL . '/scan.php?task_id=' . $t['id'];
        $qr  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url);
    ?>
    <tr>
      <td><span class="task-name <?= $t['status'] ? 'done' : '' ?>"><?= htmlspecialchars($t['name']) ?></span></td>
      <td>
        <?php if (!empty($t['location_name'])): ?>
          <span class="loc-tag"><?= htmlspecialchars($t['location_name']) ?></span>
        <?php else: ?>
          <span style="color: #e2e8f0;">—</span>
        <?php endif; ?>
      </td>
      <td class="col-status">
        <span class="badge <?= $t['status'] ? 'done' : 'pending' ?>">
          <?= $t['status'] ? 'Tak' : 'Nie' ?>
        </span>
      </td>
      <td class="col-who">
        <?php if ($t['status'] && !empty($t['scanned_by'])): ?>
          <span class="who-text"><?= htmlspecialchars($t['scanned_by']) ?></span>
        <?php else: ?>
          <span style="color: #e2e8f0;">—</span>
        <?php endif; ?>
      </td>
      <td class="col-time">
        <?php if ($t['status'] && !empty($t['scanned_at'])): ?>
          <span class="time-text"><?= date('H:i', strtotime($t['scanned_at'])) ?></span>
        <?php else: ?>
          <span style="color: #e2e8f0;">—</span>
        <?php endif; ?>
      </td>
      <td class="col-qr">
        <button class="btn-qr" onclick="openQRModal('<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>', '<?= $qr ?>', '<?= htmlspecialchars($url, ENT_QUOTES) ?>', '<?= $t['id'] ?>')" title="Pokaż kod QR">&#128269;</button>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- Modal QR -->
<div id="qrModal" class="modal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeQRModal()">&times;</span>
    <h3 class="modal-title" id="modalTaskName">Nazwa zadania</h3>
    <img src="" id="modalQRImg" class="modal-qr-img" alt="Kod QR">
    <div class="modal-actions">
      <button class="modal-btn copy" id="modalCopyBtn" onclick="copyModalLink()">&#128279; Kopiuj link</button>
      <a href="" id="modalPrintBtn" target="_blank" class="modal-btn print">&#128438; Drukuj PDF</a>
    </div>
  </div>
</div>

<script>
let currentURL = '';

function filterLocation(val) {
  if (val === '') {
    window.location.href = 'index.php';
  } else {
    window.location.href = 'index.php?location_id=' + encodeURIComponent(val);
  }
}

function openQRModal(name, qrUrl, scanUrl, taskId) {
  document.getElementById('modalTaskName').textContent = name;
  document.getElementById('modalQRImg').src = qrUrl;
  document.getElementById('modalPrintBtn').href = 'print.php?task_id=' + taskId;
  currentURL = scanUrl;
  const copyBtn = document.getElementById('modalCopyBtn');
  copyBtn.innerHTML = '&#128279; Kopiuj link';
  copyBtn.classList.remove('copied');
  document.getElementById('qrModal').classList.add('active');
}

function closeQRModal() {
  document.getElementById('qrModal').classList.remove('active');
}

window.onclick = function(event) {
  const modal = document.getElementById('qrModal');
  if (event.target === modal) closeQRModal();
}

function copyModalLink() {
  const btn = document.getElementById('modalCopyBtn');
  const ta = document.createElement('textarea');
  ta.value = currentURL;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  document.execCommand('copy');
  document.body.removeChild(ta);
  btn.textContent = '✓ Skopiowano';
  btn.classList.add('copied');
  setTimeout(function() {
    btn.innerHTML = '&#128279; Kopiuj link';
    btn.classList.remove('copied');
  }, 2000);
}
</script>
</body>
</html>

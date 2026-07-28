<?php
require 'config.php';
requireManager();

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
<title>Panel Kierownika – Zadania</title>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 960px; margin: 0 auto; padding: 20px; background: #f8fafc; color: #1e293b; }
  header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
  h1 { margin: 0; font-size: 1.8em; color: #0f172a; }
  
  nav { margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; width: 100%; }
  nav a { color: #475569; text-decoration: none; font-size: 0.9em; border: 1px solid #e2e8f0; padding: 8px 14px; border-radius: 8px; background: #fff; font-weight: 500; transition: all 0.2s; }
  nav a:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }
  nav a.logout { margin-left: auto; color: #94a3b8; }
  nav a.logout:hover { color: #ef4444; border-color: #fecaca; background: #fef2f2; }
  
  .stats-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
  .stats-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-weight: 600; }
  .progress { background: #e2e8f0; border-radius: 6px; height: 10px; overflow: hidden; }
  .progress-bar { background: #10b981; height: 100%; border-radius: 6px; transition: width .4s ease; }
  
  .filter-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
  .filter-section label { font-weight: 600; font-size: 0.9em; color: #475569; }
  .filter-section select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95em; outline: none; background-color: #fff; min-width: 200px; }
  .filter-section select:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
  
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s, box-shadow 0.2s; }
  .card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
  .card.done { border-left: 4px solid #10b981; }
  .card.pending { border-left: 4px solid #f59e0b; }
  
  .card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 12px; }
  .card h3 { margin: 0; font-size: 1.1em; font-weight: 600; color: #0f172a; line-height: 1.4; word-break: break-word; overflow-wrap: break-word; }
  .loc-badge { font-size: 0.75em; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; font-weight: 500; display: inline-block; margin-top: 4px; }
  
  .badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: .8em; font-weight: 600; }
  .badge.pending { background: #fef3c7; color: #d97706; }
  .badge.done    { background: #d1fae5; color: #059669; }
  
  .scan-info { font-size: 0.85em; color: #475569; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0; display: flex; justify-content: space-between; }
  .scan-info strong { color: #0f172a; }
  .no-tasks { text-align: center; grid-column: 1 / -1; padding: 40px; color: #64748b; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
</style>
</head>
<body>

<header>
  <div>
    <h1>Panel Kierownika</h1>
    <div style="color: #64748b; font-size: 0.9em; margin-top: 4px;">Dziś jest: <strong><?= date('d.m.Y', strtotime($today)) ?></strong></div>
  </div>
</header>

<nav>
  <a href="logs.php">Logi systemowe</a>
  <a href="scan.php" target="_blank"><i class="ph-bold ph-camera"></i> Skaner</a>
  <a href="logout.php" class="logout">Wyloguj</a>
</nav>

<div class="stats-card">
  <div class="stats-info">
    <span>Status wykonania zadań</span>
    <span style="color: #0f172a;"><?= $done ?> / <?= $total ?> (<?= $total > 0 ? round($done / $total * 100) : 0 ?>%)</span>
  </div>
  <?php if ($total > 0): ?>
  <div class="progress">
    <div class="progress-bar" style="width:<?= round($done / $total * 100) ?>%"></div>
  </div>
  <?php endif; ?>
</div>

<div class="filter-section">
  <label for="location_filter">Filtruj według lokalizacji:</label>
  <select id="location_filter" onchange="filterLocation(this.value)">
    <option value="">Wszystkie lokalizacje</option>
    <?php foreach ($locations as $loc): ?>
    <option value="<?= $loc['id'] ?>" <?= $selectedLocation === (string)$loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
    <?php endforeach; ?>
    <option value="none" <?= $selectedLocation === 'none' ? 'selected' : '' ?>>Brak przypisanej lokalizacji</option>
  </select>
</div>

<div class="grid">
  <?php if (empty($tasks)): ?>
    <div class="no-tasks">Brak zadań pasujących do wybranego filtru.</div>
  <?php else: ?>
    <?php foreach ($tasks as $t): ?>
      <div class="card <?= $t['status'] ? 'done' : 'pending' ?>">
        <div>
          <div class="card-header">
            <h3><?= htmlspecialchars($t['name']) ?></h3>
            <span class="badge <?= $t['status'] ? 'done' : 'pending' ?>">
              <?= $t['status'] ? 'Wykonane' : 'Oczekuje' ?>
            </span>
          </div>
          <?php if (!empty($t['location_name'])): ?>
            <span class="loc-badge"><?= htmlspecialchars($t['location_name']) ?></span>
          <?php endif; ?>
        </div>
        
        <?php if ($t['status'] && !empty($t['scanned_by'])): ?>
          <div class="scan-info">
            <span>Wykonał: <strong><?= htmlspecialchars($t['scanned_by']) ?></strong></span>
            <span>o godz. <strong><?= date('H:i', strtotime($t['scanned_at'])) ?></strong></span>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
function filterLocation(val) {
  if (val === '') {
    window.location.href = 'manager.php';
  } else {
    window.location.href = 'manager.php?location_id=' + encodeURIComponent(val);
  }
}
</script>
</body>
</html>

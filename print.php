<?php
require 'config.php';
requireLogin();

$db     = getDB();
$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;

if ($taskId > 0) {
    $stmt = $db->prepare("SELECT id, name FROM tasks WHERE id = :id AND active = 1");
    $stmt->execute([':id' => $taskId]);
    $tasks = $stmt->fetchAll();
} else {
    $tasks = $db->query("SELECT id, name FROM tasks WHERE active = 1 ORDER BY sort_order, name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Kody QR – wydruk</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: sans-serif; background: #fff; padding: 20px; }
  
  .no-print { margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .no-print button, .no-print a { padding: 8px 16px; background: #333; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: .9em; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .no-print a { background: #fff; color: #333; border: 1px solid #ccc; }
  .no-print a:hover { background: #f5f5f5; }
  .no-print button:hover { background: #555; }
  .no-print button:disabled { opacity: 0.4; cursor: default; }
  
  .sel-bar { margin-bottom: 14px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; font-size: 0.9em; color: #555; }
  .sel-bar label { display: flex; align-items: center; gap: 6px; cursor: pointer; user-select: none; }
  .sel-bar input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }
  .sel-count { color: #999; font-size: 0.85em; }
  
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; }
  .card { border: 1px solid #ccc; border-radius: 6px; padding: 14px; text-align: center; page-break-inside: avoid; position: relative; }
  .card img { width: 140px; height: 140px; display: block; margin: 0 auto 10px; }
  .card p { font-size: .9em; color: #333; word-break: break-word; }
  
  /* Checkbox na karcie */
  .card-check { position: absolute; top: 6px; left: 6px; z-index: 10; }
  .card-check input { width: 18px; height: 18px; cursor: pointer; }
  .card.selected { outline: 3px solid #3b82f6; outline-offset: -1px; border-radius: 6px; }
  
  /* Ukrywanie odznaczonych podczas druku */
  @media print {
    .no-print, .card-check { display: none; }
    body { padding: 10px; }
    .card.print-hidden { display: none; }
  }
</style>
</head>
<body>
<div class="no-print">
  <button id="printBtn" onclick="printSelected()">&#128438; Drukuj wszystkie</button>
  <a href="index.php">&larr; Wróć</a>
</div>

<?php if (empty($tasks)): ?>
  <p>Brak zadań do wydruku.</p>
<?php else: ?>
<div class="sel-bar no-print">
  <label><input type="checkbox" id="selectAll" onchange="toggleAll(this.checked)"> Zaznacz wszystko</label>
  <span class="sel-count" id="selCount">Zaznaczono: <?= count($tasks) ?> / <?= count($tasks) ?></span>
</div>

<div class="grid">
<?php foreach ($tasks as $t):
  $url = APP_URL . '/scan.php?task_id=' . $t['id'];
  $qr  = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . urlencode($url);
?>
  <div class="card selected" data-id="<?= $t['id'] ?>">
    <span class="card-check no-print"><input type="checkbox" checked onchange="toggleCard(this, <?= $t['id'] ?>)"></span>
    <img src="<?= $qr ?>" alt="QR">
    <p><?= htmlspecialchars($t['name']) ?></p>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function toggleAll(checked) {
  document.querySelectorAll('.card-check input').forEach(cb => cb.checked = checked);
  document.querySelectorAll('.card').forEach(c => c.classList.toggle('selected', checked));
  updateCount();
}

function toggleCard(cb, id) {
  const card = cb.closest('.card');
  card.classList.toggle('selected', cb.checked);
  // Aktualizuj "zaznacz wszystko"
  const all = document.querySelectorAll('.card-check input');
  const checked = document.querySelectorAll('.card-check input:checked');
  document.getElementById('selectAll').checked = all.length === checked.length;
  updateCount();
}

function updateCount() {
  const checked = document.querySelectorAll('.card-check input:checked').length;
  const total = document.querySelectorAll('.card-check input').length;
  document.getElementById('selCount').textContent = 'Zaznaczono: ' + checked + ' / ' + total;
  const btn = document.getElementById('printBtn');
  if (checked === 0) {
    btn.disabled = true;
    btn.innerHTML = '&#128438; Nie zaznaczono żadnych';
  } else if (checked === total) {
    btn.disabled = false;
    btn.innerHTML = '&#128438; Drukuj wszystkie (' + total + ')';
  } else {
    btn.disabled = false;
    btn.innerHTML = '&#128438; Drukuj zaznaczone (' + checked + ')';
  }
}

function printSelected() {
  const checked = document.querySelectorAll('.card-check input:checked');
  if (checked.length === 0) return;
  
  // Ukryj odznaczone karty przed drukiem
  document.querySelectorAll('.card').forEach(c => c.classList.toggle('print-hidden', !c.classList.contains('selected')));
  
  window.print();
  
  // Przywróć wszystkie po wydruku
  document.querySelectorAll('.card').forEach(c => c.classList.remove('print-hidden'));
}

// Inicjalizacja licznika
updateCount();
</script>
</body>
</html>

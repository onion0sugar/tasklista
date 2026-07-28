<?php
require 'config.php';
requireManager();

$db   = getDB();
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$stmt = $db->prepare("SELECT * FROM logs WHERE date = :date ORDER BY logged_at DESC");
$stmt->execute([':date' => $date]);
$rows = $stmt->fetchAll();

$dates = $db->query("SELECT DISTINCT date FROM logs WHERE date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) ORDER BY date DESC LIMIT 14")->fetchAll(PDO::FETCH_COLUMN);

$labels = [
    'completed'     => ['Wykonane',          '#059669', '#d1fae5'],
    'repeat'        => ['Ponowna próba',     '#d97706', '#fef3c7'],
    'created'       => ['Utworzone',         '#2563eb', '#dbeafe'],
    'renamed'       => ['Edytowane',         '#7c3aed', '#ede9fe'],
    'deleted'       => ['Usunięte',          '#dc2626', '#fee2e2'],
    'activated'     => ['Aktywowane',        '#2563eb', '#dbeafe'],
    'deactivated'   => ['Dezaktywowane',     '#4b5563', '#f3f4f6'],
    'loc_created'   => ['Nowa Lokalizacja',   '#0891b2', '#ecfeff'],
    'loc_deleted'   => ['Usunięta Lokaliz.', '#4b5563', '#f3f4f6'],
    'emp_created'   => ['Nowy Pracownik',     '#0891b2', '#ecfeff'],
    'emp_deleted'   => ['Usunięty Pracow.',  '#4b5563', '#f3f4f6'],
    'reset'         => ['Reset statusów',    '#475569', '#f1f5f9'],
    'report_sent'   => ['Raport wysłany',    '#0891b2', '#ecfeff'],
    'report_failed' => ['Raport – błąd',     '#dc2626', '#fee2e2'],
    'report_skipped'=> ['Raport pominięty',  '#475569', '#f1f5f9'],
];

$backUrl = !empty($_SESSION['admin']) ? 'index.php' : 'manager.php';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logi Systemowe – <?= htmlspecialchars($date) ?></title>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 960px; margin: 0 auto; padding: 20px; background: #f8fafc; color: #1e293b; }
  
  header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
  h1 { margin: 0; font-size: 1.8em; color: #0f172a; }
  
  nav { margin-bottom: 20px; display: flex; gap: 10px; width: 100%; }
  nav a { color: #475569; text-decoration: none; font-size: 0.9em; border: 1px solid #e2e8f0; padding: 8px 14px; border-radius: 8px; background: #fff; font-weight: 500; transition: all 0.2s; }
  nav a:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }
  
  /* ── Scrollowana oś dat ── */
  .date-bar { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
  .date-bar-label { font-weight: 600; font-size: 0.85em; color: #64748b; margin-bottom: 10px; }
  .date-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 4px 0 8px; -webkit-overflow-scrolling: touch; scroll-snap-type: x proximity; }
  .date-scroll::-webkit-scrollbar { height: 6px; }
  .date-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
  .date-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
  .date-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
  .date-chip { flex: 0 0 auto; scroll-snap-align: start; display: flex; flex-direction: column; align-items: center; min-width: 64px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; text-decoration: none; cursor: pointer; transition: all 0.2s; user-select: none; }
  .date-chip:hover { background: #f1f5f9; border-color: #cbd5e1; }
  .date-chip.active { background: #0f172a; border-color: #0f172a; }
  .date-chip.active .chip-dow { color: #94a3b8; }
  .date-chip.active .chip-day { color: #fff; }
  .date-chip.active .chip-month { color: #64748b; }
  .chip-dow { font-size: 0.7em; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; line-height: 1; }
  .chip-day { font-size: 1.15em; font-weight: 700; color: #0f172a; line-height: 1.3; margin: 2px 0; }
  .chip-month { font-size: 0.7em; font-weight: 500; color: #94a3b8; line-height: 1; }
  
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
  table { width: 100%; border-collapse: collapse; text-align: left; }
  th, td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 0.95em; }
  th { background: #f8fafc; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
  tr:last-child td { border-bottom: none; }
  
  .badge { display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: .8em; font-weight: 600; }
</style>
</head>
<body>

<header>
  <div>
    <h1>Logi Systemowe</h1>
    <div style="color: #64748b; font-size: 0.9em; margin-top: 4px;">Przeglądasz logi z dnia: <strong><?= date('d.m.Y', strtotime($date)) ?></strong></div>
  </div>
</header>

<nav>
  <a href="<?= $backUrl ?>"><i class="ph-bold ph-arrow-left"></i> Powrót do panelu</a>
</nav>

<div class="date-bar">
  <div class="date-bar-label"><i class="ph-bold ph-calendar"></i> Wybierz dzień:</div>
  <div class="date-scroll">
    <?php
    $dowPl = ['Niedz.','Pon.','Wt.','Śr.','Czw.','Pt.','Sob.'];
    $monPl = ['sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
    $allDates = in_array($date, $dates) ? $dates : array_merge([$date], $dates);
    foreach ($allDates as $d):
      $ts      = strtotime($d);
      $active  = $d === $date ? ' active' : '';
      $dow     = $dowPl[(int)date('w', $ts)];
      $day     = date('j', $ts);
      $month   = $monPl[(int)date('n', $ts) - 1];
      $url     = 'logs.php?date=' . $d;
    ?>
      <a href="<?= $url ?>" class="date-chip<?= $active ?>">
        <span class="chip-dow"><?= $dow ?></span>
        <span class="chip-day"><?= $day ?></span>
        <span class="chip-month"><?= $month ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Nazwa elementu</th>
        <th>Akcja / Status</th>
        <th>Wykonawca</th>
        <th>Czas zdarzenia</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $action = $r['action'] ?? 'completed';
      [$label, $color, $bg] = $labels[$action] ?? [$action, '#1e293b', '#f1f5f9'];
    ?>
    <tr>
      <td style="font-weight: 500; color: #0f172a;"><?= htmlspecialchars($r['task_name']) ?></td>
      <td><span class="badge" style="color:<?= $color ?>;background:<?= $bg ?>"><?= $label ?></span></td>
      <td style="color: #475569;"><?= !empty($r['scanned_by']) ? htmlspecialchars($r['scanned_by']) : '<span style="color:#cbd5e1">—</span>' ?></td>
      <td style="color: #64748b; font-size: 0.9em;"><?= date('H:i:s', strtotime($r['logged_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="4" style="color:#94a3b8;text-align:center;padding: 32px;">Brak wpisów w logach dla wybranego dnia.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>

<?php
// Cron: 0 23 * * * php /var/www/html/tasklist/report.php
// Wysyła raport za bieżący dzień o godzinie 23:00.
// Aby wysłać za poprzedni dzień, ustaw cron na 00:05 i zmień: date('Y-m-d', strtotime('yesterday'))

require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$db   = getDB();
$date = date('Y-m-d'); // zmień na: date('Y-m-d', strtotime('yesterday')) jeśli cron o północy

// Pobierz wszystkie aktywne zadania wraz z nazwą lokalizacji
$tasks = $db->query("
    SELECT t.id, t.name, l.name AS location_name 
    FROM tasks t 
    LEFT JOIN locations l ON t.location_id = l.id 
    WHERE t.active = 1 
    ORDER BY l.name, t.sort_order, t.name
")->fetchAll();

// Pobierz statusy i dane wykonania za dany dzień
$stmt = $db->prepare("
    SELECT task_id, status, scanned_by, scanned_at FROM daily_tasks WHERE date = :date
");
$stmt->execute([':date' => $date]);
$daily = [];
foreach ($stmt->fetchAll() as $row) {
    $daily[$row['task_id']] = $row;
}

// Podziel i pogrupuj zadania według lokalizacji
$doneGrouped    = [];
$missingGrouped = [];
$totalDone      = 0;
$totalMissing   = 0;

foreach ($tasks as $t) {
    $locName = !empty($t['location_name']) ? $t['location_name'] : 'Brak przypisanej lokalizacji';
    $dTask = isset($daily[$t['id']]) ? $daily[$t['id']] : null;
    
    if ($dTask && $dTask['status'] == 1) {
        if (!isset($doneGrouped[$locName])) {
            $doneGrouped[$locName] = [];
        }
        $doneGrouped[$locName][] = [
            'name' => $t['name'],
            'time' => !empty($dTask['scanned_at']) ? date('H:i', strtotime($dTask['scanned_at'])) : '—',
            'by'   => !empty($dTask['scanned_by']) ? $dTask['scanned_by'] : '—'
        ];
        $totalDone++;
    } else {
        if (!isset($missingGrouped[$locName])) {
            $missingGrouped[$locName] = [];
        }
        $missingGrouped[$locName][] = $t['name'];
        $totalMissing++;
    }
}

$totalAll     = count($tasks);
$dateFormatted = date('d.m.Y', strtotime($date));

// Jeśli wszystkie zadania zostały wykonane, nie wysyłaj raportu
if ($totalMissing === 0) {
    $db->prepare("
        INSERT INTO logs (task_id, task_name, action, date, logged_at)
        VALUES (0, :name, 'report_skipped', :date, NOW())
    ")->execute([
        ':name' => "Pominięto wysyłkę raportu – wszystkie zadania wykonane",
        ':date' => $date,
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] Wszystkie zadania zostały wykonane ($totalDone/$totalAll). Raport nie został wysłany.\n";
    exit;
}

// Buduj HTML maila
ob_start();
?>
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333">
  <h2 style="margin-bottom:4px">Raport dzienny</h2>
  <p style="color:#666;margin-top:0"><?= $dateFormatted ?></p>

  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px">
    <tr>
      <td style="background:#d1e7dd;border-radius:6px;padding:12px 16px;text-align:center;width:48%">
        <div style="font-size:2em;font-weight:bold;color:#0f5132"><?= $totalDone ?></div>
        <div style="color:#0f5132;font-size:.9em">Wykonane</div>
      </td>
      <td width="4%"></td>
      <td style="background:#f8d7da;border-radius:6px;padding:12px 16px;text-align:center;width:48%">
        <div style="font-size:2em;font-weight:bold;color:#842029"><?= $totalMissing ?></div>
        <div style="color:#842029;font-size:.9em">Niewykonane</div>
      </td>
    </tr>
  </table>

  <?php if ($totalDone > 0): ?>
  <h3 style="color:#0f5132;border-bottom:1px solid #d1e7dd;padding-bottom:6px">✓ Wykonane (<?= $totalDone ?>)</h3>
  <?php foreach ($doneGrouped as $locName => $gTasks): ?>
    <h4 style="margin: 14px 0 6px; color:#475569; font-size: 0.95em; border-left: 3px solid #10b981; padding-left: 8px;"><?= htmlspecialchars($locName) ?></h4>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px">
      <?php foreach ($gTasks as $t): ?>
      <tr>
        <td style="padding:8px 0;border-bottom:1px solid #eee;font-size:0.9em;padding-left:12px;"><?= htmlspecialchars($t['name']) ?></td>
        <td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;color:#555;font-size:0.85em;padding-right:8px;"><?= htmlspecialchars($t['by']) ?> &bull; <?= htmlspecialchars($t['time']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($totalMissing > 0): ?>
  <h3 style="color:#842029;border-bottom:1px solid #f8d7da;padding-bottom:6px">✗ Niewykonane (<?= $totalMissing ?>)</h3>
  <?php foreach ($missingGrouped as $locName => $gTasks): ?>
    <h4 style="margin: 14px 0 6px; color:#64748b; font-size: 0.95em; border-left: 3px solid #ef4444; padding-left: 8px;"><?= htmlspecialchars($locName) ?></h4>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px">
      <?php foreach ($gTasks as $name): ?>
      <tr>
        <td style="padding:8px 0;border-bottom:1px solid #eee;color:#842029;font-size:0.9em;padding-left:12px;"><?= htmlspecialchars($name) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
  <?php endif; ?>

  <p style="color:#aaa;font-size:.8em;margin-top:32px;border-top:1px solid #eee;padding-top:12px">
    Raport wygenerowany automatycznie — <?= $dateFormatted ?> | System Zadań
  </p>
</body>
</html>
<?php
$html = ob_get_clean();

// Wyślij mail
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_ENCRYPTION;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
    foreach (array_filter(array_map('trim', explode(',', REPORT_TO))) as $addr) {
        $mail->addAddress($addr);
    }

    $mail->isHTML(true);
    $mail->Subject = "Raport zadań – $dateFormatted ($totalDone/$totalAll wykonanych)";
    $mail->Body    = $html;
    $mail->AltBody = "Wykonane: $totalDone/$totalAll\nNiewykonane: $totalMissing";

    $mail->send();
    $db->prepare("
        INSERT INTO logs (task_id, task_name, action, date, logged_at)
        VALUES (0, :name, 'report_sent', :date, NOW())
    ")->execute([
        ':name' => "Raport wysłany do " . REPORT_TO,
        ':date' => $date,
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] Raport wysłany do " . REPORT_TO . "\n";
} catch (Exception $e) {
    $db->prepare("
        INSERT INTO logs (task_id, task_name, action, date, logged_at)
        VALUES (0, :name, 'report_failed', :date, NOW())
    ")->execute([
        ':name' => "Błąd wysyłki raportu: " . $mail->ErrorInfo,
        ':date' => $date,
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] Błąd wysyłki: " . $mail->ErrorInfo . "\n";
}

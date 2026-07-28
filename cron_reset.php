<?php
require __DIR__ . '/config.php';

$db    = getDB();
$today = date('Y-m-d');

$db->prepare("
    INSERT IGNORE INTO daily_tasks (task_id, date, status)
    SELECT id, :date, 0 FROM tasks WHERE active = 1
")->execute([':date' => $today]);

$stmt = $db->prepare("UPDATE daily_tasks SET status = 0 WHERE date = :date");
$stmt->execute([':date' => $today]);
$count = $stmt->rowCount();

$db->prepare("
    INSERT INTO logs (task_id, task_name, action, date, logged_at)
    VALUES (0, :name, 'reset', :date, NOW())
")->execute([
    ':name' => "Reset dzienny ($count zadań)",
    ':date' => $today,
]);

echo "[" . date('Y-m-d H:i:s') . "] Reset: zresetowano $count zadań na dzień $today\n";

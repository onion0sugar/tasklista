<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
checkAutoLogin();

if (!empty($_SESSION['admin'])) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}
if (!empty($_SESSION['manager'])) {
    header('Location: ' . APP_URL . '/manager.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);
    
    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['admin'] = true;
        if ($remember) {
            $hash = hash('sha256', 'admin:' . ADMIN_USER . ':' . ADMIN_PASS);
            setcookie('remember_auth', 'admin:' . $hash, time() + 30 * 86400, '/', '', false, true);
        }
        header('Location: ' . APP_URL . '/index.php');
        exit;
    } elseif ($user === MANAGER_USER && $pass === MANAGER_PASS) {
        $_SESSION['manager'] = true;
        if ($remember) {
            $hash = hash('sha256', 'manager:' . MANAGER_USER . ':' . MANAGER_PASS);
            setcookie('remember_auth', 'manager:' . $hash, time() + 30 * 86400, '/', '', false, true);
        }
        header('Location: ' . APP_URL . '/manager.php');
        exit;
    }
    $error = 'Nieprawidłowy login lub hasło.';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie</title>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
<style>
  body { font-family: sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .box { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 32px; width: 100%; max-width: 320px; }
  h1 { margin: 0 0 24px; font-size: 1.3em; }
  label { display: block; margin-bottom: 4px; font-size: .85em; color: #555; }
  input[type=text], input[type=password] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 16px; font-size: 1em; box-sizing: border-box; }
  .remember-row { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; cursor: pointer; user-select: none; }
  .remember-row input { margin: 0; cursor: pointer; }
  .remember-row span { font-size: 0.85em; color: #555; }
  button { width: 100%; padding: 10px; background: #333; color: #fff; border: none; border-radius: 4px; font-size: 1em; cursor: pointer; }
  .error { color: #c00; margin-bottom: 16px; font-size: .9em; }
</style>
</head>
<body>
<div class="box">
  <h1>Panel logowania</h1>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Login</label>
    <input type="text" name="username" autocomplete="username" required>
    <label>Hasło</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <label class="remember-row">
      <input type="checkbox" name="remember" value="1">
      <span>Zapamiętaj logowanie</span>
    </label>
    <button type="submit">Zaloguj</button>
  </form>
</div>
</body>
</html>

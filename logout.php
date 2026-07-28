<?php
require 'config.php';
session_start();
session_destroy();
setcookie('remember_auth', '', time() - 3600, '/');
header('Location: ' . APP_URL . '/login.php');
exit;

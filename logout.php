<?php
session_start();

// セッションを破棄
$_SESSION = array();
session_destroy();

// ログインページにリダイレクト
header('Location: login.php');
exit; 
<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// デバッグモード
define('DEBUG', true);

// ベースURL（重複を削除）
define('BASE_URL', '/liveshare/');

// データベース接続情報
define('DB_HOST', 'localhost');
define('DB_NAME', 'liveshare');
define('DB_USER', 'root');
define('DB_PASS', '');

// セッション設定（セッション開始前に設定）
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
session_start();

// タイムゾーンの設定
date_default_timezone_set('Asia/Tokyo');

// エラー表示設定
error_reporting(E_ALL);
ini_set('display_errors', 1);

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Database Connection Error: ' . $e->getMessage());
    echo $e->getMessage();
    
    die('データベースに接続できませんでした。');
}

// アセットのパス
define('CSS_PATH', BASE_URL . '/css');
define('JS_PATH', BASE_URL . '/js');
define('IMG_PATH', BASE_URL . '/img');
define('UPLOADS_PATH', BASE_URL . '/uploads');

// CSRF対策のトークン生成関数
function generateToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

error_log('Received GET id: ' . ($_GET['id'] ?? 'NONE'));



<?php
// エラー表示を有効化（開発時のみ）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 詳細なデバッグ情報
error_log('=== Request Information ===');
error_log('REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
error_log('SCRIPT_FILENAME: ' . $_SERVER['SCRIPT_FILENAME']);
error_log('DOCUMENT_ROOT: ' . $_SERVER['DOCUMENT_ROOT']);
error_log('PHP_SELF: ' . $_SERVER['PHP_SELF']);
error_log('REQUEST_URI: ' . $_SERVER['REQUEST_URI']);
error_log('SCRIPT_NAME: ' . $_SERVER['SCRIPT_NAME']);
error_log('HTTP_HOST: ' . $_SERVER['HTTP_HOST']);
error_log('HTTP_REFERER: ' . ($_SERVER['HTTP_REFERER'] ?? 'none'));
error_log('Current working directory: ' . getcwd());
error_log('__FILE__: ' . __FILE__);
error_log('__DIR__: ' . __DIR__);
error_log('=== End Request Information ===');

session_start();

// プロジェクトのベースパスを設定
define('BASE_PATH', __DIR__);
define('WEB_ROOT', '/livelog');

// デバッグ用のログ出力
error_log('=== Request Information ===');
error_log('BASE_PATH: ' . BASE_PATH);
error_log('WEB_ROOT: ' . WEB_ROOT);
error_log('REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
error_log('SCRIPT_FILENAME: ' . $_SERVER['SCRIPT_FILENAME']);
error_log('REQUEST_URI: ' . $_SERVER['REQUEST_URI']);
error_log('SCRIPT_NAME: ' . $_SERVER['SCRIPT_NAME']);
error_log('HTTP_HOST: ' . $_SERVER['HTTP_HOST']);
error_log('HTTP_REFERER: ' . ($_SERVER['HTTP_REFERER'] ?? 'none'));
error_log('Current working directory: ' . getcwd());
error_log('__FILE__: ' . __FILE__);
error_log('__DIR__: ' . __DIR__);
error_log('=== End Request Information ===');

// 必要なファイルの存在確認
$requiredFiles = [
    BASE_PATH . '/config/database.php',
    BASE_PATH . '/includes/config.php',
    BASE_PATH . '/includes/functions.php'
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log('Required file not found: ' . $file);
        die('Configuration error: Required file not found - ' . $file);
    }
}

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/functions.php';

// POSTデータのデバッグ出力
error_log('POST data: ' . print_r($_POST, true));

// デバッグ用のログ出力
error_log('Create item list script started');

// セッションチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// POSTリクエストのチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: live-board-items.php');
    exit;
}

// CSRFトークンの検証を追加
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = '不正なリクエストです。';
    header('Location: live-board-items.php');
    exit;
}

// 必須項目のバリデーション
if (empty($_POST['live_id']) || empty($_POST['title'])) {
    $_SESSION['error'] = '必須項目が入力されていません。';
    header('Location: live-board-items.php');
    exit;
}

try {
    // 入力値の取得と sanitize
    $liveId = filter_input(INPUT_POST, 'live_id', FILTER_SANITIZE_NUMBER_INT);
    $title = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $tags = trim(filter_input(INPUT_POST, 'tags', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    // トランザクション開始
    $pdo->beginTransaction();

    // 持ち物リストの作成
    $stmt = $pdo->prepare("
        INSERT INTO item_lists (user_id, live_id, title, description, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $liveId,
        $title,
        $description
    ]);
    
    $itemListId = $pdo->lastInsertId();

    // タグの処理
    if (!empty($tags)) {
        $tagNames = array_map('trim', explode(',', $tags));
        foreach ($tagNames as $tagName) {
            if (empty($tagName)) continue;

            // タグの存在確認または作成
            $stmt = $pdo->prepare("
                INSERT INTO tags (name, created_at)
                VALUES (?, NOW())
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
            ");
            $stmt->execute([$tagName]);
            $tagId = $pdo->lastInsertId();

            // タグと持ち物リストの関連付け
            $stmt = $pdo->prepare("
                INSERT INTO taggables (tag_id, taggable_id, taggable_type, created_at)
                VALUES (?, ?, 'item_list', NOW())
            ");
            $stmt->execute([$tagId, $itemListId]);
        }
    }

    // トランザクションのコミット
    $pdo->commit();

    // 成功メッセージをセット
    $_SESSION['success'] = '持ち物リストを作成しました。';
    
    // 作成した持ち物リストの詳細ページにリダイレクト
    header("Location: item-list-detail.php?id=" . $itemListId);
    exit;

} catch (Exception $e) {
    // エラー発生時はロールバック
    $pdo->rollBack();
    
    // エラーログの記録
    error_log($e->getMessage());
    
    // エラーメッセージをセット
    $_SESSION['error'] = '持ち物リストの作成に失敗しました。';
    
    // 元のページにリダイレクト
    header('Location: live-board-items.php');
    exit;
} 
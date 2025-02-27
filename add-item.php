<?php
require_once 'config/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

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

// CSRFトークンの検証
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = '不正なリクエストです。';
    header('Location: live-board-items.php');
    exit;
}

// 必須項目のバリデーション
if (empty($_POST['item_list_id']) || empty($_POST['name'])) {
    $_SESSION['error'] = '必須項目が入力されていません。';
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

// POSTデータのデバッグ出力（try文の前に追加）
error_log('POST data: ' . print_r($_POST, true));

try {
    // 入力値の取得と sanitize
    $itemListId = filter_input(INPUT_POST, 'item_list_id', FILTER_SANITIZE_NUMBER_INT);
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $category = trim(filter_input(INPUT_POST, 'category', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $isPublic = isset($_POST['is_public']) ? 1 : 0;
    // カテゴリーが「必須」の場合、is_essentialをtrueに設定
    $isEssential = ($category === '必須') ? 1 : 0;

    // リストの所有者確認
    $stmt = $pdo->prepare("
        SELECT user_id 
        FROM item_lists 
        WHERE id = ?
    ");
    $stmt->execute([$itemListId]);
    $list = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$list || $list['user_id'] !== $_SESSION['user_id']) {
        throw new Exception('このリストにアイテムを追加する権限がありません。');
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // アイテムの追加
    $stmt = $pdo->prepare("
        INSERT INTO items (
            item_list_id, 
            name, 
            category, 
            description, 
            is_essential,
            is_public, 
            created_at, 
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    $stmt->execute([
        $itemListId,
        $name,
        $category,
        $description,
        $isEssential,
        $isPublic
    ]);

    // トランザクションのコミット
    $pdo->commit();

    // 成功メッセージをセット
    $_SESSION['success'] = 'アイテムを追加しました。';
    
    // 元のページにリダイレクト
    header("Location: item-list-detail.php?id=" . $itemListId);
    exit;

} catch (Exception $e) {
    // エラー発生時はロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // エラーログの記録
    error_log($e->getMessage());
    
    // SQLエラーの詳細出力（catch文に追加）
    error_log('Database Error: ' . $e->getMessage());
    if ($e instanceof PDOException) {
        error_log('SQL State: ' . $e->errorInfo[0]);
        error_log('Error Code: ' . $e->errorInfo[1]);
        error_log('Error Message: ' . $e->errorInfo[2]);
    }
    
    // エラーメッセージをセット
    $_SESSION['error'] = 'アイテムの追加に失敗しました。';
    
    // 元のページにリダイレクト
    header("Location: item-list-detail.php?id=" . $itemListId);
    exit;
} 
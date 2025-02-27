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

try {
    // 入力値の取得と sanitize
    $itemListId = filter_input(INPUT_POST, 'item_list_id', FILTER_SANITIZE_NUMBER_INT);
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    // 権限チェック
    $stmt = $pdo->prepare("SELECT user_id FROM item_lists WHERE id = ?");
    $stmt->execute([$itemListId]);
    $itemList = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$itemList || $itemList['user_id'] !== $_SESSION['user_id']) {
        throw new Exception('このリストを編集する権限がありません。');
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // 説明文の更新
    $stmt = $pdo->prepare("
        UPDATE item_lists 
        SET description = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$description, $itemListId]);

    // トランザクションのコミット
    $pdo->commit();

    // 成功メッセージをセット
    $_SESSION['success'] = '説明文を更新しました。';
    
} catch (Exception $e) {
    // エラー発生時はロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // エラーログの記録
    error_log($e->getMessage());
    
    // エラーメッセージをセット
    $_SESSION['error'] = '説明文の更新に失敗しました。';
}

// 元のページにリダイレクト
header("Location: item-list-detail.php?id=" . $itemListId);
exit; 
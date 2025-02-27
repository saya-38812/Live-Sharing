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
    $itemId = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);

    // アイテムの所有者確認
    $stmt = $pdo->prepare("
        SELECT i.*, il.user_id, il.id as item_list_id
        FROM items i
        JOIN item_lists il ON i.item_list_id = il.id
        WHERE i.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception('アイテムが見つかりません。');
    }

    if ($item['user_id'] !== $_SESSION['user_id']) {
        throw new Exception('このアイテムを削除する権限がありません。');
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // アイテムの削除
    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$itemId]);

    // トランザクションのコミット
    $pdo->commit();

    // 成功メッセージをセット
    $_SESSION['success'] = 'アイテムを削除しました。';
    
    // 元のページにリダイレクト
    header("Location: item-list-detail.php?id=" . $item['item_list_id']);
    exit;

} catch (Exception $e) {
    // エラー発生時はロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // エラーログの記録
    error_log($e->getMessage());
    
    // エラーメッセージをセット
    $_SESSION['error'] = 'アイテムの削除に失敗しました。';
    
    // 元のページにリダイレクト（item_list_idが取得できている場合のみ）
    if (isset($item['item_list_id'])) {
        header("Location: item-list-detail.php?id=" . $item['item_list_id']);
    } else {
        header('Location: live-board-items.php');
    }
    exit;
} 
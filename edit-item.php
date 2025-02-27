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
    $itemListId = filter_input(INPUT_POST, 'item_list_id', FILTER_SANITIZE_NUMBER_INT);
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $category = trim(filter_input(INPUT_POST, 'category', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $isPublic = isset($_POST['is_public']) ? 1 : 0;
    $isEssential = ($category === '必須') ? 1 : 0;

    // 権限チェック
    $stmt = $pdo->prepare("
        SELECT il.user_id 
        FROM items i
        JOIN item_lists il ON i.item_list_id = il.id
        WHERE i.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item || $item['user_id'] !== $_SESSION['user_id']) {
        throw new Exception('このアイテムを編集する権限がありません。');
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // アイテムの更新
    $stmt = $pdo->prepare("
        UPDATE items 
        SET name = ?, 
            category = ?, 
            description = ?, 
            is_essential = ?,
            is_public = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $name,
        $category,
        $description,
        $isEssential,
        $isPublic,
        $itemId
    ]);

    // トランザクションのコミット
    $pdo->commit();

    // 成功メッセージをセット
    $_SESSION['success'] = 'アイテムを更新しました。';
    
} catch (Exception $e) {
    // エラー発生時はロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // エラーメッセージをセット
    $_SESSION['error'] = 'アイテムの更新に失敗しました。';
}

// 元のページにリダイレクト
header("Location: item-list-detail.php?id=" . $itemListId);
exit; 